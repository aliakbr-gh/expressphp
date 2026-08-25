<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\ActivityLog;
use App\Models\Permission;
use ExpressPHP\Http\Request;
use ExpressPHP\Http\Response;
use ExpressPHP\Http\Pagination;

final class PermissionController
{
    public function __construct(
        private readonly Permission  $permissions = new Permission(),
        private readonly ActivityLog $activities = new ActivityLog(),
    ) {
    }

    public function index(Request $request, Response $response): Response
    {
        $data = $request->validate([
            'limit' => 'optional|integer|min:1|max:100',
            'offset' => 'optional|integer|min:0',
        ]);
        $limit = $data['limit'] ?? 20;
        $offset = $data['offset'] ?? 0;

        return $response->success(
            Pagination::payload(
                $this->permissions->paginate($limit, $offset),
                $this->permissions->count(),
                $limit,
                $offset,
            ),
            'Permissions loaded',
        );
    }

    public function show(Request $request, Response $response): Response
    {
        $id = $this->id($request);
        $permission = $this->permissions->find($id);
        if ($permission === null) {
            return $response->error('Permission not found', 404);
        }

        $permission['roles'] = $this->permissions->roles($id);
        return $response->success($permission, 'Permission loaded');
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $request->validate([
            'name' => 'required|string|min:2|max:120',
            'slug' => 'required|string|min:2|max:120|regex:/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/|unique:permissions,slug',
        ]);

        $permission = $this->permissions->create($data);
        $this->activities->record(
            $request,
            $this->userName($request) . ' created new permission "' . $permission['name'] . '"',
        );
        return $response->success($permission, 'Permission created', 201);
    }

    public function update(Request $request, Response $response): Response
    {
        $id = $this->id($request);
        $permission = $this->permissions->find($id);
        if ($permission === null) {
            return $response->error('Permission not found', 404);
        }

        $data = $request->validate([
            'name' => 'optional|string|min:2|max:120',
            'slug' => 'optional|string|min:2|max:120|regex:/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/',
        ]);
        if (isset($data['slug'])) {
            $existing = $this->permissions->findBySlug($data['slug']);
            if ($existing !== null && (int)$existing['id'] !== $id) {
                return $response->error('Permission slug has already been taken', 409);
            }
        }

        $this->permissions->update($id, $data);
        $updated = $this->permissions->find($id);
        $this->activities->record(
            $request,
            $this->userName($request) . ' updated permission "' . $updated['name'] . '"',
        );
        return $response->success($updated, 'Permission updated');
    }

    public function destroy(Request $request, Response $response): Response
    {
        $id = $this->id($request);
        $permission = $this->permissions->find($id);
        if ($permission === null) {
            return $response->error('Permission not found', 404);
        }

        $this->permissions->delete($id);
        $this->activities->record(
            $request,
            $this->userName($request) . ' deleted permission "' . $permission['name'] . '"',
        );
        return $response->success(null, 'Permission deleted');
    }

    public function roles(Request $request, Response $response): Response
    {
        $params = $request->validateParams([
            'id' => 'required|integer|min:1',
            'limit' => 'optional|integer|min:1|max:100',
            'offset' => 'optional|integer|min:0',
        ]);
        $id = $params['id'];
        if ($this->permissions->find($id) === null) {
            return $response->error('Permission not found', 404);
        }

        $limit = $params['limit'] ?? 20;
        $offset = $params['offset'] ?? 0;
        return $response->success(
            Pagination::payload(
                $this->permissions->roles($id, $limit, $offset),
                $this->permissions->rolesCount($id),
                $limit,
                $offset,
            ),
            'Permission roles loaded',
        );
    }

    private function id(Request $request): int
    {
        return $request->validateParams(['id' => 'required|integer|min:1'])['id'];
    }

    private function userName(Request $request): string
    {
        $user = $request->user();
        return (string)($user['name'] ?? $user['username'] ?? 'System');
    }
}
