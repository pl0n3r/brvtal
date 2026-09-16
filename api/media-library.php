<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_auth.php';
require_once __DIR__ . '/../config/media.php';
require_once __DIR__ . '/../config/media_integrity.php';
require_once __DIR__ . '/../config/media_relations.php';

brvtal_admin_require();
header('X-Content-Type-Options: nosniff');

$pdo = db();
$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = strtolower(trim((string)($_GET['action'] ?? 'list')));
$id = isset($_GET['id']) && ctype_digit((string)$_GET['id']) ? (int)$_GET['id'] : null;

function brvtal_media_find(PDO $pdo, int $id, bool $lock = false): ?array
{
    $sql = 'SELECT id,type,title,file_path,mime_type,file_size,alt_text,status,created_at FROM media WHERE id=? LIMIT 1';
    if ($lock) {
        $sql .= ' FOR UPDATE';
    }
    $st = $pdo->prepare($sql);
    $st->execute([$id]);
    $row = $st->fetch();
    return is_array($row) ? $row : null;
}

function brvtal_media_input_json(): array
{
    $raw = (string)file_get_contents('php://input');
    if ($raw === '') {
        return [];
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        brvtal_media_json_response(['ok' => false, 'error' => 'INVALID_JSON'], 400);
    }
    return $decoded;
}

function brvtal_media_safe_text(mixed $value, int $max): string
{
    return mb_substr(trim((string)$value), 0, $max);
}

/** Canonical Media Library detail payload, including optional structured archive relations. */
function brvtal_media_detail_payload(PDO $pdo, array $row): array
{
    $asset = brvtal_media_asset_payload($row);
    $usage = brvtal_media_integrity_usage($pdo, $row);
    $asset['usage'] = $usage;
    $asset['usage_count'] = count($usage);
    $ready = brvtal_media_relations_ready($pdo);
    $asset['relations_available'] = $ready;
    $asset['relations'] = $ready ? brvtal_media_relations_list($pdo, (int)$row['id']) : [];
    $asset['relation_options'] = $ready ? brvtal_media_relation_options($pdo) : [];
    return $asset;
}

