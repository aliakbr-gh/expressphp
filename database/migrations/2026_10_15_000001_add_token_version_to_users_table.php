<?php

declare(strict_types=1);

use Core\Database;

/**
 * Token versioning: every JWT carries the user's token_version ("ver" claim), and AuthMiddleware
 * rejects tokens whose version no longer matches. Incrementing the column (logout, password change)
 * revokes every token issued to that user so far.
 */
return new class {
    public function up(): void
    {
        Database::execute("
            ALTER TABLE `users`
                ADD COLUMN `token_version` INT UNSIGNED NOT NULL DEFAULT 0 AFTER `password`
        ");
    }

    public function down(): void
    {
        Database::execute('ALTER TABLE `users` DROP COLUMN `token_version`');
    }
};
