<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/event_lifecycle.php';
require_once __DIR__ . '/../config/set_publication.php';
require_once __DIR__ . '/content-validation.php';

function brvtal_bulk_resource_specs(): array
{
    return [
        'events' => [
            'table'=>'events', 'label'=>'title', 'slug'=>'slug', 'updated'=>'updated_at',
            'statuses'=>['draft','published','archived'],
        ],
        'artists' => [
            'table'=>'artists', 'label'=>'name', 'slug'=>'slug', 'updated'=>'updated_at',
            'statuses'=>['draft','published'],
        ],
        'sets' => [
            'table'=>'sets_media', 'label'=>'title', 'slug'=>'slug', 'updated'=>'updated_at',
            'statuses'=>['draft','published'],
        ],
        'pages' => [
            'table'=>'pages', 'label'=>'title', 'slug'=>'slug', 'updated'=>'updated_at',
            'statuses'=>['draft','published'],
        ],
        'releases' => [
            'table'=>'releases', 'label'=>'title', 'slug'=>'slug', 'updated'=>'updated_at',
            'statuses'=>['draft','published','archived'],
        ],
        'blog' => [
            'table'=>'blog_posts', 'label'=>'title', 'slug'=>'slug', 'updated'=>'updated_at',
            'where'=>'deleted_at IS NULL', 'statuses'=>['draft','published','archived'],
        ],
    ];
}

function brvtalBulkCatalogCursorEncode(array $state): string
{
    $payload = [
        'version' => 1,
        'resource' => (string)$state['resource'],
        'q' => (string)$state['q'],
        'last_id' => (int)$state['last_id'],
        'snapshot_max_id' => (int)$state['snapshot_max_id'],
        'snapshot_total' => (int)$state['snapshot_total'],
        'snapshot_updated_at' => $state['snapshot_updated_at'] ?? null,
    ];
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        throw new RuntimeException('BULK_CURSOR_ENCODE_FAILED');
    }
    return rtrim(strtr(base64_encode($json), '+/', '-_'), '=');
}

function brvtalBulkCatalogCursorDecode(string $cursor, string $resource, string $query): ?array
{
    if ($cursor === '') {
        return null;
    }
    if (strlen($cursor) > 1024 || preg_match('/^[A-Za-z0-9_-]+$/D', $cursor) !== 1) {
        throw new InvalidArgumentException('INVALID_BULK_CURSOR');
    }

    $padding = (4 - (strlen($cursor) % 4)) % 4;
    $decoded = base64_decode(strtr($cursor . str_repeat('=', $padding), '-_', '+/'), true);
    if (!is_string($decoded)) {
        throw new InvalidArgumentException('INVALID_BULK_CURSOR');
    }

    $payload = json_decode($decoded, true);
    $keys = ['version','resource','q','last_id','snapshot_max_id','snapshot_total','snapshot_updated_at'];
    if (!is_array($payload) || array_keys($payload) !== $keys) {
        throw new InvalidArgumentException('INVALID_BULK_CURSOR');
    }
    $updatedAt = $payload['snapshot_updated_at'] ?? null;
    if (
        ($payload['version'] ?? null) !== 1
        || !is_string($payload['resource'] ?? null)
        || !is_string($payload['q'] ?? null)
        || !is_int($payload['last_id'] ?? null)
        || !is_int($payload['snapshot_max_id'] ?? null)
        || !is_int($payload['snapshot_total'] ?? null)
        || (
            $updatedAt !== null
            && (
                !is_string($updatedAt)
                || preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $updatedAt) !== 1
            )
        )
        || $payload['resource'] !== $resource
        || $payload['q'] !== $query
        || $payload['last_id'] < 0
        || $payload['snapshot_max_id'] < $payload['last_id']
        || $payload['snapshot_total'] < 0
    ) {
        throw new InvalidArgumentException('INVALID_BULK_CURSOR');
    }

    return [
        'last_id' => $payload['last_id'],
        'snapshot_max_id' => $payload['snapshot_max_id'],
        'snapshot_total' => $payload['snapshot_total'],
        'snapshot_updated_at' => $updatedAt,
    ];
}

