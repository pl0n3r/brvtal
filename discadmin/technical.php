<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_auth.php';
require_once __DIR__ . '/../config/deployment.php';
require_once __DIR__ . '/../config/version.php';
brvtal_admin_require();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

$action = (string)($_GET['action'] ?? 'system');
$root = dirname(__DIR__);

$bytes = static function ($n): string {
    if ($n === false || $n === null) return 'N/A';
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $i = 0;
    $n = (float)$n;
    while ($n >= 1024 && $i < count($units) - 1) { $n /= 1024; $i++; }
    return number_format($n, 2) . ' ' . $units[$i];
};

$tableExists = static function (PDO $pdo, string $table): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $st->execute([$table]);
    return (int)$st->fetchColumn() > 0;
};

$columnExists = static function (PDO $pdo, string $table, string $column): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    $st->execute([$table, $column]);
    return (int)$st->fetchColumn() > 0;
};

$sourceStats = static function (string $base): array {
    $languages = [
        'php' => 'PHP',
        'js' => 'JavaScript',
        'mjs' => 'JavaScript',
        'css' => 'CSS',
        'sql' => 'SQL',
    ];
    $excluded = ['.git', 'node_modules', 'vendor', 'uploads', 'storage', 'test-results', 'playwright-report'];
    $byLanguage = [];
    $files = 0;
    $loc = 0;

    $directory = new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS);
    $filter = new RecursiveCallbackFilterIterator($directory, static function (SplFileInfo $item) use ($excluded): bool {
        if ($item->isDir()) return !in_array($item->getFilename(), $excluded, true);
        return true;
    });
    $iterator = new RecursiveIteratorIterator($filter);

    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile()) continue;
        $name = $file->getFilename();
        if (preg_match('/\.(?:min\.(?:js|css)|map)$/i', $name)) continue;
        $ext = strtolower($file->getExtension());
        if (!isset($languages[$ext])) continue;
        if ($file->getSize() > 5 * 1024 * 1024) continue;

        $lines = 0;
        $handle = @fopen($file->getPathname(), 'rb');
        if ($handle) {
            while (!feof($handle)) {
                if (fgets($handle) !== false) $lines++;
            }
            fclose($handle);
        }

        $label = $languages[$ext];
        $byLanguage[$label] ??= ['files'=>0, 'lines'=>0];
        $byLanguage[$label]['files']++;
        $byLanguage[$label]['lines'] += $lines;
        $files++;
        $loc += $lines;
    }

    uasort($byLanguage, static fn(array $a, array $b): int => $b['lines'] <=> $a['lines']);
    return ['source_files'=>$files, 'source_lines'=>$loc, 'by_language'=>$byLanguage];
};

$githubRequest = static function (string $url): ?array {
    if (!function_exists('curl_init')) return null;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_HTTPHEADER => [
            'Accept: application/vnd.github+json',
            'User-Agent: BRVTAL-System-Status',
            'X-GitHub-Api-Version: 2022-11-28',
        ],
    ]);
    $raw = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $headerSize = (int)curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    if (!is_string($raw) || $status < 200 || $status >= 300) return null;
    $headerText = substr($raw, 0, $headerSize);
    $bodyText = substr($raw, $headerSize);
    $headers = [];
    foreach (preg_split('/\r?\n/', $headerText) ?: [] as $line) {
        if (!str_contains($line, ':')) continue;
        [$key, $value] = array_map(trim(...), explode(':', $line, 2));
        $headers[strtolower($key)] = $value;
    }
    $body = json_decode($bodyText, true);
    return is_array($body) ? ['body'=>$body, 'headers'=>$headers] : null;
};

