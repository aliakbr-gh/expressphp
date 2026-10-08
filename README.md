# ExpressPHP

ExpressPHP is a zero-dependency PHP micro-framework and starter kit for building secure, production-ready REST APIs with
MySQL.

It uses core PHP and PDO. There is no Composer or `vendor/` directory.

See [PROJECT.md](PROJECT.md) for a simple guide to every file and how requests move through the project.

## Features

- Versioned routing and middleware
- JWT authentication and token invalidation
- Roles and permissions
- Validation and pagination
- PDO models and migrations
- Rate limiting and permanent IP blocking
- Activity logs and daily request logs
- Server and database health checks
- SMTP and Gmail email
- Secure file uploads
- CORS and trusted proxies

## Requirements

- PHP 8.2 or newer
- MySQL 8 or MariaDB
- Apache with `mod_rewrite`
- PDO MySQL, OpenSSL, JSON, and Fileinfo PHP extensions

## Installation

Create a database and edit the values directly in `config/app.php`:

- Set `databases.connections.mysql` to your database host, database name, username, and password.
- Set `timezone`, `env`, and `debug` for the environment.
- Set `jwt.secret` to a unique random secret generated once for this environment:

```bash
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Paste the result into `jwt.secret`; keep it stable between requests and use a separate secret for each environment.

Then run:

```bash
php cli/migrate.php migrate
```

The migration creates the standard roles and permissions. There is no default username or password.
Register your first account through `POST /api/v1/auth/register` before making a fresh installation public. The first
registered user becomes `super-admin`; later users receive the `user` role. Registration requires `name`, `username`, and
`password` (at least 8 characters). Existing accounts keep their roles.

With MAMP and this project under `htdocs/expressphp`:

```text
http://localhost/expressphp/api/v1/health/server
http://localhost/expressphp/api/v1/auth/login
```

Upload the whole project as the subdomain document root (for example `public_html` for `digi.100xsoftware.pk`). Then this works with no extra Apache DocumentRoot change:

```text
https://digi.100xsoftware.pk/api/v1/health/server
https://digi.100xsoftware.pk/api/v1/auth/login
```

On the production host, set `'env' => 'production'`, `'debug' => false`, a unique `jwt.secret`, and
`'origins' => ['https://digi.100xsoftware.pk']` in the `cors` array. Native mobile apps can omit browser CORS; the origin list
is for any web client. Keep deployed credentials out of Git.

## Structure

```text
app/
├── Controllers/
├── Middlewares/
└── Models/
src/
├── Auth/
├── Core/
├── Database/
├── Http/
├── Logging/
├── Mail/
├── RateLimit/
├── Routing/
├── Storage/
├── Validation/
└── bootstrap.php
config/
└── app.php
cli/
public/
migrations/
routes/
storage/
```

Application classes use the `App\` namespace. Reusable framework classes use `ExpressPHP\`.

Keep `migrations/` at the project root. It describes application database changes, alongside `app/`, `routes/`, and `config/`.
The reusable migration runner belongs in `src/Database/Migrations/`.

## Creating an API

```php
<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Product;
use ExpressPHP\Http\Request;
use ExpressPHP\Http\Response;

