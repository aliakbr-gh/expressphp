<?php

declare(strict_types=1);

/**
 * Lightweight test runner, command line only:
 *   php tests/tests.php
 *
 * The tests write to the database (users, temporary tables, migration locks) and to storage/,
 * so they must never be runnable by a web request.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../core/bootstrap.php';

use Core\Config;
use Core\Database;
use Core\Request;
use Core\Response;
use Core\Router;
use Core\UploadedFile;
use Core\ValidationException;
use Core\Validator;

$results = [];

/** Runs one test, recording pass/fail and duration. Return a string to show it as detail. */
function test(string $name, callable $fn): void
{
    global $results;
    $start = microtime(true);

    try {
        $detail = $fn();
        $results[] = ['ok' => true, 'name' => $name, 'detail' => $detail ?? ''];
    } catch (Throwable $e) {
        $msg = $e->getMessage();
        if ($e->getPrevious()) {
            $msg .= ' (' . $e->getPrevious()->getMessage() . ')';
        }
        $results[] = ['ok' => false, 'name' => $name, 'detail' => $msg];
    }

    $results[array_key_last($results)]['ms'] = round((microtime(true) - $start) * 1000, 2);
}

/** Fails the current test with $message when $condition is false. */
function assertTrue(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

// ---------------------------------------------------------------- Config

test('Config loads app name', function () {
    $name = Config::get('app.name');
    assertTrue(is_string($name) && $name !== '', 'app.name missing');
    return $name;
});

test('Config returns default for missing key', function () {
    assertTrue(Config::get('nope.missing', 'fallback') === 'fallback', 'default not returned');
    assertTrue(Config::has('database.host'), 'has() failed for existing key');
    assertTrue(!Config::has('nope.missing'), 'has() true for missing key');
});

// ---------------------------------------------------------------- Database

test('Database connects', function () {
    $pdo = Database::connection();
    assertTrue($pdo instanceof PDO, 'not a PDO instance');
    return 'MySQL ' . $pdo->getAttribute(PDO::ATTR_SERVER_VERSION);
});

test('Database connection is reused (singleton)', function () {
    assertTrue(Database::connection() === Database::connection(), 'new connection created');
});

test('Connected to configured database', function () {
    $db = Database::fetch('SELECT DATABASE() AS db')['db'];
    assertTrue($db === Config::get('database.name'), "connected to '{$db}'");
    return $db;
});

test('Charset and time zone applied', function () {
    $row = Database::fetch('SELECT @@character_set_connection AS cs, @@session.time_zone AS tz');
    assertTrue($row['cs'] === Config::get('database.charset'), "charset is {$row['cs']}");
    assertTrue($row['tz'] === Config::get('database.timezone'), "time_zone is {$row['tz']}");
    return "{$row['cs']}, {$row['tz']}";
});

test('Prepared statement with params returns native types', function () {
    $row = Database::fetch('SELECT ? AS name, 42 AS num', ["O'Reilly"]);
    assertTrue($row['name'] === "O'Reilly", 'param not bound safely');
    assertTrue($row['num'] === 42, 'expected int 42, got ' . var_export($row['num'], true));
});

test('Transaction commit and rollback', function () {
    Database::execute('CREATE TEMPORARY TABLE _t (id INT AUTO_INCREMENT PRIMARY KEY, v VARCHAR(10))');

    Database::transaction(fn() => Database::execute('INSERT INTO _t (v) VALUES (?)', ['keep']));

    try {
        Database::transaction(function () {
            Database::execute('INSERT INTO _t (v) VALUES (?)', ['drop']);
            throw new LogicException('force rollback');
        });
    } catch (LogicException) {
    }

    $rows = Database::fetchAll('SELECT v FROM _t');
    Database::execute('DROP TEMPORARY TABLE _t');
    assertTrue(count($rows) === 1 && $rows[0]['v'] === 'keep', 'rollback did not work');
});

// ---------------------------------------------------------------- Router

/** Builds a request through Request::capture() as if served from /expressphp/public/index.php. */
function makeRequest(string $method, string $uri): Request
{
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['REQUEST_URI']    = $uri;
    $_SERVER['SCRIPT_NAME']    = '/expressphp/public/index.php';
    return Request::capture();
}

/** Router with the real routes plus two test-only routes. */
function makeRouter(): Router
{
    $router = new Router();
    require BASE_PATH . '/routes/api.php';
    $router->get('/api/items/{id}', fn($req, $res) => $res->success(['id' => $req->params['id']]));
    $router->get('/api/plain', fn() => ['hello' => 'world']);
    return $router;
}

test('Router resolves GET /api/v1/health/server', function () {
    $response = makeRouter()->dispatch(makeRequest('GET', '/expressphp/api/v1/health/server'));
    assertTrue($response->status === 200, 'status ' . $response->status);
});

test('Router resolves GET /api/v1/health/database', function () {
    $response = makeRouter()->dispatch(makeRequest('GET', '/expressphp/api/v1/health/database?x=1'));
    assertTrue($response->status === 200, 'status ' . $response->status);
});

test('Router extracts route params', function () {
    $request = makeRequest('GET', '/expressphp/api/items/42');
    makeRouter()->dispatch($request);
    assertTrue($request->params['id'] === '42', 'params[id] = ' . var_export($request->params['id'] ?? null, true));
    assertTrue($request->id === '42', 'magic $request->id failed');
});

test('Router converts array return to JSON', function () {
    $response = makeRouter()->dispatch(makeRequest('GET', '/expressphp/api/plain'));
    assertTrue($response->body['data'] === ['hello' => 'world'], 'array not wrapped');
});

test('Router returns 404 and 405', function () {
    foreach ([['GET', '/expressphp/api/missing', 404], ['GET', '/expressphp/api/health/server', 404], ['POST', '/expressphp/api/v1/health/server', 405]] as [$method, $uri, $expected]) {
        try {
            makeRouter()->dispatch(makeRequest($method, $uri));
            throw new RuntimeException("{$method} {$uri} did not throw");
        } catch (Core\HttpException $e) {
            assertTrue($e->status() === $expected, "{$method} {$uri} gave {$e->status()}");
        }
    }
});

// ---------------------------------------------------------------- Request

/** A POST request with body, query, headers and cookies, used by the Request tests. */
function sampleRequest(): Request
{
    return Request::create('POST', '/api/v1/users?page=2&sort=name', [
        'name'    => '  Ali  ',
        'email'   => 'ali@example.com',
        'age'     => '30',
        'active'  => 'yes',
        'empty'   => '',
        'address' => ['city' => 'Lahore'],
    ], [
        'Authorization' => 'Bearer abc.def.ghi',
        'Content-Type'  => 'application/json',
        'Accept'        => 'application/json',
        'User-Agent'    => 'tests',
    ], [], ['session' => 'xyz']);
}

test('Request properties', function () {
    $r = sampleRequest();
    assertTrue($r->method === 'POST' && $r->path === '/api/v1/users', 'method/path');
    assertTrue($r->body['email'] === 'ali@example.com', 'body');
    assertTrue($r->query['page'] === '2', 'query');
    assertTrue($r->headers['authorization'] === 'Bearer abc.def.ghi', 'headers');
    assertTrue($r->cookies['session'] === 'xyz', 'cookies');
    assertTrue($r->email === 'ali@example.com' && $r->page === '2' && $r->nope === null, 'magic access');
    assertTrue(isset($r->email) && !isset($r->nope), 'isset');
});

test('Request properties are read-only', function () {
    try {
        sampleRequest()->body = [];
    } catch (Error) {
        return;
    }
    throw new RuntimeException('body was writable');
});

test('Request input helpers', function () {
    $r = sampleRequest();
    assertTrue($r->input('address.city') === 'Lahore', 'dot notation');
    assertTrue($r->input('missing', 'def') === 'def', 'default');
    assertTrue($r->only('name', 'email') === ['name' => '  Ali  ', 'email' => 'ali@example.com'], 'only');
    assertTrue(!array_key_exists('age', $r->except(['age'])), 'except');
    assertTrue($r->has(['name', 'empty']) && !$r->has('nope'), 'has');
    assertTrue($r->filled('name') && !$r->filled('empty'), 'filled');
    assertTrue($r->missing('nope'), 'missing');
    assertTrue($r->string('name') === 'Ali', 'string trims');
    assertTrue($r->integer('age') === 30 && $r->integer('page') === 2, 'integer');
    assertTrue($r->boolean('active') === true && $r->boolean('nope') === false, 'boolean');
    $r->merge(['role' => 'admin']);
    assertTrue($r->role === 'admin', 'merge');
});

test('Request headers, token, url, ip', function () {
    $r = sampleRequest();
    assertTrue($r->bearerToken() === 'abc.def.ghi', 'bearerToken');
    assertTrue($r->header('ACCEPT') === 'application/json' && $r->hasHeader('user-agent'), 'header');
    assertTrue($r->isJson() && $r->expectsJson(), 'json detection');
    assertTrue($r->isMethod('post') && $r->is('api/v1/*') && !$r->is('admin/*'), 'isMethod/is');
    assertTrue($r->url() === 'http://localhost/api/v1/users', 'url ' . $r->url());
    assertTrue($r->fullUrl() === 'http://localhost/api/v1/users?page=2&sort=name', 'fullUrl');
    assertTrue($r->ip() === '127.0.0.1', 'ip');

    // X-Forwarded-For must be ignored unless the proxy is trusted.
    $spoof = Request::create('GET', '/', [], ['X-Forwarded-For' => '6.6.6.6']);
    assertTrue($spoof->ip() === '127.0.0.1', 'spoofed ip accepted');
});

test('Request user and attributes', function () {
    $r = sampleRequest();
    $r->setUser(['id' => 1]);
    $r->setAttribute('tenant', 'acme');
    assertTrue($r->user() === ['id' => 1] && $r->getAttribute('tenant') === 'acme', 'state');
});

test('Request file upload and store', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'up');
    file_put_contents($tmp, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='));
    $file = new UploadedFile($tmp, 'me.PNG', UPLOAD_ERR_OK, filesize($tmp), test: true);

    $r = Request::create('POST', '/', [], [], ['avatar' => $file]);
    assertTrue($r->hasFile('avatar') && !$r->hasFile('nope'), 'hasFile');
    assertTrue($r->file('avatar')->mimeType() === 'image/png', 'mime');
    assertTrue($r->file('avatar')->extension() === 'png', 'extension');

    $path = $r->file('avatar')->store('tests');
    $full = Core\Config::get('filesystem.root') . '/' . $path;
    assertTrue(is_file($full), 'not stored');
    unlink($full);
    @rmdir(dirname($full));
    return $path;
});

