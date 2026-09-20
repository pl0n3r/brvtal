<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/content_ordering.php';

$expect = static function (bool $condition, string $message): void {
    if (!$condition) { fwrite(STDERR, "Content ordering contract failed: {$message}\n"); exit(1); }
};

$resources = brvtalContentOrderResources();
$expect(array_keys($resources) === ['artists','sets','releases','blog'], 'canonical resources must be explicit');
$expect($resources['sets']['table'] === 'sets_media', 'Sets must map to sets_media');
$expect($resources['blog']['activity'] === 'blog', 'Blog activity resource must stay canonical');
$expect(brvtalContentOrderResource(' RELEASES ') === $resources['releases'], 'resource lookup must normalize');
$expect(brvtalContentOrderResource('events') === null, 'non-approved resources must fail closed');
$expect(brvtalContentOrderIds([3,'2',1]) === [3,2,1], 'valid IDs preserve submitted order');
foreach ([[],[1,1],[0,1],[-1,2],['1x',2],[1.5,2]] as $invalid) {
    $failed=false; try { brvtalContentOrderIds($invalid); } catch (InvalidArgumentException $e) { $failed=true; }
    $expect($failed, 'invalid or duplicate IDs must fail');
}
$oversized = range(1,501);
$failed=false; try { brvtalContentOrderIds($oversized); } catch (InvalidArgumentException $e) { $failed=true; }
$expect($failed, 'oversized order payload must fail');
$expect(brvtalContentOrderMatches([3,1,2],[1,2,3]), 'exact ID sets may arrive in different order');
$expect(!brvtalContentOrderMatches([1,2],[1,2,3]), 'partial set must be stale');
$expect(!brvtalContentOrderMatches([1,2,4],[1,2,3]), 'foreign ID must be stale');

$endpoint=(string)file_get_contents(__DIR__ . '/../api/reorder.php');
$core=(string)file_get_contents(__DIR__ . '/../discadmin/index-core.php');
$modules=(string)file_get_contents(__DIR__ . '/../discadmin/admin-modules.js');
$releases=(string)file_get_contents(__DIR__ . '/../discadmin/releases.js');
$blog=(string)file_get_contents(__DIR__ . '/../discadmin/blog.js');
$public=(string)file_get_contents(__DIR__ . '/../api/public.php');
$expect(str_contains($endpoint,'brvtal_admin_require_csrf'), 'reorder must require CSRF');
$expect(str_contains($endpoint,'FOR UPDATE'), 'reorder must lock collection');
$expect(str_contains($endpoint,'ORDER_STALE'), 'stale writes must fail explicitly');
$expect(str_contains($endpoint,'$previousIds !== $currentIds'), 'reorder must reject a concurrent order change even when the ID set is unchanged');
$expect(str_contains($endpoint,'beginTransaction') && str_contains($endpoint,'rollBack'), 'reorder must be transactional');
$expect(str_contains($endpoint,'SET sort_order=? WHERE id=?'), 'reorder must normalize positions');
$expect(!str_contains($endpoint,'{$table}') && !str_contains($endpoint,'{$labelColumn}'), 'reorder SQL must stay literal after resource whitelisting');
$expect(str_contains($core,'replaceLegacySortOrderControl'), 'Artist/Set forms must replace numeric order with visual-order guidance');
$expect(
    str_contains($core,'function orderingAttributes(section)')
        && str_contains($core,'artistOrderRow')
        && str_contains($core,'setOrderRow'),
    'core must retain the runtime ordering renderer hooks covered by real-stack E2E'
);
$expect(str_contains($modules,'visualOrderValue'), 'create/edit must preserve hidden order');
$expect(!str_contains($releases,'id="release_sort_order"'), 'Release numeric Sort Order must be removed');
$expect(str_contains($blog,'replaceBlogSortOrderControl'), 'Blog numeric Sort Order must be replaced before the editor is shown');
$expect(str_contains($public,"ORDER BY sort_order ASC, featured DESC, COALESCE(release_date"), 'public Releases must honor canonical order first');
$expect(str_contains($public,"ORDER BY sort_order ASC, featured DESC, COALESCE(published_at"), 'public Blog must honor canonical order first');
if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    fwrite(STDOUT, "Content ordering contract passed (MariaDB endpoint integration skipped).\n");
    exit(0);
}

