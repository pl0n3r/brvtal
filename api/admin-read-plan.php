<?php
declare(strict_types=1);

const BRVTAL_ADMIN_PROTECTED_SETTING_KEY = 'security.totp_encryption_key';
const BRVTAL_ADMIN_HERO_SETTING_KEY = 'home.hero.slider';
const BRVTAL_ADMIN_HERO_MEDIA_VIEW = 'hero-picker';

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
                . "WHERE type IN ('image','video') ORDER BY id DESC",
            'params' => [],
            'error' => null,
            'status' => 200,
        ];
    }

    return null;
}
