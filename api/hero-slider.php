<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/bootstrap.php';

function brvtal_hero_slider_json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=60, stale-while-revalidate=300');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function brvtal_hero_slider_clean_url(mixed $value): string
{
    $value = trim((string)$value);
    if ($value === '') return '';
    if (str_starts_with($value, '/')) return $value;
    if (preg_match('#^https://#i', $value)) return $value;
    if (str_starts_with($value, '#')) return $value;
    return '';
}

function brvtal_hero_slider_payload(mixed $raw): array
{
    if (!is_array($raw)) return ['enabled' => false, 'autoplay' => false, 'interval' => 7000, 'slides' => []];

    $slides = [];
    foreach (array_slice(is_array($raw['slides'] ?? null) ? $raw['slides'] : [], 0, 20) as $slide) {
        if (!is_array($slide) || ($slide['enabled'] ?? true) === false) continue;
        $desktop = brvtal_hero_slider_clean_url($slide['desktopSrc'] ?? '');
        if ($desktop === '') continue;
        $slides[] = [
            'id' => preg_replace('/[^a-zA-Z0-9_-]/', '', (string)($slide['id'] ?? '')) ?: ('slide-' . (count($slides) + 1)),
            'mediaType' => ($slide['mediaType'] ?? '') === 'video' ? 'video' : 'image',
            'desktopSrc' => $desktop,
            'mobileSrc' => brvtal_hero_slider_clean_url($slide['mobileSrc'] ?? ''),
            'poster' => brvtal_hero_slider_clean_url($slide['poster'] ?? ''),
            'kicker' => mb_substr(trim((string)($slide['kicker'] ?? '')), 0, 120),
            'title' => mb_substr(trim((string)($slide['title'] ?? '')), 0, 140),
            'body' => mb_substr(trim((string)($slide['body'] ?? '')), 0, 320),
            'ctaLabel' => mb_substr(trim((string)($slide['ctaLabel'] ?? '')), 0, 80),
            'ctaUrl' => brvtal_hero_slider_clean_url($slide['ctaUrl'] ?? ''),
            'contentAlign' => in_array(($slide['contentAlign'] ?? ''), ['left','center','right'], true) ? $slide['contentAlign'] : 'left',
            'overlay' => max(0, min(85, (int)($slide['overlay'] ?? 35))),
        ];
    }

    return [
        'enabled' => ($raw['enabled'] ?? false) === true && count($slides) > 0,
        'autoplay' => ($raw['autoplay'] ?? true) !== false,
        'interval' => max(2500, min(30000, (int)($raw['interval'] ?? 7000))),
        'slides' => $slides,
    ];
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    header('Allow: GET');
    brvtal_hero_slider_json(['enabled' => false, 'autoplay' => false, 'interval' => 7000, 'slides' => []], 405);
}

try {
    $pdo = db();
    $statement = $pdo->prepare("SELECT setting_value, is_json FROM settings WHERE setting_key = ? LIMIT 1");
    $statement->execute(['home.hero.slider']);
    $row = $statement->fetch();
    $value = null;
    if ($row) {
        $value = (int)$row['is_json'] === 1
            ? json_decode((string)$row['setting_value'], true)
            : null;
    }
    brvtal_hero_slider_json(brvtal_hero_slider_payload($value));
} catch (Throwable $error) {
    brvtal_hero_slider_json(['enabled' => false, 'autoplay' => false, 'interval' => 7000, 'slides' => []]);
}
