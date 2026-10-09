# ExpressPHP security, performance and QA review

Reviewed on 9 October 2026 against commit bfd773c, with a clean working tree before review. Runtime used: PHP 8.5.4. Project support target: PHP 8.2+.

**Recommendation: address the high-priority findings before using this checkout for a production backend.** The framework has useful protections, but passing syntax checks and ordinary API checks does not cover concurrency, disk failures, hostile input sizes or deployment mistakes.

This review covers the framework, shared application models and middleware, configuration, migrations, CLI tools, server rules and documentation. Example controller business logic was excluded as requested. Rate-limit, activity-log and server-log endpoints were included; EmailController was reviewed only for log listing/detail behavior. The file inventory at the end records every one of the 70 tracked files.

No framework code, deployed credentials or database data was changed. Tests used synthetic values, fake PDO connections and temporary files under /tmp. No secret or usable token is reproduced here.

## Priority and evidence

- **P1 / high:** resolve before production. Conditions are stated where a problem depends on configuration.
- **P2 / medium:** a confirmed correctness, availability, integrity or performance defect; resolve before using the affected capability in production.
- **P3 / low:** a narrower interoperability or developer-tool defect.
- **Deployment concerns:** source and documented server behavior support the risk, but the actual production server was not tested.

There are **32 confirmed code/configuration findings: 5 high, 24 medium and 3 low**. Several are conditional helper defects rather than vulnerabilities reachable through today's example routes. The separate Apache deployment concern also needs resolution before publishing.

## High-priority findings

### F01 — P1: the distributed JWT signing key is usable and committed

Location: [config/app.php:53](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/config/app.php:53), with checks at lines 195–202.

The configured signing secret is a literal tracked in Git. It matches the committed value and passes the startup checks. A fresh installation that retains it therefore shares a signing key with anyone who can read that checkout.

**Verified:** a test read the key from Git HEAD, signed synthetic claims and successfully decoded the token under the current key. The key and token were never printed. No database authentication was attempted.

**Impact/condition:** a deployment retaining that value permits token forgery. Passing AuthMiddleware additionally requires an active user's ID and current session version; those checks remain present. This is a shared-secret problem, not an HS256 algorithm bypass.

**Fix:** rotate this value wherever it has been used. Ship a rejected placeholder and require a unique generated value at installation. Keep real deployed values outside tracked files. Your preference for plain PHP configuration can remain: an ignored, private PHP configuration file or deployment-written override is sufficient; .env is not required. Do not generate a new key on every request.

### F02 — P1: login can survive a concurrent password reset, and rehashing can overwrite the new password

Location: [app/Models/User.php:56](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/app/Models/User.php:56), especially lines 70–74.

Authentication checks one password hash, then reloads the user and returns the latest session version. If a reset changes the password and increments that version between these operations, an old-password login receives the new session version. If a rehash is needed, the unconditional update can also replace the freshly reset password with a hash of the old one.

**Verified:** fake PDO interleaving returned session version 2 after verifying the version-1 password; AccessToken then issued claims with version 2. A second case demonstrated the rehash overwriting the reset hash.

**Fix:** treat the password hash and session version as one authentication snapshot. Guard rehash updates with the previous hash/version, verify the snapshot is still current before issuing credentials and reject/retry if it changed. Coordinate this with the password-reset transaction.

### F03 — P1: rate limiting fails open on failed writes and corrupted records

Location: [src/RateLimit/RateLimiter.php:230](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/RateLimit/RateLimiter.php:230), especially lines 252–254 and 286–297.

The limiter ignores the results of truncation, writing and flushing. Warnings do not trigger its Throwable handler. Invalid JSON is also silently replaced with a fresh record, clearing existing blocks and counters.

**Verified:** a child process with a zero file-size limit accepted two requests to a fail-closed login path configured for one request. The state file remained empty. A corrupted blocked record also became a fresh allowed record.

**Impact/condition:** disk exhaustion, partial writes or process interruption can remove brute-force protection exactly when storage is under pressure. Normal locking works; this is the failure path.

**Fix:** check every I/O result, write all bytes, validate stored records and distinguish a new record from a damaged existing record. For fail-closed routes, damage must deny access. Use a stable lock file plus a completely written replacement record if adopting atomic rename; locking an inode that gets replaced is insufficient. Surface storage failures to operational monitoring.

### F04 — P1 conditional: forwarded-IP handling can bypass rate limits behind an appending proxy

Location: [src/Http/Request.php:228](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Http/Request.php:228).

When REMOTE_ADDR is trusted, ip() blindly takes the leftmost X-Forwarded-For value. A proxy that appends its observed client IP preserves an attacker-supplied first value.

**Verified:** a trusted proxy with header "192.0.2.66, 203.0.113.10" returned attacker-selected 192.0.2.66 instead of the observed client 203.0.113.10.

