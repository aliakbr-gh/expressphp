<?php

declare(strict_types=1);

/**
 * Central configuration for the whole backend.
 *
 * Every setting lives here (no .env). Access values anywhere via
 * Config::get('database.host') after core/bootstrap.php has been loaded.
 *
 * IMPORTANT: this file contains secrets. It must never be reachable from the
 * web (see config/.htaccess) and should not be committed with real
 * production credentials.
 */

return [

    'app' => [
        'name'     => 'ExpressPHP API',    // shown in health responses and logs
        'env'      => 'development', // development | production
        'debug'    => true,          // set false in production: hides error details, PHP warnings and dd()
        'url'      => 'http://localhost/expressphp', // public base URL of the API, no trailing slash
        'timezone' => 'UTC',         // PHP timezone for date() and log timestamps
        'version'  => '1.0.0',       // your API version

        // IPs of proxies/load balancers allowed to set X-Forwarded-For / X-Forwarded-Proto.
        // Leave empty unless you are behind one (e.g. Cloudflare, nginx). ['*'] trusts all.
        'trusted_proxies' => [],
    ],

    'database' => [
        'driver'    => 'mysql',     // PDO driver; only MySQL/MariaDB is supported
        'host'      => '127.0.0.1', // use the host your provider gives on shared hosting
        'port'      => 3305,
        'name'      => 'expressphp',
        'username'  => 'root',
        'password'  => 'root',
        'charset'   => 'utf8mb4',   // full Unicode, including emoji
        'collation' => 'utf8mb4_unicode_ci',
        'timezone'  => '+00:00',    // session time zone, keep in sync with app.timezone
    ],

    'jwt' => [
        // Signs every token. Keep it secret, at least 32 characters, and different per environment.
        // Changing it logs everyone out. Generate one with: php -r "echo bin2hex(random_bytes(32));"
        'secret' => 'b9c3eaee4ec381247485177a5d7a9a5a8d640425cb92688699d1a19fb6731401',
        'ttl'    => 86400, // token lifetime in seconds (86400 = 24 hours)
    ],

    'filesystem' => [
        'root' => __DIR__ . '/../storage/uploads',   // UploadedFile::store() saves here
    ],

    'rate_limit' => [
        'enabled'      => true,                           // false = no limit at all
        'max_requests' => 60,                             // requests allowed per IP...
        'per_seconds'  => 60,                             // ...in this many seconds
        'path'         => __DIR__ . '/../storage/ratelimit', // one small counter file per IP
    ],

    'logging' => [
        'enabled' => true, // false = write no logs at all
        'path'    => __DIR__ . '/../storage/logs',  // one file per day: app-YYYY-MM-DD.log
    ],

];
