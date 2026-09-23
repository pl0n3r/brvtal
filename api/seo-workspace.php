<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/admin_activity.php';
require_once __DIR__ . '/../config/seo_persistence.php';
require_once __DIR__ . '/../config/seo_workspace.php';
require_once __DIR__ . '/../config/public_seo.php';
require_once __DIR__ . '/../config/indexnow.php';

brvtal_admin_require();

function brvtalSeoWorkspaceJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

function brvtalSeoWorkspaceBody(): array
{
    $raw = (string)file_get_contents('php://input');
    if ($raw === '') return $_POST;
    if (strlen($raw) > 64 * 1024) {
        brvtalSeoWorkspaceJson(['ok'=>false,'error'=>'PAYLOAD_TOO_LARGE'], 413);
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) {
        brvtalSeoWorkspaceJson(['ok'=>false,'error'=>'INVALID_JSON'], 400);
    }
    return $decoded;
}

/** @return list<string> */
function brvtalSeoWorkspaceWarnings(array $item): array
{
    $warnings = [];
    $title = trim((string)($item['effective_title'] ?? ''));
    $description = trim((string)($item['effective_description'] ?? ''));

    if ($title === '') $warnings[] = 'MISSING_TITLE';
    if ($description === '') $warnings[] = 'MISSING_DESCRIPTION';
    if (mb_strlen($title) > 70) $warnings[] = 'TITLE_LONG';
    if (mb_strlen($description) > 180) $warnings[] = 'DESCRIPTION_LONG';
    if (empty($item['public']) && ($item['mode'] ?? 'AUTO') !== 'AUTO') {
        $warnings[] = 'PRIVATE_WITH_OVERRIDE';
    }
    return $warnings;
}

function brvtalSeoWorkspaceEntityIsPublic(string $resource, array $row): bool
{
    if ($resource === 'events') return brvtal_public_event_is_visible($row);
    if ($resource === 'pages') {
        return ($row['status'] ?? '') === 'published' && ($row['locale'] ?? '') === 'en';
    }
    return ($row['status'] ?? '') === 'published';
}

function brvtalSeoWorkspaceEntityType(string $resource): string
{
    return [
        'events'=>'EVENT',
        'artists'=>'ARTIST',
        'sets'=>'SET',
        'releases'=>'RELEASE',
        'blog'=>'BLOG',
        'pages'=>'PAGE',
    ][$resource] ?? strtoupper($resource);
}

