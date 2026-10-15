<?php

declare(strict_types=1);

/**
 * Global helper functions, loaded by core/bootstrap.php so they work in every file.
 */

if (!function_exists('e')) {
    /**
     * Escapes a value for safe output in HTML views: <?= e($user['name']) ?>
     * Always use it for data in app/Views/, so user input can't inject HTML or scripts.
     */
    function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

if (!function_exists('dd')) {
    /**
     * "Dump and die" like Laravel: shows the given values and stops the request immediately.
     *
     *   dd($request);                 // one value
     *   dd($request->body, $user);    // several values, each in its own block
     *
     * Over HTTP it returns an HTML page styled like Laravel's dd() (Symfony VarDumper):
     * click ▶ / ▼ to expand or collapse; the first level starts expanded. View it in a
     * browser or Postman's "Preview" tab. In the terminal it falls back to var_dump().
     * Like Laravel's, it shows everything (passwords, tokens, $_SERVER): debugging only,
     * remove dd() calls before deploying.
     */
    function dd(mixed ...$values): never
    {
        $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 1)[0];
        $where  = Core\Log::relative($caller['file'] ?? '?') . ':' . ($caller['line'] ?? '?');

        if (PHP_SAPI === 'cli') {
            echo "dd() at {$where}\n";
            foreach ($values as $value) {
                var_dump($value);
            }
            exit(1);
        }

        $esc  = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $span = static fn(string $color, string $html, string $title = ''): string =>
            '<span style="color:' . $color . '"' . ($title !== '' ? ' title="' . $title . '"' : '') . '>' . $html . '</span>';

        // Symfony VarDumper's dark theme.
        $num = '#1299DA'; $str = '#56DB3A'; $note = '#1299DA'; $ref = '#A0A0A0'; $prop = '#FFFFFF'; $key = '#56DB3A';

        // Turns any value into HTML in Symfony VarDumper's format:
        //   array:2 [▼ "key" => "value" ]     Core\Request {#4 ▼ +public  #protected  -private }
        // $seen holds spl_object_id() of objects being printed (prevents endless loops);
        // $source is the "// file:line" note shown on the first line of the top-level value.
        $render = static function (mixed $value, int $depth, array $seen, string $source = '') use (&$render, $esc, $span, $num, $str, $note, $ref, $prop, $key): string {
            // An expandable block: header, ▼/▶ toggle (CSS checkbox), indented rows, closing bracket.
            // The first level starts expanded, deeper ones collapsed.
            $compound = static function (string $head, string $close, array $rows) use ($depth, $source): string {
                $id     = 'dd' . bin2hex(random_bytes(6));
                $indent = str_repeat('  ', $depth + 1);
                $body   = $indent . implode("\n" . $indent, $rows);

                return $head
                    . '<input type="checkbox" class="dd-t" id="' . $id . '" hidden' . ($depth >= 1 ? ' checked' : '') . '>'
                    . '<label class="dd-a" for="' . $id . '">' . $source . '</label>'
                    . '<samp class="dd-c">' . "\n" . $body . "\n" . str_repeat('  ', $depth) . '</samp>'
                    . $close;
            };

            // Scalars and resources.
            $scalar = match (true) {
                $value === null     => '<b>null</b>',
                is_bool($value)     => '<b>' . ($value ? 'true' : 'false') . '</b>',
                is_int($value), is_float($value) => $span($num, $esc(var_export($value, true))),
                is_string($value)   => '"' . $span($str, $esc($value), strlen($value) . ' characters') . '"',
                is_resource($value) => $span($note, 'resource') . ' @' . get_resource_type($value),
                default             => null,
            };
            if ($scalar !== null) {
                return $scalar . $source;
            }

            // Arrays: array:N [ key => value, ... ]
            if (is_array($value)) {
                if ($value === []) {
                    return '[]' . $source;
                }
                if ($depth >= 10) {
                    return $span($note, 'array:' . count($value)) . ' […]';
                }

                $rows = [];
                foreach ($value as $k => $item) {
                    $label  = is_int($k) ? $span($num, (string) $k) : '"' . $span($key, $esc($k)) . '"';
                    $rows[] = $label . ' => ' . $render($item, $depth + 1, $seen);
                }

                return $compound($span($note, 'array:' . count($value)) . ' [', ']', $rows);
            }

            // Objects: Class {#id +public #protected -private }
            $id   = spl_object_id($value);
            $head = $span($note, $esc($value::class)) . ' {' . $span($ref, '#' . $id);

            if ($value instanceof UnitEnum) {
                return $span($note, $esc($value::class)) . '::' . $span($prop, $esc($value->name)) . $source;
            }
            if ($value instanceof Closure || in_array($id, $seen, true) || $depth >= 10) {
                return $head . ' …}' . $source;
            }

            // An (array) cast exposes every property; protected names start with "\0*\0", private with "\0Class\0".
            $rows = [];
            foreach ((array) $value as $name => $item) {
                $parts = explode("\0", (string) $name);
                $field = end($parts);
                [$sign, $title] = match (true) {
                    count($parts) === 1 => ['+', 'Public property'],
                    $parts[1] === '*'   => ['#', 'Protected property'],
                    default             => ['-', 'Private property defined in class: `' . $esc($parts[1]) . '`'],
                };
                $rows[] = $sign . $span($prop, $esc($field), $title) . ': ' . $render($item, $depth + 1, [...$seen, $id]);
            }

            if ($rows === []) {
                return $head . '}' . $source;
            }

            return $compound($head . ' ', '}', $rows);
        };

        if (!headers_sent()) {
            http_response_code(200);
            header('Content-Type: text/html; charset=utf-8');
        }

        // Inline styles keep the colours even where <style> is ignored; the <style> block adds the toggles.
        $pre    = 'background:#18171B;color:#FF8400;font:14px Menlo,Monaco,Consolas,monospace;line-height:1.4em;'
                . 'padding:5px;margin:0 0 8px;white-space:pre-wrap;word-wrap:break-word;word-break:break-all;';
        $source = '<span style="color:#A0A0A0"> // ' . $esc($where) . '</span>';

        $blocks = '';
        foreach ($values as $value) {
            $blocks .= '<pre class="sf-dump" style="' . $pre . '">' . $render($value, 0, [], $source) . '</pre>';
        }

        echo <<<HTML
        <!doctype html>
        <html lang="en">
        <head>
        <meta charset="utf-8">
        <title>dd()</title>
        <style>
            .dd-t { display: none; }
            .dd-a { cursor: pointer; user-select: none; }
            .dd-a::before { content: "▼"; color: #A0A0A0; }
            .dd-t:checked + .dd-a::before { content: "▶"; }
            .dd-t:checked + .dd-a + .dd-c { display: none; }
            .dd-a:hover::before { color: #FFFFFF; }
        </style>
        </head>
        <body>
        {$blocks}
        </body>
        </html>
        HTML;
        exit;
    }
}
