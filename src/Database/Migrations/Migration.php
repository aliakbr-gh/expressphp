<?php

declare(strict_types=1);

namespace ExpressPHP\Database\Migrations;

use PDO;

abstract class Migration
{
    abstract public function up(PDO $database): void;

    abstract public function down(PDO $database): void;
}
