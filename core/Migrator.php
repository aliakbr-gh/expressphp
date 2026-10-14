<?php

declare(strict_types=1);

namespace Core;

use RuntimeException;
use Throwable;

/**
 * Runs database/migrations/*.php in filename order and records each one in the `migrations` table.
 *
 * A migration file returns an object with up() and down():
 *
 *   return new class {
 *       public function up(): void   { Database::execute('CREATE TABLE ...'); }
 *       public function down(): void { Database::execute('DROP TABLE IF EXISTS ...'); }
 *   };
 *
 * Note: MySQL commits DDL (CREATE/ALTER/DROP) immediately, so a migration can't be rolled back
 * automatically if it fails halfway. Keep each migration small — ideally one table/change.
 */
final class Migrator
{
    /** @var callable(string): void */
    private $output;

    /**
     * @param string $path Folder containing migration files
     * @param string $table Table that records which migrations have run
     * @param callable|null $output Receives progress lines (defaults to echo)
     */
    public function __construct(
        private readonly string $path,
        private readonly string $table = 'migrations',
        ?callable $output = null,
    ) {
        $this->output = $output ?? static function (string $line): void {
            echo $line, PHP_EOL;
        };
    }

    /** Runs all pending migrations as one batch. Returns the names that ran. */
    public function migrate(): array
    {
        return $this->locked(function (): array {
            $this->ensureTable();

            $pending = array_diff(array_keys($this->files()), $this->ran());
            if ($pending === []) {
                $this->say('Nothing to migrate.');
                return [];
            }

            $batch = $this->lastBatch() + 1;
            foreach ($pending as $name) {
                $this->run($name, 'up');
                Database::execute("INSERT INTO `{$this->table}` (migration, batch) VALUES (?, ?)", [$name, $batch]);
            }

            return array_values($pending);
        });
    }

    /** Rolls back the last $steps batches. Returns the names that were reverted. */
    public function rollback(int $steps = 1): array
    {
        return $this->locked(function () use ($steps): array {
            $this->ensureTable();

            $rows = Database::fetchAll(
                "SELECT migration FROM `{$this->table}`
                 WHERE batch > (SELECT COALESCE(MAX(batch), 0) - ? FROM (SELECT batch FROM `{$this->table}`) AS b)
                 ORDER BY batch DESC, id DESC",
                [max(1, $steps)]
            );

            if ($rows === []) {
                $this->say('Nothing to roll back.');
                return [];
            }

            $reverted = [];
            foreach ($rows as $row) {
                $this->run($row['migration'], 'down');
                Database::execute("DELETE FROM `{$this->table}` WHERE migration = ?", [$row['migration']]);
                $reverted[] = $row['migration'];
            }

            return $reverted;
        });
    }

    /** Rolls back every migration, then runs them all again. */
    public function refresh(): array
    {
        $this->rollback(PHP_INT_MAX);
        return $this->migrate();
    }

