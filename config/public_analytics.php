<?php
declare(strict_types=1);

function brvtal_public_ga_id_from_theme(mixed $theme): string
{
    $analytics = is_array($theme) && is_array($theme['analytics'] ?? null) ? $theme['analytics'] : [];
    $id = strtoupper(trim((string)($analytics['google'] ?? '')));
    return preg_match('/^G-[A-Z0-9]{4,20}$/', $id) ? $id : '';
}

function brvtal_public_ga_id(PDO $pdo): string
{
    try {
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
