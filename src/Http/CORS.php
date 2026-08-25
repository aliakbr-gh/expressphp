<?php

declare(strict_types=1);

namespace ExpressPHP\Http;

final class CORS
{
    public function __construct(private readonly array $config = [])
    {
    }

    public function apply(Request $request, Response $response): Response
    {
        if (!(bool)($this->config['enabled'] ?? true)) {
            return $response;
        }

        $origin = $request->header('Origin');
        if ($origin === null || !$this->originAllowed($origin)) {
            return $response;
        }

        $configuredOrigins = $this->config['origins'] ?? ['*'];
        $credentials = (bool)($this->config['credentials'] ?? false);
        $allowOrigin = in_array('*', $configuredOrigins, true) && !$credentials ? '*' : $origin;

        $response->header('Access-Control-Allow-Origin', $allowOrigin);
        $response->appendHeader('Vary', 'Origin');
        $response->header('Access-Control-Allow-Methods', implode(', ', $this->config['methods'] ?? []));
        $response->header('Access-Control-Allow-Headers', implode(', ', $this->config['headers'] ?? []));

        $exposed = $this->config['expose_headers'] ?? [];
        if ($exposed !== []) {
            $response->header('Access-Control-Expose-Headers', implode(', ', $exposed));
        }
        if ($credentials) {
            $response->header('Access-Control-Allow-Credentials', 'true');
        }
        if (isset($this->config['max_age'])) {
            $response->header('Access-Control-Max-Age', (string)$this->config['max_age']);
        }

        return $response;
    }

    private function originAllowed(string $origin): bool
    {
        foreach ($this->config['origins'] ?? ['*'] as $allowed) {
            if ($allowed === '*' || hash_equals((string)$allowed, $origin)) {
                return true;
            }
        }
        return false;
    }
}