    /** Drops every table in the database, then runs all migrations. Destroys all data. */
    public function fresh(): array
    {
        $this->locked(function (): void {
            $tables = Database::fetchAll("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
            Database::execute('SET FOREIGN_KEY_CHECKS = 0');
            try {
                foreach ($tables as $row) {
                    $table = reset($row);
                    Database::execute("DROP TABLE IF EXISTS `{$table}`");
                    $this->say("Dropped   {$table}");
                }
            } finally {
                Database::execute('SET FOREIGN_KEY_CHECKS = 1');
            }
        });

        return $this->migrate();
    }

    /** @return array<int, array{migration: string, ran: bool, batch: ?int}> */
    public function status(): array
    {
        $this->ensureTable();
        $batches = array_column(Database::fetchAll("SELECT migration, batch FROM `{$this->table}`"), 'batch', 'migration');

        $status = [];
        foreach (array_keys($this->files()) as $name) {
            $status[] = ['migration' => $name, 'ran' => isset($batches[$name]), 'batch' => $batches[$name] ?? null];
        }
        // Recorded migrations whose file was deleted.
        foreach (array_diff(array_keys($batches), array_keys($this->files())) as $name) {
            $status[] = ['migration' => $name . ' (file missing)', 'ran' => true, 'batch' => $batches[$name]];
        }

        return $status;
    }

    /** Creates a new migration file from a name like "create_posts_table". Returns its path. */
    public function make(string $name): string
    {
        $name = strtolower(trim(preg_replace('/[^A-Za-z0-9]+/', '_', $name), '_'));
        if ($name === '') {
            throw new RuntimeException('Migration name is required, e.g. create_posts_table');
        }

        $table = preg_match('/^create_(\w+)_table$/', $name, $m) ? $m[1] : 'table_name';
        $file  = rtrim($this->path, '/') . '/' . date('Y_m_d_His') . "_{$name}.php";

        if (!is_dir($this->path)) {
            mkdir($this->path, 0775, true);
        }

        $stub = <<<PHP
        <?php

        declare(strict_types=1);

        use Core\\Database;

        return new class {
            public function up(): void
            {
                Database::execute("
                    CREATE TABLE `{$table}` (
                        `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                        `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        PRIMARY KEY (`id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            }

            public function down(): void
            {
                Database::execute('DROP TABLE IF EXISTS `{$table}`');
            }
        };

        PHP;

        file_put_contents($file, $stub);
        return $file;
    }

    // ------------------------------------------------------------------ Internals

    /** Loads a migration file and calls its up() or down() method, timing it. */
    private function run(string $name, string $direction): void
    {
        $file = $this->files()[$name] ?? null;
        if ($file === null) {
            throw new RuntimeException("Migration file not found: {$name}");
        }

        $migration = require $file;
        if (!is_object($migration) || !method_exists($migration, $direction)) {
            throw new RuntimeException("{$name} must return an object with up() and down() methods.");
        }

        $start = microtime(true);
        try {
            $migration->{$direction}();
        } catch (Throwable $e) {
            throw new RuntimeException("Migration {$name} ({$direction}) failed: " . $e->getMessage(), 0, $e);
        }

        $label = $direction === 'up' ? 'Migrated ' : 'Reverted ';
        $this->say(sprintf('%s %s (%.1fms)', $label, $name, (microtime(true) - $start) * 1000));
    }

    /** @return array<string, string> name => path, sorted by name (timestamp prefix = run order) */
    private function files(): array
    {
        $files = [];
        foreach (glob(rtrim($this->path, '/') . '/*.php') ?: [] as $file) {
            $files[basename($file, '.php')] = $file;
        }
        ksort($files);
        return $files;
    }

    /** Names of migrations already recorded in the migrations table, in run order. */
    private function ran(): array
    {
        return array_column(Database::fetchAll("SELECT migration FROM `{$this->table}` ORDER BY id"), 'migration');
    }

    /** Highest batch number so far (0 when nothing has run). */
    private function lastBatch(): int
    {
        return (int) (Database::fetch("SELECT MAX(batch) AS b FROM `{$this->table}`")['b'] ?? 0);
    }

    /** Creates the migrations tracking table if it does not exist yet. */
    private function ensureTable(): void
    {
        Database::execute("
            CREATE TABLE IF NOT EXISTS `{$this->table}` (
                `id`        INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `migration` VARCHAR(255) NOT NULL,
                `batch`     INT UNSIGNED NOT NULL,
                `ran_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `migrations_migration_unique` (`migration`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /** Stops two deploys from migrating at the same time. */
    private function locked(callable $callback): mixed
    {
        $lock = 'migrate:' . Config::get('database.name') . ':' . $this->table;
        if ((int) Database::fetch('SELECT GET_LOCK(?, 10) AS l', [$lock])['l'] !== 1) {
            throw new RuntimeException('Another migration is already running.');
        }

        try {
            return $callback();
        } finally {
            Database::fetch('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    /** Sends a progress line to the output callback. */
    private function say(string $line): void
    {
        ($this->output)($line);
    }
}