// ---------------------------------------------------------------- Response

test('Response shapes: success has no errors, error has no data', function () {
    $ok  = (new Response())->success(['id' => 1])->body;
    $err = (new Response())->error('Bad', 422, ['email' => ['Invalid.']])->body;
    assertTrue(array_keys($ok) === ['success', 'message', 'data'], json_encode(array_keys($ok)));
    assertTrue(array_keys($err) === ['success', 'message', 'errors'], json_encode(array_keys($err)));
});

test('Response helpers', function () {
    $ok = (new Response())->success(['id' => 1], 'Done');
    assertTrue($ok->status === 200 && $ok->body['data'] === ['id' => 1] && $ok->body['message'] === 'Done', 'success');

    assertTrue((new Response())->success(['id' => 1], 'Created.', 201)->status === 201, 'custom status');
    assertTrue((new Response())->noContent()->status === 204, 'noContent');
    assertTrue((new Response())->json([1, 2])->body === [1, 2], 'json raw');

    $err = (new Response())->error('Nope', 404, ['id' => ['bad']]);
    assertTrue($err->status === 404 && $err->body['success'] === false && isset($err->body['errors']), 'error');

    $h = (new Response())->success()->header('X-A', '1')->withHeaders(['X-B' => '2'])->setStatusCode(202);
    assertTrue($h->headers === ['X-A' => '1', 'X-B' => '2'] && $h->status === 202, 'chaining');
});

