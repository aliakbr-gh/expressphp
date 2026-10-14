<?php

declare(strict_types=1);

namespace App\Controllers;

use Core\Database;
use Core\Log;
use Core\Request;
use Core\Response;
use Throwable;

final class HealthController
{
    public function server(Request $request, Response $response): Response
    {
        return $response->success([
            'status'    => 'up',
            'timestamp' => date(DATE_ATOM),
        ], 'Server is running.');
    }

    public function database(Request $request, Response $response): Response
    {
        try {
            Database::fetch('SELECT 1');
        } catch (Throwable $e) {
            Log::exception($e);

            return $response->error('Database is unreachable.', 503);
        }

        return $response->success([
            'status'    => 'up',
            'timestamp' => date(DATE_ATOM),
        ], 'Database is connected.');
    }
}
