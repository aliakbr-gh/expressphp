# ExpressPHP production QA review

Reviewed on 9 October 2026 at commit `2867737`, on PHP 8.4.4 (CLI). The project targets PHP 8.2 or newer. All 58 PHP sources under `app/`, `cli/`, `src/`, `config/`, `migrations/`, `public/`, and `routes/` passed `php -l`.

**Do not put this checkout in front of a Play Store app until the P1 items below are fixed and the production host is checked over HTTPS.** The framework already has useful controls: prepared statements, a pinned HS256 JWT verifier, session versions, permission checks on the current private routes, upload trust checks, and production setting guards. Those controls do not cover the shipped configuration, the public registration path, admin takeover permissions, or the database-backup endpoint.

This review reads every maintained project file, including every controller and route. The earlier review in `CODEX_QA_REVIEW.md` left most endpoint behavior out of scope. Findings here were checked against the current source. Where a behavior was executed, the note says **Verified**. No live MySQL, SMTP service, or Apache virtual host was used. No secret, password, or token is printed in this document.

Severity:

- **P1:** fix before this API is reachable from the internet.
- **P2:** a confirmed correctness, authorization, privacy, or performance defect in a feature you will actually run.
- **P3:** narrower interoperability or developer-tool defect.

There are **41 findings: 12 P1, 22 P2, and 7 P3**.

## What a Play Store client will actually call

Native Android clients do not enforce browser CORS. `cors.origins` only affects websites. Every route below is callable by any app that knows the base URL.

The seeded `user` role receives no permissions. A normal registered account can call only the auth routes. The seeded `admin` role receives every permission, including database backup, email send, user password changes, and role assignment. `super-admin` skips permission checks entirely because `PermissionMiddleware` returns success when `role_slug` is `super-admin`.

| Method and path | Who can call it | Production note |
|---|---|---|
| `GET /api/v1/example` | Public | HTML demo. Remove it from a production API. |
| `GET /api/v1/health/server` | Public, and excluded from the rate limiter | Safe liveness body. Unlimited calls. |
| `GET /api/v1/health/database` | Public | Opens a database connection on every call. |
| `POST /api/v1/auth/register` | Public | First account becomes `super-admin`. Later accounts get `user`. |
| `POST /api/v1/auth/login` | Public | Issues a 24-hour bearer token and does not revoke older tokens. |
| `GET /api/v1/auth/me` | Bearer token | Returns the user, role, and full permission list. Password is removed. |
| `POST /api/v1/auth/refresh` | Bearer token | Increments `session_version` for every device, then issues a new 24-hour token. |
| `POST /api/v1/auth/logout` | Bearer token | Increments `session_version`. Signs every device out. |
| `GET/POST/PATCH/DELETE /api/v1/users...` | `users.*` permissions | Password reset and role assignment are full account takeover. |
| `GET/POST/PATCH/DELETE /api/v1/roles...` | `roles.*` permissions | Can retarget the `user` role and the `admin` role. |
| `GET/POST/PATCH/DELETE /api/v1/permissions...` | `permissions.*` permissions | Deleting a permission cascades it off every role. |
| `GET/POST /api/v1/activity-logs` | `activity-logs.view` or `activity-logs.create` | `POST` stores caller-supplied text as an audit event. |
| `GET /api/v1/server-logs` | `server-logs.view` | Reads the whole daily log into memory. |
| `GET/POST /api/v1/rate-limits...` | `rate-limits.*` permissions | Manual block is permanent until cleared. |
| `GET/POST /api/v1/emails...` | `emails.view` or `emails.send` | `POST /send` can mail any recipient as the app. |
| `POST /api/v1/files/upload` | `files.upload` | Stores the file. No API exists to fetch it back. |
| `GET /api/v1/database-backups/download` | `database-backups.download` | Downloads every table, including password hashes. |

## P1 — fix before production

### C01 — The tracked config is a development config with a real signing key

`config/app.php` is tracked by Git. On this checkout it sets `env` to `development`, `debug` to `true`, database user `root` with an empty password, and a 64-character `jwt.secret`. That secret is long enough to pass the startup length check, and it is not one of the two rejected placeholders.

**Verified:** `git ls-files` lists `config/app.php`. The loaded values match the description above. The secret was not printed. File mode is `0644` and `config/` is `0755`, so any local account on the machine can read the signing key.

`Application` returns `Internal Server Error: ` plus the exception message whenever `debug` is true. The production guard that forces `debug` off runs only when `env` is exactly `production`. A typo such as `prod` leaves debug on and still allows an empty database password.

Anyone who has this repository can mint HS256 tokens. `AuthMiddleware` still requires a real active user id and the current `session_version`, so this is a shared-secret failure, not an algorithm bypass.

**Fix:** remove the live secret from Git history if this repository was ever pushed, generate a new secret per environment, and keep production values in a private file that Git does not track. Reject the current committed secret the same way placeholders are rejected. Allow only known environment names, and treat anything other than an explicit production mode as unsafe to publish. Set `debug` to `false` on the Play Store backend. Restrict the config file to the service account (`0640` or `0600`).

### C02 — Public registration hands out `super-admin`, and the check is not atomic