test('Response keeps headers set before content', function () {
    $r = (new Response())->header('X-Request-Id', 'abc')->success(['id' => 5], 'Created.', 201);
    assertTrue($r->status === 201 && $r->headers['X-Request-Id'] === 'abc', 'header lost');
});

test('Response paginate meta', function () {
    $p = (new Response())->paginate([['id' => 11], ['id' => 12]], 12, 2, 10);
    $m = $p->body['data']['pagination'];
    assertTrue(count($p->body['data']['items']) === 2, 'items');
    assertTrue($m['last_page'] === 2 && $m['from'] === 11 && $m['to'] === 12 && $m['total'] === 12, json_encode($m));
});

// ---------------------------------------------------------------- Validation

test('Validator passes and returns only validated fields', function () {
    $data = Validator::make(
        ['name' => 'Ali', 'age' => '30', 'role' => 'admin', 'extra' => 'x', 'password' => 'secret123', 'password_confirmation' => 'secret123'],
        ['name' => 'required|string|max:10', 'age' => 'integer|between:18,99', 'role' => 'in:admin,editor', 'password' => 'required|min:8|confirmed', 'bio' => 'nullable|string']
    )->validate();
    assertTrue(array_keys($data) === ['name', 'age', 'role', 'password'], json_encode(array_keys($data)));
});

test('Validator reports Laravel-style errors', function () {
    $v = Validator::make(
        ['email' => 'not-an-email', 'age' => '10', 'name' => str_repeat('a', 5), 'code' => 'abc'],
        ['email' => 'required|email', 'age' => 'integer|min:18', 'name' => 'string|min:10', 'title' => 'required', 'code' => ['regex:/^[A-Z]{3}$/']],
        ['title.required' => 'Please give a title.']
    );
    $e = $v->errors();
    assertTrue($v->fails(), 'should fail');
    assertTrue($e['email'] === ['The email field must be a valid email address.'], json_encode($e['email']));
    assertTrue($e['age'] === ['The age field must be at least 18.'], json_encode($e['age']));
    assertTrue($e['name'] === ['The name field must be at least 10 characters.'], json_encode($e['name']));
    assertTrue($e['title'] === ['Please give a title.'], 'custom message');
    assertTrue(isset($e['code']), 'regex');
});

test('Validator unique / exists hit the database', function () {
    Database::execute('CREATE TEMPORARY TABLE _users (id INT PRIMARY KEY, email VARCHAR(100))');
    Database::execute("INSERT INTO _users VALUES (1, 'taken@x.com')");

    $taken  = Validator::make(['email' => 'taken@x.com'], ['email' => 'unique:_users,email'])->fails();
    $self   = Validator::make(['email' => 'taken@x.com'], ['email' => 'unique:_users,email,1'])->passes();
    $exists = Validator::make(['user_id' => 1], ['user_id' => 'exists:_users,id'])->passes();
    $none   = Validator::make(['user_id' => 9], ['user_id' => 'exists:_users,id'])->fails();

    Database::execute('DROP TEMPORARY TABLE _users');
    assertTrue($taken && $self && $exists && $none, json_encode(compact('taken', 'self', 'exists', 'none')));
});

test('$request->validate() throws 422', function () {
    try {
        sampleRequest()->validate(['email' => 'required|email', 'phone' => 'required']);
    } catch (ValidationException $e) {
        assertTrue($e->status() === 422 && $e->getMessage() === 'The phone field is required.', $e->getMessage());
        return;
    }
    throw new RuntimeException('did not throw');
});

// ---------------------------------------------------------------- Logging

