<?php
declare(strict_types=1);

function entity_expect(bool $condition, string $message): void
{
    if (!$condition) throw new RuntimeException($message);
}

$page = file_get_contents(__DIR__ . '/../config/public_page.php');
$css = file_get_contents(__DIR__ . '/../css/public-entity.css');
entity_expect(is_string($page) && is_string($css), 'Public entity sources must be readable');
entity_expect(str_contains($page, 'class="skip-link" href="#main-content"'), 'Entity pages must expose a skip-to-content link');
entity_expect(str_contains($page, '<main id="main-content" tabindex="-1">'), 'Entity pages must expose a focusable main-content target');
entity_expect(str_contains($page, 'loading="eager" fetchpriority="high" decoding="async"'), 'Entity hero image must be prioritized and decoded asynchronously');
entity_expect(str_contains($page, 'loading="lazy" decoding="async"'), 'Related entity images must remain lazy and decode asynchronously');
entity_expect(str_contains($css, '.skip-link:focus'), 'Skip link must become visible on keyboard focus');
entity_expect(str_contains($css, '.entity-nav a:focus-visible'), 'Entity navigation links must expose visible keyboard focus');

echo "BRVTAL Public Entity contract tests passed.\n";
