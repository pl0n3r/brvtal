<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_auth.php';
require_once __DIR__ . '/../config/admin_activity.php';
require_once __DIR__ . '/../config/backups.php';
require_once __DIR__ . '/../config/backup_automation.php';

brvtal_admin_require();

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = strtolower(trim((string)($_GET['action'] ?? ($method === 'POST' ? 'create' : 'list'))));
$pdo = db();

function brvtal_backup_public_item(array $manifest): array
{
    $components = $manifest['components'] ?? [];
    $publicComponents = [];
    foreach (['database','media_manifest','media_archive'] as $key) {
        $component = is_array($components[$key] ?? null) ? $components[$key] : [];
        $publicComponents[$key] = [
            'status' => $component['status'] ?? (isset($component['file']) ? 'ready' : 'unavailable'),
            'available' => is_string($component['file'] ?? null) && ($component['file'] ?? '') !== '',
            'bytes' => (int)($component['bytes'] ?? 0),
            'size' => (string)($component['size'] ?? '0 B'),
            'sha256' => isset($component['sha256']) ? (string)$component['sha256'] : null,
            'files' => isset($component['files']) ? (int)$component['files'] : null,
            'tables' => isset($component['tables']) ? (int)$component['tables'] : null,
            'rows' => isset($component['rows']) ? (int)$component['rows'] : null,
            'reason' => isset($component['reason']) ? (string)$component['reason'] : null,
        ];
    }

    return [
        'id' => (string)($manifest['id'] ?? ''),
        'status' => (string)($manifest['status'] ?? 'unknown'),
        'scope' => (string)($manifest['scope'] ?? 'full'),
        'trigger' => (string)($manifest['trigger'] ?? 'manual'),
        'scheduled_for' => isset($manifest['scheduled_for']) ? (string)$manifest['scheduled_for'] : null,
        'created_at' => (string)($manifest['created_at'] ?? ''),
        'created_by' => [
            'id' => isset($manifest['created_by']['id']) ? (int)$manifest['created_by']['id'] : null,
            'name' => isset($manifest['created_by']['name']) ? (string)$manifest['created_by']['name'] : null,
        ],
        'deployment' => [
            'short_commit' => (string)($manifest['deployment']['short_commit'] ?? ''),
            'environment' => (string)($manifest['deployment']['environment'] ?? ''),
            'source' => (string)($manifest['deployment']['source'] ?? ''),
        ],
        'components' => $publicComponents,
        'artifacts_bytes' => (int)($manifest['artifacts_bytes'] ?? 0),
        'artifacts_size' => (string)($manifest['artifacts_size'] ?? '0 B'),
        'restore_supported' => false,
    ];
}

if ($method === 'GET' && $action === 'list') {
    $items = array_map('brvtal_backup_public_item', brvtal_backup_list());
    json_response([
        'ok' => true,
        'data' => [
            'items' => $items,
            'total' => count($items),
            'capabilities' => [
                'manual_create' => true,
                'media_archive' => class_exists('ZipArchive'),
                'download' => true,
                'restore' => false,
                'delete' => false,
                'automation' => true,
                'drive_oauth' => false,
            ],
            'automation' => brvtal_backup_automation_public_state(brvtal_backup_automation_read()),
            'private_storage' => true,
        ],
    ], 200, ['Cache-Control'=>'no-store, no-cache, must-revalidate, max-age=0']);
}

if ($method === 'GET' && $action === 'automation') {
    json_response([
        'ok'=>true,
        'data'=>brvtal_backup_automation_public_state(brvtal_backup_automation_read()),
    ], 200, ['Cache-Control'=>'no-store, no-cache, must-revalidate, max-age=0']);
}

if ($method === 'POST' && $action === 'automation') {
    brvtal_admin_require_csrf();
    $input = input_json();
    $previous = brvtal_backup_automation_read();
    try {
        $state = brvtal_backup_automation_save_config(is_array($input) ? $input : []);
        $public = brvtal_backup_automation_public_state($state);
        try {
            brvtal_activity_record(
                $pdo,
                'update',
                'backup_automation',
                null,
                null,
                null,
                [
                    'enabled'=>$public['config']['enabled'],
                    'cadence'=>$public['config']['cadence'],
                    'scope'=>$public['config']['scope'],
                    'retention_local'=>$public['config']['retention_local'],
                    'drive_enabled'=>$public['config']['drive']['enabled'],
                    'drive_folder_configured'=>$public['config']['drive']['folder'] !== null,
                ],
                'BACKUP AUTOMATION'
            );
        } catch (Throwable $auditError) {
            brvtal_backup_automation_write($previous);
            throw $auditError;
        }
        json_response(['ok'=>true,'data'=>$public], 200, ['Cache-Control'=>'no-store']);
    } catch (InvalidArgumentException $error) {
        json_response(['ok'=>false,'error'=>$error->getMessage()], 422, ['Cache-Control'=>'no-store']);
    } catch (RuntimeException $error) {
        $code = $error->getMessage() === 'BACKUP_SCHEDULER_BUSY' ? 409 : 500;
        brvtal_log('BACKUP_AUTOMATION_ERROR', 'Backup automation update failed.', ['message'=>$error->getMessage()]);
        json_response(['ok'=>false,'error'=>$error->getMessage() === 'BACKUP_SCHEDULER_BUSY' ? 'BACKUP_SCHEDULER_BUSY' : 'BACKUP_AUTOMATION_UPDATE_FAILED'], $code, ['Cache-Control'=>'no-store']);
    } catch (Throwable $error) {
        brvtal_log('BACKUP_AUTOMATION_ERROR', 'Backup automation update failed.', ['message'=>$error->getMessage()]);
        json_response(['ok'=>false,'error'=>'BACKUP_AUTOMATION_UPDATE_FAILED'], 500, ['Cache-Control'=>'no-store']);
    }
}