test('Log writes daily file and respects enabled flag', function () {
    $original = [Config::get('logging.enabled'), Config::get('logging.path')];
    $dir = sys_get_temp_dir() . '/expressphp-log-test-' . getmypid();
    @mkdir($dir);
    Config::set('logging.path', $dir);

    try {
        Config::set('logging.enabled', true);
        Core\Log::info('User registered', ['id' => 5]);
        Core\Log::exception(new LogicException('Boom'));
        Core\Log::request(Request::create('GET', '/api/v1/x'), 404, microtime(true));

        $file = $dir . '/app-' . date('Y-m-d') . '.log';
        $lines = file($file, FILE_IGNORE_NEW_LINES);
        assertTrue(count($lines) === 3, 'expected 3 lines, got ' . count($lines));
        assertTrue(preg_match('/^\[\d{4}-\d\d-\d\d \d\d:\d\d:\d\d\] INFO User registered \{"id":5\}$/', $lines[0]) === 1, $lines[0]);
        assertTrue(preg_match('/ERROR LogicException: Boom at tests\/tests\.php:\d+$/', $lines[1]) === 1, $lines[1]);
        assertTrue(preg_match('/WARNING GET \/api\/v1\/x 404 [\d.]+ms 127\.0\.0\.1$/', $lines[2]) === 1, $lines[2]);

        Config::set('logging.enabled', false);
        Core\Log::error('should not be written');
        assertTrue(count(file($file)) === 3, 'wrote while disabled');
    } finally {
        array_map('unlink', glob("{$dir}/*"));
        @rmdir($dir);
        Config::set('logging.enabled', $original[0]);
        Config::set('logging.path', $original[1]);
    }
    return $lines[2];
});

// ---------------------------------------------------------------- Rate limiter

test('RateLimiter allows max_requests then blocks, per key', function () {
    $original = Config::get('rate_limit');
    $dir = sys_get_temp_dir() . '/expressphp-rl-test-' . getmypid();
    Config::set('rate_limit', ['enabled' => true, 'max_requests' => 3, 'per_seconds' => 60, 'path' => $dir]);

    try {
        $results = [];
        for ($i = 0; $i < 4; $i++) {
            $results[] = Core\RateLimiter::hit('1.1.1.1');
        }
        assertTrue($results[0] === 0 && $results[1] === 0 && $results[2] === 0, 'first 3 should pass: ' . json_encode($results));
        assertTrue($results[3] > 0 && $results[3] <= 60, '4th should wait 1-60s: ' . $results[3]);
        assertTrue(Core\RateLimiter::hit('2.2.2.2') === 0, 'other IP blocked');

        Config::set('rate_limit.enabled', false);
        assertTrue(Core\RateLimiter::hit('1.1.1.1') === 0, 'disabled still blocks');
    } finally {
        array_map('unlink', glob("{$dir}/*/*"));
        array_map('rmdir', glob("{$dir}/*", GLOB_ONLYDIR));
        @rmdir($dir);
        Config::set('rate_limit', $original);
    }
});

// ---------------------------------------------------------------- Review fixes (see fixes.md)

test('H2: unknown upload types are stored as .bin, never with the client extension', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'up');
    file_put_contents($tmp, '<?php echo "pwned";');
    $file = new UploadedFile($tmp, 'shell.php', UPLOAD_ERR_OK, filesize($tmp), test: true);
    try {
        assertTrue($file->mimeType() === 'text/x-php', 'mime ' . $file->mimeType());
        assertTrue($file->extension() === 'bin', 'extension ' . $file->extension());
    } finally {
        @unlink($tmp);
    }
});

test('M2: control characters in log messages cannot create new lines', function () {
    $original = [Config::get('logging.enabled'), Config::get('logging.path')];
    $dir = sys_get_temp_dir() . '/expressphp-log-inj-' . getmypid();
    @mkdir($dir);
    Config::set('logging.path', $dir);
    Config::set('logging.enabled', true);
    try {
        Core\Log::warning("GET /x\n[2026-01-01 00:00:00] ERROR forged\r\x00end");
        $lines = file(Core\Log::file(), FILE_IGNORE_NEW_LINES);
        assertTrue(count($lines) === 1, 'expected 1 line, got ' . count($lines));
        assertTrue(str_contains($lines[0], 'GET /x\x0A[2026-01-01 00:00:00] ERROR forged\x0D\x00end'), $lines[0]);
    } finally {
        array_map('unlink', glob("{$dir}/*"));
        @rmdir($dir);
        Config::set('logging.enabled', $original[0]);
        Config::set('logging.path', $original[1]);
    }
});

test('M3: invalid UTF-8 is substituted, not a crash after headers', function () {
    ob_start();
    (new Response())->error("bad \xFF byte")->send();
    $out = ob_get_clean();
    assertTrue(json_decode($out, true)['message'] === "bad \u{FFFD} byte", $out);
});

test('M3: unencodable body throws before anything is sent', function () {
    ob_start();
    try {
        (new Response())->success(INF)->send();
        ob_end_clean();
        throw new RuntimeException('no exception');
    } catch (JsonException) {
        assertTrue(ob_get_clean() === '', 'output was sent before the failure');
    }
});

