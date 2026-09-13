<?php
declare(strict_types=1);

/** Published Media Library images selected in DISCADMIN; no schema migration. */
function brvtal_home_banner_slides(PDO $pdo): array
{
    try {
        $st = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=? LIMIT 1');
        $st->execute(['home.hero.slides']);
        $saved = json_decode((string)($st->fetchColumn() ?: ''), true);
        if (!is_array($saved)) return [];

        $media = $pdo->prepare("SELECT file_path FROM media WHERE file_path=? AND type='image' AND status='published' LIMIT 1");
        $slides = [];
        foreach (array_slice($saved, 0, 5) as $item) {
            if (!is_array($item) || empty($item['enabled'])) continue;
            $title = trim((string)($item['title'] ?? ''));
            $path = trim((string)($item['image'] ?? ''));
            if ($title === '' || strlen($title) > 200 || !preg_match('#^/uploads/media/[a-zA-Z0-9/_.-]+$#', $path) || str_contains($path, '..')) continue;
            $media->execute([$path]);
            if (!$media->fetchColumn()) continue;

            $url = trim((string)($item['url'] ?? ''));
            $parts = $url !== '' && filter_var($url, FILTER_VALIDATE_URL) ? parse_url($url) : false;
            $validUrl = $url === '' || preg_match('#^/(?:events|artists|sets|releases|blog|pages)/[a-z0-9-]{1,190}$#', $url)
                || (is_array($parts) && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host']) && !array_key_exists('user', $parts) && !array_key_exists('pass', $parts));
            if (!$validUrl) $url = '';
            $slides[] = [
                'title' => $title,
                'eyebrow' => substr(trim((string)($item['eyebrow'] ?? '')), 0, 140),
                'description' => substr(trim((string)($item['description'] ?? '')), 0, 480),
                'image' => $path,
                'alt' => substr(trim((string)($item['alt'] ?? $title)), 0, 360),
                'url' => $url,
                'label' => substr(trim((string)($item['label'] ?? 'DISCOVER')), 0, 80),
            ];
        }
        return $slides;
    } catch (Throwable) {
        return [];
    }
}

function brvtal_home_banner_markup(array $slides): string
{
    if (!$slides) return '';
    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $count = count($slides);
    $html = '<section class="hero-banner scene" data-scene="CORE" data-index="01" aria-label="Featured BRVTAL banners"><div class="banner-slides">';
    foreach ($slides as $index => $slide) {
        $active = $index === 0;
        $image = $escape((string)$slide['image']);
        $html .= '<article class="banner-slide' . ($active ? ' is-active' : '') . '" aria-hidden="' . ($active ? 'false' : 'true') . '"' . ($active ? '' : ' inert') . '>';
        $html .= '<img class="banner-image" src="' . $image . '" alt="' . $escape((string)$slide['alt']) . '" loading="' . ($active ? 'eager' : 'lazy') . '"' . ($active ? ' fetchpriority="high"' : '') . ' decoding="async">';
        $html .= '<div class="banner-shade"></div><div class="banner-copy">';
        if ($slide['eyebrow'] !== '') $html .= '<p class="banner-eyebrow mono">' . $escape((string)$slide['eyebrow']) . '</p>';
        $html .= '<' . ($active ? 'h1' : 'h2') . ' class="banner-title">' . $escape((string)$slide['title']) . '</' . ($active ? 'h1' : 'h2') . '>';
        if ($slide['description'] !== '') $html .= '<p class="banner-description">' . $escape((string)$slide['description']) . '</p>';
        if ($slide['url'] !== '') $html .= '<a class="banner-cta" href="' . $escape((string)$slide['url']) . '"' . (str_starts_with((string)$slide['url'], 'https://') ? ' target="_blank" rel="noopener noreferrer"' : '') . '>' . $escape((string)$slide['label'] ?: 'DISCOVER') . ' <span aria-hidden="true">↗</span></a>';
        $html .= '</div></article>';
    }
    $html .= '</div>';
    if ($count > 1) {
        $html .= '<div class="banner-controls"><button type="button" class="banner-arrow" data-banner-prev aria-label="Previous banner">←</button><span class="banner-count mono" aria-live="polite">01 / ' . str_pad((string)$count, 2, '0', STR_PAD_LEFT) . '</span><button type="button" class="banner-arrow" data-banner-next aria-label="Next banner">→</button><button type="button" class="banner-pause mono" data-banner-pause aria-label="Pause banner rotation">PAUSE</button></div>';
    }
    return $html . '<div class="scene-index">01 / 07</div></section>';
}
