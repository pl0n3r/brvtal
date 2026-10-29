#!/usr/bin/env python3
"""BRVTAL #1097: S07-L74..L78, offline evidence for the first five page families.

A local synthetic fixture is never evidence that a public CMS record exists,
that private records return HTTP 404, or that production is deployed. Those
parts remain UNKNOWN until independently observed and reconciled.
"""
from __future__ import annotations

from functools import lru_cache
import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
IDS = ("S07-L74", "S07-L75", "S07-L76", "S07-L77", "S07-L78")
PAGES = {
    "S07-L74": "Home;",
    "S07-L75": "About;",
    "S07-L76": "Manifesto;",
    "S07-L77": "Events;",
    "S07-L78": "Artists;",
}
UNKNOWN = "UNKNOWN"
LOCAL_PARTIAL = "LOCAL_PARTIAL"


def matrix_rows(raw: object) -> dict[str, tuple[str, ...]]:
    """Read exact unverified rows; reject missing, duplicated or promoted data."""
    if not isinstance(raw, str) or not raw:
        raise ValueError("UNKNOWN: matrix is missing")
    found: dict[str, tuple[str, ...]] = {}
    for line in raw.splitlines():
        for key in IDS:
            if not line.startswith("| " + key + " · "):
                continue
            if key in found:
                raise ValueError("UNKNOWN: duplicate " + key)
            cells = tuple(cell.strip() for cell in line.strip("|").split("|"))
            if (len(cells) != 5 or cells[1] != PAGES[key]
                    or cells[2] != "NO_VERIFICABLE"
                    or "Sin aserción contrastada" not in cells[3]
                    or "tests/public-entity-pages-contract.php" not in cells[3]
                    or not cells[0].startswith(key + " · [S07 L")):
                raise ValueError("UNKNOWN: invalid row " + key)
            found[key] = cells
    if set(found) != set(IDS):
        raise ValueError("UNKNOWN: missing rows " + str(set(IDS) - set(found)))
    return found


# Deliberately no PDO, HTTP or environment access. Only production PHP public
# routing, visibility, page content and SEO functions with invented records.
PHP_PROBE = r'''
require_once 'config/public_routes.php';
require_once 'config/public_i18n_routing.php';
require_once 'config/public_seo.php';
require_once 'config/public_not_found.php';
$base = 'https://example.test';
$now = new DateTimeImmutable('2026-10-28T12:00:00+00:00');
$environment = static fn(string $name): ?string => null;
$definitions = brvtal_public_content_definitions($environment);
$home = brvtalPublicLocalizedRouteUrls($base, 'es');
$homeEnglish = brvtalPublicLocalizedRouteUrls($base, 'en');
$homeSeo = brvtal_public_seo_document(null, $base);
$about = ['slug'=>'synthetic-about','locale'=>'en','status'=>'published',
  'content_json'=>'{"blocks":[{"type":"heading","content":"Synthetic About"}]}'];
$manifesto = ['slug'=>'synthetic-manifesto','locale'=>'en','status'=>'published',
  'content_json'=>'{"blocks":[{"type":"paragraph","content":"Synthetic Manifesto"}]}'];
$pageRoute = brvtalPublicLocalizedRouteUrls($base, 'es', 'pages', $about['slug']);
$manifestoRoute = brvtalPublicLocalizedRouteUrls($base, 'en', 'pages', $manifesto['slug']);
$badPage = '{"blocks":[{"type":"embed","content":"unsafe"}]}';
$event = ['status'=>'published','event_date'=>'2026-11-20 21:00:00',
  'published_at'=>'2026-10-01 12:00:00'];
$eventRoute = brvtalPublicLocalizedRouteUrls($base, 'es', 'events', 'synthetic-event');
$artistRoute = brvtalPublicLocalizedRouteUrls($base, 'en', 'artists', 'synthetic-artist');
$notFound = brvtal_public_not_found_page(
  brvtal_public_not_found_seo($base, 'artists', 'synthetic-missing'));
echo json_encode([
  'home'=>[
    'route'=>is_array($home) && $home['canonical'] === $base.'/'
      && ($home['alternates']['en'] ?? null) === $base.'/en',
    'default'=>is_array($homeEnglish) && $homeEnglish['canonical'] === $base.'/en',
    'seo'=>($homeSeo['canonical'] ?? null) === $base.'/'
      && ($homeSeo['schema']['@type'] ?? null) === 'Organization',
    'static'=>in_array('/', brvtal_public_static_routes(), true),
  ],
  'about'=>[
    'route'=>is_array($pageRoute) && $pageRoute['canonical'] === $base.'/pages/synthetic-about',
    'published'=>brvtal_public_page_is_visible($about, $environment),
    'content'=>brvtal_page_content_structure_error($about['content_json']) === null
      && brvtal_page_content_plain_text($about['content_json']) === 'Synthetic About',
    'draft_denied'=>!brvtal_public_page_is_visible(array_replace($about, ['status'=>'draft']), $environment),
    'locale_denied'=>!brvtal_public_page_is_visible(array_replace($about, ['locale'=>'fr']), $environment),
  ],
  'manifesto'=>[
    'route'=>is_array($manifestoRoute) && $manifestoRoute['canonical'] === $base.'/en/pages/synthetic-manifesto',
    'published'=>brvtal_public_page_is_visible($manifesto, $environment),
    'content'=>brvtal_page_content_structure_error($manifesto['content_json']) === null
      && brvtal_page_content_plain_text($manifesto['content_json']) === 'Synthetic Manifesto',
    'invalid_structure_denied'=>brvtal_page_content_structure_error($badPage) === 'PAGE_CONTENT_STRUCTURE_UNSUPPORTED'
      && brvtal_page_content_plain_text($badPage) === '',
    'draft_denied'=>!brvtal_public_page_is_visible(array_replace($manifesto, ['status'=>'draft']), $environment),
  ],
  'events'=>[
    'route'=>is_array($eventRoute) && $eventRoute['canonical'] === $base.'/events/synthetic-event',
    'definition'=>($definitions['events']['table'] ?? null) === 'events'
      && ($definitions['events']['event_visibility'] ?? null) === true
      && str_contains($definitions['events']['where'] ?? '', 'status IN ('),
    'public'=>brvtal_public_event_is_visible($event, $now),
    'draft_denied'=>!brvtal_public_event_is_visible(array_replace($event,['status'=>'draft']), $now),
    'future_publication_denied'=>!brvtal_public_event_is_visible(array_replace($event,['published_at'=>'2026-11-01 12:00:00']), $now),
    'malformed_publication_denied'=>!brvtal_public_event_is_visible(array_replace($event,['published_at'=>'invalid-date']), $now),
  ],
  'artists'=>[
    'route'=>is_array($artistRoute) && $artistRoute['canonical'] === $base.'/en/artists/synthetic-artist',
    'definition'=>($definitions['artists']['table'] ?? null) === 'artists'
      && ($definitions['artists']['where'] ?? null) === "status='published'"
      && ($definitions['artists']['title_field'] ?? null) === 'name',
    'malformed_slug_denied'=>brvtalPublicLocalizedRouteUrls($base,'es','artists','../private') === null,
    'unknown_type_denied'=>brvtalPublicLocalizedRouteUrls($base,'es','staff','synthetic') === null,
    'not_found_noindex'=>str_contains($notFound, '<meta name="robots" content="noindex, follow">')
      && str_contains($notFound, '404 / NOT FOUND'),
  ],
], JSON_THROW_ON_ERROR);
'''


