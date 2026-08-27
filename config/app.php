<?php

declare(strict_types=1);

$environment = getenv('APP_ENV') ?: 'development';
$debugEnvironment = getenv('APP_DEBUG');
$debugEnabled = $debugEnvironment !== false
    && filter_var($debugEnvironment, FILTER_VALIDATE_BOOL);
$jwtSecret = getenv('JWT_SECRET') ?: 'expressphp-development-secret-change-this-before-production-2026';
$corsOrigins = array_values(array_filter(array_map(
    'trim',
    explode(',', (string)(getenv('CORS_ORIGINS') ?: '*')),
)));

if ($environment === 'production') {
    $required = ['JWT_SECRET', 'DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD'];
    $missing = array_values(array_filter(
        $required,
        static fn(string $name): bool => getenv($name) === false || trim((string)getenv($name)) === '',
    ));
    if ($missing !== []) {
        throw new RuntimeException('Missing required production configuration: ' . implode(', ', $missing));
    }
    if ($debugEnabled) {
        throw new RuntimeException('APP_DEBUG must be false in production.');
    }
    if (
        $jwtSecret === 'expressphp-development-secret-change-this-before-production-2026'
        || strlen($jwtSecret) < 32
    ) {
        throw new RuntimeException('JWT_SECRET must contain at least 32 non-default bytes in production.');
    }
    if ($corsOrigins === [] || in_array('*', $corsOrigins, true)) {
        throw new RuntimeException('CORS_ORIGINS must list explicit origins in production.');
    }
}

return [
    'name' => 'ExpressPHP',
    'timezone' => getenv('APP_TIMEZONE') ?: 'Asia/Karachi',
    'env' => $environment,
    'debug' => $debugEnabled,
    'base_path' => '',

    'debug_dump' => [
        'enabled' => $debugEnabled,
        'status' => 500,
        'max_depth' => 6,
        'show_headers' => true,
    ],

    'logging' => [
        'enabled' => true,
        'path' => dirname(__DIR__) . '/storage/logs',
        // Request bodies larger than this are not copied into the log.
        'max_input_bytes' => 4096,
    ],

    'rate_limiter' => [
        'enabled' => true,
        'max_requests' => 10,
        'window_seconds' => 1,
        'pause_minutes' => 5,
        'max_violations' => 3,
        'block_minutes' => 30,
        'violation_decay_minutes' => 60,
        'path' => dirname(__DIR__) . '/storage/rate-limiter',
        'except' => [
            '/api/v1/health/server',
        ],
        'fail_closed' => [
            '/api/v1/auth/login',
            '/api/v1/auth/register',
        ],
    ],

    'jwt' => [
        // Always set JWT_SECRET to a long random value in production.
        'secret' => $jwtSecret,
        'issuer' => getenv('JWT_ISSUER') ?: 'expressphp',
        'audience' => getenv('JWT_AUDIENCE') ?: 'expressphp-api',
        'ttl' => (int)(getenv('JWT_TTL') ?: 3600),
        'leeway' => (int)(getenv('JWT_LEEWAY') ?: 5),
    ],

    'password' => [
        'bcrypt_cost' => (int)(getenv('PASSWORD_BCRYPT_COST') ?: 10),
    ],

    'mail' => [
        'enabled' => filter_var(getenv('MAIL_ENABLED') ?: false, FILTER_VALIDATE_BOOL),
        'default' => getenv('MAIL_MAILER') ?: 'smtp',
        'from_address' => getenv('MAIL_FROM_ADDRESS') ?: '',
        'from_name' => getenv('MAIL_FROM_NAME') ?: 'ExpressPHP',
        'timeout' => (int)(getenv('MAIL_TIMEOUT') ?: 10),
        'mailers' => [
            'smtp' => [
                'host' => getenv('SMTP_HOST') ?: '',
                'port' => (int)(getenv('SMTP_PORT') ?: 587),
                'encryption' => getenv('SMTP_ENCRYPTION') ?: 'tls',
                'username' => getenv('SMTP_USERNAME') ?: '',
                'password' => getenv('SMTP_PASSWORD') ?: '',
            ],
            'gmail' => [
                'host' => 'smtp.gmail.com',
                'port' => 587,
                'encryption' => 'tls',
                'username' => getenv('GMAIL_USERNAME') ?: '',
                'password' => getenv('GMAIL_APP_PASSWORD') ?: '',
            ],
        ],
    ],

    'uploads' => [
        'path' => dirname(__DIR__) . '/storage/uploads',
        'max_size' => (int)(getenv('UPLOAD_MAX_SIZE') ?: 10 * 1024 * 1024),
        'allowed_extensions' => [
            'jpg', 'jpeg', 'png', 'gif', 'webp',
            'pdf', 'txt', 'csv', 'doc', 'docx', 'xls', 'xlsx', 'zip',
        ],
        'mime_types_by_extension' => [
            'jpg' => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'png' => ['image/png'],
            'gif' => ['image/gif'],
            'webp' => ['image/webp'],
            'pdf' => ['application/pdf'],
            'txt' => ['text/plain'],
            'csv' => ['text/plain', 'text/csv', 'application/csv'],
            'doc' => ['application/msword'],
            'docx' => [
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/zip',
            ],
            'xls' => ['application/vnd.ms-excel'],
            'xlsx' => [
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/zip',
            ],
            'zip' => ['application/zip', 'application/x-zip-compressed'],
        ],
    ],

    'databases' => [
        'default' => getenv('DB_CONNECTION') ?: 'mysql',
        'connections' => [
            'mysql' => [
                'driver' => 'mysql',
                'host' => getenv('DB_HOST') ?: '127.0.0.1',
                'port' => (int)(getenv('DB_PORT') ?: 3305),
                'database' => getenv('DB_DATABASE') ?: 'expressphp_db',
                'username' => getenv('DB_USERNAME') ?: 'root',
                'password' => getenv('DB_PASSWORD') ?: 'root',
                'charset' => 'utf8mb4',
            ],
            // Add named connections here, then call Database::connection('reporting').
            // 'reporting' => [
            //     'driver' => 'mysql',
            //     'host' => getenv('REPORTING_DB_HOST') ?: '127.0.0.1',
            //     'port' => (int) (getenv('REPORTING_DB_PORT') ?: 3306),
            //     'database' => getenv('REPORTING_DB_DATABASE') ?: 'reporting',
            //     'username' => getenv('REPORTING_DB_USERNAME') ?: 'root',
            //     'password' => getenv('REPORTING_DB_PASSWORD') ?: '',
            //     'charset' => 'utf8mb4',
            // ],
        ],
    ],

    // Only trust forwarding headers when REMOTE_ADDR is in this list.
    'trusted_proxies' => [],

    // Middleware aliases used in routes, for example: ['auth', 'permission:users.view'].
    'middleware' => [
        'auth' => App\Middlewares\AuthMiddleware::class,
        'role' => App\Middlewares\RoleMiddleware::class,
        'permission' => App\Middlewares\PermissionMiddleware::class,
    ],

    'cors' => [
        'enabled' => true,
        'origins' => $corsOrigins,
        'methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
        'headers' => ['Accept', 'Authorization', 'Content-Type', 'Origin', 'X-Requested-With'],
        'expose_headers' => [
            'Retry-After',
            'X-RateLimit-Limit',
            'X-RateLimit-Remaining',
            'X-RateLimit-Reset',
        ],
        'credentials' => false,
        'max_age' => 86400,
    ],
];
