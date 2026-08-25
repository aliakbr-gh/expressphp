<?php

declare(strict_types=1);

namespace ExpressPHP\Database;

use PDO;
use RuntimeException;

abstract class Model
{
    protected string $table;
    protected string $primaryKey = 'id';
    protected ?string $connection = null;
    protected array $fillable = [];

    public function all(): array
    {
        $statement = $this->pdo()->query('SELECT * FROM ' . $this->identifier($this->table));
        return $statement->fetchAll();
    }

    public function paginate(int $limit, int $offset = 0): array
    {
        $sql = sprintf(
            'SELECT * FROM %s ORDER BY %s DESC LIMIT :limit OFFSET :offset',
            $this->identifier($this->table),
            $this->identifier($this->primaryKey),
        );
        $statement = $this->pdo()->prepare($sql);
        $statement->bindValue('limit', max(1, $limit), PDO::PARAM_INT);
        $statement->bindValue('offset', max(0, $offset), PDO::PARAM_INT);
        $statement->execute();
        return $statement->fetchAll();
    }

    public function count(): int
    {
        return (int)$this->pdo()
            ->query('SELECT COUNT(*) FROM ' . $this->identifier($this->table))
            ->fetchColumn();
    }

    public function find(int|string $id): ?array
    {
        return $this->firstWhere($this->primaryKey, $id);
    }

    public function where(string $column, mixed $value): array
    {
        $sql = sprintf(
            'SELECT * FROM %s WHERE %s = :value',
            $this->identifier($this->table),
            $this->identifier($column),
        );
        $statement = $this->pdo()->prepare($sql);
        $statement->execute(['value' => $value]);
        return $statement->fetchAll();
    }

    public function firstWhere(string $column, mixed $value): ?array
    {
        $sql = sprintf(
            'SELECT * FROM %s WHERE %s = :value LIMIT 1',
            $this->identifier($this->table),
            $this->identifier($column),
        );
        $statement = $this->pdo()->prepare($sql);
        $statement->execute(['value' => $value]);
        $row = $statement->fetch();
        return $row === false ? null : $row;
    }

    public function create(array $attributes): array
    {
        $attributes = $this->fillable($attributes);
        if ($attributes === []) {
            throw new RuntimeException('No fillable attributes were provided.');
        }

        $columns = array_keys($attributes);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->identifier($this->table),
            implode(', ', array_map($this->identifier(...), $columns)),
            implode(', ', array_map(static fn(string $column): string => ':' . $column, $columns)),
        );
        $this->pdo()->prepare($sql)->execute($attributes);
        return $this->find((string)$this->pdo()->lastInsertId()) ?? $attributes;
    }

    public function update(int|string $id, array $attributes): bool
    {
        $attributes = $this->fillable($attributes);
        if ($attributes === []) {
            return false;
        }
        $sets = array_map(
            fn(string $column): string => $this->identifier($column) . ' = :' . $column,
            array_keys($attributes),
        );
        $attributes['_primary_key'] = $id;
        $sql = sprintf(
            'UPDATE %s SET %s WHERE %s = :_primary_key',
            $this->identifier($this->table),
            implode(', ', $sets),
            $this->identifier($this->primaryKey),
        );
        return $this->pdo()->prepare($sql)->execute($attributes);
    }

    public function delete(int|string $id): bool
    {
        $sql = sprintf(
            'DELETE FROM %s WHERE %s = :id',
            $this->identifier($this->table),
            $this->identifier($this->primaryKey),
        );
        return $this->pdo()->prepare($sql)->execute(['id' => $id]);
    }

    protected function pdo(): PDO
    {
        return Database::connection($this->connection);
    }

    protected function identifier(string $identifier): string
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $identifier) !== 1) {
            throw new RuntimeException("Unsafe SQL identifier [{$identifier}].");
        }
        return '`' . $identifier . '`';
    }

    private function fillable(array $attributes): array
    {
        return array_intersect_key($attributes, array_flip($this->fillable));
    }
}
