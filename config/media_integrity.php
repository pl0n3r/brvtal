<?php
declare(strict_types=1);

/**
 * Media integrity helpers shared by the canonical Media Library mutation path.
 *
 * The Media reference migration serializes content-reference writes and Media
 * DELETE statements through `media_reference_mutex` when that migration is
 * present. These helpers extend that boundary to the filesystem so a failed
 * public-file removal cannot be reported as a successful database deletion.
 */

/**
 * Return other Media rows that point at the same local path as the selected
 * record. Existing local duplicates are treated as references so canonical
 * deletion cannot remove a shared file out from under another Media record.
 *
 * @return array<int,array{resource:string,id:int,field:string,title:string}>
 */
function brvtal_media_duplicate_usage(PDO $pdo, array $media): array
{
    $path = trim((string)($media['file_path'] ?? ''));
    $id = (int)($media['id'] ?? 0);
    if (!str_starts_with($path, '/uploads/') || $id < 1) {
        return [];
    }

    $st = $pdo->prepare('SELECT id,title FROM media WHERE file_path=? AND id<>? ORDER BY id ASC LIMIT 100');
    $st->execute([$path, $id]);
    $refs = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $refs[] = [
            'resource' => 'MEDIA',
            'id' => (int)$row['id'],
            'field' => 'file_path',
            'title' => (string)($row['title'] ?? ''),
        ];
    }
    return $refs;
}

/**
 * Return curated Memory references to this Media row when the optional
 * Memories schema is installed. Older installs remain compatible until the
 * explicit additive migration is applied.
 *
 * @return array<int,array{resource:string,id:int,field:string,title:string}>
 */
function brvtal_media_memory_usage(PDO $pdo, array $media): array
{
    $id = (int)($media['id'] ?? 0);
    if ($id < 1) return [];

    try {
        $st = $pdo->prepare('SELECT id,title FROM memories WHERE media_id=? ORDER BY id ASC LIMIT 100');
        $st->execute([$id]);
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1146) return [];
        throw $e;
    }

    $refs = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $refs[] = [
            'resource' => 'MEMORY',
            'id' => (int)$row['id'],
            'field' => 'media_id',
            'title' => (string)($row['title'] ?? ''),
        ];
    }
    return $refs;
}

/**
 * Merge editorial references with duplicate local Media ownership references.
 *
 * @return array<int,array{resource:string,id:int,field:string,title:string}>
 */
function brvtal_media_integrity_usage(PDO $pdo, array $media): array
{
    return array_merge(
        brvtal_media_usage($pdo, $media),
        brvtal_media_memory_usage($pdo, $media),
        brvtal_media_duplicate_usage($pdo, $media)
    );
}

/**
 * Return the existing owner of a local Media path, if any.
 *
 * @return array{id:int,title:string}|null
 */
function brvtal_media_existing_local_owner(PDO $pdo, string $path): ?array
{
    if (!str_starts_with($path, '/uploads/')) {
        return null;
    }
    $st = $pdo->prepare('SELECT id,title FROM media WHERE file_path=? ORDER BY id ASC LIMIT 1');
    $st->execute([$path]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return null;
    }
    return ['id'=>(int)$row['id'], 'title'=>(string)($row['title'] ?? '')];
}

/**
 * Acquire the optional database-side media-reference mutex for this transaction.
 * Older installations without the additive guard migration remain compatible.
 */
function brvtal_media_reference_mutex_lock(PDO $pdo): bool
{
    try {
        $st = $pdo->query('SELECT state FROM media_reference_mutex WHERE id=1 FOR UPDATE');
        return $st !== false && $st->fetchColumn() !== false;
    } catch (PDOException $e) {
        if ((int)($e->errorInfo[1] ?? 0) === 1146) {
            return false;
        }
        throw $e;
    }
}

/**
 * Build the list of existing local files owned by one Media row. The original
 * is staged last so a variant/sidecar failure leaves the authoritative source
 * public and recoverable.
 *
 * @return array<int,array{label:string,absolute:string}>
 */