$baseDb = (string)(getenv('BRVTAL_TEST_DB_NAME') ?: '');
$expect(
    (bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/', $baseDb),
    'integration database must stay inside the brvtal_test namespace'
);

$dbHost = (string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1');
$dbPort = (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306);
$dbUser = (string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root');
$dbPass = (string)(getenv('BRVTAL_TEST_DB_PASS') ?: '');
$scratchDb = $baseDb . '_ordering_' . bin2hex(random_bytes(4));
$expect(
    (bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/', $scratchDb),
    'scratch database must stay inside the test namespace'
);

$serverPdo = new PDO(
    sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $dbHost, $dbPort),
    $dbUser,
    $dbPass,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);
$serverPdo->exec("CREATE DATABASE `{$scratchDb}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");

$sandbox = sys_get_temp_dir() . '/brvtal-ordering-' . bin2hex(random_bytes(5));
$routerPath = $sandbox . '-router.php';
$serverLog = $sandbox . '-server.log';
$serverProcess = null;
$cleaned = false;

$removeTree = static function (string $path) use (&$removeTree): void {
    if (!is_dir($path)) {
        if (is_file($path) || is_link($path)) @unlink($path);
        return;
    }
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $removeTree($path . DIRECTORY_SEPARATOR . $entry);
    }
    @rmdir($path);
};

$cleanup = static function () use (
    &$cleaned,
    &$serverProcess,
    $serverPdo,
    $scratchDb,
    $sandbox,
    $routerPath,
    $serverLog,
    $removeTree
): void {
    if ($cleaned) return;
    $cleaned = true;
    if (is_resource($serverProcess)) {
        @proc_terminate($serverProcess);
        usleep(100000);
        $status = @proc_get_status($serverProcess);
        if (is_array($status) && ($status['running'] ?? false)) {
            @proc_terminate($serverProcess, 9);
        }
        @proc_close($serverProcess);
    }
    try {
        $serverPdo->exec("DROP DATABASE IF EXISTS `{$scratchDb}`");
    } catch (Throwable) {
    }
    $removeTree($sandbox);
    @unlink($routerPath);
    @unlink($serverLog);
};
register_shutdown_function($cleanup);

$pdo = new PDO(
    sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $dbHost, $dbPort, $scratchDb),
    $dbUser,
    $dbPass,
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);
$pdo->exec(<<<'SQL'
CREATE TABLE admins (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  email VARCHAR(190) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  name VARCHAR(120) NOT NULL DEFAULT 'BRVTAL Admin',
  is_active TINYINT(1) NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE sets_media (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(180) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
SQL);
$migration = (string)file_get_contents(__DIR__ . '/../database/migration_admin_activity_01.sql');
$expect($migration !== '', 'admin activity migration must be readable');
$pdo->exec($migration);

$adminId = 910001;
$pdo->prepare('INSERT INTO admins(id,email,password_hash,name,is_active) VALUES(?,?,?,?,1)')
    ->execute([$adminId, 'ordering-ci@brvtal.test', password_hash('test-only', PASSWORD_DEFAULT), 'Ordering CI']);
$insertSet = $pdo->prepare('INSERT INTO sets_media(title,sort_order) VALUES(?,?)');
foreach ([['Ordering A',0],['Ordering B',1],['Ordering C',2]] as [$title,$position]) {
    $insertSet->execute([$title,$position]);
}
$ids = array_map('intval', $pdo->query('SELECT id FROM sets_media ORDER BY sort_order,id')->fetchAll(PDO::FETCH_COLUMN));
$expect(count($ids) === 3, 'ordering fixture must create three records');

$copies = [
    'api/reorder.php',
    'config/admin_activity.php',
    'config/admin_auth.php',
    'config/admin_session_revalidation.php',
    'config/artist_collective_lifecycle.php',
    'config/bootstrap.php',
    'config/content_ordering.php',
    'config/public_health.php',
];
foreach ($copies as $relative) {
    $source = dirname(__DIR__) . '/' . $relative;
    $target = $sandbox . '/' . $relative;
    if (!is_dir(dirname($target))) mkdir(dirname($target), 0777, true);
    $expect(copy($source, $target), "sandbox copy failed for {$relative}");
}
$config = [
    'app' => [
        'name' => 'BRVTAL',
        'base_url' => 'http://127.0.0.1',
        'timezone' => 'America/Bogota',
        'debug' => false,
    ],
    'db' => [
        'host' => $dbHost,
        'port' => $dbPort,
        'name' => $scratchDb,
        'user' => $dbUser,
        'pass' => $dbPass,
        'charset' => 'utf8mb4',
    ],
    'security' => [
        'session_name' => 'BRVTAL_ORDER_TEST',
        'csrf_key' => 'ordering-test-csrf-key',
        'encryption_key' => 'ordering-test-encryption-key-32-bytes-minimum',
    ],
];
file_put_contents($sandbox . '/config/config.php', "<?php\nreturn " . var_export($config, true) . ";\n");

$adminAuthPath = var_export($sandbox . '/config/admin_auth.php', true);
$router = "<?php\n"
    . "\$path = parse_url((string)(\$_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH);\n"
    . "if (\$path === '/__test/session') {\n"
    . "    require_once {$adminAuthPath};\n"
    . "    brvtal_admin_session_start();\n"
    . "    \$_SESSION['admin_id'] = {$adminId};\n"
    . "    \$_SESSION['issued_at'] = time();\n"
    . "    \$_SESSION['last_activity'] = time();\n"
    . "    header('Content-Type: application/json; charset=utf-8');\n"
    . "    echo json_encode(['ok'=>true,'csrf'=>brvtal_admin_csrf_token()]);\n"
    . "    return true;\n"
    . "}\n"
    . "return false;\n";
file_put_contents($routerPath, $router);

$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
$expect(is_resource($socket), "unable to reserve local endpoint port: {$error}");
$socketName = stream_socket_get_name($socket, false);
fclose($socket);
$portParts = explode(':', (string)$socketName);
$httpPort = (int)end($portParts);
$expect($httpPort > 0, 'local endpoint port must be resolved');

$descriptors = [
    0 => ['pipe','r'],
    1 => ['file',$serverLog,'a'],
    2 => ['file',$serverLog,'a'],
];
$serverProcess = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$httpPort}", '-t', $sandbox, $routerPath],
    $descriptors,
    $pipes,
    $sandbox
);
$expect(is_resource($serverProcess), 'PHP endpoint server must start');
if (isset($pipes[0]) && is_resource($pipes[0])) fclose($pipes[0]);

$orderHttp = static function (
    string $url,
    string $method = 'GET',
    ?array $payload = null,
    array $headers = []
): array {
    $requestHeaders = array_merge(['Accept: application/json'], $headers);
    $options = [
        'method' => $method,
        'ignore_errors' => true,
        'timeout' => 5,
        'header' => implode("\r\n", $requestHeaders),
    ];
    if ($payload !== null) {
        $options['content'] = json_encode($payload, JSON_UNESCAPED_SLASHES);
    }
    $context = stream_context_create(['http' => $options]);
    $body = @file_get_contents($url, false, $context);
    $responseHeaders = $http_response_header ?? [];
    $status = 0;
    foreach ($responseHeaders as $header) {
        if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $header, $match)) {
            $status = (int)$match[1];
        }
    }
    return [
        'status' => $status,
        'body' => $body === false ? '' : $body,
        'headers' => $responseHeaders,
    ];
};

