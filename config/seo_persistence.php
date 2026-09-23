<?php
declare(strict_types=1);

require_once __DIR__ . '/admin_activity.php';
require_once __DIR__ . '/seo_defaults.php';

/**
 * Persist explicit SEO overrides for one resource inside a single transaction.
 * Blank values remain NULL so public delivery can derive live editorial fallbacks.
 *
 * @return array{id:int,seo_title:?string,seo_description:?string}
 */
function brvtalSeoPersistOverrides(
    PDO $pdo,
    string $resource,
    int $id,
    array $definition,
    array $body
): array {
    $table = (string)$definition['table'];
    $titleField = (string)$definition['title'];
    $descriptionField = (string)$definition['description'];
    $requestedTitle = trim((string)($body['seo_title'] ?? ''));
    $requestedDescription = trim((string)($body['seo_description'] ?? ''));

    $pdo->beginTransaction();
    try {
        $lock = $pdo->prepare(
            "SELECT id,seo_title,seo_description,`{$titleField}` AS source_title,`{$descriptionField}` AS source_description
             FROM `{$table}` WHERE id=? LIMIT 1 FOR UPDATE"
        );
        $lock->execute([$id]);
        $locked = $lock->fetch(PDO::FETCH_ASSOC);
        if (!$locked) {
            throw new RuntimeException('SEO_RESOURCE_NOT_FOUND');
        }

        $before = [
            'id'=>$id,
            'seo_title'=>$locked['seo_title'] ?? null,
            'seo_description'=>$locked['seo_description'] ?? null,
        ];
        $seoTitle = brvtalSeoOverrideValue($requestedTitle, 190);
        $seoDescription = brvtalSeoOverrideValue($requestedDescription, 320);
        $after = [
            'id'=>$id,
            'seo_title'=>$seoTitle,
            'seo_description'=>$seoDescription,
        ];

        $st = $pdo->prepare("UPDATE `{$table}` SET seo_title=?,seo_description=? WHERE id=?");
        $st->execute([$seoTitle, $seoDescription, $id]);

        if (brvtal_activity_changed_fields(
            brvtal_activity_snapshot($resource, $before),
            brvtal_activity_snapshot($resource, $after)
        ) !== []) {
            brvtal_activity_record(
                $pdo,
                'seo_update',
                $resource,
                $id,
                $before,
                $after,
                ['source'=>'seo_metadata']
            );
        }

        $pdo->commit();
        return $after;
    } catch (Throwable $error) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $error;
    }
}
