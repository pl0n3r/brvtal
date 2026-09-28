<?php
declare(strict_types=1);

require_once __DIR__ . '/rate_limit_store.php';

const BRVTAL_STAFF_OPS_NONCE_TTL = 600;
const BRVTAL_STAFF_OPS_IDEMPOTENCY_TTL = 86400;
const BRVTAL_STAFF_OPS_RATE_WINDOW = 60;
const BRVTAL_STAFF_OPS_RATE_MAX = 60;
const BRVTAL_STAFF_OPS_MAX_BODY_BYTES = 65536;

final class BrvtalStaffOpsHttpError extends RuntimeException
{
    public function __construct(public readonly int $status, string $code)
    {
        parent::__construct($code);
    }
}

/** @return array{enabled:bool,key_id:string,secret:string,allowlist:array<int,string>} */
function brvtalStaffOpsConfig(): array
{
    $keyId = trim((string)getenv('BRVTAL_CONTROLBOT_STAFF_KEY_ID'));
    $secret = (string)getenv('BRVTAL_CONTROLBOT_STAFF_KEY');
    $rawAllowlist = trim((string)getenv('BRVTAL_CONTROLBOT_STAFF_ALLOWLIST'));
    $allowlist = [];
    if ($rawAllowlist !== '') {
        foreach (explode(',', $rawAllowlist) as $value) {
            $ip = trim($value);
            if ($ip === '' || $ip === '*' || filter_var($ip, FILTER_VALIDATE_IP) === false) {
                return ['enabled'=>false,'key_id'=>'','secret'=>'','allowlist'=>[]];
            }
            $allowlist[] = $ip;
        }
    }
    $allowlist = array_values(array_unique($allowlist));
    $validKeyId = preg_match('/^[A-Za-z0-9._:-]{1,128}$/D', $keyId) === 1;
    return [
        'enabled' => $validKeyId && $secret !== '' && $allowlist !== [],
        'key_id' => $validKeyId ? $keyId : '',
        'secret' => $secret,
        'allowlist' => $allowlist,
    ];
}

function brvtalStaffOpsIsHttps(array $server): bool
{
    $https = strtolower(trim((string)($server['HTTPS'] ?? '')));
    return in_array($https, ['on','1'], true)
        || strtolower(trim((string)($server['REQUEST_SCHEME'] ?? ''))) === 'https';
}

function brvtalStaffOpsPercentEncode(string $value): string
{
    return preg_replace_callback(
        '/%[0-9a-f]{2}/i',
        static fn(array $match): string => strtoupper($match[0]),
        rawurlencode($value)
    ) ?? rawurlencode($value);
}

/** @return array<int,array{0:string,1:string}> */
function brvtalStaffOpsQueryPairs(string $rawQuery): array
{
    if ($rawQuery === '') return [];
    $pairs = [];
    foreach (explode('&', $rawQuery) as $fragment) {
        if ($fragment === '' || substr_count($fragment, '=') !== 1) {
            throw new BrvtalStaffOpsHttpError(422, 'INVALID_QUERY');
        }
        [$rawName, $rawValue] = explode('=', $fragment, 2);
        if ($rawName === '' || str_contains($rawName, '+') || str_contains($rawValue, '+')) {
            throw new BrvtalStaffOpsHttpError(422, 'INVALID_QUERY');
        }
        foreach ([$rawName, $rawValue] as $raw) {
            if (preg_match('/%(?![0-9A-Fa-f]{2})/', $raw) === 1) {
                throw new BrvtalStaffOpsHttpError(422, 'INVALID_QUERY');
            }
        }
        $name = rawurldecode($rawName);
        $value = rawurldecode($rawValue);
        if (!mb_check_encoding($name, 'UTF-8') || !mb_check_encoding($value, 'UTF-8')) {
            throw new BrvtalStaffOpsHttpError(422, 'INVALID_QUERY');
        }
        $pairs[] = [brvtalStaffOpsPercentEncode($name), brvtalStaffOpsPercentEncode($value)];
    }
    usort($pairs, static function(array $left, array $right): int {
        $name = strcmp($left[0], $right[0]);
        return $name !== 0 ? $name : strcmp($left[1], $right[1]);
    });
    return $pairs;
}

