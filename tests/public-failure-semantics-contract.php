<?php
declare(strict_types=1);

function public_failure_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "PUBLIC FAILURE SEMANTICS CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$hero = (string)file_get_contents(__DIR__ . '/../api/hero-slider.php');
$sitemap = (string)file_get_contents(__DIR__ . '/../sitemap.php');

public_failure_assert(
    str_contains($hero, "function brvtal_hero_slider_error(string $error, int $status = 503): never"),
    'Hero Slider must expose a dedicated error response path'
);
public_failure_assert(
    str_contains($hero, "json_encode(['ok' => false, 'error' => $error]")
        && str_contains($hero, "header('Cache-Control: no-store')")
        && str_contains($hero, "header('Retry-After: 60')"),
    'Hero Slider errors must be observable, non-cacheable and retryable'
);
public_failure_assert(
    str_contains($hero, "brvtal_log('PUBLIC_HERO_SLIDER_ERROR'")
        && str_contains($hero, "brvtal_hero_slider_error('HERO_SLIDER_UNAVAILABLE', 503)"),
    'Hero Slider internal exceptions must be logged and returned as HTTP 503 semantics'
);
public_failure_assert(
    !str_contains($hero, "catch (Throwable $error) {\n    brvtal_hero_slider_json(['enabled' => false"),
    'Hero Slider exceptions must not masquerade as a valid disabled slider'
);

public_failure_assert(
    str_contains($sitemap, "brvtal_log('PUBLIC_SITEMAP_ERROR'")
        && str_contains($sitemap, "http_response_code(503)")
        && str_contains($sitemap, "header('Cache-Control: no-store')")
        && str_contains($sitemap, "header('Retry-After: 60')")
        && str_contains($sitemap, 'SITEMAP_UNAVAILABLE'),
    'Sitemap family-query failures must be observable HTTP 503 responses'
);
public_failure_assert(
    !preg_match('/catch\s*\(Throwable(?:\s+\$\w+)?\)\s*\{\s*continue\s*;\s*\}/s', $sitemap),
    'Sitemap must not silently continue after a failed content-family query'
);
public_failure_assert(
    str_contains($sitemap, "header('Content-Type: application/xml; charset=utf-8')")
        && str_contains($sitemap, "header('Cache-Control: public, max-age=900')"),
    'healthy Sitemap responses must retain the existing XML/cache contract'
);

echo "BRVTAL public failure semantics contract tests passed.\n";
