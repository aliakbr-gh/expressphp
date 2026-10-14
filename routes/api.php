<?php

declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\FileController;
use App\Controllers\HealthController;
use App\Middlewares\AuthMiddleware;
use Core\Router;

$router->group('api/v1', function (Router $router) {
    $router->group('health', function (Router $router) {
        $router->get('/server', [HealthController::class, 'server']);
        $router->get('/database', [HealthController::class, 'database']);
    });

    $router->group('auth', function (Router $router) {
        $router->post('/register', [AuthController::class, 'register']);
        $router->post('/login', [AuthController::class, 'login']);
        $router->get('/me', [AuthController::class, 'me'], [AuthMiddleware::class]);
    });

    $router->group('files', function (Router $router) {
        $router->post('/upload', [FileController::class, 'upload']);
    }, [AuthMiddleware::class]);
});
