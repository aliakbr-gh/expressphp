# Findings

Review of the whole project except `app/` and `routes/`. Each finding was checked against the
code, and where possible reproduced. **Nothing here has been fixed yet.** This file only lists the findings.

Reviewed: `core/`, `public/`, `config/`, `database/`, `storage/`, `tests/`, `cli`, every `.htaccess`,
`.gitignore`, `.gitattributes`, `README.md`.

| Severity | Count |
|---|---|
| High | 2 |
| Medium | 5 |
| Low | 8 |
| Info | 5 |

What still holds up from earlier reviews (checked again, no problems found):
- prepared statements everywhere, and safe identifiers in validation rules;
- JWT: algorithm pinned to HS256, constant-time signature check, expiry enforced;
- passwords never returned in responses;
- control characters escaped in logs, and database values masked;
- upload extensions taken from the detected content type;
- the `.htaccess` allowlist;
- `X-Forwarded-For` trusted only from configured proxies;
- the 413 / 400 body limits;
- the PHP < 8.4 form parser, after today's fixes.

---

## High

### H1. Secrets and the database login are committed to git
**File:** `config/config.php:37`, `:46` (tracked: `git ls-files config/config.php`)

`jwt.secret`, the database username and the password (`root` / `root`) are in the repository.

**Impact:** anyone who can read the repository (a teammate, a leaked backup, a public fork) can sign
valid tokens for **any user id**. That is a full authentication bypass on every server that uses this secret.

**Recommendation:**
- Generate a new secret on every server.
- Keep real secrets out of git (see H2).

### H2. Config can't differ per server without editing a tracked file
**File:** `config/config.php`

