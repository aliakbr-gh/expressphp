# AGENTS.md

## Purpose

ExpressPHP is a zero-dependency PHP micro-framework and REST API starter kit. Keep changes explicit, readable, secure, and suitable for production.

## Architecture

- `app/` contains application code under `App\`.
- `src/` contains reusable framework code under `ExpressPHP\`.
- Controllers handle HTTP input and responses.
- Models own database access and domain queries.
- Middleware handles authentication and authorization.
- Routes belong in `routes/api.php`.
- CLI tools belong in `cli/`.
- Runtime data belongs under `storage/` and must remain private.

Do not move application behavior into `src/`. Do not add empty base classes or static model facades without a concrete need.

## Dependencies

This project intentionally has no Composer dependencies. Prefer core PHP and existing utilities. Do not add packages, `vendor/`, or generated dependency files unless explicitly requested.

## PHP conventions

- Support PHP 8.2 or newer.
- Start PHP files with `declare(strict_types=1);`.
- Match namespaces to directory paths.
- Use one class per file.
- Prefer `final` unless extension is intentional.
- Use typed properties, parameters, and return values.
- Use constructor-injected instance dependencies.
- Keep methods focused and names descriptive.
- Use PDO prepared statements for dynamic values.
- Never expose credentials, tokens, absolute paths, or internal exceptions.
- Add comments only for non-obvious decisions.

## HTTP conventions

- Validate input with `validate()` or `validateParams()`.
- Reject unknown request fields.
- Use `Response::success()` and `Response::error()`.
- Use HTTP status codes consistently.
- Protect private routes with `auth` and the narrowest permission.
- Paginated collections use `limit` and `offset`.
- Date filters use `date=Y-m-d`.
- Never return password hashes or sensitive request data.

## Database changes

- The consolidated schema is `migrations/2026_08_25_000001_init_project.php`.
- Keep `seeders/DatabaseSeeder.php` permissions synchronized with it.
- Preserve foreign-key creation and drop order.
- Do not drop or roll back existing data without explicit approval.
- Seeders must remain safe to run repeatedly.

## Security

- Treat authentication, uploads, email, logs, and rate limiting as security-sensitive.
- Keep `.env`, `storage/`, logs, uploads, and limiter state inaccessible through Apache.
- Require a unique `JWT_SECRET` of at least 32 bytes in every environment.
- Redact secrets in logs.
- Validate uploaded file extension and detected MIME type.
- Generate stored filenames and reject client paths.
- Do not weaken CORS, JWT, RBAC, upload, or SQL protections for convenience.

## Verification

Lint changed PHP files:

```bash
php -l path/to/file.php
```

Lint all project PHP:

```bash
find app cli src config migrations public routes seeders -name '*.php' -print0 | xargs -0 -n1 php -l
```

Validate the test client:

```bash
node --check tests/app.js
```

Use `http://localhost/expressphp/tests/` for end-to-end checks. Do not send real emails or mutate external systems during tests without explicit authorization.

PhpStorm must be closed before formatting:

```bash
./cli/format
./cli/format --check
```

## Working practices

- Preserve unrelated user changes.
- Inspect existing patterns before editing.
- Update routes, permissions, migration, seeder, environment example, tests, and README together when applicable.
- Keep documentation concise and accurate.
- Never commit runtime files from `storage/`.
- Avoid destructive Git and database commands.