`POST /api/v1/auth/register` has no auth middleware. `User::registrationRoleId()` counts users, then the controller inserts the account in a separate step. The first observed empty table selects `super-admin`. Later accounts get `user` and are active immediately. There is no invite code, email verification, or proof that the caller owns anything.

Two concurrent first registrations can both observe zero users and both become `super-admin`. README tells you to register privately before going public. That is an operational hope, not a lock.

**Fix:** create the first administrator from a CLI command that refuses to run when any user exists, and make public registration always assign `user`. If self-service signup is intentional, add a server-side throttle per identity, not only per IP, and confirm the account through a channel you trust.

### C03 — Several permissions are full takeover, and system roles are not frozen

`UserController::assignRole()` accepts any existing `role_id`, including `super-admin`, including the caller. The only required permission is `users.roles.assign`.

`RoleController::syncPermissions()` and `attachPermission()` can place every permission onto the `user` role. That includes `database-backups.download`, `emails.send`, `users.update`, and `users.roles.assign`. The slug lock in `update()` and `destroy()` covers `super-admin` and `user` only. The `admin` role can be renamed or deleted when no user holds it. `PermissionController::destroy()` can delete a seeded permission, and the foreign key cascades it off every role.

The migration grants every permission to both `super-admin` and `admin`. After that, one stolen admin token can:

- set a new password on any account, including the only super-admin (`users.update` writes the hash and bumps `session_version`)
- promote any account to `super-admin`
- attach backup permission to the role used by every Play Store user
- download the database

`users.update` does not ask for the current password.

**Fix:** treat `super-admin` assignment, permission edits on `user` and `admin`, and database backup as separate break-glass operations. Reject self-promotion and reject assigning `super-admin` unless the caller is already `super-admin` and a second factor or a CLI confirms it. Stop granting `database-backups.download` and `emails.send` to the normal admin role. Keep seeded permission rows undeletable.

### C04 — Database backup over HTTP is a full data export

`GET /api/v1/database-backups/download` builds a ZIP of every base table and streams it. `DatabaseBackup` runs `SELECT *` and writes explicit `INSERT` values, so `users.password` hashes are in the archive. The ZIP is not encrypted. The request holds a PHP worker for the whole export. There is no consistent snapshot and no `ORDER BY`, so concurrent writes can skip or duplicate rows. `fwrite` accepts a short write, and `ZipArchive::close()` is not checked, so a failed archive can still be reported as success.

The controller deletes the SQL and ZIP after the stream. A failure while streaming happens after `Application` has already finished its error handler, so the client can receive a partial archive and the failure is outside the normal JSON error path.

**Fix:** remove this route from the public mobile API. Run backups from the server, with a read-only database user, a consistent snapshot, and encryption at rest. Test a restore into an empty database before you depend on it.

### C05 — Login does not revoke stolen tokens, and refresh has no absolute limit

`jwt.ttl` is 86400 seconds. `User::authenticate()` verifies the password and, when a rehash is needed, writes the new hash. It does not change `session_version`. A new login therefore leaves every existing token valid.

`refresh` is an authenticated call with the same access token. It increments `session_version` and mints another 24-hour token. An expired token cannot refresh, because `AuthMiddleware` rejects it first. A stolen token can call `refresh` forever until the legitimate user logs out or changes the password. Logout and refresh bump one global version, so a phone and a tablet cannot stay signed in independently. Two overlapping refreshes can leave one client holding a token whose version is already stale.

`UserController::update()` computes the next version in PHP (`$user['session_version'] + 1`) and writes that absolute number. A logout or refresh that increments the row between the read and the write can be overwritten, so a token that should have died stays valid across a password change. The rehash inside `authenticate()` has the same shape: it can replace a password that a reset just stored, then `findDetailed()` can return the new session version to the old password.

**Fix:** give the mobile app a short-lived access token and a separate refresh token stored in the Android Keystore. Rotate the refresh token on every use, store its id server-side, and detect reuse. Keep an absolute session lifetime. On login, logout, and password change, revoke the session in one conditional `UPDATE` that matches the expected hash and version. Support more than one device row if the product needs it.

### C06 — Rate limiting fails open when the record is corrupt, and it is per IP only

`RateLimiter::decode()` turns invalid JSON and non-array JSON into a fresh allowed record. `update()` ignores the results of `ftruncate`, `fwrite`, and `fflush`. Login and register are fail-closed only when `check()` throws. A damaged file does not throw.

**Verified:** blocking `203.0.113.50`, replacing its record with broken JSON, then calling `check()` for `POST /api/v1/auth/login` returned `allowed=true` even though that path is in `fail_closed`.

There is no per-username failure counter. The limit is 120 requests per 60 seconds per IP, then a 5-minute pause, then a 30-minute block after 5 violations. Mobile carriers put many customers behind one address, so one busy or hostile neighbor can block real users. Independent app servers with separate `storage/rate-limiter` directories do not share counters. Equivalent IPv6 spellings hash to different files because the filename is `sha256` of the raw string.

**Fix:** treat a damaged record as a deny on fail-closed routes, write the full record or fail, and canonicalize IPs before every lookup. Add a per-account login throttle that survives IP rotation. Put a shared limiter at the edge if you run more than one app node.