$githubMetrics = static function () use ($githubRequest): array {
    $cache = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'brvtal-system-status-github.json';
    $ttl = 1800;
    $readCache = static function (string $path): ?array {
        if (!is_file($path)) return null;
        $decoded = json_decode((string)@file_get_contents($path), true);
        return is_array($decoded) ? $decoded : null;
    };
    $hasBacklogSchema = static fn(array $value): bool => array_key_exists('open_issues', $value) && array_key_exists('recent_issues', $value);
    $recentIssues = static function (array $body): array {
        $items = [];
        foreach (array_slice(is_array($body['items'] ?? null) ? $body['items'] : [], 0, 6) as $issue) {
            if (!is_array($issue)) continue;
            $number = (int)($issue['number'] ?? 0);
            if ($number < 1) continue;
            $labels = [];
            foreach (array_slice(is_array($issue['labels'] ?? null) ? $issue['labels'] : [], 0, 4) as $label) {
                if (!is_array($label)) continue;
                $name = trim((string)($label['name'] ?? ''));
                if ($name !== '') $labels[] = $name;
            }
            $items[] = [
                'number' => $number,
                'title' => trim((string)($issue['title'] ?? 'Untitled issue')),
                'url' => 'https://github.com/pl0n3r/brvtal/issues/' . $number,
                'labels' => $labels,
                'updated_at' => (string)($issue['updated_at'] ?? ''),
            ];
        }
        return $items;
    };

    $cached = $readCache($cache);
    if ($cached && $hasBacklogSchema($cached) && (time() - (int)($cached['cached_at'] ?? 0)) < $ttl) {
        $cached['cache'] = 'fresh';
        $cached['backlog_state'] = 'fresh';
        return $cached;
    }

    $commits = $githubRequest('https://api.github.com/repos/pl0n3r/brvtal/commits?sha=main&per_page=1');
    $prs = $githubRequest('https://api.github.com/search/issues?q=repo%3Apl0n3r%2Fbrvtal+is%3Apr+is%3Amerged&per_page=1');
    $issues = $githubRequest('https://api.github.com/search/issues?q=repo%3Apl0n3r%2Fbrvtal+is%3Aissue+is%3Aopen&sort=updated&order=desc&per_page=6');
    if ($commits && $prs && $issues) {
        $commitCount = count($commits['body']);
        $link = (string)($commits['headers']['link'] ?? '');
        if (preg_match('/[?&]page=(\d+)>; rel="last"/', $link, $match)) $commitCount = (int)$match[1];
        $payload = [
            'ok' => true,
            'commits' => $commitCount,
            'merged_prs' => (int)($prs['body']['total_count'] ?? 0),
            'open_issues' => (int)($issues['body']['total_count'] ?? 0),
            'recent_issues' => $recentIssues($issues['body']),
            'backlog_state' => 'fresh',
            'source' => 'github_public_api',
            'cached_at' => time(),
            'fetched_at' => date(DATE_ATOM),
            'cache' => 'fresh',
        ];
        @file_put_contents($cache, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), LOCK_EX);
        return $payload;
    }

    if ($cached) {
        $cached['ok'] = true;
        $cached['cache'] = 'stale';
        if ($hasBacklogSchema($cached)) {
            $cached['backlog_state'] = 'stale';
        } else {
            $cached['open_issues'] = null;
            $cached['recent_issues'] = [];
            $cached['backlog_state'] = 'unavailable';
        }
        return $cached;
    }
    return [
        'ok'=>false,
        'commits'=>null,
        'merged_prs'=>null,
        'open_issues'=>null,
        'recent_issues'=>[],
        'backlog_state'=>'unavailable',
        'source'=>'github_public_api',
        'cache'=>'unavailable',
    ];
};