@lru_cache(maxsize=1)
def php_observation() -> dict[str, dict[str, bool]]:
    php = shutil.which("php")
    if not php:
        raise AssertionError("UNKNOWN: PHP CLI missing")
    finished = subprocess.run(
        [php, "-d", "allow_url_fopen=0", "-r", PHP_PROBE],
        cwd=ROOT, capture_output=True, text=True, timeout=20, check=False,
    )
    if finished.returncode or finished.stderr.strip():
        raise AssertionError("UNKNOWN: isolated PHP probe failed: " + finished.stderr[-600:])
    result = json.loads(finished.stdout)
    if not isinstance(result, dict):
        raise AssertionError("UNKNOWN: invalid PHP evidence")
    return result


def static_checks(files: dict[str, str]) -> dict[str, dict[str, bool]]:
    spec, index, template, seo, pages = (
        files[x] for x in ("spec", "index", "template", "seo", "page_contract")
    )
    return {
        "S07-L74": {
            'specified': all('- ' + name in spec for name in ('Home;', 'About;', 'Manifesto;', 'Events;', 'Artists;')),
            'home_template': '<main id="top">' in template and '<!-- BRVTAL-HOME-PARTIAL:hero -->' in template,
            'home_fail_closed': '$emitHomeUnavailable()' in index and 'http_response_code(503)' in index,
        },
        "S07-L75": {
            'published_page_family': "'pages' => brvtal_public_trial_page_definition" in files['routes'],
            'canonical_delivery': 'brvtalPublicEntityDocument(' in index and "'pages'" in seo,
            'structure_contract': 'brvtal_page_content_structure_error' in pages,
        },
        "S07-L76": {
            'published_page_family': "'pages' => brvtal_public_trial_page_definition" in files['routes'],
            'canonical_delivery': 'brvtalPublicEntityDocument(' in index and "'pages'" in seo,
            'content_contract': 'brvtal_page_content_plain_text' in pages,
        },
        "S07-L77": {
            'route_lookup': 'brvtal_public_seo_entity(db(), $type, $slug)' in index,
            'event_guard': 'brvtal_public_event_is_visible($row)' in seo,
            '404_noindex': "header('X-Robots-Tag: noindex, follow')" in index
                and 'http_response_code(404)' in index,
        },
        "S07-L78": {
            'route_lookup': 'brvtal_public_seo_entity(db(), $type, $slug)' in index,
            'published_sql': "status='published'" in files['routes'],
            '404_noindex': 'http_response_code(404)' in index
                and 'brvtal_public_not_found_page' in index,
        },
    }


