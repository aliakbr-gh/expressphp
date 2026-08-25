<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Models\Permission;
use App\Models\Role;
use App\Models\ActivityLog;
use ExpressPHP\Http\Request;
use ExpressPHP\Http\Response;
use ExpressPHP\Http\Pagination;
use RuntimeException;

final class RoleController
{
    public function __construct(
        private readonly Role        $roles = new Role(),
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
        $roles = array_map(
            fn(array $role): array => $this->roles->details($role['id']) ?? $role,
            $this->roles->paginate($limit, $offset),
        );

        return $response->success(
            Pagination::payload($roles, $this->roles->count(), $limit, $offset),
            'Roles loaded',
        );
    }

    public function show(Request $request, Response $response): Response
    {
        $id = $this->id($request);
        $role = $this->roles->details($id);
        return $role === null
            ? $response->error('Role not found', 404)
            : $response->success($role, 'Role loaded');
    }

    public function store(Request $request, Response $response): Response
    {
        $data = $request->validate([
            'name' => 'required|string|min:2|max:100',
            'slug' => 'required|string|min:2|max:100|regex:/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/|unique:roles,slug',
        ]);

        $role = $this->roles->create($data);
        $this->activities->record(
            $request,
            $this->userName($request) . ' created new role "' . $role['name'] . '"',
        );
        return $response->success($role, 'Role created', 201);
    }

    public function update(Request $request, Response $response): Response
    {
        $id = $this->id($request);
        $role = $this->roles->find($id);
        if ($role === null) {
            return $response->error('Role not found', 404);
        }

        $data = $request->validate([
            'name' => 'optional|string|min:2|max:100',
            'slug' => 'optional|string|min:2|max:100|regex:/^[a-z0-9]+(?:[._-][a-z0-9]+)*$/',
        ]);
        if (
            in_array($role['slug'], ['super-admin', 'user'], true)
            && isset($data['slug'])
            && $data['slug'] !== $role['slug']
        ) {
            return $response->error('System role slugs cannot be changed', 409);
        }
        if (isset($data['slug'])) {
            $existing = $this->roles->findBySlug($data['slug']);
            if ($existing !== null && (int)$existing['id'] !== $id) {
                return $response->error('Role slug has already been taken', 409);
            }
        }

        $this->roles->update($id, $data);
        $updated = $this->roles->details($id);
        $this->activities->record(
            $request,
            $this->userName($request) . ' updated role "' . $updated['name'] . '"',
        );
        return $response->success($updated, 'Role updated');
    }

    public function destroy(Request $request, Response $response): Response
    {
        $id = $this->id($request);
        $role = $this->roles->find($id);
        if ($role === null) {
            return $response->error('Role not found', 404);
        }
        if (in_array($role['slug'], ['super-admin', 'user'], true)) {
            return $response->error('System roles cannot be deleted', 409);
        }
        if ($this->roles->usersCount($id) > 0) {
            return $response->error('Role is assigned to one or more users', 409);
        }

        $this->roles->delete($id);
        $this->activities->record(
            $request,
            $this->userName($request) . ' deleted role "' . $role['name'] . '"',
        );
        return $response->success(null, 'Role deleted');
    }

    public function permissions(Request $request, Response $response): Response
    {
        $params = $request->validateParams([
            'id' => 'required|integer|min:1',
            'limit' => 'optional|integer|min:1|max:100',
            'offset' => 'optional|integer|min:0',
        ]);
        $id = $params['id'];
        if ($this->roles->find($id) === null) {
            return $response->error('Role not found', 404);
        }

        $limit = $params['limit'] ?? 20;
        $offset = $params['offset'] ?? 0;
        return $response->success(
            Pagination::payload(
                $this->roles->permissions($id, $limit, $offset),
                $this->roles->permissionsCount($id),
                $limit,
                $offset,
            ),
            'Role permissions loaded',
        );
    }

    public function syncPermissions(Request $request, Response $response): Response
    {
        $id = $this->id($request);
        if ($this->roles->find($id) === null) {
            return $response->error('Role not found', 404);
        }

        $data = $request->validate(['permission_ids' => 'required|array']);
        try {
            $permissionIds = $this->ids($data['permission_ids']);
            $permissions = $this->roles->syncPermissions($id, $permissionIds);
        } catch (RuntimeException $exception) {
            return $response->error($exception->getMessage(), 422);
        }

        $role = $this->roles->find($id);
        $this->activities->record(
            $request,
            $this->userName($request) . ' synchronized permissions for role "' . $role['name'] . '"',
        );

        return $response->success($permissions, 'Role permissions synchronized');
    }

    public function attachPermission(Request $request, Response $response): Response
    {
        [$roleId, $permissionId] = $this->relationIds($request);
        if ($this->roles->find($roleId) === null || $this->permissions->find($permissionId) === null) {
            return $response->error('Role or permission not found', 404);
        }

        $this->roles->attachPermission($roleId, $permissionId);
        $role = $this->roles->find($roleId);
        $permission = $this->permissions->find($permissionId);
        $this->activities->record(
            $request,
            $this->userName($request) . ' assigned permission "' . $permission['name'] . '" to role "' . $role['name'] . '"',
        );
        return $response->success($this->roles->permissions($roleId), 'Permission assigned to role');
    }

    public function detachPermission(Request $request, Response $response): Response
    {
        [$roleId, $permissionId] = $this->relationIds($request);
        if ($this->roles->find($roleId) === null || $this->permissions->find($permissionId) === null) {
            return $response->error('Role or permission not found', 404);
        }

        $this->roles->detachPermission($roleId, $permissionId);
        $role = $this->roles->find($roleId);
        $permission = $this->permissions->find($permissionId);
        $this->activities->record(
            $request,
            $this->userName($request) . ' removed permission "' . $permission['name'] . '" from role "' . $role['name'] . '"',
        );
        return $response->success($this->roles->permissions($roleId), 'Permission removed from role');
    }

    private function id(Request $request): int
    {
        return $request->validateParams(['id' => 'required|integer|min:1'])['id'];
    }

    private function relationIds(Request $request): array
    {
        $params = $request->validateParams([
            'id' => 'required|integer|min:1',
            'permissionId' => 'required|integer|min:1',
        ]);
        return [$params['id'], $params['permissionId']];
    }

    private function ids(array $values): array
    {
        $ids = [];
        foreach ($values as $value) {
            if (!(is_int($value) || (is_string($value) && ctype_digit($value))) || (int)$value < 1) {
                throw new RuntimeException('Every permission id must be a positive integer.');
            }
            $ids[] = (int)$value;
        }
        return array_values(array_unique($ids));
    }

    private function userName(Request $request): string
    {
        $user = $request->user();
        return (string)($user['name'] ?? $user['username'] ?? 'System');
    }
}
