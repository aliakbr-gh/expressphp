<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ActivityLog;
use ExpressPHP\Http\Request;
use ExpressPHP\Http\Response;
use ExpressPHP\Http\Pagination;

final class ActivityLogController
{
    public function __construct(private readonly ActivityLog $activities = new ActivityLog())
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
        $date = $data['date'] ?? null;

        return $response->success(
            Pagination::payload(
                $this->activities->latest($limit, $offset, $date),
                $this->activities->countForDate($date),
                $limit,
                $offset,
            ),
            'Activity logs loaded',
        );
    }

    public function show(Request $request, Response $response): Response
    {
        $params = $request->validateParams([
            'id' => 'required|integer|min:1',
        ]);
        $activity = $this->activities->find($params['id']);

        return $activity === null
            ? $response->error('Activity log not found', 404)
            : $response->success($activity, 'Activity log loaded');
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $request->validate([
            'description' => 'required|string|min:2|max:1000',
        ]);

        $activity = $this->activities->record($request, $data['description']);

        return $response->success($activity, 'Activity log created', 201);
    }
}
