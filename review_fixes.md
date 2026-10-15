# Review fixes

Fixes made after moving the project from Ubuntu to macOS. The goal is that the same files run
unchanged on Linux, macOS and Windows, on shared hosting and on a VPS, with PHP 8.1 – 8.5.

`app/` and `routes/` were not part of the review and were not changed by it.

All 66 tests pass on PHP 8.1, 8.2, 8.3 and 8.4 (`php tests/tests.php`).

---

## 1. PHP version compatibility (8.1 – 8.5)

The project only ran on PHP 8.4+. MAMP ships PHP 8.3 at most, so every route crashed with
`Fatal error: Multiple access type modifiers are not allowed`.

| Fix | File | Purpose |
|---|---|---|
| Replaced `public private(set)` properties with private properties exposed read-only through `__get()`; `__set()` / `__unset()` throw an `Error` | `core/Request.php`, `core/Response.php` | `private(set)` is PHP 8.4-only syntax. `$request->body`, `$response->status` etc. work exactly as before and stay read-only. |
| Fallback when `request_parse_body()` doesn't exist: `parse_str()` for url-encoded forms, a new `parseMultipart()` for multipart forms | `core/Request.php` | `request_parse_body()` (PHP 8.4+) reads form bodies of PUT / PATCH / DELETE requests. The fallback returns the same `$_POST` / `$_FILES` layout on older PHP. |
| Files written by `parseMultipart()` skip the `is_uploaded_file()` check | `core/Request.php`, `core/UploadedFile.php` | PHP didn't create those temp files, so its own check would reject them. The framework creates them itself, so their path can't be forged. |
| README requirement changed to PHP 8.1 – 8.5 | `README.md` | Documentation. |

## 2. Bearer token rejected ("Invalid or expired token")

| Fix | File | Purpose |
|---|---|---|
| The `Authorization` header is also read from `REDIRECT_HTTP_AUTHORIZATION`, `REDIRECT_REDIRECT_HTTP_AUTHORIZATION`, … (any number of `REDIRECT_` prefixes) | `core/Request.php` | Apache running PHP as CGI/FastCGI drops the header. The `.htaccess` files copy it into an environment variable, and Apache adds one `REDIRECT_` prefix per internal rewrite (root `.htaccess` → `public/.htaccess`), so PHP never saw the token. |

## 3. Security fixes

