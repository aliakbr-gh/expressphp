<?php

declare(strict_types=1);

namespace Core\Support;

/**
 * Global per-IP rate limiter, stored in small files under storage/ratelimit/.
 *
 * Fixed window: each key may make rate_limit.max_requests requests per rate_limit.per_seconds.
 * The window starts with the key's first request and resets once it has passed.
 *
 *   $wait = RateLimiter::hit($request->ip());   // 0 = allowed, otherwise seconds until allowed again
 *
 * Called once per request from public/index.php. If the storage folder can't be written,
 * requests are allowed rather than blocked, so a permissions problem never takes the API down.
 *
 * IPv6: each address has its own limit, and its /64 network has a shared cap of
 * IPV6_NETWORK_FACTOR times that limit. Only requests an address was allowed to make count
 * towards that cap (see hit()).
 *
 * Files are spread over up to 256 sub-folders (storage/ratelimit/3f/3f9a...), so no single
 * folder grows huge and cleanup only ever scans one small sub-folder.
 */
final class RateLimiter
{
    /** Chance (1 in N requests) of deleting expired counter files. */
    private const CLEANUP_ODDS = 100;

    /**
     * An IPv6 /64 network may send this many times the per-address limit in total.
     * Each address still gets its own normal limit, so unrelated users who share a /64
     * (VPNs, hosting providers, carrier gateways, offices) don't block each other, while
     * rotating through addresses inside one /64 still runs into this shared cap.
     */
    private const IPV6_NETWORK_FACTOR = 10;

    /** Counts one request for $key. Returns 0 when allowed, or the seconds to wait when over the limit. */
    public static function hit(string $key): int
    {
        if (!Config::get('rate_limit.enabled', false)) {
            return 0;
        }

        $max    = max(1, (int) Config::get('rate_limit.max_requests', 60));
        $window = max(1, (int) Config::get('rate_limit.per_seconds', 60));
        $key    = self::normalizeKey($key);

        $network = self::networkKey($key);
        if ($network === null) {
            return self::count($key, $max, $window);
        }

        // IPv6, in three steps:
        // 1. Look at the /64 network's shared counter without counting. If the network is already
        //    at its cap, stop here: the per-address counter isn't touched, so rotating addresses
        //    can't create unlimited files.
        $networkMax = $max * self::IPV6_NETWORK_FACTOR;
        $wait = self::peek($network, $networkMax, $window);
        if ($wait > 0) {
            return $wait;
        }

        // 2. Count the address against its own limit. A blocked address stops here and does NOT
        //    use up the network's budget, so one noisy client can't lock out its /64 neighbours.
        $wait = self::count($key, $max, $window);
        if ($wait > 0) {
            return $wait;
        }

        // 3. Only allowed requests count towards the network's shared cap.
        self::count($network, $networkMax, $window);

        return 0;
    }

    /**
     * Canonical form of a key so every spelling of one address shares a counter:
     *   IPv6 => compressed lower-case form ("2001:DB8:0::1" => "2001:db8::1")
     *   ::ffff:a.b.c.d (IPv4 written as IPv6) => a.b.c.d
     *   IPv4 and non-IP keys are used as they are.
     */
    public static function normalizeKey(string $key): string
    {
        if (!filter_var($key, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return $key;
        }

        $bytes = inet_pton($key);
        if ($bytes === false) {
            return $key;
        }

        // IPv4-mapped IPv6 (::ffff:0:0/96)
        if (str_starts_with($bytes, str_repeat("\0", 10) . "\xff\xff")) {
            return inet_ntop(substr($bytes, 12));
        }

        return inet_ntop($bytes);
    }

    /** The /64 network of an IPv6 key (e.g. "20010db800000000::/64"), or null for anything else. */
    public static function networkKey(string $key): ?string
    {
        if (!filter_var($key, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return null;
        }

        $bytes = inet_pton($key);
        return $bytes === false ? null : bin2hex(substr($bytes, 0, 8)) . '::/64';
    }

    /**
     * Counter file for $key. Hashing keeps file names safe for any key (IPv6 addresses contain ":"),
     * and files are spread over up to 256 sub-folders by the first two hex characters of the hash.
     */
    private static function path(string $key): string
    {
        $hash = sha1($key);
        return rtrim((string) Config::get('rate_limit.path'), '/\\') . '/' . substr($hash, 0, 2) . '/' . $hash;
    }

    /**
     * Read-only check: 0 when $key may make another request, otherwise the seconds until its
     * window ends. Never creates or changes a file.
     */
    private static function peek(string $key, int $max, int $window): int
    {
        $handle = @fopen(self::path($key), 'r');
        if ($handle === false) {
            return 0; // no counter yet
        }

        try {
            flock($handle, LOCK_SH);
            [$start, $count] = array_map('intval', explode(' ', (string) stream_get_contents($handle)) + [0, 0]);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        $now = time();
        if ($now - $start >= $window || $count < $max) {
            return 0;
        }

        return max(1, $start + $window - $now);
    }

    /** Adds one request to $key's counter file and returns 0 (allowed) or the seconds to wait. */
    private static function count(string $key, int $max, int $window): int
    {
        $file = self::path($key);
        $dir  = dirname($file);

        if (!is_dir($dir) && !@mkdir($dir, 0775, true) && !is_dir($dir)) {
            return 0;
        }

        $handle = @fopen($file, 'c+');
        if ($handle === false) {
            return 0;
        }

        try {
            // Exclusive lock so simultaneous requests from the same key can't lose counts.
            flock($handle, LOCK_EX);

            [$start, $count] = array_map('intval', explode(' ', (string) stream_get_contents($handle)) + [0, 0]);

            $now = time();
            if ($now - $start >= $window) {
                $start = $now;
                $count = 0;
            }
            $count++;

            ftruncate($handle, 0);
            rewind($handle);
            fwrite($handle, "{$start} {$count}");

            $wait = $count > $max ? max(1, $start + $window - $now) : 0;
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }

        if (random_int(1, self::CLEANUP_ODDS) === 1) {
            self::cleanup($dir, $window);
        }

        return $wait;
    }

    /** Deletes counter files in one sub-folder whose window has ended, so storage doesn't grow forever. */
    private static function cleanup(string $dir, int $window): void
    {
        $expired = time() - $window;
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (@filemtime($file) < $expired) {
                @unlink($file);
            }
        }
    }
}
