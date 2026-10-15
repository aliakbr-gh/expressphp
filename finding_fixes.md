# Finding fixes

Fixes for the findings in `finding.md`: **M3, L1, L2, L3, L4 and L5** (L5 includes a new logout endpoint).
The other findings are still open.

All 71 tests pass on PHP 8.1, 8.2, 8.3 and 8.4. They were run on a copy of the project with the two
`dd()` lines in `AuthController::me()` removed, because `dd()` stops the test runner. Those lines are
your own debugging code and are left as they are in the project. Logout was also tested through
Apache: token valid → logout → the same token gets `401`.

---

## What `app.debug` is for

`app.debug` in `config/config.php` is the development switch. It changes four things:

| | `debug => true` (development) | `debug => false` (production) |
|---|---|---|
| **500 responses** (`public/index.php`) | exception message, file, line and full stack trace in the JSON | only `"Internal server error."` |
| **PHP warnings / errors** (`core/bootstrap.php`, `display_errors`) | printed into the response | never printed |
| **`dd()`** (`core/helpers.php`) | dumps the values (new: see M3) | dumps nothing, logs a warning, answers 500 (new) |
| **Logging** | unchanged: errors always go to `storage/logs/` | unchanged |

It does **not** turn logging on or off (that's `logging.enabled`), and it doesn't change routes,
validation or authentication. Keep it `true` locally and `false` on every public server.

---

## M3. `dd()` only works when `app.debug` is true

| | |
|---|---|
| **Files** | `core/helpers.php`, `README.md`, `config/config.php` (comment) |
| **Problem** | `dd()` dumped everything (`$_SERVER`, `Authorization` headers, passwords in request bodies) even with `debug => false`. One forgotten `dd()` on a live server leaked secrets to anyone calling that route. |
| **Fix** | With `app.debug` false, `dd()` dumps nothing. It writes a warning with its `file:line` to the log (`dd() called at app/Controllers/AuthController.php:52 while app.debug is false; nothing was dumped`). Over HTTP it discards any pending output and answers the normal `500 {"success":false,"message":"Internal server error."}`. In the terminal it prints a one-line notice to stderr and exits with status 1. With debug on, nothing changes. |
| **Purpose** | A forgotten debug call can't leak data in production, and the log still tells you where it is. |
| **Test** | `dd() dumps only when app.debug is true` (runs `dd('SECRET_VALUE')` in a child process with debug on and off) |

## L1. `mimes:jpeg` now accepts JPEG files

| | |
|---|---|
| **Files** | `core/Validator.php`, `README.md` |
| **Problem** | `mimes` compared the rule against `UploadedFile::extension()`, which names every JPEG `jpg`. `mimes:jpeg` (the usual Laravel spelling) rejected every real JPEG. Upper-case rules (`mimes:PNG`) never matched either. |
| **Fix** | New `extensionAliases()` normalizes the rule's list before comparing: lower case, surrounding dots/spaces removed, and `jpeg` / `jpe` / `jfif` → `jpg`, `mpga` → `mp3`, `m4v` → `mp4`. The check is still based on the type **detected from the contents**, never on the client's file name. |
| **Purpose** | Rules work as written, so nobody has to loosen or delete them to make uploads work. |
| **Test** | `Validator mimes accepts jpeg / jpg / upper case alike` |

## L2. `e()` no longer crashes on arrays

| | |
|---|---|
| **File** | `core/helpers.php` |
| **Problem** | `e()` did `(string) $value`. An array (e.g. from `?name[]=x`) raised "Array to string conversion", which the error handler turns into a 500, so the whole page failed. |
| **Fix** | Scalars and `Stringable` objects are escaped as before. `null`, `false`, arrays and other objects print `''`. |
| **Purpose** | Unexpected input types in a view can't crash the page, and output is still always escaped. |
| **Test** | `e() escapes scalars and prints nothing for arrays / null` |

## L3. Class and view names must match the file's letter case on every OS

| | |
|---|---|
| **Files** | new `core/Path.php`, `core/bootstrap.php` (autoloader), `core/View.php`, `README.md` |
| **Problem** | macOS and Windows file systems ignore letter case, Linux doesn't. On this Mac `View::exists('Example')` and `class_exists('App\Controllers\examplecontroller')` were both `true`, so a case typo worked locally and became a 500 only on the Linux server. |
| **Fix** | `Core\Path::isFileExact($base, $relative)` checks that the file exists **and** that every folder and file name in the path has exactly that case. It compares against `scandir()`, cached once per folder per request. The autoloader and `View` now use it. A wrong-case class isn't loaded, exactly as on Linux, and a warning is logged naming the class. A wrong-case view fails with `View not found: Example (view names are case-sensitive)`. `Path.php` is loaded by `bootstrap.php` before the autoloader, because the autoloader uses it. |
| **Purpose** | The same code behaves the same on macOS, Windows and Linux: case mistakes show up while developing, not after deploying. |
| **Test** | `Class and view names must match the file case on every OS` |

## L4. Cookies are `Secure` behind an HTTPS proxy

| | |
|---|---|
| **File** | `core/Response.php` (`cookie()`) |
| **Problem** | The default for `secure` only checked `$_SERVER['HTTPS']`. Behind Cloudflare or a load balancer that ends HTTPS, PHP sees plain HTTP, so cookies were sent without `Secure` and could travel over HTTP. |
| **Fix** | The default now uses `Request::fromGlobals()->isSecure()`. That is the same rule the request already uses: direct HTTPS, port 443, or `X-Forwarded-Proto: https` **from a proxy listed in `app.trusted_proxies`**. The header from an untrusted client is still ignored, so it can't be used to fake HTTPS. An explicit `$secure` argument still wins. |
| **Purpose** | Cookies are protected on hosts behind a proxy, without editing code per server. |
| **Test** | `Cookies are Secure behind a trusted HTTPS proxy` |

## L5. Token versioning and logout

| | |
|---|---|
| **Files** | new `database/migrations/2026_10_15_000001_add_token_version_to_users_table.php`, `app/Models/User.php`, `app/Middlewares/AuthMiddleware.php`, `app/Controllers/AuthController.php`, `routes/api.php`, `README.md` |
| **Problem** | Tokens were stateless and stayed valid for 24 hours. A stolen token kept working after a logout or password change, and there was no logout at all. |
| **Fix** | See the list below this table. |
| **Purpose** | Logging out actually ends access, and a leaked token can be killed immediately instead of after 24 hours. |
| **Test** | `Auth register -> login -> me flow` (extended): logout returns 200; the login token **and** the older register token then get 401; a second logout gets 401; a new login works; a signed token without `ver` gets 401; `me` doesn't expose `token_version`. `Users table exists with expected columns` includes `token_version`. |

How the L5 fix works:

1. **New column** `users.token_version` (`INT UNSIGNED`, default `0`), added by a migration. It has already been applied to your local database with `php cli migrate`.
2. **Every token carries the version:** `JWT::encode(['sub' => id, 'ver' => token_version])` on register and login.
3. **`AuthMiddleware` compares it:** the token's `ver` must be an integer equal to the user's current `token_version`, otherwise `401 Invalid or expired token`. `token_version` is removed from the user before `$request->user()`, so API responses don't change.
4. **New route `POST /api/v1/auth/logout`** (token required). It runs `User::revokeTokens($id)`, which increments `token_version`. **Every** token issued to that user so far stops working on every device; logging in again gives a new valid token.
5. **`User::revokeTokens()`** can be reused, e.g. after a password change or an admin "log out everywhere".

Two side effects:

- **Logout is per user, not per device.** Versioning can't revoke one token while keeping another; that would need a list of revoked tokens.
- **Tokens issued before this update have no `ver` and are rejected,** so every user has to log in once after you deploy it.

---

## Files changed

| File | Change |
|---|---|
| `core/Path.php` | **new** (L3) |
| `core/bootstrap.php` | loads `Path.php`; case-exact autoloader (L3) |
| `core/View.php` | case-exact view lookup (L3) |
| `core/helpers.php` | `dd()` respects `app.debug` (M3); safe `e()` (L2) |
| `core/Validator.php` | `mimes` aliases and case-insensitivity (L1) |
| `core/Response.php` | `Secure` cookie default behind trusted proxies (L4) |
| `database/migrations/2026_10_15_000001_add_token_version_to_users_table.php` | **new** (L5) |
| `app/Models/User.php` | `findByUsernameWithPassword()` also returns `token_version`; new `findByIdWithTokenVersion()`, `revokeTokens()` (L5) |
| `app/Middlewares/AuthMiddleware.php` | checks the `ver` claim (L5) |
| `app/Controllers/AuthController.php` | `ver` claim in tokens; new `logout()` (L5). Your `dd()` lines in `me()` are untouched. |
| `routes/api.php` | `POST /api/v1/auth/logout` (L5) |
| `config/config.php` | comment on `debug` only |
| `tests/tests.php` | 5 new tests, 2 extended |
| `README.md` | `app.debug`, `dd()`, `mimes`, logout, token versioning, `users` table, `Path.php` |

## Deploying this

1. Run `php cli migrate` on every server (it adds `users.token_version`).
2. Expect every user to be logged out once (old tokens have no `ver`).
3. Remove the `dd()` lines from `AuthController::me()` before deploying. With `debug => false` they now
   answer 500 instead of leaking data, but `/me` won't work until they're gone.

---

## Commit message

```
fix: gate dd() on debug, add token versioning and logout, case-exact loading

- dd(): dump only when app.debug is true; otherwise log file:line and answer 500 (M3)
- Validator mimes: accept jpeg/jpe/jfif as jpg, ignore case (L1)
- e(): print nothing for arrays and other non-printable values instead of crashing (L2)
- Autoloader and views: require exact file-name case on every OS via Core\Path (L3)
- cookie(): Secure by default behind trusted HTTPS proxies (L4)
- Tokens carry users.token_version ("ver"); AuthMiddleware rejects stale versions (L5)
- POST /api/v1/auth/logout revokes all of the user's tokens (L5)
- Migration adds users.token_version; tests and README updated

Note: tokens issued before this change are rejected; run php cli migrate.
```
