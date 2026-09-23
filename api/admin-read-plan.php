<?php
declare(strict_types=1);

const BRVTAL_ADMIN_PROTECTED_SETTING_KEY = 'security.totp_encryption_key';
const BRVTAL_ADMIN_HERO_SETTING_KEY = 'home.hero.slider';
const BRVTAL_ADMIN_HERO_MEDIA_VIEW = 'hero-picker';
const BRVTAL_ADMIN_HERO_MEDIA_LIMIT = 200;

/**
 * Return an optimized authenticated collection-read plan when a supported
 * query shape asks for less data than the legacy collection response.
 *
 * A null return preserves the existing full collection behavior.
 *
 * @param array<string,mixed> $query
 * @return array{sql:?string,params:array<int,string>,error:?string,status:int}|null
 */
function brvtalAdminCollectionReadPlan(string $resource, array $query): ?array
{
    if ($resource === 'settings' && array_key_exists('key', $query)) {
        $rawKey = $query['key'];
        if (!is_string($rawKey) && !is_int($rawKey)) {
            return [
                'sql' => null,
                'params' => [],
                'error' => 'KEY_REQUIRED',
                'status' => 422,
            ];
        }
        $key = (string)$rawKey;
        if ($key === '' || strlen($key) > 120 || $key !== trim($key)) {
            return [
                'sql' => null,
                'params' => [],
                'error' => 'KEY_REQUIRED',
                'status' => 422,
            ];
        }
        if ($key === BRVTAL_ADMIN_PROTECTED_SETTING_KEY) {
            return [
                'sql' => null,
                'params' => [],
                'error' => 'PROTECTED_SETTING',
                'status' => 403,
            ];
        }
        if ($key !== BRVTAL_ADMIN_HERO_SETTING_KEY) {
            return [
                'sql' => null,
                'params' => [],
                'error' => 'SETTING_NOT_ALLOWED',
                'status' => 422,
            ];
        }

        return [
            'sql' => 'SELECT setting_key,setting_value,is_json FROM settings WHERE setting_key=? LIMIT 1',
            'params' => [BRVTAL_ADMIN_HERO_SETTING_KEY],
            'error' => null,
            'status' => 200,
        ];
    }

    $mediaView = $query['view'] ?? null;
    if ($resource === 'media'
        && is_string($mediaView)
        && $mediaView === BRVTAL_ADMIN_HERO_MEDIA_VIEW
    ) {
        return [
            'sql' => 'SELECT id,type,title,file_path,status FROM media '
                . "WHERE type IN ('image','video') AND status='published' ORDER BY id DESC LIMIT "
                . BRVTAL_ADMIN_HERO_MEDIA_LIMIT,
            'params' => [],
            'error' => null,
            'status' => 200,
        ];
    }

    return null;
}


const BRVTAL_ADMIN_COLLECTION_PAGE_SIZE = 50;
const BRVTAL_ADMIN_COLLECTION_PAGE_SIZE_MAX = 100;

/**
 * @param array<string,mixed> $query
 * @return array{page:int,page_size:int,query:string,where_sql:string,params:array<int,string>,error:?string,status:int}|null
 */
function brvtalAdminCollectionPagination(string $resource, array $query): ?array
{
    if (!in_array($resource, ['events','artists','sets','media','pages'], true)) {
        return null;
    }
    if (!array_key_exists('page', $query)
        && !array_key_exists('page_size', $query)
        && !array_key_exists('q', $query)) {
        return null;
    }

    $parse = static function (mixed $value, int $fallback): ?int {
        if ($value === null || $value === '') return $fallback;
        if (!is_string($value) && !is_int($value)) return null;
        $raw = (string)$value;
        if (!preg_match('/^[1-9][0-9]*$/', $raw)) return null;
        return (int)$raw;
    };

    $page = $parse($query['page'] ?? null, 1);
    if ($page === null) {
        return ['page'=>1,'page_size'=>BRVTAL_ADMIN_COLLECTION_PAGE_SIZE,'query'=>'','where_sql'=>'','params'=>[],'error'=>'INVALID_PAGE','status'=>422];
    }

    $pageSize = $parse($query['page_size'] ?? null, BRVTAL_ADMIN_COLLECTION_PAGE_SIZE);
    if ($pageSize === null || $pageSize > BRVTAL_ADMIN_COLLECTION_PAGE_SIZE_MAX) {
        return ['page'=>$page,'page_size'=>BRVTAL_ADMIN_COLLECTION_PAGE_SIZE,'query'=>'','where_sql'=>'','params'=>[],'error'=>'INVALID_PAGE_SIZE','status'=>422];
    }

    $rawQuery = $query['q'] ?? '';
    if (!is_string($rawQuery) && !is_int($rawQuery)) {
        return ['page'=>$page,'page_size'=>$pageSize,'query'=>'','where_sql'=>'','params'=>[],'error'=>'INVALID_QUERY','status'=>422];
    }
    $search = mb_substr(trim((string)$rawQuery), 0, 120);

    $columns = [
        'events' => ['title','slug','city','venue'],
        'artists' => ['name','slug','bio'],
        'sets' => ['title','slug','description'],
        'media' => ['title','file_path','alt_text'],
        'pages' => ['title','slug','seo_title','seo_description'],
    ][$resource] ?? [];

    $whereSql = '';
    $params = [];
    if ($search !== '' && $columns !== []) {
        $like = '%' . str_replace(['!','%','_'], ['!!','!%','!_'], $search) . '%';
        $whereSql = ' WHERE (' . implode(
            ' OR ',
            array_map(static fn(string $column): string => "`{$column}` LIKE ? ESCAPE '!'", $columns)
        ) . ')';
        $params = array_fill(0, count($columns), $like);
    }

    return [
        'page'=>$page,
        'page_size'=>$pageSize,
        'query'=>$search,
        'where_sql'=>$whereSql,
        'params'=>$params,
        'error'=>null,
        'status'=>200,
    ];
}

/**
 * @return array{page:int,page_size:int,total:int,pages:int,offset:int,has_previous:bool,has_next:bool}
 */
function brvtalAdminCollectionPaginationMeta(int $requestedPage, int $pageSize, int $total): array
{
    $pageSize = max(1, min(BRVTAL_ADMIN_COLLECTION_PAGE_SIZE_MAX, $pageSize));
    $total = max(0, $total);
    $pages = max(1, (int)ceil($total / $pageSize));
    $page = max(1, min($requestedPage, $pages));
    $offset = ($page - 1) * $pageSize;

    return [
        'page' => $page,
        'page_size' => $pageSize,
        'total' => $total,
        'pages' => $pages,
        'offset' => $offset,
        'has_previous' => $page > 1,
        'has_next' => $page < $pages,
    ];
}
