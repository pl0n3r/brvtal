<?php
declare(strict_types=1);

require_once __DIR__ . '/deployment.php';

function brvtal_backup_base_dir(?string $override = null): string
{
    return $override !== null && trim($override) !== ''
        ? rtrim($override, DIRECTORY_SEPARATOR)
        : dirname(__DIR__) . '/storage/backups';
}

function brvtal_backup_format_bytes(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $value = max(0, (float)$bytes);
    $index = 0;
    while ($value >= 1024 && $index < count($units) - 1) {
        $value /= 1024;
        $index++;
    }
    return number_format($value, $index === 0 ? 0 : 2) . ' ' . $units[$index];
}

function brvtal_backup_ensure_private_dir(string $dir): void
{
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) {
        throw new RuntimeException('BACKUP_DIRECTORY_CREATE_FAILED');
    }
    @chmod($dir, 0700);

    $htaccess = $dir . '/.htaccess';
    if (!is_file($htaccess)) {
        @file_put_contents($htaccess, "Options -Indexes\nRequire all denied\nDeny from all\n", LOCK_EX);
    }
}

function brvtal_backup_id(): string
{
    return 'brvtal-' . gmdate('Ymd\THis\Z') . '-' . bin2hex(random_bytes(4));
}

function brvtal_backup_valid_id(string $id): bool
{
    return (bool)preg_match('/^brvtal-\d{8}T\d{6}Z-[a-f0-9]{8}$/', $id);
}

function brvtal_backup_identifier(string $name): string
{
    return '`' . str_replace('`', '``', $name) . '`';
}

function brvtal_backup_sql_literal(PDO $pdo, mixed $value): string
{
    if ($value === null) return 'NULL';
    if (is_bool($value)) return $value ? '1' : '0';
    if (is_int($value)) return (string)$value;
    if (is_float($value)) {
        if (!is_finite($value)) return 'NULL';
        return str_replace(',', '.', (string)$value);
    }
    $quoted = $pdo->quote((string)$value, PDO::PARAM_STR);
    if ($quoted === false) throw new RuntimeException('BACKUP_SQL_QUOTE_FAILED');
    return $quoted;
}

