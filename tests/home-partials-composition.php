<?php
declare(strict_types=1);

/** Reconstruct the current ten fixed Home partials for legacy PHP contracts. */
function brvtal_test_composed_home(): string
{
    $root = dirname(__DIR__);
    $html = file_get_contents($root . '/index.html');
    if ($html === false) {
        throw new RuntimeException('Home template missing');
    }
    foreach (['nav', 'hero', 'experience', 'nights', 'artists', 'sound', 'memories', 'journal', 'connected', 'footer'] as $name) {
        $marker = '<!-- BRVTAL-HOME-PARTIAL:' . $name . ' -->';
        if (substr_count($html, $marker) !== 1) {
            throw new RuntimeException('Nonunique Home marker: ' . $name);
        }
        $partial = file_get_contents($root . '/partials/home/' . $name . '.html');
        if ($partial === false) {
            throw new RuntimeException('Missing Home partial: ' . $name);
        }
        $html = str_replace($marker, $partial, $html);
    }
    if (str_contains($html, 'BRVTAL-HOME-PARTIAL:')) {
        throw new RuntimeException('Unexpanded Home partial marker');
    }
    return $html;
}