function brvtal_media_delete_candidates(array $media): array
{
    $publicPath = trim((string)($media['file_path'] ?? ''));
    $absolute = brvtal_media_local_absolute($publicPath);
    if ($absolute === null) {
        return [];
    }

    $candidates = [];
    $seen = [];
    $add = static function (string $label, ?string $candidate) use (&$candidates, &$seen): void {
        if ($candidate === null || !is_file($candidate) || isset($seen[$candidate])) {
            return;
        }
        $seen[$candidate] = true;
        $candidates[] = ['label'=>$label, 'absolute'=>$candidate];
    };

    $sidecarData = brvtal_media_read_sidecar($publicPath);
    if (is_array($sidecarData['variants'] ?? null)) {
        foreach ($sidecarData['variants'] as $variant) {
            $variantPath = (string)($variant['path'] ?? '');
            $add($variantPath, brvtal_media_local_absolute($variantPath));
        }
    }

    $sidecar = brvtal_media_sidecar_path($absolute);
    $add(brvtal_media_public_upload_path($sidecar) ?? basename($sidecar), $sidecar);
    $add($publicPath, $absolute);
    return $candidates;
}

/**
 * Move public Media files into the denied `.private` tree before deleting the
 * database row. A failed move restores every file already staged.
 *
 * @param callable(string,string):bool|null $renamer
 * @return array{ok:bool,staged:array<int,array{label:string,source:string,private:string}>,failed:?string,trash_dir:?string}
 */
function brvtal_media_stage_delete(array $media, ?callable $renamer = null): array
{
    $candidates = brvtal_media_delete_candidates($media);
    if ($candidates === []) {
        return ['ok'=>true, 'staged'=>[], 'failed'=>null, 'trash_dir'=>null];
    }

    $trashRoot = dirname(__DIR__) . '/.private/media-trash';
    if (!is_dir($trashRoot) && !mkdir($trashRoot, 0700, true) && !is_dir($trashRoot)) {
        return ['ok'=>false, 'staged'=>[], 'failed'=>'PRIVATE_TRASH_UNAVAILABLE', 'trash_dir'=>null];
    }
    @chmod($trashRoot, 0700);

    $trashDir = $trashRoot . '/' . bin2hex(random_bytes(12));
    if (!mkdir($trashDir, 0700, true) && !is_dir($trashDir)) {
        return ['ok'=>false, 'staged'=>[], 'failed'=>'PRIVATE_TRASH_UNAVAILABLE', 'trash_dir'=>null];
    }

    $move = $renamer ?? static fn(string $source, string $target): bool => @rename($source, $target);
    $staged = [];
    foreach ($candidates as $index => $candidate) {
        $target = $trashDir . '/' . str_pad((string)$index, 3, '0', STR_PAD_LEFT) . '-' . basename($candidate['absolute']);
        if (!$move($candidate['absolute'], $target)) {
            for ($restore = count($staged) - 1; $restore >= 0; $restore--) {
                @rename($staged[$restore]['private'], $staged[$restore]['source']);
            }
            @rmdir($trashDir);
            return ['ok'=>false, 'staged'=>[], 'failed'=>$candidate['label'], 'trash_dir'=>null];
        }
        $staged[] = [
            'label'=>$candidate['label'],
            'source'=>$candidate['absolute'],
            'private'=>$target,
        ];
    }

    return ['ok'=>true, 'staged'=>$staged, 'failed'=>null, 'trash_dir'=>$trashDir];
}

/**
 * Restore staged files when the database delete cannot commit.
 *
 * @param callable(string,string):bool|null $renamer
 * @return array{restored:array<int,string>,restore_failed:array<int,array{label:string,source:string,private:string}>}
 */
function brvtal_media_restore_staged_delete(array $stage, ?callable $renamer = null): array
{
    $move = $renamer ?? static fn(string $source, string $target): bool => @rename($source, $target);
    $staged = is_array($stage['staged'] ?? null) ? $stage['staged'] : [];
    $restored = [];
    $failed = [];
    for ($index = count($staged) - 1; $index >= 0; $index--) {
        $entry = $staged[$index];
        $label = (string)($entry['label'] ?? '');
        $source = (string)($entry['source'] ?? '');
        $private = (string)($entry['private'] ?? '');
        if ($source === '' || $private === '' || !is_file($private)) {
            continue;
        }
        if ($move($private, $source)) {
            $restored[] = $label;
            continue;
        }
        $failed[] = ['label'=>$label, 'source'=>$source, 'private'=>$private];
    }

    $trashDir = (string)($stage['trash_dir'] ?? '');
    if ($trashDir !== '' && $failed === []) {
        @rmdir($trashDir);
    }
    if ($failed !== [] && function_exists('brvtal_log')) {
        brvtal_log('MEDIA_RESTORE_PENDING', 'Media rollback has private staged files pending restore', [
            'labels'=>array_values(array_filter(array_map(
                static fn(array $entry): string => (string)($entry['label'] ?? ''),
                $failed
            ))),
            'private_paths'=>array_values(array_map(
                static fn(array $entry): string => (string)($entry['private'] ?? ''),
                $failed
            )),
            'trash_dir'=>$trashDir,
        ]);
    }

    return ['restored'=>$restored, 'restore_failed'=>$failed];
}