function brvtal_backup_export_database(PDO $pdo, string $target): array
{
    $part = $target . '.part';
    @unlink($part);
    $handle = @fopen($part, 'xb');
    if (!is_resource($handle)) throw new RuntimeException('BACKUP_DATABASE_OPEN_FAILED');

    $tables = [];
    $views = [];
    $rowsTotal = 0;
    $inTransaction = false;

    try {
        $list = $pdo->query("SHOW FULL TABLES")->fetchAll(PDO::FETCH_NUM);
        foreach ($list as $row) {
            $name = (string)($row[0] ?? '');
            $type = strtoupper((string)($row[1] ?? 'BASE TABLE'));
            if ($name === '') continue;
            if ($type === 'VIEW') $views[] = $name;
            else $tables[] = $name;
        }
        sort($tables, SORT_STRING);
        sort($views, SORT_STRING);

        $databaseName = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
        fwrite($handle, "-- BRVTAL DATABASE BACKUP\n");
        fwrite($handle, '-- Created: ' . gmdate(DATE_ATOM) . "\n");
        fwrite($handle, '-- Database: ' . str_replace(["\r", "\n"], '', $databaseName) . "\n\n");
        fwrite($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

        $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $pdo->beginTransaction();
        $inTransaction = true;

        foreach ($tables as $table) {
            $quotedTable = brvtal_backup_identifier($table);
            $createRow = $pdo->query("SHOW CREATE TABLE {$quotedTable}")->fetch(PDO::FETCH_ASSOC) ?: [];
            $createSql = (string)($createRow['Create Table'] ?? array_values($createRow)[1] ?? '');
            if ($createSql === '') throw new RuntimeException('BACKUP_CREATE_SQL_MISSING');

            fwrite($handle, "-- --------------------------------------------------------\n");
            fwrite($handle, "-- Table {$quotedTable}\n");
            fwrite($handle, "DROP TABLE IF EXISTS {$quotedTable};\n{$createSql};\n\n");

            $columns = $pdo->query("SHOW COLUMNS FROM {$quotedTable}")->fetchAll(PDO::FETCH_ASSOC);
            $columnNames = array_values(array_filter(array_map(static fn(array $column): string => (string)($column['Field'] ?? ''), $columns)));
            if ($columnNames === []) continue;

            $count = (int)$pdo->query("SELECT COUNT(*) FROM {$quotedTable}")->fetchColumn();
            $rowsTotal += $count;
            $chunk = 250;
            $columnSql = implode(',', array_map('brvtal_backup_identifier', $columnNames));

            for ($offset = 0; $offset < $count; $offset += $chunk) {
                $rows = $pdo->query("SELECT * FROM {$quotedTable} LIMIT {$chunk} OFFSET {$offset}")->fetchAll(PDO::FETCH_ASSOC);
                if ($rows === []) continue;
                $values = [];
                foreach ($rows as $row) {
                    $literals = [];
                    foreach ($columnNames as $column) {
                        $literals[] = brvtal_backup_sql_literal($pdo, $row[$column] ?? null);
                    }
                    $values[] = '(' . implode(',', $literals) . ')';
                }
                fwrite($handle, "INSERT INTO {$quotedTable} ({$columnSql}) VALUES\n" . implode(",\n", $values) . ";\n");
            }
            fwrite($handle, "\n");
        }

        foreach ($views as $view) {
            $quotedView = brvtal_backup_identifier($view);
            $createRow = $pdo->query("SHOW CREATE VIEW {$quotedView}")->fetch(PDO::FETCH_ASSOC) ?: [];
            $createSql = (string)($createRow['Create View'] ?? array_values($createRow)[1] ?? '');
            if ($createSql === '') continue;
            fwrite($handle, "DROP VIEW IF EXISTS {$quotedView};\n{$createSql};\n\n");
        }

        fwrite($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        $pdo->commit();
        $inTransaction = false;
        fflush($handle);
        fclose($handle);
        $handle = null;

        if (!@rename($part, $target)) throw new RuntimeException('BACKUP_DATABASE_FINALIZE_FAILED');
        $bytes = (int)(@filesize($target) ?: 0);
        $hash = @hash_file('sha256', $target);
        if (!is_string($hash) || $hash === '') throw new RuntimeException('BACKUP_DATABASE_HASH_FAILED');

        return [
            'file' => basename($target),
            'bytes' => $bytes,
            'size' => brvtal_backup_format_bytes($bytes),
            'sha256' => $hash,
            'tables' => count($tables),
            'views' => count($views),
            'rows' => $rowsTotal,
        ];
    } catch (Throwable $error) {
        if ($inTransaction && $pdo->inTransaction()) {
            try { $pdo->rollBack(); } catch (Throwable) {}
        }
        if (is_resource($handle)) fclose($handle);
        @unlink($part);
        @unlink($target);
        throw $error;
    }
}

function brvtal_backup_inventory(string $root, string $logicalRoot = 'uploads'): array
{
    $entries = [];
    $bytes = 0;
    if (!is_dir($root)) return ['files'=>0, 'bytes'=>0, 'size'=>'0 B', 'entries'=>[]];

    $root = rtrim($root, DIRECTORY_SEPARATOR);
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );

    foreach ($iterator as $item) {
        if (!$item instanceof SplFileInfo || !$item->isFile() || $item->isLink()) continue;
        $absolute = $item->getPathname();
        $relative = ltrim(str_replace('\\', '/', substr($absolute, strlen($root))), '/');
        if ($relative === '') continue;
        $size = max(0, (int)$item->getSize());
        $bytes += $size;
        $entries[] = [
            'path' => trim($logicalRoot, '/') . '/' . $relative,
            'relative' => $relative,
            'bytes' => $size,
            'modified_at' => gmdate(DATE_ATOM, max(0, (int)$item->getMTime())),
        ];
    }

    usort($entries, static fn(array $a, array $b): int => strcmp((string)$a['path'], (string)$b['path']));
    return [
        'files' => count($entries),
        'bytes' => $bytes,
        'size' => brvtal_backup_format_bytes($bytes),
        'entries' => $entries,
    ];
}

function brvtal_backup_write_json(string $target, array $payload): array
{
    $json = json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    if (!is_string($json)) throw new RuntimeException('BACKUP_JSON_ENCODE_FAILED');
    if (@file_put_contents($target, $json . "\n", LOCK_EX) === false) throw new RuntimeException('BACKUP_JSON_WRITE_FAILED');
    @chmod($target, 0600);
    $bytes = (int)(@filesize($target) ?: 0);
    $hash = @hash_file('sha256', $target);
    if (!is_string($hash) || $hash === '') throw new RuntimeException('BACKUP_JSON_HASH_FAILED');
    return ['file'=>basename($target), 'bytes'=>$bytes, 'size'=>brvtal_backup_format_bytes($bytes), 'sha256'=>$hash];
}

function brvtal_backup_create_media_archive(string $uploadsDir, array $inventory, string $target, int $maxSourceBytes): array
{
    if (!class_exists('ZipArchive')) {
        return ['status'=>'unavailable', 'reason'=>'ZIP_EXTENSION_UNAVAILABLE'];
    }
    if ((int)($inventory['bytes'] ?? 0) > $maxSourceBytes) {
        return ['status'=>'unavailable', 'reason'=>'MEDIA_SOURCE_EXCEEDS_V1_LIMIT', 'limit_bytes'=>$maxSourceBytes];
    }
    if ((int)($inventory['files'] ?? 0) === 0) {
        return ['status'=>'ready', 'file'=>null, 'bytes'=>0, 'size'=>'0 B', 'sha256'=>null, 'files'=>0];
    }

    $part = $target . '.part';
    @unlink($part);
    $zip = new ZipArchive();
    $opened = $zip->open($part, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    if ($opened !== true) return ['status'=>'unavailable', 'reason'=>'MEDIA_ARCHIVE_OPEN_FAILED'];

    try {
        foreach (($inventory['entries'] ?? []) as $entry) {
            $relative = (string)($entry['relative'] ?? '');
            if ($relative === '' || str_contains($relative, '..')) continue;
            $source = rtrim($uploadsDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            if (!is_file($source) || is_link($source)) continue;
            if (!$zip->addFile($source, 'uploads/' . str_replace('\\', '/', $relative))) {
                throw new RuntimeException('MEDIA_ARCHIVE_ADD_FAILED');
            }
        }
        $zip->close();
        if (!@rename($part, $target)) throw new RuntimeException('MEDIA_ARCHIVE_FINALIZE_FAILED');
        @chmod($target, 0600);
        $bytes = (int)(@filesize($target) ?: 0);
        $hash = @hash_file('sha256', $target);
        if (!is_string($hash) || $hash === '') throw new RuntimeException('MEDIA_ARCHIVE_HASH_FAILED');
        return [
            'status' => 'ready',
            'file' => basename($target),
            'bytes' => $bytes,
            'size' => brvtal_backup_format_bytes($bytes),
            'sha256' => $hash,
            'files' => (int)($inventory['files'] ?? 0),
        ];
    } catch (Throwable $error) {
        try { $zip->close(); } catch (Throwable) {}
        @unlink($part);
        @unlink($target);
        return ['status'=>'unavailable', 'reason'=>$error->getMessage() === '' ? 'MEDIA_ARCHIVE_FAILED' : $error->getMessage()];
    }
}

function brvtal_backup_create(PDO $pdo, array $options = []): array
{
    global $config;

    $baseDir = brvtal_backup_base_dir(isset($options['base_dir']) ? (string)$options['base_dir'] : null);
    $uploadsDir = isset($options['uploads_dir'])
        ? rtrim((string)$options['uploads_dir'], DIRECTORY_SEPARATOR)
        : dirname(__DIR__) . '/uploads';
    $includeMediaArchive = !empty($options['include_media_archive']);
    $maxMediaBytes = (int)($config['backups']['media_archive_max_bytes'] ?? 2 * 1024 * 1024 * 1024);
    if ($maxMediaBytes < 1) $maxMediaBytes = 2 * 1024 * 1024 * 1024;

    brvtal_backup_ensure_private_dir($baseDir);
    $id = brvtal_backup_id();
    $databasePath = $baseDir . '/' . $id . '.database.sql';
    $mediaManifestPath = $baseDir . '/' . $id . '.media.json';
    $mediaArchivePath = $baseDir . '/' . $id . '.media.zip';
    $manifestPath = $baseDir . '/' . $id . '.manifest.json';
    $createdFiles = [];

    try {
        $database = brvtal_backup_export_database($pdo, $databasePath);
        @chmod($databasePath, 0600);
        $createdFiles[] = $databasePath;

        $inventory = brvtal_backup_inventory($uploadsDir, 'uploads');
        $mediaInventoryPayload = [
            'version' => 1,
            'backup_id' => $id,
            'created_at' => gmdate(DATE_ATOM),
            'root' => 'uploads',
            'files' => (int)$inventory['files'],
            'bytes' => (int)$inventory['bytes'],
            'size' => (string)$inventory['size'],
            'entries' => array_map(static fn(array $entry): array => [
                'path' => $entry['path'],
                'bytes' => $entry['bytes'],
                'modified_at' => $entry['modified_at'],
            ], $inventory['entries']),
        ];
        $mediaManifest = brvtal_backup_write_json($mediaManifestPath, $mediaInventoryPayload);
        $mediaManifest['files'] = (int)$inventory['files'];
        $mediaManifest['source_bytes'] = (int)$inventory['bytes'];
        $mediaManifest['source_size'] = (string)$inventory['size'];
        $createdFiles[] = $mediaManifestPath;

        $mediaArchive = ['status'=>'not_requested'];
        if ($includeMediaArchive) {
            $mediaArchive = brvtal_backup_create_media_archive($uploadsDir, $inventory, $mediaArchivePath, $maxMediaBytes);
            if (($mediaArchive['status'] ?? '') === 'ready' && !empty($mediaArchive['file'])) $createdFiles[] = $mediaArchivePath;
        }

        $status = $includeMediaArchive && ($mediaArchive['status'] ?? '') !== 'ready' ? 'partial' : 'ready';
        $createdBy = is_array($options['created_by'] ?? null) ? $options['created_by'] : [];
        $deployment = is_array($options['deployment'] ?? null) ? $options['deployment'] : [
            'commit' => brvtal_deployment_sha(),
            'short_commit' => brvtal_deployment_short_sha(),
            'source' => brvtal_deployment_source(),
            'version' => defined('BRVTAL_APP_VERSION') ? BRVTAL_APP_VERSION : null,
            'environment' => defined('BRVTAL_APP_ENV') ? BRVTAL_APP_ENV : null,
        ];

        $artifactsBytes = (int)$database['bytes'] + (int)$mediaManifest['bytes'] + (int)($mediaArchive['bytes'] ?? 0);
        $manifest = [
            'version' => 1,
            'id' => $id,
            'status' => $status,
            'created_at' => gmdate(DATE_ATOM),
            'created_by' => [
                'id' => isset($createdBy['id']) ? (int)$createdBy['id'] : null,
                'name' => isset($createdBy['name']) ? mb_substr((string)$createdBy['name'], 0, 120) : null,
            ],
            'deployment' => $deployment,
            'components' => [
                'database' => $database,
                'media_manifest' => $mediaManifest,
                'media_archive' => $mediaArchive,
            ],
            'artifacts_bytes' => $artifactsBytes,
            'artifacts_size' => brvtal_backup_format_bytes($artifactsBytes),
            'restore_supported' => false,
        ];

        brvtal_backup_write_json($manifestPath, $manifest);
        @chmod($manifestPath, 0600);
        $createdFiles[] = $manifestPath;
        return $manifest;
    } catch (Throwable $error) {
        foreach ($createdFiles as $file) @unlink($file);
        @unlink($databasePath . '.part');
        @unlink($mediaArchivePath . '.part');
        @unlink($manifestPath);
        throw $error;
    }
}

function brvtal_backup_list(?string $baseDir = null): array
{
    $dir = brvtal_backup_base_dir($baseDir);
    if (!is_dir($dir)) return [];
    $items = [];
    foreach (glob($dir . '/brvtal-*.manifest.json') ?: [] as $file) {
        $decoded = json_decode((string)@file_get_contents($file), true);
        if (!is_array($decoded)) continue;
        $id = (string)($decoded['id'] ?? '');
        if (!brvtal_backup_valid_id($id)) continue;
        $items[] = $decoded;
    }
    usort($items, static fn(array $a, array $b): int => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
    return $items;
}

function brvtal_backup_get(string $id, ?string $baseDir = null): ?array
{
    if (!brvtal_backup_valid_id($id)) return null;
    $file = brvtal_backup_base_dir($baseDir) . '/' . $id . '.manifest.json';
    if (!is_file($file)) return null;
    $decoded = json_decode((string)@file_get_contents($file), true);
    return is_array($decoded) && ($decoded['id'] ?? null) === $id ? $decoded : null;
}

function brvtal_backup_component_filename(array $manifest, string $component): ?string
{
    if ($component === 'manifest') return (string)($manifest['id'] ?? '') . '.manifest.json';
    $allowed = ['database', 'media_manifest', 'media_archive'];
    if (!in_array($component, $allowed, true)) return null;
    $file = $manifest['components'][$component]['file'] ?? null;
    if (!is_string($file) || $file === '') return null;
    $basename = basename($file);
    return $basename === $file ? $basename : null;
}

function brvtal_backup_resolve_component(array $manifest, string $component, ?string $baseDir = null): ?string
{
    $filename = brvtal_backup_component_filename($manifest, $component);
    if ($filename === null) return null;
    $base = realpath(brvtal_backup_base_dir($baseDir));
    if ($base === false) return null;
    $candidate = realpath($base . DIRECTORY_SEPARATOR . $filename);
    if ($candidate === false || !is_file($candidate)) return null;
    $prefix = rtrim($base, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    return str_starts_with($candidate, $prefix) ? $candidate : null;
}

function brvtal_backup_cleanup(array $manifest, ?string $baseDir = null): void
{
    $dir = brvtal_backup_base_dir($baseDir);
    foreach (['database','media_manifest','media_archive','manifest'] as $component) {
        $file = brvtal_backup_component_filename($manifest, $component);
        if ($file !== null) @unlink($dir . '/' . $file);
    }
}