test('M4: wildcard trusted proxy returns the real client IP', function () {
    $original = Config::get('app.trusted_proxies');
    try {
        Config::set('app.trusted_proxies', ['*']);
        $r = Request::create('GET', '/', [], ['X-Forwarded-For' => '203.0.113.9, 10.0.0.2'], [], [], ['REMOTE_ADDR' => '10.0.0.1']);
        assertTrue($r->ip() === '203.0.113.9', 'wildcard got ' . $r->ip());

        Config::set('app.trusted_proxies', ['10.0.0.1', '10.0.0.2']);
        assertTrue($r->ip() === '203.0.113.9', 'explicit list got ' . $r->ip());

        Config::set('app.trusted_proxies', []);
        assertTrue($r->ip() === '10.0.0.1', 'untrusted got ' . $r->ip());
    } finally {
        Config::set('app.trusted_proxies', $original);
    }
});

test('M5/N3: IPv6 keys are canonical; /64 network key groups addresses', function () {
    assertTrue(Core\RateLimiter::normalizeKey('2001:DB8:0:0::1') === '2001:db8::1', 'not canonical');
    assertTrue(Core\RateLimiter::normalizeKey('::ffff:192.0.2.7') === '192.0.2.7', 'IPv4-mapped not unwrapped');
    assertTrue(Core\RateLimiter::normalizeKey('192.0.2.7') === '192.0.2.7', 'IPv4 changed');

    $a = Core\RateLimiter::networkKey('2001:db8:abcd:12::1');
    $b = Core\RateLimiter::networkKey('2001:db8:abcd:12:ffff:ffff:ffff:ffff');
    $c = Core\RateLimiter::networkKey('2001:db8:abcd:13::1');
    assertTrue($a === $b && $a !== $c, "network keys: {$a} {$b} {$c}");
    assertTrue(Core\RateLimiter::networkKey('192.0.2.7') === null, 'IPv4 got a network key');
});

test('N3: IPv6 users sharing a /64 have separate limits, but the /64 has a shared cap', function () {
    $original = Config::get('rate_limit');
    $dir = sys_get_temp_dir() . '/expressphp-rl6-' . getmypid();
    Config::set('rate_limit', ['enabled' => true, 'max_requests' => 2, 'per_seconds' => 60, 'path' => $dir]);
    try {
        // Address A uses up its own limit (2); address B in the same /64 is still allowed.
        $a = [Core\RateLimiter::hit('2001:db8::a'), Core\RateLimiter::hit('2001:db8::a'), Core\RateLimiter::hit('2001:db8::a')];
        assertTrue($a[0] === 0 && $a[1] === 0 && $a[2] > 0, 'address A: ' . json_encode($a));
        assertTrue(Core\RateLimiter::hit('2001:db8::b') === 0, 'address B blocked by A');

        // Rotating addresses: the /64 cap is 2 x 10 = 20 allowed requests in total. 3 used so far
        // (A's blocked 3rd request doesn't count), so 17 of the next 20 addresses get through.
        $blocked = 0;
        for ($i = 1; $i <= 20; $i++) {
            $blocked += Core\RateLimiter::hit('2001:db8::' . dechex(0x100 + $i)) > 0 ? 1 : 0;
        }
        assertTrue($blocked === 3, "expected the last 3 rotated addresses blocked, got {$blocked}");
    } finally {
        array_map('unlink', glob("{$dir}/*/*"));
        array_map('rmdir', glob("{$dir}/*", GLOB_ONLYDIR));
        @rmdir($dir);
        Config::set('rate_limit', $original);
    }
});

test('N4: trusted proxies accept CIDR ranges (IPv4 and IPv6)', function () {
    $original = Config::get('app.trusted_proxies');
    try {
        Config::set('app.trusted_proxies', ['173.245.48.0/20', '2400:cb00::/32']);
        $via4 = Request::create('GET', '/', [], ['X-Forwarded-For' => '203.0.113.9'], [], [], ['REMOTE_ADDR' => '173.245.63.254']);
        $via6 = Request::create('GET', '/', [], ['X-Forwarded-For' => '203.0.113.9'], [], [], ['REMOTE_ADDR' => '2400:cb00:1::5']);
        $out  = Request::create('GET', '/', [], ['X-Forwarded-For' => '6.6.6.6'], [], [], ['REMOTE_ADDR' => '173.245.64.1']);
        assertTrue($via4->ip() === '203.0.113.9', 'IPv4 range: ' . $via4->ip());
        assertTrue($via6->ip() === '203.0.113.9', 'IPv6 range: ' . $via6->ip());
        assertTrue($out->ip() === '173.245.64.1', 'outside range trusted: ' . $out->ip());

        // R5: malformed entries are ignored without errors (no ctype dependency).
        Config::set('app.trusted_proxies', ['173.245.48.0/abc', '173.245.48.0/99', 'not-an-ip/8']);
        assertTrue($via4->ip() === '173.245.63.254', 'malformed CIDR trusted: ' . $via4->ip());
    } finally {
        Config::set('app.trusted_proxies', $original);
    }
});

test('N1: body size limit follows post_max_size', function () {
    $expected = (int) ini_get('post_max_size') * match (strtolower(substr(ini_get('post_max_size'), -1))) {
        'g' => 1024 ** 3, 'm' => 1024 ** 2, 'k' => 1024, default => 1,
    };
    assertTrue(Request::maxBodySize() === ($expected ?: 8 * 1024 ** 2), (string) Request::maxBodySize());
    return Request::maxBodySize() . ' bytes';
});