### C07 — Request bodies have no application limit

`Request::capture()` reads all of `php://input`. `json()` decodes that body twice when it needs object-versus-list shape. Login validates `password` as `required|string` with no maximum. Registration's `max:72` runs only after the full body is already in memory.

Apache's default body limit is unlimited unless `LimitRequestBody` is set. This process did not read the MAMP or production `php.ini`, so `post_max_size` must not be assumed.

**Fix:** set an edge limit and an application limit, read at most limit+1 bytes, and answer `413` above the budget. Use a small JSON budget for auth and a separate budget for uploads. Cap the login password at 72 bytes before bcrypt. Keep one decoded tree.

### C08 — Client IP and HTTPS depend on a proxy list that is empty, and the parser takes the wrong hop

`trusted_proxies` is `[]`. `Request::ip()` then uses `REMOTE_ADDR`. Behind nginx, Apache, or a load balancer, every Play Store user shares the proxy address and one rate-limit file. `Request::secure()` stays false, so `Strict-Transport-Security` is never sent even when the public URL is HTTPS.

When the proxy address is trusted, `ip()` returns the leftmost `X-Forwarded-For` value with no IP validation.

**Verified:** a trusted peer `198.51.100.10` and header `192.0.2.66, 203.0.113.10` produced `192.0.2.66`.

nginx `proxy_add_x_forwarded_for` appends the real client, so the leftmost value is attacker-controlled. A long or non-IP value also lands in `activity_logs.ip_address`, which is `VARCHAR(45)`. The insert then fails after the main write has already succeeded, and the client gets a 500.

**Fix:** trust only the immediate proxy, walk the forwarding list from the right, and keep the first address that is not a trusted proxy. Validate it with `FILTER_VALIDATE_IP` and store a canonical form. Use the same trust decision for `X-Forwarded-Proto`. Until that is configured, the Play Store host will either share one bucket for all users or accept spoofed addresses.

### C09 — Secrets in the query string are written to the log URL

`RequestLogger` redacts the structured `query` object and then stores `originalURL()`, which still contains the raw query. `validate()` merges the query into the body with `array_replace_recursive($this->query, $input)`, so a query field is accepted when the JSON body omits it.

**Verified:** a synthetic URL containing `password=` stayed intact on `originalURL()`. A JSON user create whose body had no `role_id` still validated `role_id` from the query string. The on-disk log `storage/logs/2026-09-22.log` has 18 lines and 4 URLs that contain a query string. Those four URLs did not contain `password`, `token`, or `secret` keys. The code path is still live. The log file is mode `0644`.

Redaction is an exact key match against a short list. `current_password`, `otp`, `pin`, and `new_password` would be stored. Multipart bodies do not reach this cap: an empty `php://input` makes `input()` return null, so upload fields are omitted rather than oversized. Query arrays are not capped.

**Fix:** log the path only, plus an allowlist of query keys. Stop merging query parameters into JSON bodies. Expand redaction to password-like names. Rotate or delete old log files after the fix.

### C10 — Errors can leak internals, and several failures are invisible

With the current `debug` flag, uncaught exceptions other than validation, HTTP, and PDO constraint errors return the exception message to the client. `public/index.php` turns startup failures into a generic 500 and does not record the cause. `RequestLogger::write()` swallows every logger failure. Cutting a log string on a byte boundary can split a UTF-8 character, `JSON_THROW_ON_ERROR` then throws, and the whole line is dropped.

`Router::dispatch()` replaces the request with `withParams()`, which clones it. `AuthMiddleware` sets the user on that clone. `Application::run()` logs the original request, so `user_id` is null for authenticated calls. Activity rows are unaffected because controllers see the clone.

`Response::emit()` runs the stream after `dispatch()` has returned. A stream exception skips the application handler and the request log. `fopen` in the backup stream is not suppressed, so a warning can include an absolute path when `display_errors` is on. This app never sets `display_errors` itself.

**Fix:** force `display_errors=0` and `log_errors=1` in production. Log exceptions to a private channel with a correlation id, and return that id to the app. Pass the same request object to the logger that middleware updated. Truncate on UTF-8 boundaries. Open download files before committing the response.

### C11 — The Apache layout recommended by the README is the private-file boundary

README tells you to upload the whole repository as the document root, for example `public_html`. Root `.htaccess` forbids `.git`, `app`, `config`, `src`, `storage`, `cli`, and a few documents, then rewrites other URLs to `index.php`. Apache ignores `.htaccess` when `AllowOverride` is `None`. In that case `config/app.php`, `storage/logs`, `storage/backups`, and `storage/uploads` are static files. Logs and limiter JSON on this machine are `0644`. Directories are `0755`.

The rewrite list does not name `QA_REVIEW.md`, `CODEX_QA_REVIEW.md`, or `CURSOR_QA_REVIEW.md`. With the current rewrite (there is no "skip existing files" condition) those URLs hit the front controller and 404. The moment a host adds `RewriteCond %{REQUEST_FILENAME} !-f`, they are downloadable.