if ($method === 'POST' && $action === 'create') {
    brvtal_admin_require_csrf();
    $input = input_json();
    $includeMediaArchive = !empty($input['include_media_archive']);
    try {
        $scope = brvtal_backup_normalize_scope($input['scope'] ?? 'full');
    } catch (InvalidArgumentException $error) {
        json_response(['ok'=>false,'error'=>$error->getMessage()], 422, ['Cache-Control'=>'no-store']);
    }

    @set_time_limit(180);
    @ignore_user_abort(true);
    $actor = brvtal_activity_actor($pdo);
    $manifest = null;

    try {
        $manifest = brvtal_backup_create($pdo, [
            'include_media_archive' => $includeMediaArchive,
            'scope' => $scope,
            'trigger' => 'manual',
            'created_by' => ['id'=>$actor['id'], 'name'=>$actor['name']],
        ]);

        brvtal_activity_record(
            $pdo,
            'create',
            'backup',
            null,
            null,
            null,
            [
                'backup_id' => $manifest['id'] ?? null,
                'status' => $manifest['status'] ?? null,
                'artifacts_bytes' => $manifest['artifacts_bytes'] ?? null,
                'include_media_archive' => $includeMediaArchive,
                'scope' => $scope,
                'trigger' => 'manual',
                'deployment' => $manifest['deployment']['short_commit'] ?? null,
            ],
            'BACKUP ' . (string)($manifest['id'] ?? '')
        );

        json_response([
            'ok' => true,
            'data' => brvtal_backup_public_item($manifest),
        ], 201, ['Cache-Control'=>'no-store, no-cache, must-revalidate, max-age=0']);
    } catch (Throwable $error) {
        if (is_array($manifest)) brvtal_backup_cleanup($manifest);
        brvtal_log('BACKUP_ERROR', 'Backup creation failed.', ['message'=>$error->getMessage()]);
        json_response(['ok'=>false,'error'=>'BACKUP_CREATE_FAILED'], 500, ['Cache-Control'=>'no-store']);
    }
}

if ($method === 'GET' && $action === 'download') {
    $id = strtolower(trim((string)($_GET['id'] ?? '')));
    $component = strtolower(trim((string)($_GET['component'] ?? '')));
    $allowed = ['database','media_manifest','media_archive','manifest'];
    if (!brvtal_backup_valid_id($id) || !in_array($component, $allowed, true)) {
        json_response(['ok'=>false,'error'=>'INVALID_BACKUP_DOWNLOAD'], 422, ['Cache-Control'=>'no-store']);
    }

    $manifest = brvtal_backup_get($id);
    if ($manifest === null) json_response(['ok'=>false,'error'=>'BACKUP_NOT_FOUND'], 404, ['Cache-Control'=>'no-store']);
    $path = brvtal_backup_resolve_component($manifest, $component);
    if ($path === null) json_response(['ok'=>false,'error'=>'BACKUP_COMPONENT_NOT_FOUND'], 404, ['Cache-Control'=>'no-store']);

    try {
        brvtal_activity_record(
            $pdo,
            'download',
            'backup',
            null,
            null,
            null,
            ['backup_id'=>$id, 'component'=>$component, 'bytes'=>(int)(filesize($path) ?: 0)],
            'BACKUP ' . $id
        );
    } catch (Throwable $error) {
        brvtal_log('BACKUP_AUDIT_ERROR', 'Backup download audit failed.', ['message'=>$error->getMessage()]);
        json_response(['ok'=>false,'error'=>'BACKUP_AUDIT_REQUIRED'], 500, ['Cache-Control'=>'no-store']);
    }

    $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $contentType = match ($extension) {
        'sql' => 'application/sql; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'zip' => 'application/zip',
        default => 'application/octet-stream',
    };

    session_write_close();
    header('Content-Type: ' . $contentType);
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', basename($path)) . '"');
    header('Content-Length: ' . (string)(filesize($path) ?: 0));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    header('X-Robots-Tag: noindex, nofollow');
    $stream = fopen($path, 'rb');
    if (!is_resource($stream)) {
        http_response_code(500);
        exit;
    }
    fpassthru($stream);
    fclose($stream);
    exit;
}

if ($action === 'restore') {
    json_response(['ok'=>false,'error'=>'RESTORE_NOT_SUPPORTED'], 405, ['Cache-Control'=>'no-store']);
}

json_response(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'], 405, ['Cache-Control'=>'no-store']);