test('N5: SVG uploads are stored as .bin', function () {
    $tmp = tempnam(sys_get_temp_dir(), 'up');
    file_put_contents($tmp, '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>');
    try {
        $file = new UploadedFile($tmp, 'logo.svg', UPLOAD_ERR_OK, filesize($tmp), test: true);
        assertTrue($file->mimeType() === 'image/svg+xml' && $file->extension() === 'bin', $file->mimeType() . ' => ' . $file->extension());
    } finally {
        @unlink($tmp);
    }
});

test('R2: an IPv6 address that is already blocked does not use up its /64 budget', function () {
    $original = Config::get('rate_limit');
    $dir = sys_get_temp_dir() . '/expressphp-rl-r2-' . getmypid();
    Config::set('rate_limit', ['enabled' => true, 'max_requests' => 2, 'per_seconds' => 60, 'path' => $dir]);
    try {
        for ($i = 0; $i < 25; $i++) {
            Core\RateLimiter::hit('2001:db8::bad');
        }
        assertTrue(Core\RateLimiter::hit('2001:db8::bad') > 0, 'noisy address not blocked');
        assertTrue(Core\RateLimiter::hit('2001:db8::beef') === 0, 'neighbour blocked by a noisy address');
    } finally {
        array_map('unlink', glob("{$dir}/*/*"));
        array_map('rmdir', glob("{$dir}/*", GLOB_ONLYDIR));
        @rmdir($dir);
        Config::set('rate_limit', $original);
    }
});

test('R1: method override keeps the form body of a real POST', function () {
    $saved = [$_SERVER, $_POST];
    try {
        $_SERVER['REQUEST_METHOD']              = 'POST';
        $_SERVER['REQUEST_URI']                 = '/expressphp/api/v1/items/5';
        $_SERVER['SCRIPT_NAME']                 = '/expressphp/public/index.php';
        $_SERVER['CONTENT_TYPE']                = 'application/x-www-form-urlencoded';
        $_SERVER['HTTP_X_HTTP_METHOD_OVERRIDE'] = 'PUT';
        $_POST = ['name' => 'ali'];

        $r = Request::capture();
        assertTrue($r->method === 'PUT', 'method ' . $r->method);
        assertTrue($r->body === ['name' => 'ali'], 'body ' . json_encode($r->body));
    } finally {
        [$_SERVER, $_POST] = $saved;
    }
});

test('R4: fromGlobals() never reads the body; readBody() does', function () {
    $saved = $_SERVER;
    try {
        $_SERVER['REQUEST_METHOD'] = 'PUT';
        $_SERVER['REQUEST_URI']    = '/expressphp/api/v1/x';
        $_SERVER['SCRIPT_NAME']    = '/expressphp/public/index.php';
        $_SERVER['CONTENT_TYPE']   = 'application/json';
        $_SERVER['CONTENT_LENGTH'] = (string) (Request::maxBodySize() + 1);

        $r = Request::fromGlobals();
        assertTrue($r->path === '/api/v1/x' && $r->body === [], 'fromGlobals read the body');
        try {
            $r->readBody();
            throw new RuntimeException('oversized body accepted');
        } catch (Core\HttpException $e) {
            assertTrue($e->status() === 413, 'status ' . $e->status());
        }
    } finally {
        $_SERVER = $saved;
    }
});

test('R3: logged duplicate-key errors never contain the duplicated value', function () {
    $original = [Config::get('logging.enabled'), Config::get('logging.path')];
    $dir = sys_get_temp_dir() . '/expressphp-log-r3-' . getmypid();
    @mkdir($dir);
    Config::set('logging.path', $dir);
    Config::set('logging.enabled', true);
    Database::execute('CREATE TEMPORARY TABLE zz_dupe3 (id INT PRIMARY KEY, secret VARCHAR(40), UNIQUE KEY internal_secret_idx (secret))');
    try {
        Database::execute("INSERT INTO zz_dupe3 VALUES (1, 'sk_live_TOPSECRET')");
        try {
            Database::execute("INSERT INTO zz_dupe3 VALUES (2, 'sk_live_TOPSECRET')");
        } catch (PDOException $e) {
            Core\Log::exception($e);
        }
        $log = file_get_contents(Core\Log::file());
        assertTrue(!str_contains($log, 'TOPSECRET'), 'value leaked: ' . $log);
        assertTrue(str_contains($log, "Duplicate entry '***' for key 'zz_dupe3.internal_secret_idx'"), $log);
    } finally {
        Database::execute('DROP TEMPORARY TABLE IF EXISTS zz_dupe3');
        array_map('unlink', glob("{$dir}/*"));
        @rmdir($dir);
        Config::set('logging.enabled', $original[0]);
        Config::set('logging.path', $original[1]);
    }
});

test('S1: chunked multipart POST that PHP could not parse gets 413', function () {
    $saved = [$_SERVER, $_POST, $_FILES];
    try {
        $_SERVER['REQUEST_METHOD']         = 'POST';
        $_SERVER['REQUEST_URI']            = '/expressphp/api/v1/files/upload';
        $_SERVER['SCRIPT_NAME']            = '/expressphp/public/index.php';
        $_SERVER['CONTENT_TYPE']           = 'multipart/form-data; boundary=x';
        $_SERVER['HTTP_TRANSFER_ENCODING'] = 'chunked';
        unset($_SERVER['CONTENT_LENGTH']);
        $_POST  = [];
        $_FILES = [];

        try {
            Request::capture();
            throw new RuntimeException('empty chunked multipart accepted');
        } catch (Core\HttpException $e) {
            assertTrue($e->status() === 413, 'status ' . $e->status());
        }

        // A normal small chunked multipart body still works.
        $_POST = ['name' => 'ali'];
        assertTrue(Request::capture()->body === ['name' => 'ali'], 'small chunked multipart rejected');
    } finally {
        [$_SERVER, $_POST, $_FILES] = $saved;
    }
});