**Fix:** set the document root to `public/` and keep the parent directory outside the web tree. Confirm with real requests that `/config/app.php`, `/storage/logs/`, `/.git/HEAD`, and `/.env` are denied, without downloading their bodies into a ticket. Add every new root document to the deny list if a shared host forces the repository root.

### C12 — Play Store account rules are not implemented

Google Play requires an in-app account deletion path for apps that let users create accounts. `UserController::destroy()` returns 409 when the caller deletes their own id. There is no self-service password change, no email or phone on the user, no session list, and no export of the caller's own data. Registration still creates a permanent account.

Shipped collection that a Data safety form has to declare if these endpoints stay on: name, username, password hash, IP address, user agent, and, when email is used, recipient and subject. Server logs and activity logs have no retention limit. `storage/rate-limiter/*.json` is never deleted by the app.

**Fix:** add an authenticated delete-me flow that anonymizes or removes the account and its personal log fields, a password change that requires the current password, and a retention job for logs and limiter files. Complete the Play Data safety form from that behavior. If the app is directed at children, this backend is the wrong design; it has no age gate or parental control.

## P2 — fix before relying on the feature

### Auth, users, and sessions

**C13 — Username enumeration and login timing.** Register returns a unique-violation message when the username exists. `authenticate()` calls `password_verify` only after it finds an active user, so missing and inactive accounts return faster than a wrong password on a real account. Login's JSON message is generic, which is the right client behavior. The timing and the register response undo it.

**Fix:** always run `password_verify` against the stored hash or a fixed dummy hash. Use one registration error for rejected signups, and rely on the per-account throttle from C06.

**C14 — Usernames are unbounded in shape.** `name` and `username` are length-limited strings with no character policy. Role and permission slugs are restricted to a safe pattern. Usernames may contain spaces, homoglyphs, and control characters. That is an impersonation problem in any admin UI, and it is what gets copied into activity text.

**Fix:** restrict usernames to a documented alphabet, reject control characters in names, and normalize case if login should be case-insensitive. The database collation is `utf8mb4_unicode_ci`, which already treats some letters as equal, while PHP uniqueness checks are byte-exact. Those two checks disagree.

**C15 — `Model::create()` and `Model::all()` can return password hashes.** `create()` reloads the row with `find()`. `User` puts `password` in `$fillable`, which is required for writes. `findDetailed()` is what the current auth and user controllers return, and it unsets `password`. `all()`, `paginate()`, and `find()` do not. `AccessToken::forUser()` embeds the array it is given. A later controller that returns `create()` or `all()` will ship hashes to the device.

**Fix:** keep a single user serializer that drops `password`, `session_version` if the client does not need it, and any future secret columns. Use it at the controller edge.

**C16 — Last-admin lockout.** A super-admin can deactivate themselves or demote the only other super-admin. They cannot delete themselves. Nothing counts remaining active super-admins inside the update transaction.

**Fix:** refuse to remove the last active super-admin.

**C17 — `refresh` on a missing user becomes a 500.** `incrementSessionVersion()` updates zero rows, `findDetailed()` returns null, and `AccessToken::forUser([])` throws `InvalidArgumentException`. With debug on, that message reaches the client.

**Fix:** if the user row is gone, return 401.

### Validation and mobile response contract

**C18 — One validation error is a string, two are an array.** `Response::error()` replaces a one-element array with its only value.

**Verified:** `error(['Only one'])` encodes `message` as a JSON string. `error(['One', 'Two'])` leaves an array. The Android parser needs one shape. Auth login already depends on the collapsed string.

**Fix:** always return `message` as a string and `errors` as an array.

**C19 — Conditional rules see raw values, then the field is normalized.** For `kind` = `required|integer|in:1,2` and `approval` = `required_if:kind,1|string`, input `kind` = `01` normalizes to integer `1` and does not require `approval`.

**Verified** with `Validator::validate`. `1e0` under a `numeric` rule has the same split: the condition compares the original spelling. Current routes do not use `required_if`. The next mobile feature that does will accept the wrong payload.

**Fix:** run conditions against the normalized value of the referenced field.

**C20 — A failed parent still validates every child.** Wildcard expansion copies path arrays, and `distinct` scans prior values. An invalid list still runs `exists` or `unique` once per child. `permission_ids` is `required|array` with no max, so `syncPermissions` will query and insert an unbounded id list. Pagination `offset` is an integer with no upper bound, and MySQL `OFFSET` still walks the skipped rows.

**Fix:** enforce list size before child rules, cap `permission_ids` and `offset`, and paginate with keyset cursors for the mobile lists.

**C21 — Scalar JSON becomes an empty object.** `validate()` replaces a non-array JSON root with `[]`. `isJSON()` searches for the substring `application/json`.

**Verified:** body `true` with an optional rule validates as empty input. `text/plain; note=application/json` is treated as JSON.

**Fix:** require a JSON object for these endpoints and parse the media type, not a substring. Answer 400 or 415 for anything else.

### Logging, email, uploads, and backups

**C22 — Server log reads the entire day for one page.** `ServerLog::paginate()` calls `file()` on the daily log, reverses the array, then slices. A busy production day will exhaust the PHP memory limit on `GET /api/v1/server-logs?limit=1`. `blocked()` likewise `glob`s every limiter file before it slices the page.

