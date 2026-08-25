<?php

declare(strict_types=1);

use ExpressPHP\Core\Debugger;

if (!function_exists('dd')) {
    function dd(mixed ...$values): never
    {
        Debugger::dump(...$values);
    }
}
