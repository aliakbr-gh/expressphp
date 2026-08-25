<?php

declare(strict_types=1);

namespace App\Models;

use ExpressPHP\Logging\RequestLogger;

final class ServerLog
{
    public function paginate(string $date, int $limit, int $offset): array
    {
        $lines = $this->lines($date);
        $total = count($lines);
        $items = [];

        foreach (array_slice(array_reverse($lines), max(0, $offset), max(1, $limit)) as $line) {
            $entry = json_decode($line, true);
            $items[] = is_array($entry) ? $entry : ['raw' => $line];
        }

        return ['items' => $items, 'total' => $total];
    }

    public function today(): string
    {
        return RequestLogger::currentDate();
    }

    private function lines(string $date): array
    {
        $file = RequestLogger::directory() . '/' . $date . '.log';
        if (!is_file($file) || !is_readable($file)) {
            return [];
        }

        return file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    }
}