**Fix:** stream backward for the newest page, or store logs in an indexed table. Cap file size and rotate daily files. Paginate blocked IPs without loading every historical record. Delete expired limiter files without deleting a live lock.

**C23 — Email send is an application mail relay.** `POST /api/v1/emails/send` accepts any recipient, a subject, and up to 100000 bytes of HTML, and it sends as `from_address`. Default `html` is true. The worker waits on SMTP for the configured timeout on every command. `encryption` may be `none`. `fwrite` results are ignored. If `DATA` is accepted and `QUIT` fails, the mailer throws, the log row is `failed`, and a client retry sends a second copy. SMTP exception text, which can include the server banner, is stored in `email_logs.error_message` and returned to anyone with `emails.view`.

**Fix:** keep this route off the mobile app. If an admin tool needs it, allowlist recipients or templates, send plain text unless a template says otherwise, mark the message accepted after the final `DATA` response, and write the full SMTP payload in a loop. Do not store raw SMTP replies in a table the API returns.

**C24 — Upload policy is broader than a mobile app should accept, and per-call MIME limits are skipped.** `FileController::upload()` uses the global list: images, pdf, text, csv, Word, Excel, and zip, up to 10 MB. The tighter option block in that controller is commented out. `validateMIMEType()` returns as soon as `mime_types_by_extension` matches, so an `allowed_mime_types` option on that call is ignored. `docx` and `xlsx` also allow `application/zip`. An empty `allowed_extensions` list means every extension is allowed. Stored files are `chmod 0644`. Directories are created `0775`. `uploadMany()` keeps earlier files when a later file fails. If `storage/uploads/files` is a symlink, `move_uploaded_file` follows it; the path check rejects `..` in the directory argument and does not re-check the real destination. There is no download route, so the app currently cannot show what it uploaded. A later route that trusts client `relative_path` needs the same realpath containment `delete()` already uses.

**Fix:** for the Play Store app, allow only the types that screen needs, re-encode images, reject zip and Office files unless an admin flow truly needs them, and enforce the narrower MIME list as an intersection. Unlink the batch on failure. Confirm the final directory realpath stays inside the upload root. Serve files through an authorized controller, not through the web root.

**C25 — Backup restore gaps beyond C04.** Generated columns would be inserted as normal values. This schema has none. The exporter does not include views, triggers, routines, or events. README and `PROJECT.md` should stay aligned with that. `cli/migrate.php rollback` runs `down()`, which drops every application table.

**Fix:** omit generated columns, document the missing object types, and make production rollback an explicit flag rather than the same command developers use locally.

### Routing, responses, and middleware footguns

**C26 — `permission` with no names allows any signed-in user.** `Router` splits on the colon and passes an empty parameter list. `PermissionMiddleware` then loops zero times and returns null. `super-admin` also bypasses a non-empty list, which is intentional and easy to forget.

**Verified:** a `user` with no permissions was allowed through `PermissionMiddleware` when no permission names were passed. Current routes in `routes/api.php` all pass concrete names. A new route written as `permission` or `permission:` would not.

**Fix:** reject an empty permission list at route registration.

**C27 — Response mode changes leave the previous body channel in place.** `json()`, `text()`, `html()`, `send()`, and `noContent()` do not clear `streamCallback`. `view()` does. `cookie()` uses `appendHeader`, which joins values with commas, so two `Set-Cookie` headers become one illegal field.

**Verified:** `stream(...)->error('Denied', 401)` still had a stream callback. Two `cookie()` calls produced a single `Set-Cookie` value containing both pairs.

Today's mobile auth is a bearer token, so cookies are unused. The stream bug matters for the backup response if a later middleware replaces it with `error()`.

**Fix:** clear stream state in one place whenever the body becomes a normal payload, and send each cookie with `header(..., false)`.

**C28 — Path parameters use `urldecode`.** `/files/a+b` becomes `a b`.

**Verified.** Current ids are integers, so this does not cross an authorization check today. It will surprise any future file or slug route.

**Fix:** use `rawurldecode` and reject decoded slashes and control characters.

### Time, database, and process model

**C29 — Three clocks are in use.** Config timezone is `Asia/Karachi`. `RequestLogger` and backups use it. `HealthController` uses `new DateTimeImmutable()` with the PHP default timezone. MySQL `created_at` uses the database session timezone, and the PDO connection never sets `time_zone`. Activity and email date filters construct `DateTimeImmutable` from `Y-m-d` without the application zone. Around midnight, the mobile app can query a day that does not match the rows it just created.

**Fix:** store UTC in MySQL, set the PDO session zone to `+00:00`, and convert only at the edge.

**C30 — Database connections have no timeout or TLS setting.** The DSN is built from config strings. A stalled MySQL holds the PHP worker. A remote MySQL without TLS exposes queries and credentials on the network. `ATTR_EMULATE_PREPARES` is false, which is the right default for real prepared statements.

**Fix:** set a short connect timeout, require TLS for any non-local database, and use a database account that cannot `DROP`, `FILE`, or `SUPER`.