def verdict(key: object, table: object, evidence: object) -> dict[str, str]:
    result = {"code": UNKNOWN, "published_route": UNKNOWN,
              "private_404": UNKNOWN, "production": UNKNOWN}
    if (not isinstance(key, str) or key not in IDS
            or not isinstance(table, dict) or set(table) != set(IDS)
            or not isinstance(evidence, dict) or set(evidence) != set(IDS)):
        return result
    row = table.get(key)
    checks = evidence.get(key)
    if (not isinstance(row, tuple) or len(row) != 5
            or row[1] != PAGES[key] or row[2] != 'NO_VERIFICABLE'
            or not isinstance(checks, dict) or not checks
            or not all(value is True for value in checks.values())):
        return result
    result['code'] = LOCAL_PARTIAL
    return result


class BrechasBrvtal07Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.rows = matrix_rows((ROOT / 'docs/matriz-cobertura.md').read_text(encoding='utf-8'))
        cls.files = {
            'spec': (ROOT / 'docs/BRVTAL-SPEC.md').read_text(encoding='utf-8'),
            'index': (ROOT / 'index.php').read_text(encoding='utf-8'),
            'template': (ROOT / 'index.html').read_text(encoding='utf-8'),
            'seo': (ROOT / 'config/public_seo.php').read_text(encoding='utf-8'),
            'routes': (ROOT / 'config/public_routes.php').read_text(encoding='utf-8'),
            'page_contract': (ROOT / 'tests/public-entity-pages-contract.php').read_text(encoding='utf-8'),
        }
        cls.php = php_observation()
        cls.static = static_checks(cls.files)
        cls.evidence = {
            key: {**cls.php[section], **cls.static[key]}
            for key, section in zip(IDS, ('home', 'about', 'manifesto', 'events', 'artists'))
        }

    def test_contrato_principal(self) -> None:
        self.assertEqual(set(self.rows), set(IDS))
        self.assertEqual(set(self.evidence), set(IDS))
        for key in IDS:
            with self.subTest(row=key):
                self.assertTrue(all(value is True for value in self.evidence[key].values()), key)
                self.assertEqual(verdict(key, self.rows, self.evidence)['code'], LOCAL_PARTIAL)

    def test_falla_cerrado(self) -> None:
        for key in IDS:
            with self.subTest(row=key):
                self.assertEqual(verdict(key, None, self.evidence)['code'], UNKNOWN)
                self.assertEqual(verdict(key, self.rows, {})['code'], UNKNOWN)
                altered = {k: dict(v) for k, v in self.evidence.items()}
                altered[key]['synthetic_failure'] = False
                self.assertEqual(verdict(key, self.rows, altered)['code'], UNKNOWN)
        matrix = (ROOT / 'docs/matriz-cobertura.md').read_text(encoding='utf-8')
        with self.assertRaisesRegex(ValueError, 'UNKNOWN'):
            matrix_rows(None)
        with self.assertRaisesRegex(ValueError, 'UNKNOWN'):
            matrix_rows(matrix.replace('| S07-L77 · ', '| S07-L77X · '))
        row = next(line for line in matrix.splitlines() if line.startswith('| S07-L75 · '))
        with self.assertRaisesRegex(ValueError, 'UNKNOWN'):
            matrix_rows(matrix + '\n' + row)
        with self.assertRaisesRegex(ValueError, 'UNKNOWN'):
            matrix_rows(matrix.replace('| About; | NO_VERIFICABLE', '| About; | CUMPLE'))

    def test_sin_datos_reales(self) -> None:
        self.assertIn('synthetic-about', PHP_PROBE)
        self.assertIn('synthetic-manifesto', PHP_PROBE)
        self.assertIn('synthetic-artist', PHP_PROBE)
        for forbidden in ('new PDO', 'getenv(', 'curl_', 'file_get_contents(', '$_SERVER', '$_ENV', 'sk_live_'):
            self.assertNotIn(forbidden, PHP_PROBE)
        self.assertEqual(self.php['events']['draft_denied'], True)
        self.assertEqual(self.php['artists']['malformed_slug_denied'], True)

    def test_estados_unknown_explicitos(self) -> None:
        for key in IDS:
            with self.subTest(row=key):
                result = verdict(key, self.rows, self.evidence)
                self.assertEqual(result['published_route'], UNKNOWN)
                self.assertEqual(result['private_404'], UNKNOWN)
                self.assertEqual(result['production'], UNKNOWN)
        missing = {k: dict(v) for k, v in self.evidence.items()}
        del missing['S07-L76']
        self.assertEqual(verdict('S07-L74', self.rows, missing)['code'], UNKNOWN)
        self.assertEqual(verdict('S07-L80', self.rows, self.evidence)['code'], UNKNOWN)


if __name__ == '__main__':
    unittest.main()