function brvtalStaffOpsCanonicalTarget(string $requestUri): string
{
    if (str_contains($requestUri, "\r") || str_contains($requestUri, "\n") || str_contains($requestUri, "\0")) {
        throw new BrvtalStaffOpsHttpError(422, 'INVALID_REQUEST_TARGET');
    }
    $question = strpos($requestUri, '?');
    $path = $question === false ? $requestUri : substr($requestUri, 0, $question);
    $rawQuery = $question === false ? '' : substr($requestUri, $question + 1);
    if ($path === '' || $path[0] !== '/' || !str_starts_with($path, '/ops')) {
        throw new BrvtalStaffOpsHttpError(422, 'INVALID_REQUEST_TARGET');
    }
    $pairs = brvtalStaffOpsQueryPairs($rawQuery);
    if ($pairs === []) return $path;
    $query = implode('&', array_map(
        static fn(array $pair): string => $pair[0] . '=' . $pair[1],
        $pairs
    ));
    return $path . '?' . $query;
}

/** @return array<string,string> */
function brvtalStaffOpsQueryMap(string $requestUri): array
{
    $question = strpos($requestUri, '?');
    $rawQuery = $question === false ? '' : substr($requestUri, $question + 1);
    $map = [];
    foreach (brvtalStaffOpsQueryPairs($rawQuery) as [$encodedName, $encodedValue]) {
        $name = rawurldecode($encodedName);
        if (array_key_exists($name, $map)) {
            throw new BrvtalStaffOpsHttpError(422, 'DUPLICATE_QUERY_FIELD');
        }
        $map[$name] = rawurldecode($encodedValue);
    }
    return $map;
}

function brvtalStaffOpsCanonicalRequest(
    string $keyId,
    string $method,
    string $target,
    string $timestamp,
    string $nonce,
    string $rawBody
): string {
    return $keyId . "\n"
        . strtoupper($method) . "\n"
        . $target . "\n"
        . $timestamp . "\n"
        . $nonce . "\n"
        . hash('sha256', $rawBody);
}

function brvtalStaffOpsRequestFingerprint(string $method, string $target, string $rawBody): string
{
    return hash('sha256', strtoupper($method) . "\n" . $target . "\n" . hash('sha256', $rawBody));
}

function brvtalStaffOpsStatePath(string $kind, string $identity, ?string $directory = null): string
{
    $directory ??= __DIR__ . '/../storage/rate_limits/staff_ops';
    if (!preg_match('/^[a-z_]{2,24}$/D', $kind)) {
        throw new InvalidArgumentException('INVALID_STATE_KIND');
    }
    return rtrim($directory, '/\\') . DIRECTORY_SEPARATOR
        . $kind . '-' . hash('sha256', $identity) . '.json';
}

function brvtalStaffOpsReadJsonState(string $file): array
{
    if (!is_file($file)) return [];
    $raw = @file_get_contents($file);
    if (!is_string($raw)) throw new RuntimeException('OPS_SECURITY_STORE_UNAVAILABLE');
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) throw new RuntimeException('OPS_SECURITY_STORE_UNAVAILABLE');
    return $decoded;
}

function brvtalStaffOpsWriteJsonState(string $file, array $state): void
{
    $encoded = json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($encoded) || !brvtal_rate_limit_store_atomic_replace($file, $encoded)) {
        throw new RuntimeException('OPS_SECURITY_STORE_UNAVAILABLE');
    }
}

