# ExpressPHP API

A REST API backend in plain PHP and MySQL, with no framework and no Composer packages.
It has routing, middleware, validation, JWT authentication, file uploads, migrations, code generators,
rate limiting, logging and a Laravel-style `dd()`, and borrows Laravel's naming so it feels familiar.

## Contents

- [Requirements](#requirements)
- [Project structure](#project-structure)
- [Setup](#setup)
- [Configuration](#configuration)
- [API response format](#api-response-format)
- [Current routes](#current-routes)
- [Authentication (JWT)](#authentication-jwt)
- [File uploads](#file-uploads)
- [Adding a new feature (step by step)](#adding-a-new-feature-step-by-step)
- [Routing](#routing)
- [Controllers, Request and Response](#controllers-request-and-response)
- [Views](#views)
- [Validation](#validation)
- [Middleware](#middleware)
- [Models](#models)
- [Database](#database)
- [Migrations](#migrations)
- [Generators](#generators)
- [Rate limiting](#rate-limiting)
- [Logging](#logging)
- [Debugging with dd()](#debugging-with-dd)
- [Tests](#tests)
- [Security](#security)
- [Git](#git)

## Requirements

| | |
|---|---|
| PHP | **8.1 – 8.5** (tested on 8.1, 8.2, 8.3 and 8.4) |
| PHP extensions | `pdo_mysql`, `json` (`fileinfo` and `mbstring` recommended: without `fileinfo` every upload is treated as an unknown type and stored as `.bin`) |
| MySQL | 8.0+ (or MariaDB 10.6+) |
| OS | Linux, macOS or Windows |
| Web server | Apache 2.2/2.4 or LiteSpeed with `mod_rewrite` (works as is, through the `.htaccess` files), or nginx, IIS or `php -S` pointed at `public/` (see [Web servers](#web-servers)) |

## Project structure

```
app/                         Your application code (no comments by convention)   namespace App\
  Controllers/
    AuthController.php       register, login, me
    ExampleController.php    renders the example view
    FileController.php       upload
    HealthController.php     server and database health checks
  Middlewares/
    AuthMiddleware.php       requires a valid JWT
  Models/
    User.php                 raw SQL queries for the users table
  Views/
    example.php              HTML template rendered by ExampleController
core/                        Framework internals (fully commented)              namespace Core\
  bootstrap.php              Startup: autoloader, config, error handling, helpers
  Config.php                 Config::get('database.host')
  Database.php               PDO connection + query helpers
  Router.php                 Routes, groups, middleware
  Request.php                $request->body, ->query, ->params, validate() ...
  Response.php               $response->success(), ->error(), ->view() ...
  View.php                   Renders plain-PHP templates from app/Views/
  Path.php                   Case-exact file checks (same behaviour on macOS, Windows, Linux)
  Validator.php              Laravel-style validation rules
  ValidationException.php    Turns failed validation into a 422 response
  HttpException.php          throw new HttpException(404, '...') from anywhere
  MiddlewareInterface.php    Contract every middleware implements
  UploadedFile.php           Uploaded file wrapper, store()
  JWT.php                    JSON Web Tokens (HS256), our own implementation
  JWTException.php           Invalid or expired token
  RateLimiter.php            Global per-IP request limit
  Log.php                    Daily log files
  Migrator.php               Database migrations
  helpers.php                Global functions: dd(), e()
config/config.php            The one config file (no .env)
database/migrations/         Migration files
public/index.php             Front controller, which receives every request
routes/api.php               Route definitions
storage/logs/                app-YYYY-MM-DD.log
storage/uploads/             Uploaded files
storage/ratelimit/           Rate limit counters (one small file per IP)
tests/tests.php              Test runner (command line only)
cli                          Command-line tool: migrations and generators
.htaccess                    Sends requests to public/index.php, blocks private files
.gitignore
```

**Web access** on Apache/LiteSpeed (when the project folder itself is the web root):
- **Every request goes to `public/index.php`** (an allowlist). Other files in the project are never served directly, so a stray `backup.sql` or `index.php~` returns the API's 404 instead of downloading.
- **Also blocked (403):** the private folders `config/`, `core/`, `app/`, `routes/`, `database/`, `storage/` and `tests/`, the `cli` script, every `*.md` file and every dotfile.

## Setup

1. **Copy the project** into your web root, for example `htdocs/expressphp` or `public_html/`.
2. **Create the database:**
   ```sql
   CREATE DATABASE expressphp CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
   ```
3. **Edit `config/config.php`** with your database credentials and app URL.
4. **Run the migrations:**
   ```bash
   php cli migrate
   ```
5. **Make `storage/` writable** by the web server, since logs, uploads and rate-limit counters are written there:
   - **Linux VPS** (the web server user is `www-data` on Debian/Ubuntu, `apache` or `nginx` on RHEL-based systems):
     ```bash
     sudo chown -R $USER:www-data storage && sudo chmod -R 2775 storage
     ```
   - **Shared hosting (cPanel, Plesk, DirectAdmin):** usually nothing to do, because PHP runs as your own user. If logs don't appear, set `storage/` and its sub-folders to `755` (or `775`) in the file manager.
   - **Local macOS / Windows stacks (XAMPP, WAMP, Laragon and similar):** usually nothing to do, because the web server runs as your user.
6. **Check it works:**
   ```bash
   php tests/tests.php
   curl http://localhost/expressphp/api/v1/health/server
   ```

### URLs

The app detects which folder it is served from, so routes work anywhere without code changes:

| Where the project lives | URL |
|---|---|
| `htdocs/expressphp/` | `http://localhost/expressphp/api/v1/health/server` |
| `public_html/` | `https://domain.com.pk/api/v1/health/server` |
| Domain document root set to `public/` | `https://domain.com.pk/api/v1/health/server` |

### Web servers

| Server | What to do |
|---|---|
| **Apache / LiteSpeed** (most shared hosting, XAMPP, WAMP, cPanel, Plesk) | Nothing. The `.htaccess` files route requests and block private folders, whether the project folder or `public/` is the web root. Requires `mod_rewrite` and `AllowOverride All` (the default on shared hosting). |
| **nginx** | `.htaccess` is ignored, so the document root **must** be `public/` (otherwise `storage/` and `config/` are exposed). Use the config below. |
| **IIS** (Windows Server) | Set the site's physical path to `public/` and add a URL Rewrite rule that sends requests for missing files to `index.php`. |
| **PHP built-in server** (local development only) | `php -S localhost:8000 -t public` |

```nginx
server {
    listen 80;
    server_name api.example.com;
    root /var/www/expressphp/public;   # always public/, never the project folder
    index index.php;

    location / {
        try_files $uri /index.php$is_args$args;
    }

    location = /index.php {
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        fastcgi_pass unix:/run/php/php-fpm.sock;   # or 127.0.0.1:9000
    }

    location ~ /\. { deny all; }
}
```

nginx and PHP-FPM pass the `Authorization` header to PHP as is. On Apache with PHP as CGI/FastCGI, the `.htaccess` files forward it.

### Shared hosting / production checklist

- **Document root:** if your host allows it, point the domain's document root at the `public/` folder. That is the safest setup.
- **Edit `config/config.php` on the server itself:**
  - `'env' => 'production'` and `'debug' => false`
  - the host's database credentials
  - a new `jwt.secret`: `php -r "echo bin2hex(random_bytes(32));"`
- **Migrations:** run `php cli migrate --force`, or ask your host how to run PHP commands.
- **Tests:** `tests/tests.php` only runs from the command line (`tests/` is also denied by `.htaccess`). Don't run it against the production database: it creates and deletes test data.
- **Permissions:** make sure `storage/logs`, `storage/uploads` and `storage/ratelimit` are writable (755 or 775).
- **Behind Cloudflare or another proxy:** add its IP ranges to `app.trusted_proxies` (CIDR such as `173.245.48.0/20` works), so IPs and rate limits are per visitor. Avoid `'*'` unless the server can only be reached through the proxy.

## Configuration

All settings live in `config/config.php`. There is no `.env` file. Read any value with `Config::get('app.name')`.

| Key | Purpose |
|---|---|
| `app.name` / `app.version` | Your API's name and version |
| `app.env` | `development` or `production` (production makes schema commands need `--force`) |
| `app.debug` | Development switch; **set it to `false` in production.** With `true`: 500 responses include the exception message, file, line and stack trace; PHP warnings and errors are printed into responses (`display_errors`); `dd()` dumps values. With `false`: 500 responses only say `Internal server error.`, nothing is printed, and `dd()` dumps nothing. Details are written to the log either way. |
| `app.url` | Public base URL of the API |
| `app.timezone` | PHP timezone, also used for log timestamps (keep it in sync with `database.timezone`) |
| `app.trusted_proxies` | Proxies allowed to set `X-Forwarded-For` / `X-Forwarded-Proto`: single IPs, CIDR ranges (`'173.245.48.0/20'`, `'2400:cb00::/32'`), or `'*'` (trust all; only safe when the server is reachable through the proxy alone) |
| `database.*` | MySQL connection settings |
| `jwt.secret` | Key that signs auth tokens. At least 32 characters, unique per environment. **Changing it logs everyone out.** |
| `jwt.ttl` | Token lifetime in seconds (default `86400` = 24 hours) |
| `filesystem.root` | Where `UploadedFile::store()` saves files (`storage/uploads`) |
| `rate_limit.enabled` | `true` / `false` turns the global rate limit on or off |
| `rate_limit.max_requests` / `per_seconds` | Requests allowed per IP in that many seconds (default 60 per 60) |
| `rate_limit.path` | Folder for the rate-limit counter files |
| `logging.enabled` | `true` / `false` turns all logging on or off |
| `logging.path` | Folder for the daily log files |

## API response format

Every JSON response uses one of two shapes.

Success (`data` holds the result):
```json
{ "success": true, "message": "User fetched.", "data": { "id": 1 } }
```

Error (`errors` holds details, or `null` when there are none):
```json
{ "success": false, "message": "The username field is required.", "errors": { "username": ["The username field is required."] } }
```

| Status | When |
|---|---|
| `200` / `201` | Success (`201` for something created, e.g. register, upload) |
| `400` | Malformed JSON or form body |
| `401` | Missing, invalid or expired token; wrong username or password |
| `404` | Unknown route |
| `405` | Known route, wrong method (with an `Allow` header) |
| `413` | Request body larger than PHP's `post_max_size` (any method, including chunked uploads that PHP had to cut off) |
| `422` | Validation failed, with a list of errors per field. Also returned when a database unique index named `<table>_<column>_unique` rejects a duplicate that slipped past a `unique` rule (two requests at the same moment). Duplicates on other indexes are treated as server errors (500). Every duplicate-key hit is logged with the index name |
| `429` | Rate limit exceeded (with a `Retry-After` header) |
| `500` | Uncaught error. With `debug` on, `errors` holds the exception class, file and stack trace; with it off, just `Internal server error.` |
| `503` | Database unreachable (health check) |

## Current routes

| Method | URL | Auth | Description |
|---|---|---|---|
| GET | `/api/v1/health/server` | — | Server is up |
| GET | `/api/v1/health/database` | — | Database is reachable (`503` if not) |
| POST | `/api/v1/auth/register` | — | Create an account; returns the user and a token |
| POST | `/api/v1/auth/login` | — | Log in; returns the user and a token |
| GET | `/api/v1/auth/me` | **token** | The logged-in user |
| POST | `/api/v1/auth/logout` | **token** | Revoke every token of the logged-in user (all devices) |
| POST | `/api/v1/files/upload` | **token** | Upload one file (multipart field `file`, max 2 MB) |
| GET | `/api/v1/example/view` | **token** | Example HTML page rendered from `app/Views/example.php` |

## Authentication (JWT)

Register:
```bash
curl -X POST http://localhost/expressphp/api/v1/auth/register \
  -H "Content-Type: application/json" \
  -d '{"name":"Muhammad Ali","username":"ali_akbar","password":"secret123"}'
```
| Field | Rules |
|---|---|
| `name` | required, max 100 characters |
| `username` | required, 3 to 50 characters, letters, numbers, `-` and `_` only, unique (case-insensitive) |
| `password` | required, 8 to 72 characters |

Log in:
```bash
curl -X POST http://localhost/expressphp/api/v1/auth/login \
  -H "Content-Type: application/json" \
  -d '{"username":"ali_akbar","password":"secret123"}'
```

Both return (register with status `201`):
```json
{
  "success": true,
  "message": "Logged in successfully.",
  "data": {
    "user": { "id": 1, "name": "Muhammad Ali", "username": "ali_akbar", "created_at": "...", "updated_at": "..." },
    "token": "eyJ0eXAiOiJKV1QiLCJhbGciOiJIUzI1NiJ9..."
  }
}
```

Send the token on protected routes:
```bash
curl http://localhost/expressphp/api/v1/auth/me -H "Authorization: Bearer <token>"
```

- **Protecting routes:** add `AuthMiddleware` to a route or a group (see [Middleware](#middleware)). Read the user with `$request->user()`, an array: `id`, `name`, `username`, `created_at`, `updated_at`.
- **Missing, invalid or expired tokens** get `401` with a `WWW-Authenticate: Bearer` header.
- **Tokens are JWTs** (HS256, signed with `jwt.secret`) that carry the user id (`sub`) and the user's token version (`ver`). There are no refresh tokens: a token works until `jwt.ttl` runs out or until it is revoked. A token for a deleted user is rejected.
- **Logout:** `POST /api/v1/auth/logout` with the token increments the user's `token_version`, so **every** token issued to that user so far stops working (all devices). Logging in again issues a new, valid token. Call `User::revokeTokens($id)` to do the same elsewhere, e.g. after a password change.
- **Tokens issued before token versioning** (without `ver`) are rejected, so everyone logs in once after this update.
- **Passwords** are hashed with bcrypt (`password_hash`) and never included in responses.
- **Failed logins** return the same message and take the same time whether the username or the password was wrong, so usernames can't be discovered.

## File uploads

```bash
curl -X POST http://localhost/expressphp/api/v1/files/upload \
  -H "Authorization: Bearer <token>" \
  -F "file=@/path/to/photo.png"
```
```json
{
  "success": true,
  "message": "File uploaded successfully.",
  "data": { "path": "files/24a6cfbd805cc88baeb59a99235c98f6.png", "original_name": "photo.png", "mime_type": "image/png", "size": 70 }
}
```

- **Storage:** files are saved to `storage/uploads/files/` with a random name. `path` is relative to `storage/uploads/`; store it in the database if you need it later.
- **File type:** `mime_type` and the saved extension are detected from the file's **contents**, never from the name the client sent. Types outside the known list (JPEG/PNG/GIF/WebP/BMP images, PDF, Office files, text/CSV/JSON, zip, mp4, mp3) are saved as **`.bin`**, so an upload can never be stored as `.php`, `.html`, `.js` or `.svg`. SVG is deliberately excluded because it can contain `<script>`.
- **`storage/uploads/.htaccess`** is a second layer that denies access and turns off script execution in that folder, even if the parent rule is ever removed.
- **`storage/` is not reachable from the web**, so uploaded files can't be opened by URL. That's safer, because an uploaded `.php` file can never run. To serve files, add a route that checks permission and returns `$response->download($path)`.
- **Size limit:** PHP's `upload_max_filesize` (2 MB by default) rejects bigger files with `"The file is too large."`. To allow more, raise `upload_max_filesize` and `post_max_size` in `php.ini`, and `max:2048` (KB) in `FileController`.

In your own controllers:
```php
$request->validate(['avatar' => 'required|image|mimes:jpg,png|max:2048']);
$path = $request->file('avatar')->store('avatars');   // storage/uploads/avatars/<random>.png
```

## Adding a new feature (step by step)

Example: a `posts` resource that only logged-in users can use.

1. **Create the table:**
   ```bash
   php cli make:migration create_posts_table
   ```
   Edit the new file in `database/migrations/` (add your columns), then run:
   ```bash
   php cli migrate
   ```
2. **Create the model** with raw SQL queries:
   ```bash
   php cli make:model Post
   ```
3. **Create the controller:**
   ```bash
   php cli make:controller PostController
   ```
   Validate input, call the model, and return `$response->success(...)`.
4. **Add the routes** in `routes/api.php`, inside the `api/v1` group:
   ```php
   $router->group('posts', function (Router $router) {
       $router->get('/', [PostController::class, 'index']);
       $router->post('/', [PostController::class, 'store']);
       $router->get('/{id}', [PostController::class, 'show']);
   }, [AuthMiddleware::class]);
   ```
5. **Update this README's** [Current routes](#current-routes) and [Tables](#tables).

## Routing

All routes are defined in `routes/api.php`, and the full URL is written there, with no hidden prefix:

```php
use App\Controllers\UserController;
use App\Middlewares\AuthMiddleware;
use Core\Router;

$router->group('api/v1', function (Router $router) {
    $router->get('/users', [UserController::class, 'index']);
    $router->get('/users/{id}', [UserController::class, 'show']);      // $request->params['id']
    $router->post('/users', [UserController::class, 'store']);
    $router->put('/users/{id}', [UserController::class, 'update']);
    $router->patch('/users/{id}', [UserController::class, 'update']);
    $router->delete('/users/{id}', [UserController::class, 'destroy']);

    // A group: prefix, routes, then optional middleware for all of them
    $router->group('admin', function (Router $router) {
        $router->get('/stats', [AdminController::class, 'stats']);    // /api/v1/admin/stats
    }, [AuthMiddleware::class]);
});
```

- **Signatures:** `group(string $prefix, callable $routes, array $middleware = [])`, and the same optional `$middleware` last argument on `get/post/put/patch/delete`.
- **Nesting:** groups nest, and inner routes get every outer prefix and middleware.
- **404 and 405:** an unknown route returns `404`. A known route with the wrong method returns `405` with an `Allow` header.
- **HEAD requests** are answered by the matching `GET` route.
- **Clients that can only send POST** can send the `X-HTTP-Method-Override: PUT|PATCH|DELETE` header instead. JSON, form and multipart bodies (including files) all arrive normally.
- **A route can return a plain array.** It's wrapped in the success shape automatically.

## Controllers, Request and Response

Every controller method receives the request and a response object:

```php
namespace App\Controllers;

use Core\Request;
use Core\Response;

final class PostController
{
    public function store(Request $request, Response $response): Response
    {
        $data = $request->validate([
            'title' => 'required|string|max:200',
        ]);

        $id = Post::create($request->user()['id'], $data['title']);

        return $response->success(Post::findById($id), 'Post created.', 201);
    }
}
```

### Request

Read-only properties:
```php
$request->method     // 'POST'
$request->path       // '/api/v1/posts'
$request->body       // parsed JSON / form body
$request->query      // ?page=2 => ['page' => '2']
$request->params     // route params, e.g. ['id' => '5']
$request->headers    // lower-case names: ['authorization' => 'Bearer ...']
$request->files      // UploadedFile objects
$request->cookies
$request->title      // shortcut: route params, then body, then query, then files
```

Methods:
```php
// input
$request->input('address.city', 'default')   $request->all()   $request->query('page')   $request->param('id')
$request->only('name', 'email')              $request->except('password')
$request->has('x')  $request->hasAny(['x', 'y'])  $request->filled('x')  $request->missing('x')
$request->string('name')  $request->integer('page', 1)  $request->float('price')  $request->boolean('active')
$request->merge(['role' => 'admin'])
$request->validate([...])

// files and cookies
$request->file('avatar')  $request->hasFile('avatar')  $request->cookie('token')

// headers and client
$request->header('Accept')  $request->hasHeader('X-Key')  $request->bearerToken()  $request->userAgent()
$request->isJson()  $request->expectsJson()  $request->ip()

// method and URL
$request->isMethod('post')  $request->is('api/v1/posts/*')
$request->url()  $request->fullUrl()  $request->host()  $request->scheme()  $request->isSecure()

// per-request state (used by middleware)
$request->user()  $request->setUser($user)  $request->getAttribute('key')  $request->setAttribute('key', $value)
```

Bodies are read for JSON, form-urlencoded and multipart on POST, PUT, PATCH and DELETE.
Malformed JSON gets `400`.

### Response

```php
return $response->success($data, 'Message');            // 200
return $response->success($data, 'Created.', 201);      // any status code
return $response->error('Not found.', 404);             // default status 400
return $response->error('Invalid data.', 422, $errors);
return $response->paginate($items, $total, $page, 15);  // data = { items, pagination }
return $response->noContent();                          // 204, empty body (outside the standard shape)
return $response->download($path, 'report.pdf');
return $response->view('example', ['title' => 'Hi']);  // HTML from app/Views/example.php (see Views)
return $response->json($raw);                           // raw JSON, skips the standard shape

$response->header('X-Total', '5')->withHeaders([...])->cookie('token', $t, 60)->withoutCookie('old')->success($data);
$response->status  $response->body  $response->headers  // read-only properties
```

You can also throw an HTTP error from anywhere (controllers, models, middleware):
```php
throw new Core\HttpException(403, 'You cannot do that.');
```

## Views

Views are plain PHP templates in `app/Views/`. Render one from a controller with `$response->view()`;
each key of the data array becomes a variable in the template:

```php
// app/Controllers/ExampleController.php
return $response->view('example', [
    'title' => 'Example view',
    'user'  => $request->user(),
    'items' => ['Routing', 'Middleware', 'Views'],
]);

return $response->view('users.profile', $data);       // app/Views/users/profile.php
return $response->view('errors.missing', $data, 404); // custom status (and optional headers)
```

```php
<!-- app/Views/example.php -->
<h1><?= e($title) ?></h1>
<?php foreach ($items as $item): ?>
    <li><?= e($item) ?></li>
<?php endforeach; ?>
```

- **Always print values with `e()`**: it escapes HTML so user data can't inject markup or scripts.
- The response is sent as `text/html`. Errors thrown before the view renders (e.g. a `401` from
  `AuthMiddleware`) are still JSON.
- `View::render('example', $data)` returns the HTML as a string (e.g. for emails).
- The example route `GET /api/v1/example/view` sits behind `AuthMiddleware`, so send
  `Authorization: Bearer <token>`; in Postman, open the response's **Preview** tab.

## Validation

`$request->validate($rules)` returns only the fields that passed validation. If validation fails, it responds with **422** and a list of errors per field.

Rules: `required, nullable, sometimes, string, integer, numeric, boolean, array, email, url, date,
min, max, between, size, in, not_in, regex, alpha, alpha_num, alpha_dash, confirmed, same,
different, unique:table,column[,exceptId], exists:table,column, file, image, mimes`

```php
$request->validate([
    'username' => 'required|alpha_dash|min:3|unique:users,username',
    'password' => 'required|min:8|confirmed',                      // needs password_confirmation
    'age'      => 'nullable|integer|between:18,120',
    'avatar'   => 'sometimes|image|mimes:jpg,png|max:2048',          // KB
    'code'     => ['required', 'regex:/^[A-Z]{3}-\d+$/'],            // use an array when a rule contains "|"
], [
    'username.unique' => 'This username is already taken.',          // custom message
]);
```

- **What `min`/`max`/`between` measure:** the number's value for `integer`/`numeric` fields, the item count for arrays, kilobytes for files, and otherwise the string length.
- **Empty optional fields:** fields that aren't `required` skip their other rules when empty.
- **Uploads PHP already rejected** (e.g. too large) report PHP's reason, such as `"The file is too large."`.
- **`mimes`** checks the type detected from the file's contents, not its name. `jpeg`, `jpe` and `jfif` count as `jpg`, upper case is ignored, so `mimes:jpeg,PNG` works.

You can also validate any array: `Core\Validator::make($data, $rules)->fails()` / `->errors()` / `->validate()`.

## Middleware

Middleware classes go in `app/Middlewares/` (`php cli make:middleware Name`) and implement `Core\MiddlewareInterface`:

```php
public function handle(Request $request, callable $next): Response
{
    if ($request->user()['username'] !== 'admin') {
        return (new Response())->error('Forbidden.', 403);   // stop here
    }
    return $next($request);                                  // continue to the controller
}
```

Apply it:
```php
$router->get('/me', [AuthController::class, 'me'], [AuthMiddleware::class]);                 // one route
$router->group('admin', function (Router $router) { ... }, [AuthMiddleware::class, AdminMiddleware::class]); // a group
```
Middleware runs in the order listed, and nested groups inherit their parent's middleware.

## Models

Models go in `app/Models/` (`php cli make:model Name`). Each is a plain class whose static methods run **raw SQL**
through `Core\Database` and return arrays. Controllers call these methods; there is no base model or query builder.

```php
namespace App\Models;

use Core\Database;

final class Post
{
    public static function create(int $userId, string $title): int
    {
        Database::execute('INSERT INTO posts (user_id, title) VALUES (?, ?)', [$userId, $title]);
        return Database::lastInsertId();
    }

    public static function findById(int $id): ?array
    {
        return Database::fetch('SELECT id, user_id, title, created_at FROM posts WHERE id = ?', [$id]);
    }
}
```

Select columns explicitly, so sensitive ones (like `password`) are never sent by accident.
See `app/Models/User.php` for a real example.

## Database

```php
use Core\Database;

Database::fetch('SELECT * FROM users WHERE id = ?', [$id]);              // one row or null
Database::fetchAll('SELECT * FROM users WHERE name LIKE ?', ['A%']);     // array of rows
Database::execute('UPDATE users SET name = ? WHERE id = ?', [$n, $id]); // affected rows
Database::lastInsertId();
Database::transaction(function () { /* commits, or rolls back on exception */ });
```

- **Always pass values as parameters (`?`).** Never put user input directly into SQL.
- **Connection settings:** utf8mb4, the session time zone from `database.timezone`, and strict SQL mode.
- **Types:** integers come back as PHP `int`.

## Migrations

```bash
php cli migrate                          # run pending migrations
php cli migrate:status                   # show which have run
php cli migrate:rollback [--step=N]      # undo the last batch (or N batches)       ⚠ drops data
php cli migrate:refresh                  # undo all, then migrate again             ⚠ drops data
php cli migrate:fresh                    # DROP ALL TABLES, then migrate            ⚠ drops data
php cli make:migration create_posts_table
```

A migration is a file in `database/migrations/` named `YYYY_MM_DD_HHMMSS_name.php`:

```php
use Core\Database;

return new class {
    public function up(): void
    {
        Database::execute("
            CREATE TABLE `posts` (
                `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `user_id`    BIGINT UNSIGNED NOT NULL,
                `title`      VARCHAR(200) NOT NULL,
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    public function down(): void
    {
        Database::execute('DROP TABLE IF EXISTS `posts`');
    }
};
```

- **Order and tracking:** migrations run in filename order and are recorded in the `migrations` table, so each runs once.
- **Changing a table that has data:** don't roll back. Create a new migration with `ALTER TABLE` (e.g. `php cli make:migration add_email_to_users_table`) and run `php cli migrate`.
- **Production safety:** when `app.env` is `production`, `migrate`, `rollback`, `refresh` and `fresh` refuse to run without `--force`.
- **Keep migrations small.** MySQL saves `CREATE`/`ALTER`/`DROP` immediately and can't undo them as part of a transaction, so keep one change per migration.
- **One runs at a time:** a database lock stops two migrations running at once (e.g. two deploys).

### Tables

| Table | Columns |
|---|---|
| `users` | `id`, `name`, `username` (unique, case-insensitive), `password` (bcrypt hash), `token_version` (incremented on logout to revoke tokens), `created_at`, `updated_at` |
| `migrations` | Which migrations have run (managed by `php cli`) |

## Generators

```bash
php cli make:controller UserController          # app/Controllers/UserController.php
php cli make:controller Admin/UserController    # app/Controllers/Admin/UserController.php (namespace App\Controllers\Admin)
php cli make:model Post                         # app/Models/Post.php
php cli make:middleware AdminMiddleware         # app/Middlewares/AdminMiddleware.php
php cli make:migration create_posts_table       # database/migrations/<timestamp>_create_posts_table.php
php cli                                         # list all commands
```

- **Names** must be StudlyCase (`UserController`, not `user_controller`). Migration names are snake_case.
- **Existing files** are never overwritten.
- **Templates:**
  - a controller with an `index()` method,
  - a model with a raw-SQL `findById()` (replace `table_name`),
  - a middleware whose `handle()` just calls `$next($request)`,
  - a migration with `id`, `created_at` and `updated_at` columns (the table name comes from `create_<table>_table`).

## Rate limiting

Every request is limited per client IP. There's nothing to add to routes: `public/index.php` checks the limit before routing.
The default is **60 requests per 60 seconds**, set in `rate_limit` in `config/config.php`.

Over the limit, the client gets `429` with a `Retry-After` header (seconds):
```json
{ "success": false, "message": "Too many requests. Try again in 42 seconds.", "errors": null }
```

- **Window:** fixed. It starts with an IP's first request and resets after `per_seconds`.
- **Storage:** counters live in `storage/ratelimit/`, one tiny plain-text file per IP (`<window start> <count>`), locked while updated so simultaneous requests are counted correctly. Expired files are cleaned up automatically.
- **Cost:** about 20 µs per request.
- **IPv6:** each address has its own normal limit, and its whole `/64` network has a shared cap of **10×** that limit (600 per minute by default).
  - Unrelated users who share a `/64` (VPNs, hosting providers, offices) don't block each other.
  - Someone rotating addresses inside their `/64` still hits the network cap.
  - Only requests an address was **allowed** to make count towards the network cap, so one noisy address (already getting 429s) can't use up its neighbours' budget.
  - IPv4 addresses written as IPv6 (`::ffff:1.2.3.4`) count as the IPv4 address.
- **Storage layout:** counter files are spread over up to 256 sub-folders (`storage/ratelimit/3f/3f9a…`), and cleanup scans only one small sub-folder at a time.
- **Behind a proxy** (e.g. Cloudflare), add its IPs or CIDR ranges to `app.trusted_proxies`. Otherwise every visitor shares the proxy's IP and limit. With `['*']`, the left-most address in `X-Forwarded-For` (the original client) is used, which can be faked if the server is also reachable directly.
- **If the folder isn't writable,** requests are allowed rather than blocked, so a permissions problem never takes the API down.

## Logging

Logs are written to `storage/logs/app-YYYY-MM-DD.log`, one file per day. Each entry is one line, timestamped in `app.timezone`:

```
[2026-10-14 18:43:11] INFO GET /api/v1/health/server 200 0.9ms 127.0.0.1
[2026-10-14 18:43:12] WARNING GET /api/v1/nope 404 0.8ms 127.0.0.1
[2026-10-14 18:43:13] WARNING GET /api/v1/health/server 429 0.4ms 127.0.0.1
[2026-10-14 18:43:20] ERROR LogicException: Something broke at app/Controllers/UserController.php:42
[2026-10-14 18:43:25] INFO User registered {"id":5}
```

- **Logged automatically:** every request (2xx/3xx = INFO, 4xx = WARNING, 5xx = ERROR), including ones rejected for a malformed (400) or oversized (413) body; uncaught exceptions, fatal errors and PHP warnings.
- **Data values from database errors are never logged:** values MySQL quotes in its messages are written as `'***'`, while column and index names are kept, e.g. `Duplicate entry '***' for key 'users.users_username_unique'`, `Incorrect integer value: '***' for column 'age'`.
- **Deprecations** (`E_DEPRECATED`) are logged as WARNING and the request continues, so a PHP upgrade can't break endpoints.
- **One entry, one line:** control characters in messages are written as `\x0A`, `\x0D`, … so input like `/x%0A[date] ERROR …` can't forge extra log lines.
- **Your own entries:** `Log::info()`, `Log::warning()`, `Log::error()` (each with an optional context array), and `Log::exception($e)`.
- **On/off switch:** `logging.enabled` in the config.
- **Cost:** each entry is a single file append, and a failed write never breaks a request.
- **Cleanup:** old files are not deleted automatically. Remove them yourself as needed.

## Debugging with dd()

`dd()` ("dump and die") works everywhere, like Laravel's. It shows the values and stops the request:

```php
dd($request);              // one value
dd($request->body, $user); // several values, each in its own block
```

- **Over HTTP** it returns an HTML page styled like Laravel's (Symfony VarDumper):
  - a dark block,
  - `// file:line` showing where `dd()` was called,
  - ▶/▼ to expand or collapse, with only the first level open.

  In Postman, open the response's **Preview** tab.
- **Object properties** are prefixed like Laravel: `+` public, `#` protected, `-` private.
- **Like Laravel's, it shows everything**, including passwords, tokens and the request's private `-server` (`$_SERVER`). It's for debugging only.
- **Only works when `app.debug` is `true`.** With debug off, a forgotten `dd()` dumps nothing: it writes a warning with its `file:line` to the log, and the request answers a plain `500 Internal server error.`
- **In the terminal** it uses `var_dump()`.
- **Remove `dd()` calls when you're done.** While one is in a route, that route returns the dump instead of its normal response, and `php tests/tests.php` stops when a test reaches it.

## Tests

```bash
php tests/tests.php
```

The tests run **from the command line only**. Over the web, `tests/tests.php` is blocked (403) and also refuses to run outside the CLI, because the tests write to the database. The command exits with status `1` if any test fails.

- **Coverage:** config, database, router, request, response, validation, logging, rate limiter, migrations, JWT, the User model and the full register → login → me flow.
- **Test data:** tests that need users create them with a `zz_` prefix and delete them afterwards. Migration tests use their own temporary table, so your data isn't touched.

## Security

- **Private files:** the root `.htaccess` is an allowlist: every request goes to `public/index.php`, and private folders (including `tests/`), dotfiles, `cli` and `*.md` are additionally denied.
- **Request size:** bodies larger than `post_max_size` are refused with `413` before they're read, for every method. The rate limit is checked first, so oversized or malformed requests are counted and logged too.
- **Logs:** control characters are escaped, so log lines can't be forged.
- **SQL injection:** every query uses prepared statements. Table and column names in validation rules are checked against a safe pattern.
- **Passwords:** bcrypt via `password_hash()`. They are never selected in normal queries or returned in responses.
- **JWT:** the algorithm is pinned to HS256 (`alg: none` is rejected), signatures are compared in constant time, and expiry is checked.
- **Login:** the same message and timing for a wrong username or a wrong password.
- **Uploads:**
  - stored outside the web root with random names,
  - the extension comes only from the detected content type (unknown types become `.bin`),
  - script execution is turned off in `storage/uploads/`.
- **Rate limiting:** every IP gets a global limit.
- **Error details:** with `debug` off, errors show only `Internal server error.`; details go to the log.
- **Client IPs:** `X-Forwarded-For` is only trusted from proxies in `app.trusted_proxies` (single IPs or CIDR ranges).
- **Response headers:** every response sends `X-Content-Type-Options: nosniff` and `Referrer-Policy: no-referrer`. JSON and downloads also send `Content-Security-Policy: default-src 'none'; frame-ancestors 'none'; sandbox`. HTML views send `X-Frame-Options: SAMEORIGIN` instead, so they can use inline CSS and JS. Set your own header in the controller to override a default.
- **Stored file names:** `storeAs()` treats `\` as a path separator on every OS and rejects `..`, `:` (Windows alternate data streams), control characters, and trailing dots or spaces, so a name can't escape `storage/uploads/` on Linux or Windows.
- **Same files everywhere:** `.gitattributes` keeps LF line endings, so a Windows checkout deployed to Linux doesn't break the `cli` shebang.

## Git

`.gitignore` keeps these out of the repository:
- runtime files in `storage/logs`, `storage/uploads` and `storage/ratelimit` (the folders are kept with `.gitkeep`),
- OS files (`.DS_Store`, `Thumbs.db`) and editor folders (`.idea/`, `.vscode/`),
- `vendor/`, `node_modules/` and `.env` files, in case those are ever added.

`config/config.php` is committed on purpose, because the repository is private. Edit it on each server with that server's values.
