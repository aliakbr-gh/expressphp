<?php

declare(strict_types=1);

use Core\Database;

/**
 * Users who can register and log in (see AuthController).
 * Run with: php cli migrate   |   undo with: php cli migrate:rollback
 */
return new class {
    /** Creates the table. `username` is unique and case-insensitive (utf8mb4_unicode_ci). */
    public function up(): void
    {
        Database::execute("
            CREATE TABLE `users` (
                `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                `name`       VARCHAR(100) NOT NULL,
                `username`   VARCHAR(50) NOT NULL,
                `password`   VARCHAR(255) NOT NULL,  -- bcrypt hash from password_hash(), never plain text
                `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                PRIMARY KEY (`id`),
                UNIQUE KEY `users_username_unique` (`username`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }

    /** Removes the table and all its rows. */
    public function down(): void
    {
        Database::execute('DROP TABLE IF EXISTS `users`');
    }
};