/**
 * Remove a failed upload or quarantine it below the denied private tree.
 *
 * @param callable(string):bool|null $unlinker
 * @param callable(string,string):bool|null $renamer
 * @return array{removed:bool,quarantined:bool,recovery_path:?string}
 */
function brvtal_media_cleanup_failed_upload(
    string $absolute,
    ?string $recoveryRoot = null,
    ?callable $unlinker = null,
    ?callable $renamer = null
): array {
    if ($absolute === '' || !is_file($absolute)) {
        return ['removed'=>true, 'quarantined'=>false, 'recovery_path'=>null];
    }

    $unlink = $unlinker ?? static fn(string $path): bool => @unlink($path);
    if ($unlink($absolute) || !is_file($absolute)) {
        return ['removed'=>true, 'quarantined'=>false, 'recovery_path'=>null];
    }

    $root = $recoveryRoot !== null && trim($recoveryRoot) !== ''
        ? rtrim($recoveryRoot, DIRECTORY_SEPARATOR)
        : dirname(__DIR__) . '/.private/media-recovery';
    if (!is_dir($root) && !@mkdir($root, 0700, true) && !is_dir($root)) {
        if (function_exists('brvtal_log')) {
            brvtal_log('MEDIA_UPLOAD_RECOVERY_FAILED', 'Failed upload could not be removed or quarantined', [
                'file'=>basename($absolute),
            ]);
        }
        return ['removed'=>false, 'quarantined'=>false, 'recovery_path'=>$absolute];
    }
    @chmod($root, 0700);

    $target = $root . DIRECTORY_SEPARATOR . bin2hex(random_bytes(12)) . '-' . basename($absolute);
    $move = $renamer ?? static fn(string $source, string $target): bool => @rename($source, $target);
    if (!$move($absolute, $target)) {
        if (function_exists('brvtal_log')) {
            brvtal_log('MEDIA_UPLOAD_RECOVERY_FAILED', 'Failed upload could not be removed or quarantined', [
                'file'=>basename($absolute),
            ]);
        }
        return ['removed'=>false, 'quarantined'=>false, 'recovery_path'=>$absolute];
    }

    if (function_exists('brvtal_log')) {
        brvtal_log('MEDIA_UPLOAD_QUARANTINED', 'Failed upload moved to private recovery storage', [
            'file'=>basename($absolute),
            'recovery_file'=>basename($target),
        ]);
    }
    return ['removed'=>false, 'quarantined'=>true, 'recovery_path'=>$target];
}

/**
 * Permanently remove already-private staged files after the DB commit. Failure
 * here cannot make the old public URL readable again, so it is reported only as
 * private cleanup debt and logged with enough metadata for manual cleanup.
 *
 * @param callable(string):bool|null $unlinker
 * @return array{deleted:array<int,string>,cleanup_failed:array<int,string>}
 */
function brvtal_media_finalize_staged_delete(array $stage, ?callable $unlinker = null): array
{
    $unlink = $unlinker ?? static fn(string $path): bool => @unlink($path);
    $deleted = [];
    $failed = [];
    foreach ((array)($stage['staged'] ?? []) as $entry) {
        $private = (string)($entry['private'] ?? '');
        $label = (string)($entry['label'] ?? '');
        if ($private === '' || !is_file($private)) {
            $deleted[] = $label;
            continue;
        }
        if ($unlink($private)) {
            $deleted[] = $label;
        } else {
            $failed[] = $label;
        }
    }
    $trashDir = (string)($stage['trash_dir'] ?? '');
    if ($trashDir !== '') {
        @rmdir($trashDir);
    }
    if ($failed !== [] && function_exists('brvtal_log')) {
        brvtal_log('MEDIA_PRIVATE_CLEANUP_PENDING', 'Deleted Media has private staged files pending cleanup', [
            'paths'=>$failed,
            'trash_dir'=>$trashDir,
        ]);
    }
    return ['deleted'=>$deleted, 'cleanup_failed'=>$failed];
}
