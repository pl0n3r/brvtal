<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_activity.php';
require_once __DIR__ . '/../config/content_ordering.php';

brvtal_admin_require();

function brvtalOrderJson(array $payload, int $status = 200): never
{
    json_response($payload, $status, ['Cache-Control' => 'no-store']);
}

/** @return array<int,array{id:mixed,sort_order:mixed,label:mixed}> */
function brvtalOrderLockedRows(PDO $pdo, string $resource): array
{
    $statement = match ($resource) {
        'artists' => $pdo->query(
            'SELECT id,sort_order,name AS label '
            . 'FROM artists ORDER BY sort_order ASC,id ASC FOR UPDATE'
        ),
        'sets' => $pdo->query(
            'SELECT id,sort_order,title AS label '
            . 'FROM sets_media ORDER BY sort_order ASC,id ASC FOR UPDATE'
        ),
        'releases' => $pdo->query(
            'SELECT id,sort_order,title AS label '
            . 'FROM releases ORDER BY sort_order ASC,id ASC FOR UPDATE'
        ),
        'blog' => $pdo->query(
            'SELECT id,sort_order,title AS label '
            . 'FROM blog_posts ORDER BY sort_order ASC,id ASC FOR UPDATE'
        ),
        default => throw new InvalidArgumentException(
            'ORDER_RESOURCE_NOT_ALLOWED'
        ),
    };

    return $statement->fetchAll(PDO::FETCH_ASSOC);
}

function brvtalOrderUpdateStatement(
    PDO $pdo,
    string $resource
): PDOStatement {
    return match ($resource) {
        'artists' => $pdo->prepare(
            'UPDATE artists SET sort_order=? WHERE id=?'
        ),
        'sets' => $pdo->prepare(
            'UPDATE sets_media SET sort_order=? WHERE id=?'
        ),
        'releases' => $pdo->prepare(
            'UPDATE releases SET sort_order=? WHERE id=?'
        ),
        'blog' => $pdo->prepare(
            'UPDATE blog_posts SET sort_order=? WHERE id=?'
        ),
        default => throw new InvalidArgumentException(
            'ORDER_RESOURCE_NOT_ALLOWED'
        ),
    };
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
if ($method !== 'POST') {
    brvtalOrderJson(
        ['ok' => false, 'error' => 'METHOD_NOT_ALLOWED'],
        405
    );
}

brvtal_admin_require_csrf();

try {
    $input = input_json();
    $resource = strtolower(trim((string)($input['resource'] ?? '')));
    $definition = brvtalContentOrderResource($resource);

    if ($definition === null) {
        brvtalOrderJson(
            ['ok' => false, 'error' => 'ORDER_RESOURCE_NOT_ALLOWED'],
            422
        );
    }

    $ids = brvtalContentOrderIds($input['ids'] ?? null);
    $previousIds = brvtalContentOrderIds($input['previous_ids'] ?? null);
    $labelColumn = $definition['label'];
    $activityResource = $definition['activity'];

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $rows = brvtalOrderLockedRows($pdo, $resource);
        $currentIds = array_map(
            static fn(array $row): int => (int)$row['id'],
            $rows
        );

        if (
            !brvtalContentOrderMatches($ids, $currentIds)
            || $previousIds !== $currentIds
        ) {
            throw new RuntimeException('ORDER_STALE', 409);
        }

        $byId = [];
        foreach ($rows as $row) {
            $byId[(int)$row['id']] = $row;
        }

        $update = brvtalOrderUpdateStatement($pdo, $resource);
        $changed = 0;

        foreach ($ids as $position => $id) {
            $row = $byId[$id];
            if ((int)$row['sort_order'] === $position) {
                continue;
            }

            $before = [
                'id' => $id,
                $labelColumn => (string)$row['label'],
                'sort_order' => (int)$row['sort_order'],
            ];

            $update->execute([$position, $id]);
            if ($update->rowCount() < 1) {
                throw new RuntimeException('ORDER_STALE', 409);
            }

            $after = $before;
            $after['sort_order'] = $position;

            brvtal_activity_record(
                $pdo,
                'update',
                $activityResource,
                $id,
                $before,
                $after,
                [
                    'source' => 'content_ordering',
                    'position' => $position,
                ],
                (string)$row['label']
            );
            $changed++;
        }

        $pdo->commit();
        brvtalOrderJson([
            'ok' => true,
            'resource' => $resource,
            'ids' => $ids,
            'changed' => $changed,
        ]);
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $error;
    }
} catch (InvalidArgumentException $error) {
    brvtalOrderJson(
        ['ok' => false, 'error' => $error->getMessage()],
        422
    );
} catch (RuntimeException $error) {
    $publicErrors = [
        'ORDER_STALE' => 409,
        'ACTIVITY_SCHEMA_MISSING' => 503,
    ];
    $message = $error->getMessage();

    if (isset($publicErrors[$message])) {
        brvtalOrderJson(
            ['ok' => false, 'error' => $message],
            $publicErrors[$message]
        );
    }

    if (function_exists('brvtal_log')) {
        brvtal_log(
            'CONTENT_ORDER_ERROR',
            'Content reorder failed',
            [
                'class' => get_class($error),
                'message' => $message,
            ]
        );
    }

    brvtalOrderJson(
        ['ok' => false, 'error' => 'INTERNAL_ERROR'],
        500
    );
} catch (Throwable $error) {
    if (function_exists('brvtal_log')) {
        brvtal_log(
            'CONTENT_ORDER_ERROR',
            'Content reorder failed',
            [
                'class' => get_class($error),
                'message' => $error->getMessage(),
            ]
        );
    }

    brvtalOrderJson(
        ['ok' => false, 'error' => 'INTERNAL_ERROR'],
        500
    );
}
