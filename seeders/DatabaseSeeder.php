<?php

declare(strict_types=1);

namespace Seeders;

use ExpressPHP\Auth\Password;
use PDO;
use RuntimeException;
use Throwable;

final class DatabaseSeeder
{
    private const ROLES = [
        ['Admin', 'admin'],
        ['User', 'user'],
    ];

    private const PERMISSIONS = [
        ['View roles', 'roles.view'],
        ['Create roles', 'roles.create'],
        ['Update roles', 'roles.update'],
        ['Delete roles', 'roles.delete'],
        ['Assign role permissions', 'roles.permissions.assign'],
        ['View permissions', 'permissions.view'],
        ['Create permissions', 'permissions.create'],
        ['Update permissions', 'permissions.update'],
        ['Delete permissions', 'permissions.delete'],
        ['View users', 'users.view'],
        ['Create users', 'users.create'],
        ['Update users', 'users.update'],
        ['Delete users', 'users.delete'],
        ['Assign user roles', 'users.roles.assign'],
        ['View activity logs', 'activity-logs.view'],
        ['Create activity logs', 'activity-logs.create'],
        ['View server logs', 'server-logs.view'],
        ['View rate limits', 'rate-limits.view'],
        ['Block IP addresses', 'rate-limits.block'],
        ['Clear rate limits', 'rate-limits.clear'],
        ['View email logs', 'emails.view'],
        ['Send emails', 'emails.send'],
        ['Upload files', 'files.upload'],
        ['Download database backups', 'database-backups.download'],
    ];

    public function run(PDO $database): array
    {
        $database->beginTransaction();

        try {
            $this->seedRoles($database);
            $this->seedPermissions($database);
            $adminRoleId = $this->roleId($database, 'admin');
            $this->grantAllPermissions($database, $adminRoleId);
            $user = $this->seedAdminUser($database, $adminRoleId);
            $permissionCount = (int)$database->query('SELECT COUNT(*) FROM permissions')->fetchColumn();
            $database->commit();

            return [
                'user' => $user,
                'role' => 'admin',
                'permissions' => $permissionCount,
            ];
        } catch (Throwable $exception) {
            if ($database->inTransaction()) {
                $database->rollBack();
            }
            throw $exception;
        }
    }

    private function seedRoles(PDO $database): void
    {
        $statement = $database->prepare(
            'INSERT INTO roles (name, slug) VALUES (:name, :slug) ' .
            'ON DUPLICATE KEY UPDATE name = VALUES(name)'
        );

        foreach (self::ROLES as [$name, $slug]) {
            $statement->execute(['name' => $name, 'slug' => $slug]);
        }
    }

    private function seedPermissions(PDO $database): void
    {
        $statement = $database->prepare(
            'INSERT INTO permissions (name, slug) VALUES (:name, :slug) ' .
            'ON DUPLICATE KEY UPDATE name = VALUES(name)'
        );

        foreach (self::PERMISSIONS as [$name, $slug]) {
            $statement->execute(['name' => $name, 'slug' => $slug]);
        }
    }

    private function grantAllPermissions(PDO $database, int $roleId): void
    {
        $statement = $database->prepare(
            'INSERT IGNORE INTO role_permissions (role_id, permission_id) ' .
            'SELECT :role_id, id FROM permissions'
        );
        $statement->execute(['role_id' => $roleId]);
    }

    private function seedAdminUser(PDO $database, int $roleId): array
    {
        $passwordHash = Password::hash('akbar123');

        $statement = $database->prepare(
            'INSERT INTO users (name, username, password, is_active, session_version, role_id) ' .
            'VALUES (:name, :username, :password, 1, 1, :role_id) ' .
            'ON DUPLICATE KEY UPDATE name = VALUES(name), password = VALUES(password), ' .
            'is_active = 1, session_version = session_version + 1, role_id = VALUES(role_id)'
        );
        $statement->execute([
            'name' => 'Ali Akbar',
            'username' => 'akbar',
            'password' => $passwordHash,
            'role_id' => $roleId,
        ]);

        $user = $database->prepare(
            'SELECT users.id, users.name, users.username, users.is_active, users.session_version, ' .
            'roles.id AS role_id, roles.name AS role_name, roles.slug AS role_slug ' .
            'FROM users INNER JOIN roles ON roles.id = users.role_id ' .
            'WHERE users.username = :username LIMIT 1'
        );
        $user->execute(['username' => 'akbar']);
        $record = $user->fetch();

        if ($record === false) {
            throw new RuntimeException('The admin user could not be seeded.');
        }

        $record['is_active'] = (bool)$record['is_active'];
        return $record;
    }

    private function roleId(PDO $database, string $slug): int
    {
        $statement = $database->prepare('SELECT id FROM roles WHERE slug = :slug LIMIT 1');
        $statement->execute(['slug' => $slug]);
        $id = $statement->fetchColumn();

        if ($id === false) {
            throw new RuntimeException("Role [{$slug}] could not be seeded.");
        }

        return (int)$id;
    }
}
