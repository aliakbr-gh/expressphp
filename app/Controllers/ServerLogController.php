<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ServerLog;
use ExpressPHP\Http\Pagination;
use ExpressPHP\Http\Request;
use ExpressPHP\Http\Response;

final class ServerLogController
{
    public function __construct(private readonly ServerLog $logs = new ServerLog())
    {
    }

    public function index(Request $request, Response $response): Response
    {
        $data = $request->validate([
            'limit' => 'optional|integer|min:1|max:100',
            'offset' => 'optional|integer|min:0',
            'date' => 'optional|date_format:Y-m-d',
        ]);
        $limit = $data['limit'] ?? 20;
        $offset = $data['offset'] ?? 0;
        $date = $data['date'] ?? $this->logs->today();
        $logs = $this->logs->paginate($date, $limit, $offset);

        return $response->success([
            'date' => $date,
            ...Pagination::payload($logs['items'], $logs['total'], $limit, $offset),
        ], 'Server logs loaded');
    }
}