**C31 — Static service configuration assumes one-shot PHP.** `JWT`, `Password`, `Database`, `FileUploader`, `SMTPMailer`, `RequestLogger`, and `Debugger` keep process-wide static state. `Database::configure()` clears connections on each new `Application`, which matches PHP-FPM. A long-lived worker that reused those statics across requests would mix config and could keep a previous `Debugger` request.

**Fix:** stay on PHP-FPM for production, or reset that state at the start and end of every request before moving to another runtime.

**C32 — Migration runners can apply the same file twice.** `Migrator::migrate()` reads the tracking table, runs `up()`, then inserts the name. Two overlapping CLI processes can both run `up()` before either insert wins the unique key. The current init migration is mostly `IF NOT EXISTS` and `INSERT IGNORE`. A later data migration may not be.

**Fix:** take a MySQL advisory lock around discovery and execution.

### Performance under mobile traffic

These are the limits that will show up once the app has real users, even after the security fixes.

- **120 requests per minute per IP** is tight for a screen that fans out several calls, and it is shared by every customer on the same carrier address. A 5-minute pause after the window, then a 30-minute block, will look like a random outage in the Play Store app.
- **Bcrypt cost 12** is appropriate for passwords and will dominate login CPU. Size the number of PHP workers for that cost, and keep the per-account throttle so cost 12 cannot be used as a CPU flood.
- **User and role lists are N+1.** `paginateDetailed()` runs one permissions query per user. `RoleController::index()` calls `details()` per role, which loads every permission and a user count. A page of 20 is dozens of queries.
- **`OFFSET` pagination** gets slower as the mobile client scrolls. Prefer `id < last_seen`.
- **Synchronous mail and backups** occupy a worker for the whole operation. `fastcgi_finish_request` runs only after the response is fully emitted, so it does not hide that wait.
- **Daily log and limiter scans** grow without a cleanup job. Disk and inode exhaustion then feed C06.
- **JSON bodies are decoded twice** and held with the raw string until the request ends.
- **No idempotency key.** A mobile retry after a timeout creates a second email, a second backup, or a second activity row. Unique usernames already protect register.

## P3 — smaller defects

**C33 — `MakeCommand` accepts PHP reserved words.** `className()` checks the character pattern and does not reject `Class`, `List`, or a generated `Model` that collides with the imported base class. CLI only.

**C34 — Encoded-word subjects are not folded.** `SMTPMailer::encodeHeader()` puts the whole subject in one encoded word. RFC 2047 limits each encoded word to 75 characters. CR and LF are stripped before encoding, which blocks the usual header-injection case.

**C35 — `base_path` in config is unused.** `Request::capture()` derives the base path from `SCRIPT_NAME`. The config value cannot move the API mount.

**C36 — Health responses use a hand-built JSON body** in the database failure path, while success uses `Response::success()`. The shapes match today. The server health route is the only rate-limit exemption, so it is the unmetered public URL.

**C37 — `user_agent` is `VARCHAR(500)` and is not truncated in PHP.** A longer agent fails the activity insert after the business write.

**C38 — The example view escapes output and is still a public HTML route** with no Content-Security-Policy. `Response` sets `X-Content-Type-Options`, `X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`, and `Cache-Control: no-store`. CSP is absent. That is minor while responses stay JSON.

**C39 — Single-element versus list messages, missing `X-Request-ID` echo, and no cursor fields** will cost Android time. The logger creates a request id and does not return it. Pagination exposes `limit`, `offset`, and `next_offset` only.

## Play Store release checklist

1. Rotate the JWT secret, set `env` to `production`, set `debug` to `false`, and use a database user with a password and no admin rights.
2. Close public super-admin registration. Create the first admin from the server.
3. Remove `GET /api/v1/example`, lock or remove `GET /api/v1/health/database`, and remove `GET /api/v1/database-backups/download` from the mobile host.
4. Freeze system roles and split admin permissions so a normal staff token cannot export the database or promote itself.
5. Replace the 24-hour self-refreshing bearer token with a short access token, rotating refresh tokens, and per-device sessions. Store them in the Android Keystore. Disable cleartext in the app network security config.
6. Put HTTPS at the edge, set `trusted_proxies` to the real proxy, and send HSTS.
7. Cap body size, cap login password length, and make login rate limits per account as well as per IP.
8. Ship account deletion and password change. Declare collected data in Play Console.
9. Point the document root at `public/` and prove that `config/`, `storage/`, and `.git/` are not fetchable.
10. Add regression checks for C01–C10, C18, C19, and C26. This repository has no test suite. The fixtures for this review were temporary and were not committed.

## Controls that held