| Fix | File | Purpose |
|---|---|---|
| `storeAs()` treats `\` as a path separator on every OS and rejects `..`, `:`, control characters, and names that are empty after removing trailing dots/spaces | `core/UploadedFile.php` | **Path traversal on Windows:** on Linux `basename('..\..\x.php')` keeps the backslashes, and Windows then climbs out of `storage/uploads/`. `:` writes a hidden alternate data stream on Windows (`x.jpg:evil.php`), and Windows strips trailing dots (`x.php.` becomes `x.php`). |
| Multipart fallback: a later field like `a[x]` after a plain `a` replaces it | `core/Request.php` | A crafted request used to crash with "Cannot create references to/from string offsets" (500). |
| Multipart / url-encoded fallback: more fields than `max_input_vars` give `400` | `core/Request.php` | Used to emit a PHP warning, which the error handler turns into a 500. PHP 8.4's parser also answers 400. |
| Fallback body size capped at ¼ of `memory_limit` (`413` above that) | `core/Request.php` | The fallback holds the body in memory. A large `post_max_size` (common on a VPS that accepts uploads) could exhaust memory and crash the request. |
| Fallback temp files go to `upload_tmp_dir` first; a failure is reported as an upload error | `core/Request.php` | On shared hosting the system temp folder is often outside `open_basedir`, which used to cause a 500. |
| Top-level multipart names convert spaces and dots to `_` (`my file` → `my_file`) | `core/Request.php` | Same field names as PHP's own parser, so code behaves the same on every PHP version. |
| `unique` and `exists` rules fail for non-scalar values without running a query | `core/Validator.php` | An array sent to a field with one of these rules caused "Array to string conversion" (500). |
| Default security headers, sent unless the controller sets its own: JSON and downloads get `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; sandbox`, HTML views get `X-Frame-Options: SAMEORIGIN`, everything gets `Referrer-Policy: no-referrer` | `core/Response.php` | JSON or a downloaded file can never run scripts or be framed (clickjacking), and URLs with tokens don't leak through the Referer header. Views only refuse foreign frames, so their inline CSS/JS keeps working. |

## 4. Portability (any OS, any host)

| Fix | File | Purpose |
|---|---|---|
| `Require all denied` wrapped in `<IfModule mod_authz_core.c>`, with `Order deny,allow` / `Deny from all` as the Apache 2.2 alternative | all `.htaccess` files outside `app/` and `routes/` | Works on Apache 2.2, Apache 2.4 and LiteSpeed without editing. |
| `Options -MultiViews` (inside `<IfModule mod_negotiation.c>`) | `.htaccess`, `public/.htaccess` | Content negotiation can serve a file before the rewrite rules run. It's enabled on some hosts. |
| `.well-known/acme-challenge` and `.well-known/pki-validation` are let through | `.htaccess` | Let's Encrypt / cPanel AutoSSL domain checks used to hit the API and get 404, so SSL couldn't be issued when the project folder is the domain root. |
| `Options -Indexes` added to `public/.htaccess` | `public/.htaccess` | No directory listings when `public/` is the document root. |
| MIME detection works without the `fileinfo` extension (falls back to `application/octet-stream`) | `core/UploadedFile.php`, `core/Response.php` | Some shared hosts disable `fileinfo`, which used to be a fatal error. Without it, uploads are stored as `.bin` and fail `image` / `mimes` rules: safe, never guessed from the file name. |
| String length uses `mb_strlen()` when available, otherwise a UTF-8 regex | `core/Validator.php` | `iconv` is missing on some minimal builds, which used to be a fatal error. |
| `Log::relative()` compares paths with forward slashes and is reused by `bootstrap.php` and `dd()` | `core/Log.php`, `core/bootstrap.php`, `core/helpers.php` | Paths are shortened to `app/...` on Windows (`C:\...\app\x.php`) too. |
| Config paths trimmed of `/` and `\` | `core/Log.php`, `core/RateLimiter.php`, `core/UploadedFile.php` | Windows-style paths in `config.php` don't produce double separators. |
| New `.gitattributes` with `* text=auto eol=lf` | `.gitattributes` | A Windows checkout would otherwise convert to CRLF, breaking the `cli` shebang (`#!/usr/bin/env php`) when deployed to Linux. |
| README: per-OS setup for `storage/` permissions, a **Web servers** section (Apache/LiteSpeed, nginx config, IIS, `php -S`), updated requirements and security notes | `README.md` | nginx and IIS ignore `.htaccess`, so there the document root must be `public/`. |

## 5. MAMP / macOS references removed

| Change | File |
|---|---|
| Comment `(e.g. MAMP)` removed | `core/Request.php` |
| `# macOS` / `# Windows` / `# Linux` headings merged into `# Operating system files` (same entries) | `.gitignore` |
| `/var/www/...` example replaced with "the full server path" | `core/Log.php` |

## 6. New tests

| Test | Covers |
|---|---|
| Response properties are read-only | Section 1 |
| Multipart parser matches PHP's layout | Section 1 |
| Authorization header recovered from `REDIRECT_` env vars | Section 2 |
| Multipart parser: name collisions and field limit | Section 3 |
| `storeAs()` rejects Windows-style traversal and stream names | Section 3 |
| Validator `unique` / `exists` reject arrays | Section 3 |
| Response sends safe default security headers | Section 3 |
| Validator string length counts UTF-8 characters | Section 4 |
| `Log::relative()` shortens Windows and Unix paths | Section 4 |

---

## Still to do before production

These are settings, not code, so they were left as they are:

- **`config/config.php`:**
  - `port` is `3305` (MAMP's MySQL port); most servers use `3306`.
  - Set `'env' => 'production'` and `'debug' => false`.
  - Replace the `root` / `root` database login.
- **`jwt.secret` is committed to git:** anyone with access to the repository can sign valid tokens. Generate a new secret on every server: `php -r "echo bin2hex(random_bytes(32));"`.
- **`app/.htaccess` and `routes/.htaccess`** (outside the review) still use Apache 2.4-only syntax. That's fine on current hosts; wrap them like the others if you need Apache 2.2.
- **`$request->url()`** is built from the client's `Host` header. Use `app.url` from the config for links in emails (e.g. password resets).
