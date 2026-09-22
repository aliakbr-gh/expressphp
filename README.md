# ExpressPHP

ExpressPHP is a zero-dependency PHP micro-framework and starter kit for building secure, production-ready REST APIs with
MySQL.

It uses core PHP and PDO. There is no Composer or `vendor/` directory.

## Features

- Versioned routing and middleware
- JWT authentication and token invalidation
- Roles and permissions
- Validation and pagination
- PDO models, migrations, and seeders
- Rate limiting and permanent IP blocking
- Activity logs and daily request logs
- Server and database health checks
- SMTP and Gmail email
- Secure file uploads
- CORS and trusted proxies
- Raw HTML and Fetch API test console

## Requirements

- PHP 8.2 or newer
- MySQL 8 or MariaDB
- Apache with `mod_rewrite`
- PDO MySQL, OpenSSL, JSON, and Fileinfo PHP extensions

## Installation

```bash
cp .env.example .env
```

Create a database, update the `DB_*` values in `.env`, and set a strong `JWT_SECRET`. Then run:

```bash
php migrate.php migrate
php seed.php
```

The development seeder creates:

```text
Username: akbar
Password: akbar123
Role: admin
```

Change this password outside local development.

With MAMP and this project under `htdocs/expressphp`, open:

```text
http://localhost/expressphp/api/v1/health/server
http://localhost/expressphp/tests/
```

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
└── Validation/
config/
migrations/
routes/
seeders/
storage/
tests/
```

Application classes use the `App\` namespace. Reusable framework classes use `ExpressPHP\`.

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
php make controller Product
php make model Product
php make middleware ProductAccess
php make migration create_products_table
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

Block and clear accept `{"ip":"192.0.2.10"}`. These match the `php rate-limit` commands and require `rate-limits.view`,
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

Configure generic SMTP or Gmail in `.env`:

```dotenv
MAIL_ENABLED=true
MAIL_MAILER=gmail
MAIL_FROM_ADDRESS=your-account@gmail.com
MAIL_FROM_NAME=ExpressPHP
GMAIL_USERNAME=your-account@gmail.com
GMAIL_APP_PASSWORD=your-app-password
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
php migrate.php status
php migrate.php migrate
php migrate.php rollback
php seed.php

php rate-limit status 192.0.2.10
php rate-limit block 192.0.2.10
php rate-limit clear 192.0.2.10
php rate-limit blocked
```

Format the project with PhpStorm closed:

```bash
./format
./format --check
```

## Production checklist

- Set `APP_ENV=production` and `APP_DEBUG=false`
- Use a long random `JWT_SECRET`
- Use a restricted database account
- Change or remove seeded credentials
- Configure exact comma-separated `CORS_ORIGINS` and trusted proxies
- Enable HTTPS
- Keep `.env` and `storage/` private
- Restrict `database-backups.download` to trusted administrators
- Configure PHP request and upload limits
- Configure SMTP sender authentication
- Monitor health checks and logs

## License

ExpressPHP is open-source software licensed under the [MIT License](LICENSE).