function brvtalStaffOpsWithExclusiveState(string $file, callable $callback): mixed
{
    $lock = brvtal_rate_limit_store_open_lock($file);
    if (!$lock) throw new RuntimeException('OPS_SECURITY_STORE_UNAVAILABLE');
    try {
        if (!flock($lock, LOCK_EX)) throw new RuntimeException('OPS_SECURITY_STORE_UNAVAILABLE');
        return $callback($file);
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function brvtalStaffOpsConsumeRate(string $keyId, string $sourceIp, ?int $now = null, ?string $directory = null): void
{
    $now ??= time();
    $max = (int)(getenv('BRVTAL_CONTROLBOT_STAFF_RATE_MAX') ?: BRVTAL_STAFF_OPS_RATE_MAX);
    $max = max(1, min(600, $max));
    $file = brvtalStaffOpsStatePath('rate', $keyId . '|' . $sourceIp, $directory);
    brvtalStaffOpsWithExclusiveState($file, static function(string $file) use ($now, $max): void {
        $state = brvtalStaffOpsReadJsonState($file);
        $timestamps = array_values(array_filter(
            (array)($state['timestamps'] ?? []),
            static fn(mixed $value): bool => is_int($value) && $value > $now - BRVTAL_STAFF_OPS_RATE_WINDOW
        ));
        if (count($timestamps) >= $max) {
            throw new BrvtalStaffOpsHttpError(429, 'RATE_LIMITED');
        }
        $timestamps[] = $now;
        brvtalStaffOpsWriteJsonState($file, ['timestamps'=>$timestamps]);
    });
}

function brvtalStaffOpsClaimNonce(
    string $keyId,
    string $nonce,
    ?int $now = null,
    ?string $directory = null
): void {
    $now ??= time();
    $file = brvtalStaffOpsStatePath('nonce', $keyId . '|' . $nonce, $directory);
    brvtalStaffOpsWithExclusiveState($file, static function(string $file) use ($now): void {
        $state = brvtalStaffOpsReadJsonState($file);
        if ((int)($state['expires_at'] ?? 0) > $now) {
            throw new BrvtalStaffOpsHttpError(409, 'NONCE_REUSED');
        }
        brvtalStaffOpsWriteJsonState($file, ['expires_at'=>$now + BRVTAL_STAFF_OPS_NONCE_TTL]);
    });
}

/** @return array{status:int,payload:array<string,mixed>} */
function brvtalStaffOpsIdempotent(
    string $scope,
    string $idempotencyKey,
    string $fingerprint,
    callable $operation,
    ?int $now = null,
    ?string $directory = null
): array {
    $now ??= time();
    if (!preg_match('/^[A-Za-z0-9._:-]{8,160}$/D', $idempotencyKey)) {
        throw new BrvtalStaffOpsHttpError(422, 'INVALID_IDEMPOTENCY_KEY');
    }
    $file = brvtalStaffOpsStatePath('idem', $scope . '|' . $idempotencyKey, $directory);
    return brvtalStaffOpsWithExclusiveState($file, static function(string $file) use ($fingerprint, $operation, $now): array {
        $state = brvtalStaffOpsReadJsonState($file);
        if ((int)($state['expires_at'] ?? 0) > $now) {
            if (!hash_equals((string)($state['fingerprint'] ?? ''), $fingerprint)) {
                throw new BrvtalStaffOpsHttpError(409, 'IDEMPOTENCY_CONFLICT');
            }
            $payload = $state['payload'] ?? null;
            if (!is_array($payload)) throw new RuntimeException('OPS_SECURITY_STORE_UNAVAILABLE');
            return ['status'=>(int)($state['status'] ?? 200),'payload'=>$payload];
        }
        $result = $operation();
        if (!is_array($result) || !isset($result['status'],$result['payload']) || !is_array($result['payload'])) {
            throw new RuntimeException('INVALID_IDEMPOTENT_RESULT');
        }
        brvtalStaffOpsWriteJsonState($file, [
            'fingerprint'=>$fingerprint,
            'status'=>(int)$result['status'],
            'payload'=>$result['payload'],
            'expires_at'=>$now + BRVTAL_STAFF_OPS_IDEMPOTENCY_TTL,
        ]);
        return ['status'=>(int)$result['status'],'payload'=>$result['payload']];
    });
}

/** @return array{key_id:string,source_ip:string,request_id:string,target:string} */
function brvtalStaffOpsAuthenticate(array $server, string $rawBody, ?int $now = null, ?string $stateDirectory = null): array
{
    $config = brvtalStaffOpsConfig();
    if (!$config['enabled']) throw new BrvtalStaffOpsHttpError(404, 'NOT_FOUND');
    if (!brvtalStaffOpsIsHttps($server)) throw new BrvtalStaffOpsHttpError(403, 'HTTPS_REQUIRED');

    $sourceIp = trim((string)($server['REMOTE_ADDR'] ?? ''));
    if ($sourceIp === '' || !in_array($sourceIp, $config['allowlist'], true)) {
        throw new BrvtalStaffOpsHttpError(403, 'ORIGIN_NOT_ALLOWED');
    }
    $keyId = trim((string)($server['HTTP_X_FACTORY_KEY_ID'] ?? ''));
    $timestamp = trim((string)($server['HTTP_X_FACTORY_TIMESTAMP'] ?? ''));
    $nonce = trim((string)($server['HTTP_X_FACTORY_NONCE'] ?? ''));
    $signature = trim((string)($server['HTTP_X_FACTORY_SIGNATURE'] ?? ''));
    if ($keyId === '' || !hash_equals($config['key_id'], $keyId)) {
        throw new BrvtalStaffOpsHttpError(401, 'INVALID_AUTH');
    }
    if (!ctype_digit($timestamp)) throw new BrvtalStaffOpsHttpError(401, 'INVALID_AUTH');
    $now ??= time();
    if (abs($now - (int)$timestamp) > 300) throw new BrvtalStaffOpsHttpError(401, 'INVALID_AUTH');
    if (!preg_match('/^[A-Fa-f0-9]{32,256}$/D', $nonce)
        || !preg_match('/^[a-f0-9]{64}$/D', $signature)) {
        throw new BrvtalStaffOpsHttpError(401, 'INVALID_AUTH');
    }
    if (strlen($rawBody) > BRVTAL_STAFF_OPS_MAX_BODY_BYTES) {
        throw new BrvtalStaffOpsHttpError(422, 'PAYLOAD_TOO_LARGE');
    }

    brvtalStaffOpsConsumeRate($keyId, $sourceIp, $now, $stateDirectory);
    $method = strtoupper((string)($server['REQUEST_METHOD'] ?? 'GET'));
    $target = brvtalStaffOpsCanonicalTarget((string)($server['REQUEST_URI'] ?? '/'));
    $canonical = brvtalStaffOpsCanonicalRequest($keyId, $method, $target, $timestamp, $nonce, $rawBody);
    $expected = hash_hmac('sha256', $canonical, $config['secret']);
    if (!hash_equals($expected, $signature)) throw new BrvtalStaffOpsHttpError(401, 'INVALID_AUTH');

    brvtalStaffOpsClaimNonce($keyId, $nonce, $now, $stateDirectory);
    $providedRequestId = strtolower(trim((string)($server['HTTP_X_REQUEST_ID'] ?? '')));
    $requestId = preg_match('/^[a-f0-9]{32}$/D', $providedRequestId) === 1
        ? $providedRequestId
        : bin2hex(random_bytes(16));
    return ['key_id'=>$keyId,'source_ip'=>$sourceIp,'request_id'=>$requestId,'target'=>$target];
}

function brvtalStaffOpsProtectedRole(string $role): bool
{
    return in_array(strtolower(trim($role)), ['root','owner','superadmin','platform_owner'], true);
}

function brvtalStaffOpsAllowedManagedRole(string $role): bool
{
    // BRVTAL currently has one real non-root DISCADMIN authority class. Do not
    // invent editor/viewer semantics that the product does not enforce.
    return strtolower(trim($role)) === 'admin';
}

function brvtalStaffOpsMaskEmail(string $email): string
{
    $email = strtolower(trim($email));
    $at = strrpos($email, '@');
    if ($at === false || $at < 1) return '***';
    return mb_substr($email, 0, 1) . '***' . substr($email, $at);
}

function brvtalStaffOpsAssertSafePayload(array $payload): void
{
    $forbidden = '/(?:password|password_hash|token|signature|raw_body|cookie|otp|secret|credential)/i';
    $walk = static function(mixed $value) use (&$walk, $forbidden): void {
        if (!is_array($value)) return;
        foreach ($value as $key => $item) {
            if (preg_match($forbidden, (string)$key) === 1) {
                throw new RuntimeException('UNSAFE_OPS_RESPONSE');
            }
            $walk($item);
        }
    };
    $walk($payload);
}

function brvtalStaffOpsAudit(
    PDO $pdo,
    string $action,
    string $keyId,
    ?int $staffId,
    string $result,
    string $requestId,
    array $meta = []
): void {
    $safeMeta = [];
    foreach ($meta as $key => $value) {
        if (preg_match('/(?:password|token|signature|body|secret|credential)/i', (string)$key) === 1) continue;
        if (is_scalar($value) || $value === null) $safeMeta[(string)$key] = $value;
    }
    $safeMeta['actor_key_id'] = mb_substr($keyId, 0, 128);
    $safeMeta['result'] = mb_substr($result, 0, 40);
    $encodedMeta = json_encode($safeMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $changed = json_encode([], JSON_UNESCAPED_SLASHES);
    $statement = $pdo->prepare(
        'INSERT INTO admin_activity_log(admin_id,admin_name,admin_email,action,resource,resource_id,resource_label,changed_fields,before_json,after_json,meta_json,request_id) '
        . 'VALUES(NULL,NULL,NULL,?,?,?,?,?,?,?,?,?)'
    );
    $statement->execute([
        mb_substr($action, 0, 40),
        'admin_staff',
        $staffId,
        $staffId !== null ? 'Staff #' . $staffId : 'Staff operations',
        $changed,
        null,
        null,
        is_string($encodedMeta) ? $encodedMeta : '{}',
        $requestId,
    ]);
}

/** @return array{items:array<int,array<string,mixed>>,next_cursor:?int} */
function brvtalStaffOpsSearch(PDO $pdo, string $query, ?int $cursor, int $limit): array
{
    $query = trim($query);
    if (mb_strlen($query) < 2 || mb_strlen($query) > 120) {
        throw new BrvtalStaffOpsHttpError(422, 'INVALID_QUERY');
    }
    if ($cursor !== null && $cursor < 1) throw new BrvtalStaffOpsHttpError(422, 'INVALID_CURSOR');
    $limit = max(1, min(100, $limit));
    $escaped = str_replace(['\\','%','_'], ['\\\\','\\%','\\_'], $query);
    $params = ['%' . $escaped . '%', '%' . $escaped . '%'];
    $where = "(name LIKE ? ESCAPE '\\\\' OR email LIKE ? ESCAPE '\\\\')";
    if ($cursor !== null) {
        $where .= ' AND id < ?';
        $params[] = $cursor;
    }
    $statement = $pdo->prepare(
        'SELECT id,name,email,staff_role,is_active,last_login_at FROM admins WHERE ' . $where
        . ' ORDER BY id DESC LIMIT ' . ($limit + 1)
    );
    $statement->execute($params);
    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);
    $hasMore = count($rows) > $limit;
    if ($hasMore) array_pop($rows);
    $items = array_map(static fn(array $row): array => [
        'id'=>(int)$row['id'],
        'name'=>(string)$row['name'],
        'email_masked'=>brvtalStaffOpsMaskEmail((string)$row['email']),
        'role'=>(string)$row['staff_role'],
        'status'=>(int)$row['is_active'] === 1 ? 'active' : 'suspended',
        'last_access_at'=>$row['last_login_at'] ?: null,
    ], $rows);
    $next = $hasMore && $rows !== [] ? (int)array_last($rows)['id'] : null;
    return ['items'=>$items,'next_cursor'=>$next];
}

/** @return array{active:int,suspended:int,recent_failed_logins:?int} */
function brvtalStaffOpsSummary(PDO $pdo): array
{
    $row = $pdo->query(
        'SELECT SUM(is_active=1) AS active_count,SUM(is_active=0) AS suspended_count FROM admins'
    )->fetch(PDO::FETCH_ASSOC) ?: [];
    return [
        'active'=>(int)($row['active_count'] ?? 0),
        'suspended'=>(int)($row['suspended_count'] ?? 0),
        // BRVTAL does not currently persist a trustworthy login-failure counter.
        // Unknown stays null instead of being fabricated as zero.
        'recent_failed_logins'=>null,
    ];
}

/** @return array<string,mixed> */
function brvtalStaffOpsFindForMutation(PDO $pdo, int $id, bool $lock = true): array
{
    if ($id < 1) throw new BrvtalStaffOpsHttpError(422, 'INVALID_STAFF_ID');
    $statement = $pdo->prepare(
        'SELECT id,name,email,staff_role,is_active,credential_epoch,last_login_at FROM admins WHERE id=? LIMIT 1'
        . ($lock ? ' FOR UPDATE' : '')
    );
    $statement->execute([$id]);
    $row = $statement->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) throw new BrvtalStaffOpsHttpError(404, 'STAFF_NOT_FOUND');
    if (brvtalStaffOpsProtectedRole((string)$row['staff_role'])) {
        throw new BrvtalStaffOpsHttpError(403, 'PROTECTED_AUTHORITY');
    }
    return $row;
}

function brvtalStaffOpsTransaction(PDO $pdo, callable $operation): mixed
{
    $pdo->beginTransaction();
    try {
        $result = $operation();
        $pdo->commit();
        return $result;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