function brvtalBulkCatalogNormalizeQuery(array $input): array
{
    foreach (array_keys($input) as $parameter) {
        if (!is_string($parameter) || !in_array($parameter, ['resource','q','cursor','limit'], true)) {
            throw new InvalidArgumentException('INVALID_BULK_PARAMETER');
        }
    }

    $specs = brvtal_bulk_resource_specs();
    $resourceRaw = $input['resource'] ?? '';
    if (!is_string($resourceRaw)) {
        throw new InvalidArgumentException('INVALID_BULK_RESOURCE');
    }
    $resource = strtolower(trim($resourceRaw));
    if (!isset($specs[$resource])) {
        throw new InvalidArgumentException('INVALID_BULK_RESOURCE');
    }

    $queryRaw = $input['q'] ?? '';
    if (!is_string($queryRaw)) {
        throw new InvalidArgumentException('INVALID_BULK_QUERY');
    }
    $query = trim($queryRaw);
    if (mb_strlen($query) > 120) {
        throw new InvalidArgumentException('INVALID_BULK_QUERY');
    }

    $limitRaw = $input['limit'] ?? 50;
    if (is_int($limitRaw)) {
        $limit = $limitRaw;
    } elseif (is_string($limitRaw) && ctype_digit($limitRaw)) {
        $limit = (int)$limitRaw;
    } else {
        throw new InvalidArgumentException('INVALID_BULK_LIMIT');
    }
    if ($limit < 1 || $limit > 50) {
        throw new InvalidArgumentException('INVALID_BULK_LIMIT');
    }

    $cursorRaw = $input['cursor'] ?? '';
    if (!is_string($cursorRaw)) {
        throw new InvalidArgumentException('INVALID_BULK_CURSOR');
    }
    $cursor = brvtalBulkCatalogCursorDecode(trim($cursorRaw), $resource, $query);

    return [
        'resource' => $resource,
        'q' => $query,
        'limit' => $limit,
        'last_id' => (int)($cursor['last_id'] ?? 0),
        'snapshot_max_id' => $cursor['snapshot_max_id'] ?? null,
        'snapshot_total' => $cursor['snapshot_total'] ?? null,
        'snapshot_updated_at' => $cursor['snapshot_updated_at'] ?? null,
    ];
}

function brvtalBulkCatalogFetch(PDO $pdo, array $query): array
{
    $specs = brvtal_bulk_resource_specs();
    $resource = (string)($query['resource'] ?? '');
    if (!isset($specs[$resource])) {
        throw new InvalidArgumentException('INVALID_BULK_RESOURCE');
    }

    $search = (string)($query['q'] ?? '');
    $limit = (int)($query['limit'] ?? 0);
    $lastId = (int)($query['last_id'] ?? 0);
    $snapshotMaxId = $query['snapshot_max_id'] ?? null;
    $cursorSnapshot = $snapshotMaxId !== null;
    if ($limit < 1 || $limit > 50 || $lastId < 0) {
        throw new InvalidArgumentException('INVALID_BULK_QUERY');
    }

    $spec = $specs[$resource];
    $table = (string)$spec['table'];
    $labelColumn = (string)$spec['label'];
    $slugColumn = (string)$spec['slug'];
    $updatedColumn = (string)$spec['updated'];
    $catalogWhere = trim((string)($spec['where'] ?? ''));
    $catalogWhereSql = $catalogWhere === '' ? '' : " AND {$catalogWhere}";

    if ($snapshotMaxId === null) {
        $snapshot = $pdo->query("SELECT COALESCE(MAX(id),0) FROM {$table}");
        $snapshotMaxId = (int)$snapshot->fetchColumn();
    } elseif (!is_int($snapshotMaxId) || $snapshotMaxId < $lastId) {
        throw new InvalidArgumentException('INVALID_BULK_CURSOR');
    }

    $searchSql = '';
    $searchParams = [];
    if ($search !== '') {
        $searchSql = " AND (LOCATE(LOWER(?), LOWER(COALESCE({$labelColumn},''))) > 0"
            . " OR LOCATE(LOWER(?), LOWER(COALESCE({$slugColumn},''))) > 0)";
        $searchParams = [$search, $search];
    }

    $snapshotState = $pdo->prepare(
        "SELECT COUNT(*) AS total, MAX({$updatedColumn}) AS updated_at"
        . " FROM {$table} WHERE id <= ?{$catalogWhereSql}{$searchSql}"
    );
    $snapshotState->execute(array_merge([$snapshotMaxId], $searchParams));
    $snapshotRow = $snapshotState->fetch(PDO::FETCH_ASSOC) ?: [];
    $total = (int)($snapshotRow['total'] ?? 0);
    $updatedAt = ($snapshotRow['updated_at'] ?? null) === null
        ? null
        : (string)$snapshotRow['updated_at'];

    if ($cursorSnapshot && (
        !is_int($query['snapshot_total'] ?? null)
        || (int)$query['snapshot_total'] !== $total
        || ($query['snapshot_updated_at'] ?? null) !== $updatedAt
    )) {
        throw new InvalidArgumentException('STALE_BULK_CURSOR');
    }

    $fetchLimit = $limit + 1;
    $rowsStatement = $pdo->prepare(
        "SELECT id,{$labelColumn} AS resource_label,{$slugColumn} AS slug,status"
        . " FROM {$table} WHERE id > ? AND id <= ?{$catalogWhereSql}{$searchSql}"
        . " ORDER BY id ASC LIMIT {$fetchLimit}"
    );
    $rowsStatement->execute(array_merge([$lastId, $snapshotMaxId], $searchParams));
    $rows = $rowsStatement->fetchAll(PDO::FETCH_ASSOC);
    $hasMore = count($rows) > $limit;
    if ($hasMore) {
        $rows = array_slice($rows, 0, $limit);
    }

    $items = array_map(
        static fn(array $row): array => [
            'id' => (int)$row['id'],
            'label' => (string)($row['resource_label'] ?? ''),
            'slug' => (string)($row['slug'] ?? ''),
            'status' => (string)($row['status'] ?? ''),
        ],
        $rows
    );
    $lastReturnedId = $items === [] ? $lastId : (int)array_last($items)['id'];
    $nextCursor = $hasMore
        ? brvtalBulkCatalogCursorEncode([
            'resource' => $resource,
            'q' => $search,
            'last_id' => $lastReturnedId,
            'snapshot_max_id' => $snapshotMaxId,
            'snapshot_total' => $total,
            'snapshot_updated_at' => $updatedAt,
        ])
        : null;

    return [
        'resource' => $resource,
        'q' => $search,
        'items' => $items,
        'pagination' => [
            'limit' => $limit,
            'returned' => count($items),
            'total' => $total,
            'has_more' => $hasMore,
            'next_cursor' => $nextCursor,
            'snapshot_complete' => true,
            'range' => [
                'after_id' => $lastId,
                'last_id' => $lastReturnedId,
            ],
        ],
    ];
}