try {
    if ($action === 'overview') {
        $started = microtime(true);
        $pdo = db();
        $pdo->query('SELECT 1');
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $counts = [];
        foreach ([
            'events'=>'events', 'artists'=>'artists', 'sets'=>'sets_media', 'media'=>'media',
            'pages'=>'pages', 'releases'=>'releases', 'blog'=>'blog_posts'
        ] as $label => $table) {
            $counts[$label] = in_array($table, $tables, true)
                ? (int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn()
                : 0;
        }

        $hostTotal = @disk_total_space($root);
        $hostFree = @disk_free_space($root);

        $requiredExtensions = ['PDO','pdo_mysql','mbstring','json','curl','fileinfo','openssl','iconv'];
        $extensions = [];
        foreach ($requiredExtensions as $extension) $extensions[$extension] = extension_loaded($extension);
        $phpReady = !in_array(false, $extensions, true);

        $totpColumnsReady = true;
        foreach (['totp_enabled','totp_secret_enc','totp_confirmed_at'] as $column) {
            if (!$columnExists($pdo, 'admins', $column)) $totpColumnsReady = false;
        }
        $totpKeyReady = false;
        if ($tableExists($pdo, 'settings')) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM settings WHERE setting_key='security.totp_encryption_key' AND setting_value IS NOT NULL AND setting_value<>''");
            $st->execute();
            $totpKeyReady = (int)$st->fetchColumn() > 0;
        }
        $activityReady = $tableExists($pdo, 'admin_activity_log');
        $uploadsReady = is_dir($root . '/uploads') && is_writable($root . '/uploads');
        $logsReady = is_dir($root . '/storage/logs') && is_writable($root . '/storage/logs');
        $deploymentReady = brvtalDeploymentIsExact();

        $checks = [
            ['key'=>'api','label'=>'API','status'=>'ok','value'=>'ONLINE'],
            ['key'=>'database','label'=>'DATABASE','status'=>'ok','value'=>'CONNECTED'],
            ['key'=>'uploads','label'=>'MEDIA STORAGE','status'=>$uploadsReady?'ok':'error','value'=>$uploadsReady?'WRITABLE':'CHECK'],
            ['key'=>'logs','label'=>'LOGS','status'=>$logsReady?'ok':'error','value'=>$logsReady?'WRITABLE':'CHECK'],
            ['key'=>'runtime','label'=>'PHP RUNTIME','status'=>$phpReady?'ok':'error','value'=>$phpReady?'READY':'CHECK'],
            ['key'=>'totp','label'=>'2FA FOUNDATION','status'=>($totpColumnsReady&&$totpKeyReady)?'ok':'error','value'=>($totpColumnsReady&&$totpKeyReady)?'READY':'CHECK'],
            ['key'=>'activity','label'=>'ACTIVITY HISTORY','status'=>$activityReady?'ok':'error','value'=>$activityReady?'READY':'CHECK'],
            ['key'=>'deployment','label'=>'DEPLOYMENT','status'=>$deploymentReady?'ok':'error','value'=>$deploymentReady?'TRACKED':'CHECK'],
        ];
        $okCount = count(array_filter($checks, static fn(array $check): bool => $check['status'] === 'ok'));
        $score = (int)round(($okCount / count($checks)) * 100);
        $healthStatus = $score === 100 ? 'healthy' : ($score >= 75 ? 'degraded' : 'critical');

        $issues = [];
        foreach ($checks as $check) {
            if ($check['status'] !== 'ok') $issues[] = ['severity'=>'error','title'=>$check['label'],'detail'=>$check['value'] . ' — review this service.'];
        }
        $github = $githubMetrics();
        $repositoryDiagnostics = [];
        if (!$github['ok']) {
            $repositoryDiagnostics[] = ['severity'=>'info','title'=>'GITHUB METRICS','detail'=>'GitHub metrics are temporarily unavailable; platform health is unaffected.'];
        } elseif (($github['cache'] ?? '') === 'stale') {
            $repositoryDiagnostics[] = ['severity'=>'info','title'=>'GITHUB CACHE','detail'=>'Showing the most recent cached GitHub metrics.'];
        }
        if (($github['backlog_state'] ?? 'unavailable') === 'unavailable' && $github['ok']) {
            $repositoryDiagnostics[] = ['severity'=>'info','title'=>'GITHUB BACKLOG','detail'=>'Open Issue metadata is temporarily unavailable; platform health is unaffected.'];
        }

        echo json_encode([
            'ok' => true,
            'health' => ['score'=>$score, 'status'=>$healthStatus, 'checks_ok'=>$okCount, 'checks_total'=>count($checks)],
            'checks' => $checks,
            'storage' => [
                'available'=>false,
                'source'=>'managed_storage_endpoint',
            ],
            'host_filesystem' => [
                'total_bytes'=>$hostTotal === false ? null : (int)$hostTotal,
                'free_bytes'=>$hostFree === false ? null : (int)$hostFree,
                'total'=>$bytes($hostTotal),
                'free'=>$bytes($hostFree),
                'diagnostic_only'=>true,
            ],
            'database' => [
                'driver'=>$pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
                'server'=>$pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
                'counts'=>$counts,
            ],
            'runtime' => [
                'php'=>PHP_VERSION,
                'sapi'=>PHP_SAPI,
                'memory_limit'=>ini_get('memory_limit'),
                'upload_max_filesize'=>ini_get('upload_max_filesize'),
                'post_max_size'=>ini_get('post_max_size'),
                'max_execution_time'=>ini_get('max_execution_time'),
                'extensions'=>$extensions,
            ],
            'deployment' => [
                'commit'=>brvtal_deployment_sha(),
                'short_commit'=>brvtal_deployment_short_sha(),
                'source'=>brvtal_deployment_source(),
                'exact'=>brvtalDeploymentIsExact(),
                'version'=>BRVTAL_APP_VERSION,
                'environment'=>BRVTAL_APP_ENV,
                'release_date'=>BRVTAL_RELEASE_DATE,
            ],
            'repository' => array_merge($sourceStats($root), ['github'=>$github]),
            'issues' => $issues,
            'repository_diagnostics' => $repositoryDiagnostics,
            'time' => date(DATE_ATOM),
            'generated_ms' => round((microtime(true) - $started) * 1000, 2),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'health') {
        db()->query('SELECT 1');
        echo json_encode(['ok'=>true,'api'=>'online','database'=>'connected','time'=>date(DATE_ATOM)]);
        exit;
    }

    if ($action === 'system') {
        $pdo = db();
        $pdo->query('SELECT 1');
        echo json_encode([
            'ok'=>true,'php'=>PHP_VERSION,'sapi'=>PHP_SAPI,
            'session'=>session_status()===PHP_SESSION_ACTIVE?'active':'inactive',
            'database'=>'connected','driver'=>$pdo->getAttribute(PDO::ATTR_DRIVER_NAME),'server'=>$pdo->getAttribute(PDO::ATTR_SERVER_VERSION),
            'storage_free'=>$bytes(@disk_free_space($root)),'storage_total'=>$bytes(@disk_total_space($root)),
            'uploads_writable'=>is_writable($root.'/uploads'),'logs_writable'=>is_writable($root.'/storage/logs'),'time'=>date(DATE_ATOM),
        ]);
        exit;
    }

    if ($action === 'database') {
        $pdo = db();
        $tables = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        $counts = [];
        foreach (['events','artists','sets_media','media','pages','settings','analytics_events','releases','blog_posts','admin_activity_log'] as $table) {
            if (in_array($table,$tables,true)) $counts[$table]=(int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        }
        echo json_encode(['ok'=>true,'driver'=>$pdo->getAttribute(PDO::ATTR_DRIVER_NAME),'server'=>$pdo->getAttribute(PDO::ATTR_SERVER_VERSION),'database'=>$config['db']['name']??'[configured]','tables'=>$tables,'counts'=>$counts]);
        exit;
    }

    if ($action === 'storage') {
        echo json_encode([
            'ok'=>true,'root_free'=>$bytes(@disk_free_space($root)),'root_total'=>$bytes(@disk_total_space($root)),
            'uploads_exists'=>is_dir($root.'/uploads'),'uploads_writable'=>is_writable($root.'/uploads'),
            'logs_exists'=>is_dir($root.'/storage/logs'),'logs_writable'=>is_writable($root.'/storage/logs'),
            'uploads_items'=>is_dir($root.'/uploads')?count(array_diff(scandir($root.'/uploads'),['.','..'])):0,
        ]);
        exit;
    }

    if ($action === 'php') {
        $extensionsToCheck=['PDO','pdo_mysql','mbstring','json','curl','fileinfo','openssl','iconv'];
        $extensions=[]; foreach($extensionsToCheck as $extension)$extensions[$extension]=extension_loaded($extension);
        echo json_encode(['ok'=>true,'version'=>PHP_VERSION,'sapi'=>PHP_SAPI,'memory_limit'=>ini_get('memory_limit'),'upload_max_filesize'=>ini_get('upload_max_filesize'),'post_max_size'=>ini_get('post_max_size'),'max_execution_time'=>ini_get('max_execution_time'),'extensions'=>$extensions]);
        exit;
    }

    if ($action === 'logs') {
        $file=$root.'/storage/logs/brvtal.log';
        $exists=is_file($file);
        $rawLines=$exists?file($file,FILE_IGNORE_NEW_LINES):false;
        $lines=is_array($rawLines)?array_slice($rawLines,-300):[];
        $measuredSize=$exists?filesize($file):false;
        $size=$measuredSize===false?0:(int)$measuredSize;
        echo json_encode(['ok'=>true,'file'=>'storage/logs/brvtal.log','bytes'=>$size,'lines'=>count($lines),'content'=>implode("\n",$lines)]);
        exit;
    }

    echo json_encode(['ok'=>false,'error'=>'UNKNOWN_ACTION'],JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    brvtal_log('TECH_ERROR','Technical endpoint failed.',['action'=>$action,'class'=>$e::class]);
    http_response_code(500);
    echo json_encode(['ok'=>false,'error'=>'TECH_ERROR']);
}