$origin = "http://127.0.0.1:{$httpPort}";
$sessionResponse = null;
for ($attempt = 0; $attempt < 60; $attempt++) {
    $candidate = $orderHttp($origin . '/__test/session');
    if ($candidate['status'] === 200) {
        $sessionResponse = $candidate;
        break;
    }
    usleep(100000);
}
$expect(is_array($sessionResponse), 'local endpoint server must become ready');
$sessionPayload = json_decode((string)$sessionResponse['body'], true);
$expect(is_array($sessionPayload) && !empty($sessionPayload['csrf']), 'test session must expose a CSRF token');

$cookie = '';
foreach ((array)$sessionResponse['headers'] as $header) {
    if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $header, $match)) {
        $cookie = $match[1];
        break;
    }
}
$expect($cookie !== '', 'test session must set an authentication cookie');
$csrf = (string)$sessionPayload['csrf'];
$endpointUrl = $origin . '/api/reorder.php';
$jsonHeader = ['Content-Type: application/json'];
$authHeaders = ['Content-Type: application/json', 'Cookie: ' . $cookie];
$csrfHeaders = array_merge($authHeaders, ['X-CSRF-Token: ' . $csrf]);

$authRejected = $orderHttp($endpointUrl, 'POST', [
    'resource' => 'sets',
    'ids' => $ids,
    'previous_ids' => $ids,
], $jsonHeader);
$authBody = json_decode($authRejected['body'], true);
$expect($authRejected['status'] === 401 && ($authBody['error'] ?? '') === 'AUTH_REQUIRED', 'endpoint must reject unauthenticated reorder');