test('S2: data values in database errors are masked in logs', function () {
    $cases = [
        "Duplicate entry 'ali@x.com' for key 'users.users_email_unique'" => "Duplicate entry '***' for key 'users.users_email_unique'",
        "1366 Incorrect integer value: 'sk_SECRET' for column 'age' at row 1" => "1366 Incorrect integer value: '***' for column 'age' at row 1",
        "Truncated incorrect DOUBLE value: 'O'Reilly'" => "Truncated incorrect DOUBLE value: '***'",
        "Data too long for column 's' at row 1" => "Data too long for column 's' at row 1",
    ];
    foreach ($cases as $in => $expected) {
        $out = Core\Log::maskDataValues($in);
        assertTrue($out === $expected, "{$in} => {$out}");
    }

    Database::execute('CREATE TEMPORARY TABLE zz_mask (n INT)');
    try {
        Database::execute('INSERT INTO zz_mask (n) VALUES (?)', ['TOPSECRET']);
        throw new RuntimeException('bad integer accepted');
    } catch (PDOException $e) {
        assertTrue(!str_contains(Core\Log::maskDataValues($e->getMessage()), 'TOPSECRET'), $e->getMessage());
    } finally {
        Database::execute('DROP TEMPORARY TABLE IF EXISTS zz_mask');
    }
});

test('M6: deprecations are logged, not thrown', function () {
    $original = Config::get('logging.enabled');
    Config::set('logging.enabled', false);
    try {
        trigger_error('old api', E_USER_DEPRECATED);
    } finally {
        Config::set('logging.enabled', $original);
    }
});

test('M8: duplicate-key errors map to the column of the unique index', function () {
    Database::execute('CREATE TEMPORARY TABLE zz_dupe (id INT PRIMARY KEY, username VARCHAR(20), UNIQUE KEY zz_dupe_username_unique (username))');
    try {
        Database::execute("INSERT INTO zz_dupe VALUES (1, 'ali')");
        Database::execute("INSERT INTO zz_dupe VALUES (2, 'ali')");
        throw new RuntimeException('duplicate was accepted');
    } catch (PDOException $e) {
        assertTrue(Database::duplicateKeyColumn($e) === 'username', 'column: ' . var_export(Database::duplicateKeyColumn($e), true));
    } finally {
        Database::execute('DROP TEMPORARY TABLE IF EXISTS zz_dupe');
    }
    assertTrue(Database::duplicateKeyColumn(new RuntimeException('x')) === null, 'non-PDO error treated as duplicate');
});

test('N2: duplicates on non-convention indexes are not mapped to a column', function () {
    Database::execute('CREATE TEMPORARY TABLE zz_dupe2 (id INT PRIMARY KEY, code VARCHAR(20), UNIQUE KEY internal_code_idx (code))');
    try {
        Database::execute("INSERT INTO zz_dupe2 VALUES (1, 'a')");
        Database::execute("INSERT INTO zz_dupe2 VALUES (2, 'a')");
        throw new RuntimeException('duplicate was accepted');
    } catch (PDOException $e) {
        assertTrue(Database::duplicateKeyIndex($e) === 'zz_dupe2.internal_code_idx', 'index: ' . var_export(Database::duplicateKeyIndex($e), true));
        assertTrue(Database::duplicateKeyColumn($e) === '', 'column: ' . var_export(Database::duplicateKeyColumn($e), true));
    } finally {
        Database::execute('DROP TEMPORARY TABLE IF EXISTS zz_dupe2');
    }
});

// ---------------------------------------------------------------- Migrations

test('Migrator make, migrate, status, rollback', function () {
    $dir      = sys_get_temp_dir() . '/expressphp-mig-test-' . getmypid();
    $migrator = new Core\Migrator($dir, '_test_migrations', fn() => null);

    try {
        $file = $migrator->make('create_zz_mig_test_table');
        assertTrue(is_file($file) && str_contains(file_get_contents($file), 'CREATE TABLE `zz_mig_test`'), 'make');

        assertTrue(count($migrator->migrate()) === 1, 'migrate');
        assertTrue(Database::fetch("SHOW TABLES LIKE 'zz_mig_test'") !== null, 'table not created');
        assertTrue($migrator->migrate() === [], 'ran twice');
        assertTrue($migrator->status()[0]['ran'] === true && $migrator->status()[0]['batch'] === 1, 'status');

        assertTrue(count($migrator->rollback()) === 1, 'rollback');
        assertTrue(Database::fetch("SHOW TABLES LIKE 'zz_mig_test'") === null, 'table not dropped');
        assertTrue($migrator->status()[0]['ran'] === false, 'status after rollback');
    } finally {
        Database::execute('DROP TABLE IF EXISTS `zz_mig_test`');
        Database::execute('DROP TABLE IF EXISTS `_test_migrations`');
        array_map('unlink', glob("{$dir}/*"));
        @rmdir($dir);
    }
});

