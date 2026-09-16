<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/event_lifecycle.php';
require_once __DIR__ . '/../config/set_publication.php';

function brvtal_bulk_resource_specs(): array
{
    return [
        'events' => ['table' => 'events', 'label' => 'title', 'statuses' => ['draft','published','archived']],
        'artists' => ['table' => 'artists', 'label' => 'name', 'statuses' => ['draft','published']],
        'sets' => ['table' => 'sets_media', 'label' => 'title', 'statuses' => ['draft','published']],
        'pages' => ['table' => 'pages', 'label' => 'title', 'statuses' => ['draft','published']],
        'releases' => ['table' => 'releases', 'label' => 'title', 'statuses' => ['draft','published','archived']],
        'blog' => ['table' => 'blog_posts', 'label' => 'title', 'statuses' => ['draft','published','archived']],
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

    $ids = array_values(array_unique(array_map('intval', (array)$request['ids'])));
    if ($ids === [] || count($ids) > 100 || min($ids) < 1) {
        throw new InvalidArgumentException('INVALID_BULK_IDS');
    }

    $table = $specs[$resource]['table'];
    $labelColumn = $specs[$resource]['label'];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $lifecycleColumns = $resource === 'events' ? ',published_at,cancelled_at,finished_at' : '';
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
