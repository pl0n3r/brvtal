<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_activity.php';
require_once __DIR__ . '/../config/content_ordering.php';

brvtal_admin_require();

function brvtal_order_json(array $payload, int $status = 200): never
{
    json_response($payload, $status, ['Cache-Control'=>'no-store']);
}

/** @return array<int,array{id:mixed,sort_order:mixed,label:mixed}> */
function brvtal_order_locked_rows(PDO $pdo, string $resource): array
{
    $statement = match ($resource) {
        'artists' => $pdo->query('SELECT id,sort_order,name AS label FROM artists ORDER BY sort_order ASC,id ASC FOR UPDATE'),
        'sets' => $pdo->query('SELECT id,sort_order,title AS label FROM sets_media ORDER BY sort_order ASC,id ASC FOR UPDATE'),
        'releases' => $pdo->query('SELECT id,sort_order,title AS label FROM releases ORDER BY sort_order ASC,id ASC FOR UPDATE'),
        'blog' => $pdo->query('SELECT id,sort_order,title AS label FROM blog_posts ORDER BY sort_order ASC,id ASC FOR UPDATE'),
        default => throw new InvalidArgumentException('ORDER_RESOURCE_NOT_ALLOWED'),
    };
    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function brvtal_order_update_statement(PDO $pdo, string $resource): PDOStatement
{
    return match ($resource) {
        'artists' => $pdo->prepare('UPDATE artists SET sort_order=? WHERE id=?'),
        'sets' => $pdo->prepare('UPDATE sets_media SET sort_order=? WHERE id=?'),
        'releases' => $pdo->prepare('UPDATE releases SET sort_order=? WHERE id=?'),
        'blog' => $pdo->prepare('UPDATE blog_posts SET sort_order=? WHERE id=?'),
        default => throw new InvalidArgumentException('ORDER_RESOURCE_NOT_ALLOWED'),
    };
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'POST') {
    brvtal_order_json(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'], 405);
}
brvtal_admin_require_csrf();

try {
    $input = input_json();
    $resource = strtolower(trim((string)($input['resource'] ?? '')));
    $definition = brvtal_content_order_resource($resource);
    if ($definition === null) {
        brvtal_order_json(['ok'=>false,'error'=>'ORDER_RESOURCE_NOT_ALLOWED'], 422);
    }
    $ids = brvtal_content_order_ids($input['ids'] ?? null);
    $previousIds = brvtal_content_order_ids($input['previous_ids'] ?? null);
    $labelColumn = $definition['label'];
    $activityResource = $definition['activity'];

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $rows = brvtal_order_locked_rows($pdo, $resource);
        $currentIds = array_map(static fn(array $row): int => (int)$row['id'], $rows);
        if (!brvtal_content_order_matches($ids, $currentIds)
            || $previousIds !== $currentIds
        ) {
            throw new RuntimeException('ORDER_STALE', 409);
        }

        $byId = [];
        foreach ($rows as $row) $byId[(int)$row['id']] = $row;
        $update = brvtal_order_update_statement($pdo, $resource);
        $changed = 0;

        foreach ($ids as $position => $id) {
            $row = $byId[$id];
            if ((int)$row['sort_order'] === $position) continue;
            $before = ['id'=>$id,$labelColumn=>(string)$row['label'],'sort_order'=>(int)$row['sort_order']];
            $update->execute([$position,$id]);
            if ($update->rowCount() < 1) throw new RuntimeException('ORDER_STALE', 409);
            $after = $before;
            $after['sort_order'] = $position;
            brvtal_activity_record(
                $pdo, 'update', $activityResource, $id, $before, $after,
                ['source'=>'content_ordering','position'=>$position], (string)$row['label']
            );
            $changed++;
        }

        $pdo->commit();
        brvtal_order_json(['ok'=>true,'resource'=>$resource,'ids'=>$ids,'changed'=>$changed]);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
} catch (InvalidArgumentException $error) {
    brvtal_order_json(['ok'=>false,'error'=>$error->getMessage()], 422);
} catch (RuntimeException $error) {
    if ($error->getMessage() === 'ACTIVITY_SCHEMA_MISSING') {
        brvtal_order_json(['ok'=>false,'error'=>'ACTIVITY_SCHEMA_MISSING'], 503);
    }
    $status = $error->getCode() >= 400 && $error->getCode() <= 599 ? $error->getCode() : 500;
    brvtal_order_json(['ok'=>false,'error'=>$error->getMessage()], $status);
} catch (Throwable $error) {
    if (function_exists('brvtal_log')) {
        brvtal_log('CONTENT_ORDER_ERROR','Content reorder failed',[
            'class'=>get_class($error),'message'=>$error->getMessage()
        ]);
    }
    brvtal_order_json(['ok'=>false,'error'=>'INTERNAL_ERROR'], 500);
}
