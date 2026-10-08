<?php

declare(strict_types=1);

namespace App\Controllers;

use ExpressPHP\Http\Request;
use ExpressPHP\Http\Response;

final class ExampleController
{
    public function index(Request $request, Response $response): Response
    {
        $request->validate([]);
        $cars = array("Volvo", "BMW", "Toyota");

        return $response->view('example', [
            'title' => 'Hello',
            'message' => 'This view is Rendered from Express PHP controller.',
            'cars' => $cars
        ]);
    }
}
