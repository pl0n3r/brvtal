<?php
declare(strict_types=1);

function brvtal_bulk_resource_specs(): array
{
    return [
        'events' => ['table' => 'events', 'statuses' => ['draft','published','archived']],
        'artists' => ['table' => 'artists', 'statuses' => ['draft','published']],
        'sets' => ['table' => 'sets_media', 'statuses' => ['draft','published']],
        'pages' => ['table' => 'pages', 'statuses' => ['draft','published']],
        'releases' => ['table' => 'releases', 'statuses' => ['draft','published','archived']],
        'blog' => ['table' => 'blog_posts', 'statuses' => ['draft','published','archived']],
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

function brvtal_bulk_apply(PDO $pdo, array $request): array
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

    $ids = array_values(array_unique(array_map('intval', (array)$request['ids'])));
    if ($ids === [] || count($ids) > 100 || min($ids) < 1) {
        throw new InvalidArgumentException('INVALID_BULK_IDS');
    }

    $table = $specs[$resource]['table'];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));

    $pdo->beginTransaction();
    try {
        $select = $pdo->prepare("SELECT id,status FROM {$table} WHERE id IN ({$placeholders}) FOR UPDATE");
        $select->execute($ids);
        $rows = $select->fetchAll(PDO::FETCH_ASSOC);
        if (count($rows) !== count($ids)) {
            throw new RuntimeException('BULK_ITEMS_NOT_FOUND');
        }

        $update = $pdo->prepare("UPDATE {$table} SET status=? WHERE id IN ({$placeholders})");
        $update->execute(array_merge([$status], $ids));
        $changed = $update->rowCount();

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