final class ProductController
{
    public function __construct(
        private readonly Product $products = new Product(),
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        return $response->success($this->products->all(), 'Products loaded');
    }
}
```

```php
$router->get(
    '/products',
    [ProductController::class, 'index'],
    ['auth', 'permission:products.view'],
);
```

Generate starter files:

```bash
php cli/make controller Product
php cli/make model Product
php cli/make middleware ProductAccess
php cli/make migration create_products_table
```

## Responses and validation

```php
return $response->success($data, 'Request successful');
return $response->success($data, 'Resource created', 201);
return $response->error('Resource not found', 404);
```

```php
$data = $request->validate([
    'name' => 'required|string|min:2|max:100',
    'email' => 'required|string|email',
    'is_active' => 'optional|boolean',
    'profile' => 'optional|object',
    'profile.timezone' => 'optional|string',
    'tags' => 'optional|list',
]);
```

Use `array` for any PHP array, `object` for a JSON object, and `list` for a sequential
JSON array. Nested object fields use dot notation, such as `profile.timezone`.

Unknown fields are rejected. Validation errors return HTTP 422.

Collections use `limit` and `offset`:

```text
GET /api/v1/users?limit=20&offset=0
```

## Authentication and RBAC

Access tokens last `jwt.ttl` seconds (default 86400). The IP limiter defaults to 120 requests per 60 seconds. Tune the
`rate_limiter` array in `config/app.php` for a chatty mobile client.

```text
POST /api/v1/auth/register
POST /api/v1/auth/login
GET  /api/v1/auth/me
POST /api/v1/auth/refresh
POST /api/v1/auth/logout
```

Send protected requests with:

```http
Authorization: Bearer <token>
```

Refresh creates a replacement token and invalidates previous tokens for that user. Logout invalidates all active tokens
through `session_version`.

```php
['auth', 'permission:reports.view']
['auth', 'role:admin,manager']
```

## Logs and health

```text
GET /api/v1/health/server
GET /api/v1/health/database
GET /api/v1/activity-logs?date=2026-08-25&limit=20&offset=0
GET /api/v1/server-logs?date=2026-08-25&limit=20&offset=0
GET /api/v1/rate-limits/blocked?limit=20&offset=0
GET /api/v1/rate-limits/status?ip=192.0.2.10
POST /api/v1/rate-limits/block
POST /api/v1/rate-limits/clear
```

Block and clear accept `{"ip":"192.0.2.10"}`. These match the `php cli/rate-limit` commands and require `rate-limits.view`,
`rate-limits.block`, or `rate-limits.clear`.

Daily JSON request logs are stored under `storage/logs/`. Passwords, tokens, cookies, authorization headers, and other
sensitive fields are redacted.

## Database backups

```text
GET /api/v1/database-backups/download
```

This route takes no query parameters. It dumps the current database, zips it, and downloads a file named with the
application timezone in 12-hour AM/PM form, for example `expressphp_db-2026-09-22-06-58-PM.zip`. Temporary files stay
under private `storage/backups/` and are deleted after the response. The permission is `database-backups.download`.

## Email

Configure generic SMTP or Gmail in the `mail` array in `config/app.php`. For Gmail:

```php
'mail' => [
    'enabled' => true,
    'default' => 'gmail',
    'from_address' => 'your-account@gmail.com',
    'from_name' => 'ExpressPHP',
    'timeout' => 10,
    'mailers' => [
        'gmail' => [
            'host' => 'smtp.gmail.com',
            'port' => 587,
            'encryption' => 'tls',
            'username' => 'your-account@gmail.com',
            'password' => '', // Your Gmail app password.
        ],
    ],
],
```

```text
POST /api/v1/emails/send
GET  /api/v1/emails?date=2026-08-25&limit=20&offset=0
GET  /api/v1/emails/{id}
```

Email bodies and credentials are never stored in `email_logs`.

## File uploads

```php
use ExpressPHP\Storage\FileUploader;

public function __construct(
    private readonly FileUploader $uploads = new FileUploader(),
) {
}

$uploaded = $this->uploads->upload($request->file('file'), 'documents');
```

Global restrictions live in `config/app.php`. Files are stored under the private `storage/uploads/` directory.

## Commands

```bash
php cli/migrate.php status
php cli/migrate.php migrate
php cli/migrate.php rollback

php cli/rate-limit status 192.0.2.10
php cli/rate-limit block 192.0.2.10
php cli/rate-limit clear 192.0.2.10
php cli/rate-limit blocked
```

## Production checklist

- Set `env` to `'production'` and `debug` to `false`; debug dumps follow this switch
- Keep a unique `jwt.secret` generated from at least 32 random bytes (also required locally)
- Upload the project as the subdomain document root; `/api/v1/...` is served by root `index.php`
- Use a restricted database account
- Configure exact origins in `cors.origins` (never `*`) and trusted proxies
- Enable HTTPS
- Keep `config/`, `storage/`, and deployed secrets private and out of public downloads
- Restrict `config/app.php` permissions to the owner and the PHP service account
- Restrict `database-backups.download` to trusted administrators
- Configure PHP request and upload limits
- Configure SMTP sender authentication
- Monitor health checks and logs

## License

ExpressPHP is open-source software licensed under the [MIT License](LICENSE).
