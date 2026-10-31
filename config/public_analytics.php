<?php
declare(strict_types=1);

require_once __DIR__ . '/public_settings.php';

function brvtal_public_gtm_id_value(mixed $value): string
{
    $id = strtoupper(trim((string)$value));
    return preg_match('/^GTM-[A-Z0-9]{4,20}$/', $id) ? $id : '';
}

function brvtal_public_gtm_id_from_theme(mixed $theme): string
{
    $analytics = is_array($theme) && is_array($theme['analytics'] ?? null) ? $theme['analytics'] : [];
    foreach (['gtm_id', 'google_tag_manager', 'tag_manager', 'gtm'] as $key) {
        $id = brvtal_public_gtm_id_value($analytics[$key] ?? '');
        if ($id !== '') return $id;
    }
    return '';
}

function brvtal_public_gtm_id(PDO $pdo): string
{
    try {
        // Google Tag Manager is the single public tag-delivery layer. The
        // settings record is authoritative; legacy theme GTM keys are only a
        // non-destructive migration fallback. Direct GA4 IDs are ignored.
        $analytics = brvtal_config_setting_json($pdo, 'analytics');
        if (array_key_exists('gtm_id', $analytics)) {
            return brvtal_public_gtm_id_value($analytics['gtm_id']);
        }
        foreach (['google_tag_manager', 'tag_manager', 'gtm'] as $key) {
            if (array_key_exists($key, $analytics)) {
                $configured = brvtal_public_gtm_id_value($analytics[$key]);
                if ($configured !== '') return $configured;
            }
        }

        $rows = $pdo->query("SELECT setting_key,setting_value FROM settings WHERE setting_key='theme.active' OR setting_key LIKE 'theme.%'")->fetchAll();
        $settings = [];
        foreach ($rows as $row) $settings[(string)$row['setting_key']] = (string)$row['setting_value'];
        $active = trim($settings['theme.active'] ?? 'core', '" ');
        if (!preg_match('/^[a-z0-9_-]{1,60}$/i', $active)) return '';
        return brvtal_public_gtm_id_from_theme(json_decode($settings['theme.' . $active] ?? '', true));
    } catch (Throwable) {
        return '';
    }
}

/**
 * Shared consent bootstrap for home, entity, contact and public error routes.
 * The server emits NO analytics/measurement script on first response, even for
 * a previously accepted cookie: only the first-party JS can read exact stored
 * consent and then install the optional scripts. This also avoids cache leaks
 * across users because public documents are not varied by cookies.
 */
function brvtal_public_analytics_markup(string $id, string $version): string
{
    $id = brvtal_public_gtm_id_value($id);
    $queryVersion = rawurlencode($version);
    $safeId = htmlspecialchars($id, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $safeVersion = htmlspecialchars($version, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return '<script defer src="/js/cookie-consent.js?v=' . $queryVersion
        . '" data-gtm-id="' . $safeId
        . '" data-version="' . $safeVersion . '"></script>';
}
