<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/home_banners.php';

function check(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

check(brvtal_home_banner_markup([]) === '', 'No configured banners should preserve the existing hero.');
$html = brvtal_home_banner_markup([
    ['title' => 'RED <NIGHT>', 'eyebrow' => 'SAMPLE', 'description' => 'Sample & test', 'image' => '/uploads/media/red.png', 'alt' => 'Red crowd', 'url' => '/events/sample-red', 'label' => 'SEE EVENT'],
    ['title' => 'BLUE NIGHT', 'eyebrow' => '', 'description' => '', 'image' => '/uploads/media/blue.png', 'alt' => 'Blue crowd', 'url' => 'https://example.org/event', 'label' => 'MORE'],
]);
check(str_contains($html, '<h1 class="banner-title">RED &lt;NIGHT&gt;</h1>'), 'The visible headline must be escaped.');
check(str_contains($html, 'Sample &amp; test'), 'Descriptions must be escaped.');
check(str_contains($html, 'loading="eager" fetchpriority="high"'), 'First banner image must be prioritized.');
check(str_contains($html, 'loading="lazy"'), 'Later banner images must be lazy.');
check(str_contains($html, 'aria-hidden="true" inert'), 'Inactive slides must be outside keyboard navigation.');
check(str_contains($html, 'data-banner-prev') && str_contains($html, 'data-banner-next'), 'Multiple banners need manual navigation.');
check(str_contains($html, 'target="_blank" rel="noopener noreferrer"'), 'External links need opener protection.');
echo "Home banners contract passed\n";