// ---------------------------------------------------------------- JWT

test('JWT encode/decode round trip', function () {
    $claims = Core\JWT::decode(Core\JWT::encode(['sub' => 7]));
    assertTrue($claims['sub'] === 7 && $claims['exp'] - $claims['iat'] === Core\JWT::ttl(), json_encode($claims));
});

test('JWT rejects tampered, alg=none, malformed and expired tokens', function () {
    $b64 = fn($s) => rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    [$h, $p, $sig] = explode('.', Core\JWT::encode(['sub' => 1]));

    $cases = [
        'tampered payload' => [$h . '.' . $b64('{"sub":2,"exp":9999999999}') . '.' . $sig, 'Invalid token.'],
        'alg none'         => [$b64('{"alg":"none"}') . '.' . $b64('{"sub":1,"exp":9999999999}') . '.', 'Invalid token.'],
        'malformed'        => ['not-a-jwt', 'Invalid token.'],
    ];

    $ttl = Core\JWT::ttl();
    Config::set('jwt.ttl', -100);
    $cases['expired'] = [Core\JWT::encode(['sub' => 1]), 'Token has expired.'];
    Config::set('jwt.ttl', $ttl);

    foreach ($cases as $name => [$token, $expected]) {
        try {
            Core\JWT::decode($token);
            throw new RuntimeException("{$name} accepted");
        } catch (Core\JWTException $e) {
            assertTrue($e->getMessage() === $expected, "{$name}: {$e->getMessage()}");
        }
    }
});

// ---------------------------------------------------------------- User model & Auth

test('User model raw queries', function () {
    $id = App\Models\User::create('Test User', 'zz_model_test', password_hash('secret123', PASSWORD_DEFAULT));
    try {
        $user = App\Models\User::findById($id);
        assertTrue($user['username'] === 'zz_model_test', 'findById');
        assertTrue(!array_key_exists('password', $user), 'findById leaked password');

        $withPassword = App\Models\User::findByUsernameWithPassword('zz_model_test');
        assertTrue(password_verify('secret123', $withPassword['password']), 'password hash');
        assertTrue(App\Models\User::findByUsernameWithPassword('zz_nobody') === null, 'missing user');
    } finally {
        Database::execute('DELETE FROM users WHERE id = ?', [$id]);
    }
});

test('Auth register -> login -> me flow', function () {
    $router = makeRouter();
    $call = function (string $method, string $uri, array $body = [], array $headers = []) use ($router) {
        try {
            return $router->dispatch(Request::create($method, $uri, $body, $headers));
        } catch (Core\HttpException $e) {
            return (new Response())->error($e->getMessage(), $e->status(), $e->errors());
        }
    };

    try {
        $reg = $call('POST', '/api/v1/auth/register', ['name' => 'Flow', 'username' => 'zz_flow_test', 'password' => 'secret123']);
        assertTrue($reg->status === 201 && is_string($reg->body['data']['token']), 'register ' . $reg->status);
        assertTrue(!array_key_exists('password', $reg->body['data']['user']), 'register leaked password');

        $dup = $call('POST', '/api/v1/auth/register', ['name' => 'Flow', 'username' => 'zz_flow_test', 'password' => 'secret123']);
        assertTrue($dup->status === 422, 'duplicate ' . $dup->status);

        $bad = $call('POST', '/api/v1/auth/login', ['username' => 'zz_flow_test', 'password' => 'wrong-pass']);
        assertTrue($bad->status === 401, 'wrong password ' . $bad->status);

        $login = $call('POST', '/api/v1/auth/login', ['username' => 'zz_flow_test', 'password' => 'secret123']);
        assertTrue($login->status === 200, 'login ' . $login->status);
        assertTrue(!array_key_exists('password', $login->body['data']['user']), 'login leaked password');

        $token = $login->body['data']['token'];
        $me = $call('GET', '/api/v1/auth/me', [], ['Authorization' => "Bearer {$token}"]);
        assertTrue($me->status === 200 && $me->body['data']['username'] === 'zz_flow_test', 'me ' . $me->status);

        $anon = $call('GET', '/api/v1/auth/me');
        assertTrue($anon->status === 401 && $anon->headers['WWW-Authenticate'] === 'Bearer', 'me without token ' . $anon->status);
    } finally {
        Database::execute("DELETE FROM users WHERE username = 'zz_flow_test'");
    }
});

test('Users table exists with expected columns', function () {
    $columns = array_column(Database::fetchAll('SHOW COLUMNS FROM users'), 'Field');
    $expected = ['id', 'name', 'username', 'password', 'created_at', 'updated_at'];
    assertTrue($columns === $expected, json_encode($columns));
});

// ---------------------------------------------------------------- Output

$passed = count(array_filter($results, fn($r) => $r['ok']));
$total  = count($results);

foreach ($results as $r) {
    printf(
        "%s %-50s %7.2fms  %s\n",
        $r['ok'] ? "\033[32m✔ PASS\033[0m" : "\033[31m✘ FAIL\033[0m",
        $r['name'],
        $r['ms'],
        $r['detail']
    );
}
echo "\n{$passed}/{$total} tests passed\n";
exit($passed === $total ? 0 : 1);
