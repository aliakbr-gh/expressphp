<?php

declare(strict_types=1);

namespace ExpressPHP\Http;

final class Pagination
{
    public static function payload(array $items, int $total, int $limit, int $offset): array
    {
        $count = count($items);
        $nextOffset = $offset + $count;
        $hasMore = $nextOffset < $total;

        return [
            'items' => $items,
            'pagination' => [
                'total' => $total,
                'count' => $count,
                'limit' => $limit,
                'offset' => $offset,
                'has_more' => $hasMore,
                'next_offset' => $hasMore ? $nextOffset : null,
            ],
        ];
    }
}