**Condition:** trusted_proxies is currently empty, so direct requests use REMOTE_ADDR. The bypass appears when a configured trusted proxy appends rather than overwrites the incoming header. Nginx documents this appending behavior for [proxy_add_x_forwarded_for](https://nginx.org/en/docs/http/ngx_http_proxy_module.html).

**Fix:** validate addresses and walk the forwarding chain from the right, removing explicitly trusted proxy hops until the first untrusted client. Alternatively, configure the edge to overwrite untrusted forwarding headers and enforce a single trusted hop. Apply the same trust policy to forwarded scheme information.

### F05 — P1: query-string credentials remain in request logs

Location: [src/Logging/RequestLogger.php:61](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Logging/RequestLogger.php:61).

The structured query field is redacted, but url stores the original URI including its untouched query string.

**Verified:** synthetic password/token values were redacted in query but remained visible in url. Debugger has the same URI issue while debug is enabled.

**Impact/condition:** any URL containing a reset token, API key or other secret copies it into persistent logs, even on failed requests. None of the actual project credentials was tested or printed.

**Fix:** log the path and a separately sanitized query representation. Prefer allowlisted request metadata over logging arbitrary input. Apply one sanitizer to request logging and debug output, and remove or securely expire historical log entries containing credentials.

## Validation and request handling

### F06 — P2: conditional required rules can be bypassed with accepted numeric aliases

Location: [src/Validation/Validator.php:234](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Validation/Validator.php:234); normalization is local to the field being validated.

For these rules:

    kind: required|integer|in:1,2
    approval: required_if:kind,1|string

Input kind="1" requires approval. Input kind="01" or "001" returns normalized kind=1 without approval. Equivalent numeric spellings such as "1e0" and "1.0" also produce this discrepancy with the numeric rule. required_unless has the inverse inconsistency.

**Verified:** the validator harness accepted missing approval for the alias inputs. A controller branching on validated kind === 1 can therefore receive data that its conditional rule was intended to reject.

**Fix:** evaluate conditions against a normalized snapshot of referenced fields, according to their declared type and independently of declaration order. Keep literal comparisons for string fields. The documented raw-input semantics of same/different/confirmed are a separate intentional behavior.

### F07 — P2: an invalid parent does not stop child validation or database checks

Location: [src/Validation/Validator.php:25](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Validation/Validator.php:25), database checks at lines 635–649.

A list rejected by its parent max or type rule still expands and validates every child.

**Verified:** 50 items with parent list|max:2 caused 50 exists queries before returning the parent error. A 50-entry associative array rejected by list also caused 50 queries.

**Impact:** declared collection limits do not bound validation work. An inevitably invalid request can still generate thousands of database calls.

**Fix:** validate container structure and count before expanding descendants, skip children of failed containers and enforce a global expanded-field budget. Use a structural first phase so safety does not depend on rule order. Batch exists/unique checks where suitable.

### F08 — P2: wildcard expansion and distinct checks grow quadratically

Location: [src/Validation/Validator.php:475](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Validation/Validator.php:475), array spreading at line 486; distinct at lines 48–51.

Expansion repeatedly copies the accumulated path array. distinct repeatedly scans prior values and retains arrays that cause additional copies.

**Verified bounded benchmark:** 1,000/2,000/4,000/8,000 distinct integer items took approximately 11/32/108/383 ms in a repeat run. Initial results were similar. An 8,000-item JSON representation is only about 39 KB. These are local synthetic timings, not production throughput measurements.

**Fix:** generate paths incrementally or append into one accumulator. Use a type-aware lookup set for normalized scalar distinct values, preserving strict comparison semantics. Combine this with F07's work limits.

### F09 — P2: request bodies have no framework budget and JSON is decoded twice

Location: [src/Http/Request.php:33](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Http/Request.php:33), lines 82–84; request capture precedes limiting.

capture() reads the complete raw body. json() builds an associative tree and another object tree to collect shape information. The project supplies no enforced body-size budget in the framework/server rules.

**Verified:** a valid 840,011-byte JSON fixture with 40,000 small objects used 4 MiB before decoding and reached 46 MiB peak afterward. The same fixture exhausted a 32 MiB child process at the second decode.

**Condition:** an external proxy/PHP limit can mitigate this, but its configuration was not verified. Apache's documented default body allowance is far larger than this fixture; it should not be assumed safe for this application. See [LimitRequestBody](https://httpd.apache.org/docs/2.4/mod/core.html#limitrequestbody).

**Fix:** set explicit edge and application budgets, read at most budget+1 bytes, reject oversized input with 413 and bound nested object/list counts. Avoid keeping two full decoded trees. Use separate budgets for upload routes and normal JSON APIs; do not rely on Content-Length alone.

### F29 — P2: JSON root values and media types are interpreted inconsistently

Location: [src/Http/Request.php:110](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Http/Request.php:110), isJSON at line 203.

validate() replaces scalar JSON with an empty array. isJSON searches for a substring rather than parsing the media type.

**Verified:** true, 123, "unexpected" and null were accepted as empty input when all rules were optional. application/problem+json was ignored, while application/jsonp and text/plain; note=application/json were parsed as JSON.

**Impact:** malformed API input can silently become an accepted no-op or lose intended data. Required fields still reject missing values; this test does not show a bypass of unconditional required rules.

**Fix:** define an object-root contract for field-based validation, reject other root shapes with 400/422, parse the media type before parameters and deliberately support documented +json types or reject them with 415.

## Logging, rate management and observability

### F10 — P2: multipart/form input bypasses the logging byte limit

Location: [src/Logging/RequestLogger.php:100](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Logging/RequestLogger.php:100).

max_input_bytes measures raw text before logging parsed input. Multipart requests normally have no raw body available through this code, yet their populated form arrays are serialized. PHP documents this [multipart php://input behavior](https://www.php.net/manual/en/wrappers.php.php) when automatic POST parsing is enabled.

**Verified:** 1,000 form fields containing 1,000 bytes each produced an approximately 1 MB log entry despite a 4,096-byte limit.

**Fix:** cap aggregate serialized input/query/entry size, node count and nesting depth after sanitizing, regardless of body encoding. Rate-limited and invalid requests also need bounded logging. Configure retention and rotation.

### F11 — P2: fetching one log entry loads and reverses the whole daily file

Location: [app/Models/ServerLog.php:11](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/app/Models/ServerLog.php:11), file() at line 37.

Pagination loads every line, reverses the whole array and only then slices the requested page.

**Verified:** a 24,408,000-byte fixture with 24,000 entries reached about 55 MiB peak for limit=1 and exhausted a 32 MiB child process.

**Fix:** read backward in bounded chunks for recent pages, or use an indexed log store. Count by streaming when necessary. Add maximum file sizes and log rotation. Protecting the endpoint with permissions does not remove its operational memory cost.

### F12 — P2: authenticated request logs lose the user identity

Location: [src/Routing/Router.php:106](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Routing/Router.php:106), [src/Core/Application.php:150](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Core/Application.php:150).

Router gives middleware a cloned Request. AuthMiddleware sets the user on that clone, while Application logs the original request.

**Verified:** a synthetic protected controller saw user ID 123, but the resulting request log had user_id=null.

**Fix:** use a shared request context or return/pass the effective routed request to the logger. ActivityLog.record called from the controller receives the clone and is a separate path; the defect is in the global request logger.

### F13 — P2: ordinary UTF-8 truncation can silently discard a log entry

Location: [src/Logging/RequestLogger.php:116](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Logging/RequestLogger.php:116), byte substring at line 120.

Truncating at byte 1,000 can cut through a multi-byte character. JSON_THROW_ON_ERROR then raises an encoding exception and the broad logging catch drops the entire entry.

**Verified:** a 334-character euro-sign string was cut mid-character and produced no log file entry.

**Fix:** truncate safely on UTF-8 boundaries and/or encode with JSON_INVALID_UTF8_SUBSTITUTE. Record logger failures through a separate bounded operational channel rather than silently losing evidence.

### F14 — P2: equivalent IPv6 spellings have different rate-limit identities

Location: [src/RateLimit/RateLimiter.php:322](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/RateLimit/RateLimiter.php:322); management methods validate but do not canonicalize.

Record filenames hash the literal address. Expanded and compressed spellings of the same IPv6 address therefore select different state.

**Verified:** blocking the expanded form did not block the equivalent compressed form; inet_pton confirmed that both identify the same address.

**Fix:** canonicalize valid addresses consistently before every check, block, status, clear and storage lookup. Integrate this with the forwarding fix in F04.

## Authentication, authorization and database behavior

### F15 — P2: an empty permission declaration fails open

Location: [app/Middlewares/PermissionMiddleware.php:12](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/app/Middlewares/PermissionMiddleware.php:12), [src/Routing/Router.php:153](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Routing/Router.php:153).

A route declared with permission or permission: produces zero required permissions and allows any authenticated user.

**Verified:** a user with no permissions received 200 for those declarations, while permission:admin.view correctly returned 403.

**Condition:** the current reviewed administrative routes declare explicit permissions. This becomes an authorization gap when a new route is misconfigured.

**Fix:** reject missing/blank permission arguments, preferably during route registration. Authentication alone is not sufficient for a declaration intended to enforce authorization.

### F16 — P2: first-account administrator selection is not atomic

Location: [app/Models/User.php:107](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/app/Models/User.php:107).

registrationRoleId() selects super-admin after observing an empty users table, separately from creating the account.

**Verified:** two interleaved bootstrap decisions both returned the super-admin role before either insertion.

**Condition:** fresh installation with concurrent registration. README's instruction to register privately first mitigates exposure operationally but does not make the code atomic.

**Fix:** provision the initial administrator through a private setup command, or hold a database advisory lock across the role decision and insertion. A locked singleton bootstrap row inside a transaction is another option; a transaction around the current COUNT and INSERT alone is insufficient. Ordinary public registration should always receive the ordinary role.

### F20 — P2: concurrent migration runners can apply a migration twice

Location: [src/Database/Migrations/Migrator.php:23](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Database/Migrations/Migrator.php:23).

Two processes can both read a pending migration and run up() before one loses the unique tracking-record insert.

**Verified:** an interleaved fake-PDO run called up() twice but stored one migration record.

**Condition:** concurrent deployment/CLI runners. The current initialization migration's idempotent statements reduce some effects; future data migrations may not be idempotent.

**Fix:** acquire a database advisory lock across migration/rollback discovery, execution and tracking, releasing it in finally. Document partial failure recovery; MySQL DDL cannot be made atomic merely by wrapping every migration in a normal transaction.

### F21 — P2: detailed user collections issue one permission query per user

Location: [app/Models/User.php:23](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/app/Models/User.php:23), withPermissions at line 130.

Both collection methods call permissions() separately for each user.

**Verified:** a 100-user page executed 101 queries before any separate count query. allDetailed() also has unbounded result size.

**Fix:** bulk-load permissions for the page's distinct role IDs, then attach them in memory. Cache only within a request unless permission changes have an explicit invalidation strategy.

## Backup reliability

### F17 — P2: backups can skip live rows and combine inconsistent table states

Location: [src/Database/DatabaseBackup.php:81](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Database/DatabaseBackup.php:81), chunk reads at lines 108–131.

The export has no consistent snapshot and uses unordered LIMIT/OFFSET reads. Concurrent inserts/deletes shift page boundaries; related tables can represent different moments.

**Verified:** a 401-row fixture deleted row 1 between chunks. The next OFFSET page omitted surviving row 201. The lack of ORDER BY is also visible in generated queries.

**Fix:** use a dedicated consistent-snapshot connection for transactional tables and a stable ordered streaming/keyset export. Handle nontransactional tables explicitly. OFFSET rescanning also gets slower as tables grow. Compare the consistency precautions in the [MySQL dump documentation](https://dev.mysql.com/doc/refman/8.0/en/mysqldump.html).

Do not rely on this exporter as a sole recovery mechanism until restoration from a live, changing database has been tested.

### F18 — P2: short SQL writes and failed ZIP finalization can be reported as success

Location: [src/Database/DatabaseBackup.php:51](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Database/DatabaseBackup.php:51), SQL writer at line 184.

The SQL writer only rejects false, accepting a short byte count. createZIP ignores close() failure.

**Verified:** a mocked seven-byte write was accepted as a complete write. A real ZipArchive finalization failure returned success metadata for a nonexistent archive and left the SQL file behind.

**Fix:** write all bytes with zero/false handling, check flush/close and ZIP finalization, confirm the final archive exists and is readable, and clean failed artifacts for all exception types. PHP documents both [partial fwrite results](https://www.php.net/manual/en/function.fwrite.php) and [ZipArchive.close failure](https://www.php.net/manual/en/ziparchive.close.php).

### F19 — P2: generated-column exports cannot be restored as written

Location: [src/Database/DatabaseBackup.php:117](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Database/DatabaseBackup.php:117).

SELECT * results are turned into explicit INSERT values for every column, including computed generated columns.

**Verified:** a synthetic generated total column was included in the exported INSERT. No live MySQL restore was attempted. MySQL permits only DEFAULT when explicitly assigning generated columns; see [generated-column rules](https://dev.mysql.com/doc/refman/8.0/en/create-table-generated-columns.html).

**Condition:** production schemas with generated columns; the current initialization schema does not use them.

**Fix:** inspect column metadata and omit generated columns or emit DEFAULT. Test round-trip restores for supported column types.

## Uploads and SMTP

### F22 — P2: per-upload MIME restrictions are ignored when a global extension map exists

Location: [src/Storage/FileUploader.php:118](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Storage/FileUploader.php:118).

validateMIMEType() returns after checking mime_types_by_extension, skipping allowed_mime_types merged from per-call options.

**Verified:** a text file permitted by the global txt mapping was stored even when that call restricted MIME to image/png.

**Fix:** enforce the intersection of the global extension policy and per-call MIME policy, or explicitly expose/document a different override model. A request for a narrower policy must not silently broaden acceptance.

### F23 — P2: a failed batch upload leaves earlier files orphaned

Location: [src/Storage/FileUploader.php:54](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Storage/FileUploader.php:54).

uploadMany() stores files sequentially and throws on a later invalid file without returning prior paths or removing them.

**Verified:** a valid first file followed by a forbidden second file left the first file on disk after the call failed.

**Impact/condition:** repeated mixed valid/invalid batches can consume storage, and controllers cannot reliably clean up from the failed return.

**Fix:** validate the entire batch first where possible and remove newly stored files on failure, or design an explicit partial-success contract. Also impose aggregate batch size/count limits.

### F24 — P2: SMTP commands and message data do not handle partial writes

Location: [src/Mail/SMTPMailer.php:64](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Mail/SMTPMailer.php:64), command writer at line 127.

Both paths ignore fwrite results and proceed to await a response.

**Verified:** a mock wrote seven bytes of DATA, after which the mailer immediately read the server response instead of sending the remainder.

**Fix:** use a write-all loop with false/zero handling and an absolute operation deadline. Test fragmented writes and disconnected sockets. This is a transport failure problem; no SMTP service was contacted.

### F25 — P2: an already accepted email is marked failed if QUIT fails

Location: [src/Mail/SMTPMailer.php:65](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Mail/SMTPMailer.php:65).

After the server accepts the complete DATA with 250, failure during QUIT still throws a send exception.

**Verified:** a synthetic accepted-DATA exchange followed by a connection close during QUIT was reported as failure.

**Impact:** an application may retry and deliver duplicates. SMTP acceptance does not guarantee final inbox delivery, but it does mean the server accepted responsibility; see [RFC 5321](https://www.rfc-editor.org/rfc/rfc5321.html).

**Fix:** mark acceptance after the final DATA response and make QUIT best-effort. Track accepted/failed/ambiguous outcomes explicitly when building retry logic.

### F32 — P3: encoded email headers exceed protocol limits

Location: [src/Mail/SMTPMailer.php:181](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Mail/SMTPMailer.php:181).

The encoder puts the entire subject/display name into one encoded word. A 100-byte ASCII subject becomes a 148-character encoded word.

**Fix:** split and fold UTF-8 safely. [RFC 2047](https://www.rfc-editor.org/rfc/rfc2047.html) limits each encoded word to 75 characters including delimiters. Current CR/LF stripping and base64 encoding prevent the ordinary subject/header injection case tested.

## Responses, routing and developer tools

### F26 — P2: changing a streaming response to an error still emits the old stream

Location: [src/Http/Response.php:41](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Http/Response.php:41), emit at line 198.

json(), text(), html(), send() and noContent() do not clear an existing stream callback. Only view() clears it.

**Verified:** stream(private synthetic body)->error("Denied", 401)->emit() emitted the old body with status 401, rather than the JSON error.

**Condition:** reusing a prepared streaming/download response in later middleware or controller logic. No current example endpoint leak is claimed.

**Fix:** centralize body-mode changes and clear stale stream state and stream-specific headers whenever selecting a normal body/error/no-content response.

### F27 — P2: stream failures escape application error handling and skip request logging

Location: [src/Core/Application.php:139](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Core/Application.php:139), [src/Http/Response.php:204](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Http/Response.php:204).

Application catches dispatch errors, but executes the stream afterward without a corresponding boundary. sendFile also opens its handle only during emission.

**Verified:** with debug=false, dispatch returned 200 for a synthetic failing stream, then emit threw outside Application's error handling. The write-after-emit logger is not reached on this path.

**Impact/condition:** unreadable/deleted files, broken resources or callback failures may produce partial responses, missing audit entries and, if PHP display_errors is enabled, internal error output. Display-error settings on production were not inspected.

**Fix:** preflight/open resources before committing the response; add an emission error boundary and logging in finally. Once output is committed, handle/record a failed stream without trying to append a JSON error to the existing body. Keep display_errors off and log_errors on in production.

### F28 — P2: multiple cookies are merged into one Set-Cookie header

Location: [src/Http/Response.php:34](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Http/Response.php:34), cookie at line 106.

appendHeader joins values with commas, and cookie uses that method.

**Verified:** two cookie() calls produced a single "first=...; HttpOnly, second=...; HttpOnly" field.

**Fix:** represent headers as multiple values where required and emit each cookie separately with header(..., false). [RFC 6265](https://www.rfc-editor.org/rfc/rfc6265.html) explains why Set-Cookie fields must not be folded this way. Today's bearer-token auth does not use cookies; this matters before adding cookie/session features.

### F30 — P3: literal plus signs change in route parameters

Location: [src/Routing/Route.php:35](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Routing/Route.php:35).

urldecode applies form-query decoding semantics to path segments.

**Verified:** /files/a+b produced parameter "a b".

**Fix:** use rawurldecode for path segments and define whether decoded slash/control characters are permitted before handlers use those values.

### F31 — P3: the generator accepts names that create invalid PHP

Location: [src/Core/MakeCommand.php:98](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Core/MakeCommand.php:98), name validation at line 164.

The name syntax check does not reject PHP reserved words or imported class collisions.

**Verified:** model Class generated an invalid reserved-word declaration; model Model conflicted with the imported base Model. Normal fixture names linted successfully.

**Fix:** validate names against reserved identifiers and template imports before writing. Lint generated source in a temporary file before reporting success.

## Deployment and hardening concerns

### D01 — high, conditional: repository-root hosting relies on .htaccess being honored

Locations: [README.md:63](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/README.md:63), [root .htaccess:17](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/.htaccess:17), [public/.htaccess](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/public/.htaccess).

README recommends the entire repository as DocumentRoot. Its private-file protection resides in .htaccess. Apache documents that [AllowOverride None ignores .htaccess](https://httpd.apache.org/docs/2.4/howto/htaccess.html). In that setup, existing static logs, backups, uploads, Git metadata and extensionless CLI source can become downloadable. Rewritten API URLs may also fail.

**This was not tested against a live server.** It is a deployment prerequisite, not a claim that your current server is exposed.

Prefer a public-only DocumentRoot with an explicit virtual-host directory policy. Ensure the parent's .htaccess is not applied in a way that blocks public/ itself. If shared hosting requires the repository root, document permitted override categories and verify private-path denial through real HTTP before making it public. Also test encoded paths and backup filenames without downloading actual sensitive content.

Other concrete precautions:

1. **Secret/file permissions:** local config/app.php is mode 0664 and config/ is 0775. Unrelated local accounts with directory traversal can read it. Use a restricted owner/service group and 0600/0640 as appropriate. Uploads are explicitly chmod 0644; backup/log modes follow the process umask. Private directories need matching traversal restrictions. Root or the PHP service account can read PHP configuration just as they can read .env.
2. **Production switches:** the current config is development with debug=true. The production guard is useful, but only applies to the exact environment string. Validate allowed environment names and require deployment to select production explicitly. Debugger and debug exception messages intentionally expose internals in development.
3. **Storage lifecycle:** rate records never expire/delete automatically. blocked() scans all historical records before endpoint pagination. Add safe cleanup that preserves active blocks and respects locks. Rotate/retain logs and failed uploads/backups. Monitor disk space and inode exhaustion.
4. **Scale limits:** the file limiter protects one shared local filesystem, not independent nodes with separate storage. IP-only limits do not replace account-specific login throttling and edge limits. Set SMTP message/reply size budgets and a whole-operation deadline; synchronous mail and log writes keep PHP workers busy even after fastcgi_finish_request finishes the client response.
5. **Credentials in helpers:** generic User.find/create/all can return password hashes, and AccessToken.forUser returns its input user unchanged. Current detailed methods remove the password. Use explicit safe serialization before any new controller response. The password helper adds no explicit 72-byte input check, and verify accepted a longer alias in the fixture; PHP-version behavior for hashing longer input differs. The current request rules enforce the ceiling. Apply consistent helper validation before using new jobs/CLI paths.
6. **Upload containment:** existing symlink subdirectories can lead uploads outside the root. The test confirmed this with a deliberately created local symlink. Creating that symlink requires local filesystem influence; it is not a remote-only traversal bypass. Check realpath containment of the final destination directory and restrict storage writes to the service account.
7. **Views:** templates are trusted PHP code and are not autoescaped. The renderer's path containment and reserved-variable protections worked in review; the example uses the supplied escape helper. Keep escaping context-specific, and do not allow user-uploaded PHP templates.
8. **Database/application time:** timezone is used explicitly by logger/debugger/backup/generator, but it does not set PHP's default timezone or the DB session timezone. Choose a consistent UTC storage policy and translate date filters/display deliberately. Otherwise daily database logs and file logs may describe different day boundaries.
9. **Unused/incomplete configuration guidance:** base_path is exposed but not consumed; Request derives it from SCRIPT_NAME. Document/remove the setting. README omits the ZIP extension required by DatabaseBackup. Avoid saying the exporter includes views, triggers, routines or events: it only exports base tables.
10. **Operational errors:** exceptions from configuration/dispatch can become generic 500s without a private structured exception log. Add bounded internal diagnostics with redaction and a correlation ID. Do not put internal exception text into API responses.

## Protections that held in review

- JWT rejected invalid signatures, alg=none, expired tokens, future iat/nbf, string exp, wrong issuer/audience and missing sub.
- Auth middleware still checks database activation and session version; role and explicit permission denials behaved as expected.
- Dynamic query values use prepared statements and SQL identifiers are constrained. No SQL-injection path was found in the reviewed framework/shared models.
- The migration's foreign-key creation/drop ordering and initialization permission assignments were reviewed.
- The ten included rate/log administrative routes all declared auth and a specific permission.
- Strict date/unknown-field checks prevented the tested log date/path inputs from reaching file reads. Database log date boundaries use bound values rather than interpolated user data.
- Normal limiter contention worked: 12 concurrent child processes with a limit of 7 allowed exactly 7.
- Upload checks rejected non-upload fixtures, errors, empty/oversized files, forbidden extensions, MIME mismatches and unsafe path segments. Filenames are random; ordinary deletion is confined by realpath.
- View paths are constrained to the configured directory, including symlink containment. Reserved data keys cannot overwrite renderer state. The example output is escaped.
- CLI entrypoints check PHP_SAPI before initialization. Ordinary generated-file overwrite attempts are rejected.
- Production config rejects debug=true, short/known-placeholder JWT values, wildcard/empty CORS origins and incomplete default database credentials.
- SMTP TLS uses PHP's peer/name verification defaults; no TLS validation bypass was found. See [PHP SSL context defaults](https://www.php.net/manual/en/context.ssl.php).

These results apply to the exercised cases, not to every possible attack or deployment.

## Verification performed and remaining QA

Completed:

- Linted **all 61 tracked PHP sources/CLI scripts**, including excluded example controllers for syntax: zero failures.
- Ran auth/reset, RBAC, migration-concurrency and JWT fixtures using fake PDO and generated test keys.
- Ran **51 rate/log assertions**, including 12-process limiter contention and inspection of the ten protected administrative routes.
- Ran **20 upload checks** plus SMTP/backup/debug fixtures. HTTP upload trust primitives were mocked for CLI execution; MIME detection, filesystem behavior and ZipArchive failure testing used real temporary resources.
- Ran bounded validator benchmarks and invalid-parent database-query counts.
- Ran HTTP helper fixtures for forwarding, JSON shape/media types, stream replacement/errors, cookies and route decoding.
- Ran bounded-memory child processes. Their expected fatal exits are evidence of resource failure, not syntax-test failures.
- Checked signing-key tracking/reuse without printing a secret or token.

Temporary reproduction scripts are under /tmp with names beginning expressphp-; they are not committed project tests and may be removed by the OS.

Still required before release: run the fixes against real MySQL/MariaDB, restore a backup into an empty disposable database, test SMTP against a controlled service, verify actual Apache/proxy/FPM settings through HTTP, test multipart uploads through the real SAPI, run on the PHP 8.2 support floor, and measure representative concurrent application traffic. No live database, external SMTP service, production virtual host or full load test was exercised in this review.

Suggested fix order:

1. Rotate/separate the signing key, remove secrets from logged URIs and settle the public/private server boundary.
2. Fix password-reset concurrency, rate persistence and proxy address handling.
3. Fix conditional validation, parent/work budgets and request/log size limits.
4. Repair audit identity, log reading/encoding and authorization/bootstrap defaults.
5. Repair backups, upload batch policy, SMTP and response-state handling before those features are used.
6. Add focused regression checks for the fixes through temporary/CI tooling consistent with the project's preference to omit a permanent tests directory.

## File-by-file coverage

"Reviewed" means source/configuration/documentation inspection within scope; it does not imply a dedicated execution test for that individual file. Endpoint exclusions are explicit below. Runtime contents and Git internals were not treated as project source or read for credentials.

| File | Coverage / result |
|---|---|
| [.gitignore](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/.gitignore) | Reviewed: Runtime exclusions; signing-key tracking F01. |
| [.htaccess](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/.htaccess) | Reviewed: Private-file/rewrite rules; deployment D01. |
| [AGENTS.md](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/AGENTS.md) | Reviewed: Project conventions; stale references to removed tests, seeders and format tool. |
| [LICENSE](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/LICENSE) | Reviewed: MIT text; no execution behavior. |
| [PROJECT.md](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/PROJECT.md) | Reviewed: File map and deployment descriptions. |
| [README.md](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/README.md) | Reviewed: Setup, validation contracts and deployment D01. |
| [app/Controllers/ActivityLogController.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/app/Controllers/ActivityLogController.php) | Reviewed endpoint: date/input validation and DB pagination. |
| [app/Controllers/AuthController.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/app/Controllers/AuthController.php) | Example business logic excluded as requested; syntax linted. |
| [app/Controllers/DatabaseBackupController.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/app/Controllers/DatabaseBackupController.php) | Example business logic excluded as requested; syntax linted. |
| [app/Controllers/EmailController.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/app/Controllers/EmailController.php) | Partial: log index/show reviewed; example send excluded. |
| [app/Controllers/ExampleController.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/app/Controllers/ExampleController.php) | Example business logic excluded as requested; syntax linted. |
| [app/Controllers/FileController.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/app/Controllers/FileController.php) | Example business logic excluded as requested; syntax linted. |
| [app/Controllers/HealthController.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/app/Controllers/HealthController.php) | Example business logic excluded as requested; syntax linted. |
| [app/Controllers/PermissionController.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/app/Controllers/PermissionController.php) | Example business logic excluded as requested; syntax linted. |
| [app/Controllers/RateLimitController.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/app/Controllers/RateLimitController.php) | Reviewed endpoint: management/validation; F14 and lifecycle notes. |
| [app/Controllers/RoleController.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/app/Controllers/RoleController.php) | Example business logic excluded as requested; syntax linted. |
| [app/Controllers/ServerLogController.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/app/Controllers/ServerLogController.php) | Reviewed endpoint: strict date/field checks; F11 reader. |
| [app/Controllers/UserController.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/app/Controllers/UserController.php) | Example business logic excluded as requested; syntax linted. |
| [app/Middlewares/AuthMiddleware.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/app/Middlewares/AuthMiddleware.php) | Reviewed: JWT, activation/session checks and request identity. |
| [app/Middlewares/PermissionMiddleware.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/app/Middlewares/PermissionMiddleware.php) | Reviewed: Explicit permission enforcement; F15. |
| [app/Middlewares/RoleMiddleware.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/app/Middlewares/RoleMiddleware.php) | Reviewed: Role and missing-user denials. |
| [app/Models/ActivityLog.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/app/Models/ActivityLog.php) | Reviewed: Bound date/pagination queries and activity context. |
| [app/Models/EmailLog.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/app/Models/EmailLog.php) | Reviewed: Bound date/pagination queries. |
| [app/Models/Permission.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/app/Models/Permission.php) | Reviewed: Role relation queries and SQL binding. |
| [app/Models/Role.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/app/Models/Role.php) | Reviewed: Relation updates, binding and transactions. |
| [app/Models/ServerLog.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/app/Models/ServerLog.php) | Reviewed: File-backed pagination; F11. |
| [app/Models/User.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/app/Models/User.php) | Reviewed: Authentication F02, bootstrap F16, collections F21. |
| [app/Views/example.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/app/Views/example.php) | Reviewed: Escaped template output. |
| [cli/make](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/cli/make) | Reviewed: CLI guard and generator dispatch. |
| [cli/migrate.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/cli/migrate.php) | Reviewed: CLI guard/commands; no real DB execution. |
| [cli/rate-limit](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/cli/rate-limit) | Reviewed: CLI guard and limiter management. |
| [config/app.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/config/app.php) | Reviewed: Settings and production guards; F01; values withheld. |
| [index.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/index.php) | Reviewed: Root entrypoint forwarding. |
| [migrations/2026_08_25_000001_init_project.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/migrations/2026_08_25_000001_init_project.php) | Reviewed: Schema, indexes, permissions and FK/drop order; no real DB execution. |
| [public/.htaccess](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/public/.htaccess) | Reviewed: Public entrypoint rewrites; deployment D01. |
| [public/index.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/public/index.php) | Reviewed: Initialization and generic startup errors. |
| [routes/api.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/routes/api.php) | Reviewed: Registration/middleware metadata; ten included rate/log routes protected. |
| [src/Auth/AccessToken.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Auth/AccessToken.php) | Reviewed: Protected claims and user serialization; F02. |
| [src/Auth/JWT.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Auth/JWT.php) | Reviewed: Signature/claim constraints and negative fixtures. |
| [src/Auth/JWTException.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Auth/JWTException.php) | Reviewed: Exception contract. |
| [src/Auth/Password.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Auth/Password.php) | Reviewed: Bcrypt settings, verification/rehash and helper length policy. |
| [src/Core/Application.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Core/Application.php) | Reviewed: Dispatch, headers, limiting/emission/logging; F12/F27. |
| [src/Core/Debugger.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Core/Debugger.php) | Reviewed: Development gate, depth/redaction; URI concern related to F05. |
| [src/Core/MakeCommand.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Core/MakeCommand.php) | Reviewed: Templates, name/path handling and writes; F31. |
| [src/Database/Database.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Database/Database.php) | Reviewed: DSN, lazy connections/options and disconnect. |
| [src/Database/DatabaseBackup.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Database/DatabaseBackup.php) | Reviewed: Export consistency, SQL/ZIP lifecycle; F17–F19. |
| [src/Database/Migrations/Migration.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Database/Migrations/Migration.php) | Reviewed: Migration method contract. |
| [src/Database/Migrations/Migrator.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Database/Migrations/Migrator.php) | Reviewed: Discovery/tracking, order and concurrency; F20. |
| [src/Database/Model.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Database/Model.php) | Reviewed: Prepared values, identifiers, fillable policy and results. |
| [src/Http/CORS.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Http/CORS.php) | Reviewed: Origin matching, credentials and Vary behavior. |
| [src/Http/HttpException.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Http/HttpException.php) | Reviewed: HTTP status/message exception contract. |
| [src/Http/Pagination.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Http/Pagination.php) | Reviewed: Count/offset metadata. |
| [src/Http/Request.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Http/Request.php) | Reviewed: Body/query/params, headers, proxies and identity; F04/F09/F29. |
| [src/Http/Response.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Http/Response.php) | Reviewed: JSON/HTML/views, files/streams, cookies/headers; F26–F28. |
| [src/Logging/RequestLogger.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Logging/RequestLogger.php) | Reviewed: Redaction, size/encoding/persistence; F05/F10/F12/F13. |
| [src/Mail/MailException.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Mail/MailException.php) | Reviewed: Exception contract. |
| [src/Mail/SMTPMailer.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Mail/SMTPMailer.php) | Reviewed: TLS/auth, protocol I/O, MIME/deadlines; F24/F25/F32. |
| [src/RateLimit/RateLimitDecision.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/RateLimit/RateLimitDecision.php) | Reviewed: Immutable decision data. |
| [src/RateLimit/RateLimiter.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/RateLimit/RateLimiter.php) | Reviewed: Transitions, locks, failures and identity; F03/F14. |
| [src/Routing/Route.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Routing/Route.php) | Reviewed: Matching and path decoding; F30. |
| [src/Routing/Router.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Routing/Router.php) | Reviewed: Groups, actions, middleware and clones; F12/F15. |
| [src/Storage/FileUploadException.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Storage/FileUploadException.php) | Reviewed: Exception contract. |
| [src/Storage/FileUploader.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Storage/FileUploader.php) | Reviewed: Trust, size/MIME/path, batches/deletion; F22/F23. |
| [src/Validation/ValidationException.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Validation/ValidationException.php) | Reviewed: Validation error transport. |
| [src/Validation/Validator.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/Validation/Validator.php) | Reviewed: Rule parsing/normalization, nested schema and DB work; F06–F08. |
| [src/View/View.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/View/View.php) | Reviewed: Names/realpath containment, extraction, escaping and buffers. |
| [src/bootstrap.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/bootstrap.php) | Reviewed: Namespace loading and start time. |
| [src/helpers.php](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/src/helpers.php) | Reviewed: Debugger helper and production gate. |
| [storage/backups/.gitignore](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/storage/backups/.gitignore) | Reviewed: Runtime backup exclusion. |
| [storage/uploads/.gitignore](/home/muhammad-ali-akbar/Desktop/htdocs/expressphp/storage/uploads/.gitignore) | Reviewed: Runtime upload exclusion. |