$csrfRejected = $orderHttp($endpointUrl, 'POST', [
    'resource' => 'sets',
    'ids' => $ids,
    'previous_ids' => $ids,
], $authHeaders);
$csrfBody = json_decode($csrfRejected['body'], true);
$expect($csrfRejected['status'] === 419 && ($csrfBody['error'] ?? '') === 'CSRF', 'endpoint must reject missing CSRF');

$readPositions = static function (PDO $connection, array $orderedIds): array {
    $statement = $connection->query('SELECT id, sort_order FROM sets_media');
    if (!$statement instanceof PDOStatement) {
        throw new RuntimeException('Unable to read persisted ordering fixture.');
    }
    $positions = [];
    foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $positions[(int)$row['id']] = (int)$row['sort_order'];
    }
    return array_map(
        static fn (int $id): ?int => $positions[$id] ?? null,
        $orderedIds
    );
};

$target = [$ids[2], $ids[0], $ids[1]];
$committed = $orderHttp($endpointUrl, 'POST', [
    'resource' => 'sets',
    'ids' => $target,
    'previous_ids' => $ids,
], $csrfHeaders);
$committedBody = json_decode($committed['body'], true);
$expect($committed['status'] === 200 && ($committedBody['ok'] ?? false) === true, 'valid reorder must commit');
$persistedPositions = $readPositions($pdo, $target);
$expect($persistedPositions === [0,1,2], 'committed reorder must persist exact zero-based positions');

$staleTarget = [$ids[0], $ids[2], $ids[1]];
$stale = $orderHttp($endpointUrl, 'POST', [
    'resource' => 'sets',
    'ids' => $staleTarget,
    'previous_ids' => $ids,
], $csrfHeaders);
$staleBody = json_decode($stale['body'], true);
$expect($stale['status'] === 409 && ($staleBody['error'] ?? '') === 'ORDER_STALE', 'stale reorder must preserve its public domain error');
$afterStalePositions = $readPositions($pdo, $target);
$expect($afterStalePositions === [0,1,2], 'stale reorder must preserve exact persisted positions');

$pdo->exec('DROP TABLE admin_activity_log');
$rollbackTarget = [$ids[1], $ids[2], $ids[0]];
$rollback = $orderHttp($endpointUrl, 'POST', [
    'resource' => 'sets',
    'ids' => $rollbackTarget,
    'previous_ids' => $target,
], $csrfHeaders);
$rollbackBody = json_decode($rollback['body'], true);
$expect($rollback['status'] === 503 && ($rollbackBody['error'] ?? '') === 'ACTIVITY_SCHEMA_MISSING', 'audit failure must remain a bounded public domain error');
$afterRollbackPositions = $readPositions($pdo, $target);
$expect($afterRollbackPositions === [0,1,2], 'audit failure must rollback every ordering position');

$pdo->exec('DROP TABLE sets_media');
$unexpected = $orderHttp($endpointUrl, 'POST', [
    'resource' => 'sets',
    'ids' => $target,
    'previous_ids' => $target,
], $csrfHeaders);
$unexpectedBody = json_decode($unexpected['body'], true);
$expect($unexpected['status'] === 500 && ($unexpectedBody['error'] ?? '') === 'INTERNAL_ERROR', 'unexpected database failure must return INTERNAL_ERROR');
$expect(
    !str_contains($unexpected['body'], 'SQLSTATE') && !str_contains($unexpected['body'], 'sets_media'),
    'unexpected database details must not leak to the client'
);

$cleanup();
fwrite(STDOUT, "Content ordering contract + MariaDB endpoint integration passed.\n");