function brvtal_bulk_normalize_request(array $input): array
{
    $specs = brvtal_bulk_resource_specs();
    $resource = strtolower(trim((string)($input['resource'] ?? '')));
    if (!isset($specs[$resource])) {
        throw new InvalidArgumentException('INVALID_BULK_RESOURCE');
    }

    $action = strtolower(trim((string)($input['action'] ?? 'set_status')));
    if ($action !== 'set_status') {
        throw new InvalidArgumentException('INVALID_BULK_ACTION');
    }

    $status = strtolower(trim((string)($input['status'] ?? '')));
    if (!in_array($status, $specs[$resource]['statuses'], true)) {
        throw new InvalidArgumentException('INVALID_BULK_STATUS');
    }

    $rawIds = $input['ids'] ?? null;
    if (!is_array($rawIds) || $rawIds === []) {
        throw new InvalidArgumentException('BULK_IDS_REQUIRED');
    }
    if (count($rawIds) > 100) {
        throw new InvalidArgumentException('BULK_LIMIT_EXCEEDED');
    }

    $ids = [];
    foreach ($rawIds as $rawId) {
        if (is_int($rawId)) {
            $id = $rawId;
        } elseif (is_string($rawId) && ctype_digit($rawId)) {
            $id = (int)$rawId;
        } else {
            throw new InvalidArgumentException('INVALID_BULK_ID');
        }
        if ($id < 1) {
            throw new InvalidArgumentException('INVALID_BULK_ID');
        }
        $ids[$id] = $id;
    }

    if ($ids === []) {
        throw new InvalidArgumentException('BULK_IDS_REQUIRED');
    }

    return [
        'action' => 'set_status',
        'resource' => $resource,
        'status' => $status,
        'ids' => array_values($ids),
    ];
}

