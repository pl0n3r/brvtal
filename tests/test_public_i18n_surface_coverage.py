#!/usr/bin/env python3
"""Phase 3 public ES/EN surface coverage regressions for BRVTAL#855."""
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def php_json(source: str) -> dict:
    process = subprocess.run(
        ["php", "-r", source],
        cwd=ROOT,
        check=False,
        capture_output=True,
        text=True,
    )
    if process.returncode != 0:
        raise AssertionError(process.stderr or process.stdout)
    return json.loads(process.stdout)


class PublicI18nSurfaceCoverageTests(unittest.TestCase):
    def test_public_editorial_surfaces_use_canonical_locale_pipeline(self) -> None:
        payload = php_json(r"""
require 'config/public_home.php';
require 'config/public_page.php';

final class SurfaceCoverageAdapter implements BrvtalPublicTranslationAdapter {
    public function version(): string { return 'surface-coverage-v1'; }
    public function translate(string $source, string $sourceLocale, string $targetLocale): string {
        if ($sourceLocale !== 'es' || $targetLocale !== 'en') {
            throw new RuntimeException('unexpected locale pair');
        }
        return 'EN::' . $source;
    }
}
$cache = [];
$read = static function(array $identity) use (&$cache): ?string {
    return $cache[(string)$identity['cache_key']] ?? null;
};
$write = static function(array $identity, string $value) use (&$cache): void {
    $cache[(string)$identity['cache_key']] = $value;
};
$adapter = new SurfaceCoverageAdapter();

$event = [
    'id' => 9, 'slug' => 'genesis', 'title' => 'GENESIS',
    'description' => 'Noche canónica.', 'seo_description' => 'Evento canónico.',
];
$home = brvtalPublicHomeLocalizedEditorialRecord(
    'events', $event, 'en', $adapter, $read, $write
);
$page = [
    'entity' => [
        'id' => 41, 'slug' => 'ritual-industrial', 'route_type' => 'blog',
        'title' => 'Ritual industrial', 'description' => 'Crónica canónica.',
        'seo_title' => 'Ritual industrial — BRVTAL',
        'seo_description' => 'Crónica editorial canónica.',
    ],
    'record' => ['body_html' => '<p>Cuerpo canónico español.</p>'],
    'facts' => [], 'links' => [], 'related' => [],
];
$localizedPage = brvtalPublicPageLocalizedEditorial(
    $page, 'en', $adapter, $read, $write
);
echo json_encode([
    'surfaces' => brvtalPublicI18nEditorialSurfaceFields(),
    'home' => $home,
    'page' => $localizedPage,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
""")
        self.assertEqual(
            set(payload["surfaces"]),
            {"events", "artists", "sets", "releases", "blog", "pages",
             "memories", "media", "ticket_types"},
        )
        home = payload["home"]
        self.assertEqual(home["resolved_locale"], "en")
        self.assertTrue(home["translated"])
        self.assertEqual(home["record"]["id"], 9)
        self.assertEqual(home["record"]["slug"], "genesis")
        self.assertEqual(home["record"]["title"], "GENESIS")
        self.assertEqual(home["record"]["description"], "EN::Noche canónica.")
        self.assertNotIn("locale", home["record"])

        page = payload["page"]
        self.assertEqual(page["resolved_locale"], "en")
        self.assertTrue(page["translated"])
        self.assertEqual(page["page"]["entity"]["id"], 41)
        self.assertEqual(page["page"]["entity"]["slug"], "ritual-industrial")
        self.assertEqual(page["page"]["entity"]["title"], "EN::Ritual industrial")
        self.assertEqual(page["page"]["entity"]["description"], "EN::Crónica canónica.")
        self.assertEqual(page["page"]["record"]["body_html"], "<p>Cuerpo canónico español.</p>")
        self.assertNotIn("locale", page["page"]["entity"])

    def test_missing_or_stale_translation_falls_back_to_spanish_without_duplicate_records(self) -> None:
        payload = php_json(r"""
require 'config/public_home.php';
require 'config/public_page.php';

final class FailingSurfaceAdapter implements BrvtalPublicTranslationAdapter {
    public function version(): string { return 'surface-fallback-v1'; }
    public function translate(string $source, string $sourceLocale, string $targetLocale): string {
        throw new RuntimeException('provider unavailable');
    }
}
$cache = [];
$old = brvtalPublicTranslationCacheIdentity(
    'Descripción anterior.', 'es', 'en', 'surface-fallback-v1'
);
$cache[(string)$old['cache_key']] = 'EN::stale';
$read = static function(array $identity) use (&$cache): ?string {
    return $cache[(string)$identity['cache_key']] ?? null;
};
$write = static function(array $identity, string $value) use (&$cache): void {
    $cache[(string)$identity['cache_key']] = $value;
};
$adapter = new FailingSurfaceAdapter();

$event = [
    'id' => 9, 'slug' => 'genesis', 'title' => 'GENESIS',
    'description' => 'Descripción nueva.', 'seo_description' => 'Evento canónico.',
];
$home = brvtalPublicHomeLocalizedEditorialRecord(
    'events', $event, 'en', $adapter, $read, $write
);
$page = [
    'entity' => [
        'id' => 77, 'slug' => 'manifiesto', 'route_type' => 'pages',
        'title' => 'Manifiesto', 'description' => 'Texto canónico en español.',
        'seo_title' => 'Manifiesto — BRVTAL', 'seo_description' => 'Página canónica.',
    ],
    'record' => [], 'facts' => [], 'links' => [], 'related' => [],
];
$localizedPage = brvtalPublicPageLocalizedEditorial(
    $page, 'en', $adapter, $read, $write
);
echo json_encode(['home' => $home, 'page' => $localizedPage],
    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
""")
        home = payload["home"]
        self.assertEqual(home["resolved_locale"], "es")
        self.assertEqual(home["source"], "fallback")
        self.assertFalse(home["translated"])
        self.assertEqual(home["record"]["description"], "Descripción nueva.")
        self.assertEqual(home["record"]["id"], 9)
        self.assertEqual(home["record"]["slug"], "genesis")
        self.assertNotIn("locale", home["record"])

        page = payload["page"]
        self.assertEqual(page["resolved_locale"], "es")
        self.assertEqual(page["source"], "fallback")
        self.assertFalse(page["translated"])
        self.assertEqual(page["page"]["entity"]["title"], "Manifiesto")
        self.assertEqual(page["page"]["entity"]["description"], "Texto canónico en español.")
        self.assertEqual(page["page"]["entity"]["id"], 77)
        self.assertEqual(page["page"]["entity"]["slug"], "manifiesto")
        self.assertNotIn("locale", page["page"]["entity"])


if __name__ == "__main__":
    unittest.main()
