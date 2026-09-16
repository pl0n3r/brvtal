<?php
declare(strict_types=1);

require_once __DIR__ . '/public_settings.php';

function brvtal_public_ga_id_value(mixed $value): string
{
    $id = strtoupper(trim((string)$value));
    return preg_match('/^G-[A-Z0-9]{4,20}$/', $id) ? $id : '';
}

function brvtal_public_ga_id_from_theme(mixed $theme): string
{
    $analytics = is_array($theme) && is_array($theme['analytics'] ?? null) ? $theme['analytics'] : [];
    return brvtal_public_ga_id_value($analytics['google'] ?? '');
}

function brvtal_public_ga_id(PDO $pdo): string
{
    try {
        // Global analytics is behavior/integration configuration and therefore
        // belongs to Settings. Existing theme.analytics.google remains a
        // non-destructive fallback for installations created before #400.
        $analytics = brvtal_config_setting_json($pdo, 'analytics');
        $configured = brvtal_public_ga_id_value($analytics['ga4_id'] ?? $analytics['google'] ?? '');
        if ($configured !== '') return $configured;

        $rows = $pdo->query("SELECT setting_key,setting_value FROM settings WHERE setting_key='theme.active' OR setting_key LIKE 'theme.%'")->fetchAll();
        $settings = [];
        foreach ($rows as $row) $settings[(string)$row['setting_key']] = (string)$row['setting_value'];
        $active = trim($settings['theme.active'] ?? 'core', '" ');
        if (!preg_match('/^[a-z0-9_-]{1,60}$/i', $active)) return '';
        return brvtal_public_ga_id_from_theme(json_decode($settings['theme.' . $active] ?? '', true));
    } catch (Throwable) {
        return '';
    }
}

function brvtal_public_analytics_markup(string $id, string $version): string
{
    if ($id === '') return '';
    $version = rawurlencode($version);
    return '<link rel="stylesheet" href="/css/public-analytics.css?v=' . $version . '">' . "\n"
        . '<script defer src="/js/public-analytics.js?v=' . $version . '" data-ga-id="' . htmlspecialchars($id, ENT_QUOTES, 'UTF-8') . '"></script>';
}