function brvtal_bulk_apply(PDO $pdo, array $request, ?callable $audit = null): array
{
    $specs = brvtal_bulk_resource_specs();
    $resource = (string)$request['resource'];
    if (!isset($specs[$resource])) {
        throw new InvalidArgumentException('INVALID_BULK_RESOURCE');
    }

    $status = (string)$request['status'];
    if (!in_array($status, $specs[$resource]['statuses'], true)) {
        throw new InvalidArgumentException('INVALID_BULK_STATUS');
    }

    $ids = array_values(array_unique(array_map(intval(...), (array)$request['ids'])));
    if ($ids === [] || count($ids) > 100 || min($ids) < 1) {
        throw new InvalidArgumentException('INVALID_BULK_IDS');
    }

    $table = $specs[$resource]['table'];
    $labelColumn = $specs[$resource]['label'];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $lifecycleColumns = $resource === 'events' ? ',title,event_date,city,published_at,cancelled_at,finished_at' : '';
    $publicationColumns = $resource === 'sets' ? ',external_url' : '';
    $selectColumns = $audit === null
        ? "id,status{$lifecycleColumns}{$publicationColumns}"
        : "id,status{$lifecycleColumns}{$publicationColumns},`{$labelColumn}` AS resource_label";

    $pdo->beginTransaction();
    try {
        $select = $pdo->prepare("SELECT {$selectColumns} FROM {$table} WHERE id IN ({$placeholders}) FOR UPDATE");
        $select->execute($ids);
        $rows = $select->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== count($ids)) {
            throw new RuntimeException('BULK_ITEMS_NOT_FOUND');
        }

        if ($resource === 'sets' && $status === 'published') {
            foreach ($rows as $row) {
                $publicationError = brvtal_set_publication_error(array_replace($row, ['status'=>'published']));
                if ($publicationError !== null) {
                    throw new InvalidArgumentException($publicationError);
                }
            }
        }

        if ($resource === 'events') {
            foreach ($rows as $row) {
                $eventError = brvtal_event_publication_error(array_replace($row, ['status'=>$status]));
                if ($eventError !== null) {
                    throw new InvalidArgumentException((string)$eventError['error']);
                }
            }
        }

        $changed = 0;
        if ($resource === 'events') {
            $updateEvent = $pdo->prepare('UPDATE events SET status=?,published_at=?,cancelled_at=?,finished_at=? WHERE id=?');
            foreach ($rows as $row) {
                $patch = brvtal_event_lifecycle_patch($row, ['status'=>$status]);
                $after = array_replace($row, $patch);
                $updateEvent->execute([
                    (string)$after['status'],
                    ($after['published_at'] ?? null) ?: null,
                    ($after['cancelled_at'] ?? null) ?: null,
                    ($after['finished_at'] ?? null) ?: null,
                    (int)$row['id'],
                ]);
                $changed += $updateEvent->rowCount();

                if ($audit !== null) {
                    $beforeAudit = [
                        'id'=>(int)$row['id'],
                        'status'=>(string)$row['status'],
                        'published_at'=>$row['published_at'] ?? null,
                        'cancelled_at'=>$row['cancelled_at'] ?? null,
                        'finished_at'=>$row['finished_at'] ?? null,
                    ];
                    $afterAudit = [
                        'id'=>(int)$row['id'],
                        'status'=>(string)$after['status'],
                        'published_at'=>$after['published_at'] ?? null,
                        'cancelled_at'=>$after['cancelled_at'] ?? null,
                        'finished_at'=>$after['finished_at'] ?? null,
                    ];
                    if ($beforeAudit !== $afterAudit) {
                        $audit(
                            $resource,
                            (int)$row['id'],
                            $beforeAudit,
                            $afterAudit,
                            (string)($row['resource_label'] ?? '')
                        );
                    }
                }
            }
        } elseif (in_array($resource, ['releases','blog'], true)) {
            $update = $pdo->prepare("UPDATE {$table} SET status=?, published_at=CASE WHEN ?='published' THEN COALESCE(published_at,CURRENT_TIMESTAMP) ELSE published_at END WHERE id IN ({$placeholders})");
            $update->execute(array_merge([$status, $status], $ids));
            $changed = $update->rowCount();

            if ($audit !== null) {
                foreach ($rows as $row) {
                    if ((string)$row['status'] === $status) continue;
                    $audit(
                        $resource,
                        (int)$row['id'],
                        ['id'=>(int)$row['id'],'status'=>(string)$row['status']],
                        ['id'=>(int)$row['id'],'status'=>$status],
                        (string)($row['resource_label'] ?? '')
                    );
                }
            }
        } else {
            $update = $pdo->prepare("UPDATE {$table} SET status=? WHERE id IN ({$placeholders})");
            $update->execute(array_merge([$status], $ids));
            $changed = $update->rowCount();

            if ($audit !== null) {
                foreach ($rows as $row) {
                    if ((string)$row['status'] === $status) continue;
                    $audit(
                        $resource,
                        (int)$row['id'],
                        ['id'=>(int)$row['id'],'status'=>(string)$row['status']],
                        ['id'=>(int)$row['id'],'status'=>$status],
                        (string)($row['resource_label'] ?? '')
                    );
                }
            }
        }

        $pdo->commit();
        return [
            'resource' => $resource,
            'status' => $status,
            'ids' => $ids,
            'matched' => count($rows),
            'changed' => $changed,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}