- JWT decode rejects a bad signature, the wrong algorithm, a missing `typ`, expired `exp`, future `iat` or `nbf`, a bad issuer, and a bad audience. Claims the encoder writes after the caller claims cannot override `iss`, `aud`, `iat`, `nbf`, or `exp`.
- Private routes in `routes/api.php` send `auth` before a named permission. The `user` role is created with zero grants.
- Dynamic SQL values in models and the validator's `exists` and `unique` rules are bound parameters. Table and column names used by those rules must match a safe identifier pattern. Backup identifiers use the same style of allowlist.
- Upload handling requires `is_uploaded_file`, rejects path segments outside `[A-Za-z0-9_-]`, generates a random stored name, and checks extension plus detected MIME for the global map.
- View rendering stays inside `app/Views`, refuses reserved extract keys, and the example template calls `$escape()`.
- CLI scripts exit when `PHP_SAPI` is not `cli`, before they load config.
- Production mode, when actually selected, rejects debug, an empty database password, wildcard CORS, and a short or placeholder JWT secret.
- PDO constraint `1062`, `1451`, and `1452` become 409 or 422 instead of a SQL dump.
- Login's client-facing failure text does not say whether the username exists. C13 is the remaining timing and register gap.
- `User::findDetailed()` and `withPermissions()` remove `password` before the current auth and user responses.

## Verification

Completed:

- Read every maintained source, config, route, migration, CLI script, Apache file, view, and project document listed below.
- `php -l` on all 58 PHP files: no syntax errors.
- Executed local fixtures for conditional validation, scalar JSON, content-type matching, forwarded IP selection, query-string logging, stream state, cookie joining, empty permission middleware, `+` in routes, corrupt rate-limit records, query-to-body merging, and the one-error versus many-error JSON shape. All of those fixtures matched the findings above.
- Confirmed `config/app.php` is tracked and that `.env` is not tracked. `.env` was not opened.
- Counted the existing request log without printing lines: 18 records, 4 query URLs, no `password` / `token` / `secret` query keys.

Not done, and still required on a staging host before release: MySQL restore of a backup, SMTP against a local sink, Apache `AllowOverride` and TLS termination, multipart upload through the real SAPI, PHP 8.2, and a concurrent mobile-style load with more than one client behind one address.

## Suggested fix order

1. C01, C11, and the production switches. Rotate the signing key everywhere this checkout was copied.
2. C02, C03, C04, and C12. Close registration takeover, freeze roles, and take backup and account deletion off the public mobile surface.
3. C05, C06, C07, and C08. Sessions, limiter failure behavior, body size, and real client IPs.
4. C09, C10, C18, and C22. Logs, error shape, and the Android contract.
5. C13–C17 and C23–C32 before the matching feature is used in production.
6. Add a small repeatable check for each P1 so the next change cannot reopen it.

## File-by-file coverage

Reviewed means the file was read and judged against this production use. It does not mean a live database or HTTP call was made for that file.