/** @return list<array<string,mixed>> */
function brvtalSeoWorkspaceEntityInventory(PDO $pdo, string $base): array
{
    $items = [];
    $homeDefaults = brvtal_public_global_seo($pdo);

    foreach (brvtalSeoWorkspaceEntityDefinitions() as $resource => $definition) {
        $table = $definition['table'];
        $titleField = $definition['title'];
        $descriptionField = $definition['description'];
        $imageField = $definition['image'];
        $imageSelect = $imageField === '' ? "'' AS image" : "`{$imageField}` AS image";
        $extras = match ($resource) {
            'events' => ',event_date,published_at',
            'pages' => ',locale',
            'blog' => ',published_at',
            default => '',
        };
        $sql = "SELECT id,slug,status,seo_title,seo_description,"
            . "`{$titleField}` AS source_title,"
            . "`{$descriptionField}` AS source_description,"
            . "{$imageSelect}{$extras} FROM `{$table}` ORDER BY id DESC";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $description = (string)($row['source_description'] ?? '');
            if ($resource === 'pages') {
                $description = brvtal_page_content_plain_text($description);
            }
            $entity = [
                'id'=>(int)$row['id'],
                'route_type'=>$resource,
                'slug'=>(string)($row['slug'] ?? ''),
                'title'=>(string)($row['source_title'] ?? ''),
                'description'=>$description,
                'image'=>(string)($row['image'] ?? ''),
                'seo_title'=>(string)($row['seo_title'] ?? ''),
                'seo_description'=>(string)($row['seo_description'] ?? ''),
                'schema_type'=>(string)$definition['schema_type'],
            ];
            foreach (['event_date','published_at','locale'] as $extra) {
                if (array_key_exists($extra, $row)) $entity[$extra] = $row[$extra];
            }

            $effective = brvtal_public_seo_document($entity, $base, $homeDefaults);
            $automaticEntity = $entity;
            $automaticEntity['seo_title'] = '';
            $automaticEntity['seo_description'] = '';
            $automatic = brvtal_public_seo_document($automaticEntity, $base, $homeDefaults);
            $manualTitle = trim((string)($row['seo_title'] ?? '')) !== '';
            $manualDescription = trim((string)($row['seo_description'] ?? '')) !== '';
            $manualCount = (int)$manualTitle + (int)$manualDescription;
            $mode = $manualCount === 0 ? 'AUTO' : ($manualCount === 2 ? 'MANUAL' : 'MIXED');
            $type = brvtalSeoWorkspaceEntityType($resource);
            $path = '/' . $resource . '/' . rawurlencode((string)($row['slug'] ?? ''));
            $item = [
                'kind'=>'entity',
                'key'=>$resource . ':' . (int)$row['id'],
                'resource'=>$resource,
                'id'=>(int)$row['id'],
                'type'=>$type,
                'label'=>(string)($row['source_title'] ?? ''),
                'path'=>$path,
                'canonical'=>rtrim($base, '/') . $path,
                'status'=>(string)($row['status'] ?? 'draft'),
                'public'=>brvtalSeoWorkspaceEntityIsPublic($resource, $row),
                'mode'=>$mode,
                'seo_title'=>(string)($row['seo_title'] ?? ''),
                'seo_description'=>(string)($row['seo_description'] ?? ''),
                'share_image'=>'',
                'automatic_title'=>$automatic['title'],
                'automatic_description'=>$automatic['description'],
                'automatic_image'=>$automatic['image'],
                'effective_title'=>$effective['title'],
                'effective_description'=>$effective['description'],
                'effective_image'=>$effective['image'],
                'fallback_title_source'=>'AUTO FROM ' . $type . ' TITLE',
                'fallback_description_source'=>'AUTO FROM ' . $type . ' CONTENT',
            ];
            $item['warnings'] = brvtalSeoWorkspaceWarnings($item);
            $items[] = $item;
        }
    }
    return $items;
}

/** @return list<array<string,mixed>> */
function brvtalSeoWorkspaceInventory(PDO $pdo): array
{
    $base = brvtal_public_base_url($GLOBALS['config'] ?? []);
    $items = [];

    foreach (array_keys(brvtalSeoWorkspaceStaticDefinitions()) as $key) {
        $state = brvtalSeoWorkspaceStaticState($pdo, $key, $base);
        $item = [
            'kind'=>'static',
            'key'=>$key,
            'resource'=>'static',
            'id'=>null,
            'type'=>$state['type'],
            'label'=>$state['label'],
            'path'=>$state['path'],
            'canonical'=>$state['canonical'],
            'status'=>'public',
            'public'=>true,
            'mode'=>$state['mode'],
            'seo_title'=>$state['seo_title'],
            'seo_description'=>$state['seo_description'],
            'share_image'=>$state['share_image'],
            'automatic_title'=>$state['automatic_title'],
            'automatic_description'=>$state['automatic_description'],
            'automatic_image'=>$state['automatic_image'],
            'effective_title'=>$state['effective_title'],
            'effective_description'=>$state['effective_description'],
            'effective_image'=>$state['effective_image'],
            'fallback_title_source'=>'AUTO FROM ROUTE DEFAULT',
            'fallback_description_source'=>'AUTO FROM ROUTE DEFAULT',
        ];
        $item['warnings'] = brvtalSeoWorkspaceWarnings($item);
        $items[] = $item;
    }

    array_push($items, ...brvtalSeoWorkspaceEntityInventory($pdo, $base));

    $paths = [];
    foreach ($items as $index => $item) {
        $path = (string)($item['path'] ?? '');
        if ($path === '') continue;
        $paths[$path][] = $index;
    }
    foreach ($paths as $indexes) {
        if (count($indexes) < 2) continue;
        foreach ($indexes as $index) {
            $items[$index]['warnings'][] = 'DUPLICATE_CANONICAL';
        }
    }
    return $items;
}