try {
    if ($method === 'GET' && $action === 'list') {
        $rows = $pdo->query(
            'SELECT id,type,title,file_path,mime_type,file_size,alt_text,status,created_at FROM media ORDER BY created_at DESC,id DESC'
        )->fetchAll();
        $data = array_map('brvtal_media_asset_payload', $rows ?: []);
        brvtal_media_json_response([
            'ok' => true,
            'data' => $data,
            'engine' => [
                'gd' => extension_loaded('gd'),
                'webp' => function_exists('imagewebp'),
                'max_upload_bytes' => 25 * 1024 * 1024,
            ],
        ]);
    }

    if ($method === 'GET' && $action === 'detail') {
        if (!$id) {
            brvtal_media_json_response(['ok' => false, 'error' => 'MEDIA_ID_REQUIRED'], 422);
        }
        $row = brvtal_media_find($pdo, $id);
        if (!$row) {
            brvtal_media_json_response(['ok' => false, 'error' => 'MEDIA_NOT_FOUND'], 404);
        }
        brvtal_media_json_response(['ok' => true, 'data' => brvtal_media_detail_payload($pdo, $row)]);
    }

    if ($method === 'POST' && $action === 'upload') {
        brvtal_admin_require_csrf();
        if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
            brvtal_media_json_response(['ok' => false, 'error' => 'UPLOAD_REQUIRED'], 422);
        }
        $file = $_FILES['file'];
        if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            brvtal_media_json_response(['ok' => false, 'error' => 'UPLOAD_FAILED', 'upload_error' => (int)($file['error'] ?? -1)], 422);
        }
        $tmp = (string)($file['tmp_name'] ?? '');
        $size = (int)($file['size'] ?? 0);
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            brvtal_media_json_response(['ok' => false, 'error' => 'INVALID_UPLOAD'], 422);
        }
        if ($size < 1 || $size > 25 * 1024 * 1024) {
            brvtal_media_json_response(['ok' => false, 'error' => 'FILE_TOO_LARGE'], 422);
        }

        $mime = (new finfo(FILEINFO_MIME_TYPE))->file($tmp) ?: '';
        $allowed = [
            'image/jpeg' => ['image', 'jpg'],
            'image/png' => ['image', 'png'],
            'image/webp' => ['image', 'webp'],
            'image/gif' => ['image', 'gif'],
            'video/mp4' => ['video', 'mp4'],
            'audio/mpeg' => ['audio', 'mp3'],
            'audio/wav' => ['audio', 'wav'],
            'application/pdf' => ['document', 'pdf'],
        ];
        if (!isset($allowed[$mime])) {
            brvtal_media_json_response(['ok' => false, 'error' => 'FILE_TYPE_NOT_ALLOWED', 'mime_type' => $mime], 422);
        }
        [$type, $extension] = $allowed[$mime];
        if ($type === 'image' && @getimagesize($tmp) === false) {
            brvtal_media_json_response(['ok' => false, 'error' => 'INVALID_IMAGE'], 422);
        }

        $year = date('Y');
        $month = date('m');
        $directory = dirname(__DIR__) . '/uploads/media/' . $year . '/' . $month;
        if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
            brvtal_media_json_response(['ok' => false, 'error' => 'UPLOAD_STORAGE_ERROR'], 500);
        }

        $token = bin2hex(random_bytes(16));
        $absolute = $directory . '/' . $token . '.' . $extension;
        if (!move_uploaded_file($tmp, $absolute)) {
            brvtal_media_json_response(['ok' => false, 'error' => 'UPLOAD_MOVE_FAILED'], 500);
        }
        @chmod($absolute, 0640);
        $publicPath = '/uploads/media/' . $year . '/' . $month . '/' . $token . '.' . $extension;

        $originalName = (string)($file['name'] ?? 'Media');
        $fallbackTitle = pathinfo($originalName, PATHINFO_FILENAME) ?: 'Media';
        $title = brvtal_media_safe_text($_POST['title'] ?? $fallbackTitle, 180);
        if ($title === '') {
            $title = 'Media';
        }
        $alt = brvtal_media_safe_text($_POST['alt_text'] ?? '', 255);

        try {
            $st = $pdo->prepare(
                'INSERT INTO media(type,title,file_path,mime_type,file_size,alt_text,status) VALUES(?,?,?,?,?,?,?)'
            );
            $st->execute([$type, $title, $publicPath, $mime, $size, $alt, 'draft']);
            $mediaId = (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            @unlink($absolute);
            throw $e;
        }

        if ($type === 'image') {
            $metadata = brvtal_media_generate_variants($absolute, $mime);
            brvtal_media_store_sidecar($absolute, $metadata);
        }

        $row = brvtal_media_find($pdo, $mediaId);
        brvtal_media_json_response([
            'ok' => true,
            'data' => $row ? brvtal_media_asset_payload($row) : ['id' => $mediaId, 'file_path' => $publicPath],
        ], 201);
    }

    if ($method === 'POST' && $action === 'register') {
        brvtal_admin_require_csrf();
        $data = brvtal_media_input_json();
        $path = brvtal_media_safe_text($data['file_path'] ?? '', 500);
        if ($path === '') {
            brvtal_media_json_response(['ok' => false, 'error' => 'FILE_PATH_REQUIRED'], 422);
        }
        $local = str_starts_with($path, '/uploads/') && !str_contains($path, '..');
        $external = filter_var($path, FILTER_VALIDATE_URL) !== false && in_array(strtolower((string)parse_url($path, PHP_URL_SCHEME)), ['http', 'https'], true);
        if (!$local && !$external) {
            brvtal_media_json_response(['ok' => false, 'error' => 'INVALID_FILE_PATH'], 422);
        }
        $type = brvtal_media_safe_text($data['type'] ?? 'image', 20);
        if (!in_array($type, ['image', 'video', 'audio', 'document'], true)) {
            brvtal_media_json_response(['ok' => false, 'error' => 'INVALID_MEDIA_TYPE'], 422);
        }
        $title = brvtal_media_safe_text($data['title'] ?? 'Media', 180) ?: 'Media';
        $mime = brvtal_media_safe_text($data['mime_type'] ?? '', 120);
        $size = 0;

        if ($local) {
            $absolute = brvtal_media_local_absolute($path);
            if ($absolute === null || !is_file($absolute)) {
                brvtal_media_json_response(['ok' => false, 'error' => 'LOCAL_MEDIA_NOT_FOUND'], 422);
            }

            $existingOwner = brvtal_media_existing_local_owner($pdo, $path);
            if ($existingOwner !== null) {
                brvtal_media_json_response([
                    'ok' => false,
                    'error' => 'LOCAL_MEDIA_ALREADY_REGISTERED',
                    'media_id' => $existingOwner['id'],
                ], 409);
            }

            $detectedMime = (new finfo(FILEINFO_MIME_TYPE))->file($absolute) ?: '';
            $detectedType = match ($detectedMime) {
                'image/jpeg', 'image/png', 'image/webp', 'image/gif' => 'image',
                'video/mp4' => 'video',
                'audio/mpeg', 'audio/wav' => 'audio',
                'application/pdf' => 'document',
                default => null,
            };
            if ($detectedType === null) {
                brvtal_media_json_response(['ok' => false, 'error' => 'FILE_TYPE_NOT_ALLOWED', 'mime_type' => $detectedMime], 422);
            }
            if ($type !== $detectedType) {
                brvtal_media_json_response(['ok' => false, 'error' => 'MEDIA_TYPE_MISMATCH', 'expected_type' => $detectedType], 422);
            }
            if ($detectedType === 'image' && @getimagesize($absolute) === false) {
                brvtal_media_json_response(['ok' => false, 'error' => 'INVALID_IMAGE'], 422);
            }

            $mime = $detectedMime;
            $size = max(0, (int)(@filesize($absolute) ?: 0));
        }

        $alt = brvtal_media_safe_text($data['alt_text'] ?? '', 255);
        $status = ($data['status'] ?? 'published') === 'draft' ? 'draft' : 'published';
        $st = $pdo->prepare(
            'INSERT INTO media(type,title,file_path,mime_type,file_size,alt_text,status) VALUES(?,?,?,?,?,?,?)'
        );
        $st->execute([$type, $title, $path, $mime, $size, $alt, $status]);
        $row = brvtal_media_find($pdo, (int)$pdo->lastInsertId());
        brvtal_media_json_response(['ok' => true, 'data' => brvtal_media_asset_payload($row ?: [])], 201);
    }

    if ($method === 'POST' && $action === 'transform') {
        brvtal_admin_require_csrf();
        if (!$id) brvtal_media_json_response(['ok'=>false,'error'=>'MEDIA_ID_REQUIRED'], 422);
        $row = brvtal_media_find($pdo, $id);
        if (!$row || ($row['type'] ?? '') !== 'image') brvtal_media_json_response(['ok'=>false,'error'=>'IMAGE_NOT_FOUND'], 404);
        $absolute = brvtal_media_local_absolute((string)$row['file_path']);
        if ($absolute === null) brvtal_media_json_response(['ok'=>false,'error'=>'LOCAL_IMAGE_REQUIRED'], 422);
        $data = brvtal_media_input_json();
        $x = filter_var($data['x'] ?? null, FILTER_VALIDATE_FLOAT);
        $y = filter_var($data['y'] ?? null, FILTER_VALIDATE_FLOAT);
        if ($x === false || $y === false || $x < 0 || $x > 1 || $y < 0 || $y > 1) brvtal_media_json_response(['ok'=>false,'error'=>'INVALID_FOCAL_POINT'], 422);
        $metadata = brvtal_media_generate_variants($absolute, (string)$row['mime_type'], ['x'=>$x,'y'=>$y]);
        if (($metadata['status'] ?? '') !== 'ready') brvtal_media_json_response(['ok'=>false,'error'=>$metadata['reason'] ?? 'VARIANT_GENERATION_FAILED'], 422);
        brvtal_media_remove_generated_variants($absolute, $metadata['variants']);
        brvtal_media_store_sidecar($absolute, $metadata);
        $asset = brvtal_media_asset_payload($row);
        $asset['usage'] = brvtal_media_integrity_usage($pdo, $row);
        brvtal_media_json_response(['ok'=>true,'data'=>$asset]);
    }

    if ($method === 'PUT' && $action === 'update') {
        brvtal_admin_require_csrf();
        if (!$id) {
            brvtal_media_json_response(['ok' => false, 'error' => 'MEDIA_ID_REQUIRED'], 422);
        }
        $data = brvtal_media_input_json();
        $hasRelations = array_key_exists('relations', $data);
        if ($hasRelations && !brvtal_media_relations_ready($pdo)) {
            brvtal_media_json_response(['ok'=>false,'error'=>'MEDIA_RELATIONS_MIGRATION_REQUIRED'], 409);
        }
        try {
            $relations = $hasRelations ? brvtal_media_relations_normalize($data['relations']) : [];
        } catch (InvalidArgumentException $relationError) {
            brvtal_media_json_response(['ok'=>false,'error'=>$relationError->getMessage()], 422);
        }

        $pdo->beginTransaction();
        try {
            $row = brvtal_media_find($pdo, $id, true);
            if (!$row) {
                $pdo->rollBack();
                brvtal_media_json_response(['ok' => false, 'error' => 'MEDIA_NOT_FOUND'], 404);
            }
            $title = brvtal_media_safe_text($data['title'] ?? $row['title'], 180);
            if ($title === '') {
                $pdo->rollBack();
                brvtal_media_json_response(['ok' => false, 'error' => 'TITLE_REQUIRED'], 422);
            }
            $alt = brvtal_media_safe_text($data['alt_text'] ?? $row['alt_text'], 255);
            $status = ($data['status'] ?? $row['status']) === 'draft' ? 'draft' : 'published';
            $st = $pdo->prepare('UPDATE media SET title=?,alt_text=?,status=? WHERE id=?');
            $st->execute([$title, $alt, $status, $id]);
            if ($hasRelations) {
                brvtal_media_relations_replace($pdo, $id, $relations);
            }
            $pdo->commit();
        } catch (InvalidArgumentException $relationError) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            brvtal_media_json_response(['ok'=>false,'error'=>$relationError->getMessage()], 422);
        } catch (Throwable $writeError) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $writeError;
        }

        $updated = brvtal_media_find($pdo, $id);
        if (!$updated) {
            brvtal_media_json_response(['ok'=>false,'error'=>'MEDIA_NOT_FOUND'], 404);
        }
        brvtal_media_json_response(['ok' => true, 'data' => brvtal_media_detail_payload($pdo, $updated)]);
    }

    if ($method === 'DELETE') {
        brvtal_admin_require_csrf();
        if (!$id) {
            brvtal_media_json_response(['ok' => false, 'error' => 'MEDIA_ID_REQUIRED'], 422);
        }

        $stage = null;
        $committed = false;
        $pdo->beginTransaction();
        try {
            brvtal_media_reference_mutex_lock($pdo);
            $row = brvtal_media_find($pdo, $id, true);
            if (!$row) {
                $pdo->rollBack();
                brvtal_media_json_response(['ok' => false, 'error' => 'MEDIA_NOT_FOUND'], 404);
            }

            $usage = brvtal_media_integrity_usage($pdo, $row);
            if ($usage !== []) {
                $pdo->rollBack();
                brvtal_media_json_response([
                    'ok' => false,
                    'error' => 'MEDIA_IN_USE',
                    'usage' => $usage,
                    'usage_count' => count($usage),
                ], 409);
            }

            $stage = brvtal_media_stage_delete($row);
            if (($stage['ok'] ?? false) !== true) {
                $pdo->rollBack();
                brvtal_media_json_response([
                    'ok' => false,
                    'error' => 'MEDIA_FILE_STAGE_FAILED',
                    'failed_file' => $stage['failed'] ?? null,
                ], 500);
            }

            $st = $pdo->prepare('DELETE FROM media WHERE id=?');
            $st->execute([$id]);
            if ((int)$st->rowCount() !== 1) {
                brvtal_media_restore_staged_delete($stage);
                $pdo->rollBack();
                brvtal_media_json_response(['ok'=>false,'error'=>'MEDIA_DELETE_CONFLICT'], 409);
            }

            $pdo->commit();
            $committed = true;
        } catch (Throwable $deleteError) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if (!$committed && is_array($stage) && ($stage['ok'] ?? false) === true) {
                brvtal_media_restore_staged_delete($stage);
            }
            throw $deleteError;
        }

        $cleanup = brvtal_media_finalize_staged_delete(is_array($stage) ? $stage : []);
        brvtal_media_json_response([
            'ok' => true,
            'deleted' => 1,
            'deleted_files' => $cleanup['deleted'],
            'private_cleanup_failed' => $cleanup['cleanup_failed'],
        ]);
    }

    brvtal_media_json_response(['ok' => false, 'error' => 'METHOD_NOT_ALLOWED'], 405);
} catch (Throwable $e) {
    if (function_exists('brvtal_log')) {
        brvtal_log('MEDIA_LIBRARY_ERROR', 'Media Library request failed', [
            'class' => get_class($e),
            'message' => $e->getMessage(),
        ]);
    }
    brvtal_media_json_response(['ok' => false, 'error' => 'INTERNAL_ERROR'], 500);
}