| File | Result |
|---|---|
| `.gitignore` | Ignores `.env`, logs, limiter JSON, uploads, and backups. Does not ignore `config/app.php`, which is why C01 is in Git. |
| `.htaccess` | Root rewrite and private-path deny. Deployment boundary C11. |
| `AGENTS.md` | Contributor rules. No runtime behavior. |
| `LICENSE` | MIT. No runtime behavior. |
| `PROJECT.md` | File map. Overstates built-in role protection: code locks `super-admin` and `user` only, not `admin`. |
| `README.md` | Install and the document-root instruction behind C11. Registration warning matches C02 and does not make it atomic. |
| `QA_REVIEW.md` | Previous review. Renamed to `CODEX_QA_REVIEW.md` when this file was added. Endpoint business logic was out of its scope. |
| `app/Controllers/ActivityLogController.php` | Date and page validation are sound. `store` writes arbitrary audit text (see activity finding under C03's audit note and the endpoint table). |
| `app/Controllers/AuthController.php` | C02, C05, C13. `me` returns the detailed user. `refresh` can 500 (C17). |
| `app/Controllers/DatabaseBackupController.php` | C04. Generic 500 on failure. Stream unlink is in a `finally` that still runs after a failed open. |
| `app/Controllers/EmailController.php` | List and show are permissioned queries. `send` is C23. Client error is generic; the database stores the mailer message. |
| `app/Controllers/ExampleController.php` | Public HTML. Remove for production (C12 checklist and C38). |
| `app/Controllers/FileController.php` | Auth and `files.upload`. Uses global upload policy (C24). Returns `relative_path` and does not expose a local filesystem path. |
| `app/Controllers/HealthController.php` | Public server and database probes (endpoint table, C36). Database errors do not return the driver message. |
| `app/Controllers/PermissionController.php` | CRUD is permissioned. Destroy has no system-row guard (C03). Uniqueness is check-then-write, with the unique index as the backstop. |
| `app/Controllers/RateLimitController.php` | Permissioned. Loads a second limiter from a fresh config include. `blocked()` is unbounded before the page slice (C22). |
| `app/Controllers/RoleController.php` | System slug lock is partial (C03). `syncPermissions` has no list cap (C20). Index is N+1. |
| `app/Controllers/ServerLogController.php` | Permissioned date filter. Memory cost is C22. |
| `app/Controllers/UserController.php` | Self-delete blocked (C12). Role assignment and password update are C03 and C05. Username check is not the only guard; the unique index still returns 409. |
| `app/Middlewares/AuthMiddleware.php` | Signature, active flag, and session version are all required. Sets the user on the cloned request (C10). |
| `app/Middlewares/PermissionMiddleware.php` | AND semantics for named permissions. Empty list fails open (C26). `super-admin` bypass is total. |
| `app/Middlewares/RoleMiddleware.php` | Denies missing users and unknown roles. No current route uses the `role` alias. |
| `app/Models/ActivityLog.php` | Bound date range and pagination. `record` stores IP and user agent with no length clamp (C08, C37). |
| `app/Models/EmailLog.php` | Same date-query pattern as activity logs. No body column, which avoids storing email content. Error text is still sensitive (C23). |
| `app/Models/Permission.php` | Bound lookups. `roles()` without a limit returns every related role. |
| `app/Models/Role.php` | Permission sync is transactional when this connection is not already in one. `INSERT IGNORE` on attach hides duplicates. |
| `app/Models/ServerLog.php` | Full-file read (C22). Path is the logger directory plus a date that the controller already validated as `Y-m-d`. |
| `app/Models/User.php` | C02, C05, C13, N+1 permissions. `authenticate` rehash is unconditional. |
| `app/Views/example.php` | Escaped title, message, and list values. |
| `cli/make` | CLI guard, then generator. |
| `cli/migrate.php` | CLI guard. Rollback drops tables (C25). |
| `cli/rate-limit` | CLI guard and the same limiter as the API. |
| `config/app.php` | C01, C06, C08, upload allowlist, CORS localhost, 24-hour TTL. |
| `index.php` | Forwards to `public/index.php`. |
| `migrations/2026_08_25_000001_init_project.php` | InnoDB, unique usernames, FK order on drop is safe, seed grants every permission to `admin` and `super-admin`, none to `user`. |
| `public/.htaccess` | Front-controller rewrite when `public/` is the document root. This is the layout C11 asks for. |
| `public/index.php` | Bootstraps, registers routes, and hides startup exceptions without logging them (C10). |
| `routes/api.php` | Route inventory in the table above. Current private routes name real permissions. |
| `src/Auth/AccessToken.php` | Requires id, username, and session version. Returns the user array unchanged (C15). |
| `src/Auth/JWT.php` | HS256 only, `hash_equals`, required claims, leeway. No server-side `jti` store, which is why logout depends entirely on `session_version` (C05). |
| `src/Auth/JWTException.php` | Exception type only. |
| `src/Auth/Password.php` | Bcrypt with a configured cost. No 72-byte guard inside `hash` or `verify`; callers must cap input (C07). |
| `src/Core/Application.php` | Dispatch, CORS, limiter, security headers, debug messages (C01, C08, C10). Logs the pre-middleware request. |
| `src/Core/Debugger.php` | Debug gate follows config. Dumps include the raw URL (same class of leak as C09) when a `dd()` runs with debug on. |
| `src/Core/MakeCommand.php` | C33. Refuses to overwrite an existing file. |
| `src/Database/Database.php` | Lazy PDO, exceptions, real prepares (C30, C31). |
| `src/Database/DatabaseBackup.php` | C04 and C25. |
| `src/Database/Migrations/Migration.php` | `up` / `down` contract. |
| `src/Database/Migrations/Migrator.php` | C32. Tracking insert is not locked to `up()`. |
| `src/Database/Model.php` | Fillable filter, bound values, identifier allowlist. Unbounded `all()` (C15, performance). |
| `src/Http/CORS.php` | Exact origin match, credentials off, no wildcard in the shipped config. This is not an auth control for the Android app. |
| `src/Http/HttpException.php` | Status-bearing exception. |
| `src/Http/Pagination.php` | Offset metadata only (C39). |
| `src/Http/Request.php` | C07, C08, C09, C21. Bearer parse is limited to the Authorization header. |
| `src/Http/Response.php` | C27. Attachment names strip CR, LF, and quotes. |
| `src/Logging/RequestLogger.php` | C09 and C10. Sensitive-key list is exact and short. |
| `src/Mail/MailException.php` | Exception type only. |
| `src/Mail/SMTPMailer.php` | C23 and C34. TLS enablement uses PHP stream crypto; this review found no `verify_peer` disable flag. |
| `src/RateLimit/RateLimitDecision.php` | Immutable decision fields. |
| `src/RateLimit/RateLimiter.php` | C06 and C22. Locking on a healthy file is present; failure handling is the defect. |
| `src/Routing/Route.php` | C28. Parameters cannot contain a raw slash. |
| `src/Routing/Router.php` | Clones the request (C10). Empty middleware parameters (C26). Linear route scan is fine at this route count. |
| `src/Storage/FileUploadException.php` | Exception type only. |
| `src/Storage/FileUploader.php` | C24. `delete()` realpath check is the pattern downloads should reuse. |
| `src/Validation/ValidationException.php` | Carries the error list. The public exception message is generic. |
| `src/Validation/Validator.php` | C19, C20, C21. Unknown keys are rejected, which is the right mobile contract once the query merge in C09 is removed. |
| `src/View/View.php` | Template path containment and reserved variables held on inspection. Templates are executable PHP and must stay developer-authored. |
| `src/bootstrap.php` | Namespace autoload and `APP_STARTED_AT`. Class names are not taken from the request. |
| `src/helpers.php` | `dd()` goes through `Debugger`, which is off when `debug` is off. |
| `storage/backups/.gitignore` | Ignores backup artifacts. |
| `storage/uploads/.gitignore` | Ignores uploaded files. |
