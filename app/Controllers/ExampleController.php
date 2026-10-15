<?php

declare(strict_types=1);

namespace App\Controllers;

use Core\Request;
use Core\Response;

final class ExampleController
{
    public function show(Request $request, Response $response): Response
    {
        return $response->view('example', [
            'title' => 'Example view',
            'user'  => $request->user(),
            'items' => ['Routing', 'Middleware', 'Views'],
            'time'  => date(DATE_ATOM),
        ]);
    }
}