Everything comes from one tracked PHP file: no environment variables, no local override file. The
defaults that ship are development values:
- `debug => true` (line 21);
- `env => development` (line 20);
- `port => 3305` (line 34, MAMP's MySQL port; the usual port is 3306);
- `app.url => http://localhost/...`.

**Impact:**
- Deploying "without changing files" means deploying with debug on. Every 500 then returns the
  exception message, absolute server paths and a full stack trace (see L7), and PHP warnings are
  printed into responses (`display_errors = 1`).
- The only other option is editing a tracked file on each server, where it gets overwritten by the
  next deploy or committed by mistake.

**Recommendation:** read secrets and per-server values from environment variables
(`getenv('DB_PASSWORD') ?: ...`), or from an untracked `config/config.local.php` merged over the
defaults (add it to `.gitignore`). Make `debug` default to `false`.

---

## Medium

### M1. Login brute force is only limited by the global per-IP limit
**File:** `core/RateLimiter.php:39-75`, `public/index.php:28`

The only throttle is 60 requests per minute per IP, shared by every route. `RateLimiter::hit()`
always uses the global `rate_limit.max_requests`, so there's no stricter per-route or
per-username limit.

**Impact:**
- One IP can try about 86,000 passwords a day against one account.
- A botnet spreading the attempts across IPs isn't limited at all per account.

**Recommendation:** let `hit()` take its own `$max` and `$window` (e.g.
`RateLimiter::hit('login:' . strtolower($username), 5, 300)`), and call it from the login route.

### M2. Behind Cloudflare or a load balancer, every user shares one rate limit
**File:** `config/config.php:28` (`trusted_proxies => []`), `core/Request.php` `ip()`

With no trusted proxies, `ip()` returns the proxy's address. All visitors coming through one
Cloudflare edge or load balancer then count as **one IP**.

**Impact:** the API starts answering 429 to everyone once the combined traffic passes 60 requests a
minute. This is common on shared hosting with Cloudflare in front, and on VPSs behind a load balancer.

**Recommendation:**
- Document clearly in the deployment checklist that `trusted_proxies` must list the proxy ranges
  (Cloudflare publishes its own).
- Optionally log a warning when requests arrive with `X-Forwarded-For` from an untrusted address.

### M3. `dd()` works even when `debug` is off
**File:** `core/helpers.php:33`

`dd()` dumps everything regardless of `app.debug`: the request's private `server` array
(`$_SERVER`), headers including `Authorization`, bodies including passwords, and any object passed to it.

**Impact:** one forgotten `dd()` in a deployed controller publishes tokens, passwords and server
environment variables (which on many hosts include database credentials) to anyone who calls that route.

**Recommendation:** when `app.debug` is false, have `dd()` log a warning with its file and line and
answer a plain 500 instead of dumping.

### M4. `migrate:fresh` drops every table in the database, not just this project's
**File:** `core/Migrator.php:102-119`

`fresh()` runs `SHOW FULL TABLES` and drops all of them. The only guard is `--force` when
`app.env = production`, and `env` defaults to `development` (H2).

**Impact:** on shared hosting one database is often shared with other apps (WordPress, another API).
`php cli migrate:fresh` silently destroys their tables too.

**Recommendation:**
- Ask for an interactive confirmation that shows the database name and table count.
- Optionally drop only the tables created by this project's migrations.

### M5. On a VPS, Apache's default `AllowOverride None` silently disables every `.htaccess`
**File:** `.htaccess`, all folder `.htaccess` files, `README.md`

Ubuntu/Debian Apache ships with `AllowOverride None` for `/var/www`. All the protection then
disappears without any error:
- `storage/logs/*.log` and `storage/uploads/*` become downloadable;
- `README.md`, `cli` and the migrations become readable;
- `config/config.php` still runs as PHP, so it outputs nothing.

nginx and IIS ignore `.htaccess` the same way, as the README already says.

**Impact:** request logs (paths, client IPs) and every uploaded file are public.

**Recommendation:**
- Say plainly in the README: *on a VPS, always set the document root to `public/`*.
- Optionally have `public/index.php` refuse to start (or log an error) when it detects it's served
  from the project root without the rewrite in place.

---

## Low

### L1. The `mimes` rule never accepts `jpeg`
**File:** `core/Validator.php:218`

`mimes` compares against `extension()`, which maps `image/jpeg` to `jpg` only.
Reproduced: a real JPEG fails `mimes:jpeg` and passes `mimes:jpg`.

**Impact:** Laravel-style rules like `mimes:jpeg,png` reject every JPEG. Developers may then
loosen the rule (e.g. drop it) to make uploads work.

**Recommendation:** treat `jpeg`/`jpg` (and `tif`/`tiff`, `htm`/`html`, …) as aliases in `mimes`.

### L2. `e()` crashes on arrays
**File:** `core/helpers.php:14-17`

`(string) $value` on an array raises "Array to string conversion". The error handler turns that into
a 500 (reproduced).

**Impact:** a view that prints a field which can be an array (for example from `?name[]=x`) crashes
instead of showing something harmless.

**Recommendation:** return `''` (or a JSON string) for non-scalars, and keep `null` as `''`.

### L3. Class and view names work on macOS/Windows but not on Linux
**File:** `core/bootstrap.php:25`, `core/View.php:66`

macOS and Windows file systems are case-insensitive by default; Linux isn't.
Reproduced on this Mac: `View::exists('Example')` and
`class_exists('App\Controllers\examplecontroller')` both return `true`.

**Impact:** a typo in letter case works locally and becomes a 500 ("View not found" /
"Class not found") only after deploying to a Linux server.

**Recommendation:** after `is_file()`, compare the real file name's case. For example, check
`basename($file)` against the entries returned by `scandir(dirname($file))`, and fail with a clear
message when they don't match.

### L4. Cookies aren't marked `Secure` behind an HTTPS proxy
**File:** `core/Response.php:184`

`cookie()` decides `secure` from `$_SERVER['HTTPS']` only. It ignores `X-Forwarded-Proto` from
trusted proxies, which `Request::isSecure()` already handles.

**Impact:** behind Cloudflare or a load balancer that ends HTTPS, cookies are sent without `Secure`
and can travel over plain HTTP. The API itself uses Bearer tokens, so this only matters once an app
sets cookies.

**Recommendation:** use the same logic as `Request::isSecure()` (trusted proxies) for the default.

### L5. Tokens can't be revoked
**File:** `core/JWT.php`

Tokens are stateless and valid until `exp` (24 hours by default). There's no token version or
deny-list.

**Impact:** a stolen token keeps working after a logout or password change, until it expires.

**Recommendation:**
- Add a `token_version` (or `password_changed_at`) column to the users table.
- Put it in the token, and have `AuthMiddleware` compare it on every request.

### L6. Logs grow forever
**File:** `core/Log.php:121`

Every request writes one line. Nothing rotates or deletes old files (the README says so).

**Impact:** on small shared-hosting quotas, sustained traffic (or a flood spread over many IPs) fills
the disk. After that, uploads and rate-limit counters fail too.

**Recommendation:** delete `app-*.log` older than N days (configurable), the same occasional way
`RateLimiter` cleans up its counters.

### L7. With `debug` on, error traces include function arguments
**File:** `public/index.php:61-66`

`getTraceAsString()` includes argument values (strings cut to 15 characters) whenever
`zend.exception_ignore_args = Off`. That's the case on this machine for both PHP 8.2 and 8.4, and
it's the default in development `php.ini` files.

**Impact:** together with H2 (debug on by default), a 500 can expose the start of passwords, tokens
or emails passed to functions further up the stack.

**Recommendation:** build the debug trace from `getTrace()` with only file, line and function (no
`args`). Optionally set `ini_set('zend.exception_ignore_args', '1')` when debug is off.

### L8. `$request->url()` trusts the client's `Host` header
**File:** `core/Request.php:767-777`

`host()` and `url()` use the `Host` header as sent.

**Impact:** if an app builds links from `url()` (password-reset emails, redirects), an attacker who
sends a forged `Host` can point those links at their own domain.

**Recommendation:** document `app.url` as the only source for absolute links, or check `Host`
against a configured list of allowed hosts.

---

## Info

### I1. The `date` rule accepts relative words
**File:** `core/Validator.php:198`

`strtotime()` accepts `"tomorrow"`, `"+1 week"`, `"next monday"` (reproduced). This matches Laravel's
`date` rule, but often surprises people. Add a `date_format:Y-m-d` rule if exact dates are needed.

### I2. No CORS support
**File:** `core/Router.php`

There are no `Access-Control-*` headers, and `OPTIONS` preflights get 405. A browser front end on
another origin (e.g. a separate SPA domain) can't call the API. That's safe by default, but needs a
CORS middleware once a web front end exists.

### I3. Fixed-window rate limit allows short bursts
**File:** `core/RateLimiter.php`

A client can send `max_requests` at the end of one window and again at the start of the next: up to
2× the limit within a few seconds. Fine for a global limit; consider a sliding window if M1 adds
tight login limits.

### I4. Rate limiting turns itself off when `storage/ratelimit/` isn't writable
**File:** `core/RateLimiter.php:156-163`

This is by design and documented: the API stays up rather than blocking everyone. But nothing is
logged, so on a host with wrong permissions the limit is silently off. Log it once per day.

### I5. `Options -Indexes` needs `AllowOverride Options`
**File:** `.htaccess:10`, `public/.htaccess:7`

Hosts that allow `.htaccess` but not `Options` answer **500 for the whole site**. That's rare
(Laravel ships the same line), but it's the only `.htaccess` directive that can stop the app from
starting. If a host shows a 500 on every URL, removing this line is the first thing to try.

---

## Checked and not a problem

Suspected during the review, then checked and ruled out:

| Suspicion | Result |
|---|---|
| `migrate:refresh` (`rollback(PHP_INT_MAX)`) overflows the unsigned `batch` column | Ran against MySQL: works, returns all rows. |
| Multipart fallback regexes fail on large files when PCRE JIT is off | Tested 5–6 MB parts with `pcre.jit=0` and `=1`: no failure. |
| Debug traces leak the database password from `PDO::__construct` | The connection error is re-thrown from `Database::connect()`, so its trace doesn't include the PDO call or its arguments. |
| Tests modify real data | They use `zz_`-prefixed users that are deleted afterwards, and their own `_test_migrations` table. |
| `REDIRECT_*_HTTP_AUTHORIZATION` can be injected by a client | Client headers always arrive as `HTTP_*`; a header named `Redirect-Http-Authorization` becomes `HTTP_REDIRECT_HTTP_AUTHORIZATION`, which doesn't match. |