try {
    $pdo = db();
    $method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    if ($method === 'GET') {
        $items = brvtalSeoWorkspaceInventory($pdo);
        $summary = [
            'total'=>count($items),
            'auto'=>count(array_filter($items, static fn(array $item): bool => $item['mode'] === 'AUTO')),
            'manual'=>count(array_filter($items, static fn(array $item): bool => $item['mode'] !== 'AUTO')),
            'issues'=>count(array_filter($items, static fn(array $item): bool => $item['warnings'] !== [])),
        ];
        brvtalSeoWorkspaceJson(['ok'=>true,'data'=>$items,'summary'=>$summary]);
    }

    if ($method !== 'PUT') {
        header('Allow: GET, PUT');
        brvtalSeoWorkspaceJson(['ok'=>false,'error'=>'METHOD_NOT_ALLOWED'], 405);
    }

    brvtal_admin_require_csrf();
    $body = brvtalSeoWorkspaceBody();
    $kind = strtolower(trim((string)($body['kind'] ?? '')));

    if ($kind === 'static') {
        $key = strtolower(trim((string)($body['key'] ?? '')));
        $base = brvtal_public_base_url($GLOBALS['config'] ?? []);
        $result = brvtalSeoWorkspacePersistStatic($pdo, $key, $body, $base);
        if ($result['changed']) {
            brvtalIndexNowEnqueueUrls($pdo, [(string)$result['state']['canonical']]);
        }
        brvtalSeoWorkspaceJson(['ok'=>true,'data'=>$result['state']]);
    }

    if ($kind === 'entity') {
        $resource = strtolower(trim((string)($body['resource'] ?? '')));
        $id = (int)($body['id'] ?? 0);
        $definitions = brvtalSeoWorkspaceEntityDefinitions();
        if (!isset($definitions[$resource])) {
            brvtalSeoWorkspaceJson(['ok'=>false,'error'=>'INVALID_RESOURCE'], 422);
        }
        if ($id < 1) {
            brvtalSeoWorkspaceJson(['ok'=>false,'error'=>'ID_REQUIRED'], 422);
        }

        try {
            $after = brvtalSeoPersistOverrides(
                $pdo,
                $resource,
                $id,
                $definitions[$resource],
                $body
            );
        } catch (RuntimeException $error) {
            if ($error->getMessage() === 'SEO_RESOURCE_NOT_FOUND') {
                brvtalSeoWorkspaceJson(['ok'=>false,'error'=>'NOT_FOUND'], 404);
            }
            throw $error;
        }
        brvtalIndexNowNotifyEntityId($pdo, $resource, $id);
        brvtalSeoWorkspaceJson([
            'ok'=>true,
            'data'=>[
                'kind'=>'entity',
                'resource'=>$resource,
                'id'=>$id,
                'seo_title'=>$after['seo_title'],
                'seo_description'=>$after['seo_description'],
            ],
        ]);
    }

    brvtalSeoWorkspaceJson(['ok'=>false,'error'=>'INVALID_KIND'], 422);
} catch (InvalidArgumentException $error) {
    brvtalSeoWorkspaceJson(['ok'=>false,'error'=>$error->getMessage()], 422);
} catch (RuntimeException $error) {
    if ($error->getMessage() === 'ACTIVITY_SCHEMA_MISSING') {
        brvtalSeoWorkspaceJson(['ok'=>false,'error'=>'ACTIVITY_SCHEMA_MISSING'], 503);
    }
    if ($error->getMessage() === 'SEO_SETTING_INVALID') {
        brvtalSeoWorkspaceJson(['ok'=>false,'error'=>'SEO_SETTING_INVALID'], 503);
    }
    if (function_exists('brvtal_log')) {
        brvtal_log('SEO_WORKSPACE_RUNTIME_ERROR', 'SEO workspace runtime failure', [
            'class'=>get_class($error),
            'message'=>$error->getMessage(),
        ]);
    }
    brvtalSeoWorkspaceJson(['ok'=>false,'error'=>'INTERNAL_ERROR'], 500);
} catch (Throwable $error) {
    if (function_exists('brvtal_log')) {
        brvtal_log('SEO_WORKSPACE_ERROR', 'SEO workspace request failed', [
            'class'=>get_class($error),
            'message'=>$error->getMessage(),
        ]);
    }
    brvtalSeoWorkspaceJson(['ok'=>false,'error'=>'INTERNAL_ERROR'], 500);
}
