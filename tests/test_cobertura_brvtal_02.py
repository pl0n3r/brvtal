#!/usr/bin/env python3
"""Issue #1271: real offline locale, editorial-overlay, and route regressions.

LOCAL_PARTIAL is a verified code-level behavior, not deployed site evidence.
No real editor records, network, provider, credentials, or live state are used.
"""
from __future__ import annotations

import json
import re
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
IDS = {
    "S06-R001": "Spanish (**es**) is the canonical editorial language and default public locale.",
    "S06-R002": "English (**en**) is the supported alternate locale.",
    "S06-R003": "Public locale settings are effective only through the allowlisted contract default_locale + available_locales; unsupported values fail closed to the supported ES/EN policy.",
    "S06-R004": "Critical public microcopy uses a small versioned catalog in code.",
    "S06-R005": "BRVTAL brand terms, artist names and music taxonomy are protected from automatic translation by an explicit glossary/field policy.",
    "S06-R006": "Editors maintain one canonical Spanish record rather than duplicating ES/EN editorial entities.",
    "S06-R007": "Owner decision #821 materializes the Phase 1 foundation in three leaves: #824 establishes locale policy/catalog/glossary, #825 adds the provider-agnostic server-side translation cache contract, and #826 adds the accessible no-reload selector.",
    "S06-R008": "Owner decision #842 authorizes Phase 2.",
    "S06-R010": "Leaf #845 adds English editorial overlays as projections bound to the stable Spanish route_type:id plus a canonical source hash; a source change invalidates the overlay, protected music-domain titles remain canonical, and draft/private/untrusted content fails closed before translation.",
    "S06-R011": "The overlay reuses #825's injected adapter/cache contract and does not select a paid provider or create duplicate ES/EN editorial records.",
    "S07-L74": "Home;",
    "S07-L75": "About;",
    "S07-L76": "Manifesto;",
    "S07-L77": "Events;",
    "S07-L78": "Artists;",
    "S07-L80": "Releases;",
    "S07-L82": "Contact;",
    "S07-L83": "Legal;",
    "S07-L84": "Blog;",
    "S07-R002": "Sets / Music;",
}
# Policy decisions are not executable features. Published page contents and
# deployment need independent evidence; route generation never proves a page
# exists, has editorial data or was validated on a live site.
NON_OFFLINE = {
    "S06-R007": ("C", "Owner decision allocates work; not an isolated runtime operation"),
    "S06-R008": ("C", "Owner approval of Phase 2; not an isolated runtime operation"),
}
DEPLOY_EVIDENCE = {
    "S07-L74": "real Home browser rendering",
    "S07-L75": "published About CMS record",
    "S07-L76": "published Manifesto CMS record",
    "S07-L77": "published Events records",
    "S07-L78": "published Artists records",
    "S07-L80": "published Releases records",
    "S07-L82": "real contact form and delivery",
    "S07-L83": "published Legal CMS record",
    "S07-L84": "published Blog records",
    "S07-R002": "published Sets/Music records",
}
# The router's output is a *local* URI contract, not proof that a CMS record
# with the synthetic slug has been published.
ROUTES = {
    "S07-L74": ("", "", "", "/"),
    "S07-L75": ("pages", "about", "", "/pages/about"),
    "S07-L76": ("pages", "manifesto", "", "/pages/manifesto"),
    "S07-L77": ("events", "sample-event", "", "/events/sample-event"),
    "S07-L78": ("artists", "sample-artist", "", "/artists/sample-artist"),
    "S07-L80": ("releases", "sample-release", "", "/releases/sample-release"),
    "S07-L82": ("", "", "contact", "/contact"),
    "S07-L83": ("pages", "legal", "", "/pages/legal"),
    "S07-L84": ("blog", "sample-article", "", "/blog/sample-article"),
    "S07-R002": ("sets", "sample-set", "", "/sets/sample-set"),
}
PHP_SOURCES = (
    "config/public_i18n.php", "config/public_i18n_routing.php",
    "config/public_translation.php", "config/public_translation_overlay.php",
    "tests/editorial-translation-overlay-scenarios.php",
)

PHP_PROBE = r'''
require $argv[1] . '/config/public_i18n_routing.php';
$ops = json_decode($argv[2], true, 48, JSON_THROW_ON_ERROR);
$out = [];
foreach ($ops as $item) {
    $out[] = match ($item['op']) {
        'policy' => brvtalPublicI18nPayload($item['site'] ?? []),
        'locale' => brvtalPublicRouteLocale($item['value'] ?? null),
        'fields' => [
            'artist_fields' => brvtalPublicI18nEditorialFields('artists'),
            'event_fields' => brvtalPublicI18nEditorialFields('events'),
            'set_fields' => brvtalPublicI18nEditorialFields('sets'),
            'nonexistent' => brvtalPublicI18nEditorialFields('internal_secret'),
        ],
        'route' => brvtalPublicLocalizedRouteUrls(
            'https://example.invalid', $item['locale'],
            $item['type'] ?? '', $item['slug'] ?? '', $item['page'] ?? ''
        ),
        'document' => brvtalPublicApplyLocalizedDocument(
            '<html lang="es"><body>synthetic</body></html>',
            $item['locale'], $item['paths'] ?? []
        ),
        default => null,
    };
}
echo json_encode($out, JSON_THROW_ON_ERROR);
'''


def matrix_rows(source: object) -> dict[str, tuple[str, ...]]:
    """Check all 20 *full* rows; duplicates/missing/false promotions fail shut."""
    if type(source) is not str or not source.strip():
        raise ValueError("UNKNOWN: missing matrix")
    result: dict[str, tuple[str, ...]] = {}
    for line in source.splitlines():
        match = re.match(r"^\| (S06-R\d{3}|S07-(?:L\d{2}|R\d{3})) · ", line)
        if match is None or match.group(1) not in IDS:
            continue
        ident = match.group(1)
        if ident in result:
            raise ValueError("UNKNOWN: duplicate " + ident)
        parts = tuple(p.strip() for p in line[1:-1].split("|")) if line.endswith("|") else ()
        if (len(parts) != 5 or not parts[0].startswith(ident + " · [S")
                or parts[1] != IDS[ident] or parts[2] != "NO_VERIFICABLE"
                or not parts[3].startswith("Sin aserción contrastada para")
                or not parts[4]):
            raise ValueError("UNKNOWN: invalid row " + ident)
        result[ident] = parts
    if set(result) != set(IDS):
        raise ValueError("UNKNOWN: incomplete matrix " + str(sorted(set(IDS) - set(result))))
    return result


def run_php(operations: list[dict]) -> list:
    php = shutil.which("php")
    if php is None:
        raise AssertionError("UNKNOWN: PHP CLI needed to exercise real application")
    for path in PHP_SOURCES:
        if not (ROOT / path).is_file():
            raise AssertionError("UNKNOWN: real PHP source missing: " + path)
    proc = subprocess.run(
        [php, "-d", "allow_url_fopen=0", "-r", PHP_PROBE,
         str(ROOT), json.dumps(operations, ensure_ascii=False)],
        cwd=ROOT, capture_output=True, text=True, timeout=25, check=False,
    )
    if proc.returncode or proc.stderr.strip():
        raise AssertionError("UNKNOWN: real PHP failed: " + proc.stderr[-800:])
    return json.loads(proc.stdout)


def run_overlay(scenario: str) -> str:
    php = shutil.which("php")
    if not php:
        raise AssertionError("UNKNOWN: PHP CLI required")
    script = ROOT / "tests/editorial-translation-overlay-scenarios.php"
    if not script.is_file():
        raise AssertionError("UNKNOWN: no real overlay scenarios")
    proc = subprocess.run(
        [php, "-d", "allow_url_fopen=0", str(script), scenario],
        cwd=ROOT, capture_output=True, text=True, timeout=25, check=False,
    )
    if proc.returncode or proc.stderr.strip():
        raise AssertionError("UNKNOWN: overlay contract failed: " + proc.stderr[-800:])
    return proc.stdout


class CoberturaBrvtal02Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: run real PHP locale, overlay and route contracts, not labels."""
        self.assertEqual(20, len(IDS))
        self.assertEqual(2, len(NON_OFFLINE))
        self.assertEqual(10, len(ROUTES))
        self.assertEqual(set(IDS), set(NON_OFFLINE) | set(ROUTES) | {
            "S06-R001", "S06-R002", "S06-R003", "S06-R004",
            "S06-R005", "S06-R006", "S06-R010", "S06-R011",
        })
        self.assertEqual(20, len(matrix_rows(MATRIX.read_text(encoding="utf-8"))))
        ops = [
            {"op": "policy", "site": {"default_locale": "en",
                "available_locales": ["en", "fr", "es", "en"]}},
            {"op": "fields"},
        ]
        for ident, (kind, slug, page, _) in ROUTES.items():
            for locale in ("es", "en"):
                ops.append({"op": "route", "locale": locale,
                            "type": kind, "slug": slug, "page": page})
        result = run_php(ops)
        locale = result[0]
        self.assertEqual(locale["canonical_locale"], "es")
        self.assertEqual(locale["default_locale"], "es")
        self.assertEqual(locale["available_locales"], ["en", "es"])
        self.assertEqual(locale["version"], 1)
        self.assertEqual(set(locale["catalog"]), {"es", "en"})
        self.assertEqual(set(locale["catalog"]["es"]), set(locale["catalog"]["en"]))
        self.assertEqual(locale["catalog"]["en"]["nav.events"], "EVENTS")
        protected = locale["protected_terms"]
        self.assertIn("BRVTAL", protected["exact"])
        self.assertIn("HARD TECHNO", protected["music_taxonomy"])
        self.assertIn("artists[].name", protected["entity_fields"])
        fields = result[1]
        self.assertNotIn("name", fields["artist_fields"])
        self.assertNotIn("title", fields["event_fields"])
        self.assertNotIn("title", fields["set_fields"])
        self.assertEqual([], fields["nonexistent"])
        position = 2
        for ident, (_, _, _, path) in ROUTES.items():
            with self.subTest(row=ident):
                spanish, english = result[position:position + 2]
                position += 2
                self.assertEqual(spanish["canonical"], "https://example.invalid" + path)
                self.assertEqual(english["canonical"], "https://example.invalid/en" + ("" if path == "/" else path))
                self.assertEqual(spanish["alternates"], english["alternates"])
                self.assertEqual(spanish["paths"]["es"], path)
        self.assertIn("identity-ok", run_overlay("identity"))
        self.assertIn("visibility-ok", run_overlay("visibility"))

    def test_falla_cerrado(self) -> None:
        """AC-02: unsupported locale/type/slug and unsafe source fail shut."""
        ops = [
            {"op": "policy", "site": {"default_locale": "fr", "available_locales": ["fr"]}},
            {"op": "locale", "value": "../../en"},
            {"op": "locale", "value": "fr"},
            {"op": "route", "locale": "fr", "type": "events", "slug": "synthetic"},
            {"op": "route", "locale": "es", "type": "staff", "slug": "synthetic"},
            {"op": "route", "locale": "en", "type": "events", "slug": "../../private"},
            {"op": "route", "locale": "en", "type": "events", "slug": ""},
            {"op": "route", "locale": "es", "type": "events", "slug": "fixture", "page": "contact"},
            {"op": "document", "locale": "fr"},
            {"op": "document", "locale": "en", "paths": {"es": "//foreign", "en": "/en"}},
        ]
        result = run_php(ops)
        self.assertEqual(result[0]["canonical_locale"], "es")
        self.assertEqual(result[0]["available_locales"], ["es", "en"])
        self.assertEqual(result[1:8], [None] * 7)
        self.assertEqual(result[8], '<html lang="es"><body>synthetic</body></html>')
        self.assertEqual(result[9], '<html lang="es"><body>synthetic</body></html>')
        # A fail-closed matrix contract cannot be satisfied by missing rows,
        # forged coverage, duplicates or changed requirement text.
        sample = MATRIX.read_text(encoding="utf-8")
        for ident in IDS:
            match = next(line for line in sample.splitlines() if line.startswith("| " + ident + " · "))
            for damaged in (sample.replace(match + "\n", "", 1),
                            sample.replace(match, match.replace("NO_VERIFICABLE", "CUBIERTO", 1), 1)):
                with self.subTest(row=ident):
                    with self.assertRaisesRegex(ValueError, "UNKNOWN"):
                        matrix_rows(damaged)
        self.assertIn("visibility-ok", run_overlay("visibility"))

    def test_sin_datos_reales(self) -> None:
        """AC-03: synthetic inputs only, no network, secrets or external costs."""
        rows = matrix_rows(MATRIX.read_text(encoding="utf-8"))
        self.assertEqual(set(rows), set(IDS))
        self.assertEqual({"C"}, {v[0] for v in NON_OFFLINE.values()})
        self.assertTrue(all(reason.strip() for _, reason in NON_OFFLINE.values()))
        self.assertEqual(set(ROUTES), set(DEPLOY_EVIDENCE))
        source = PHP_PROBE + json.dumps(ROUTES, ensure_ascii=False)
        for sensitive in ("api_key=", "ghp_", "Bearer ", "DATABASE_URL=", "Authorization:",
                          "requests.get(", "urllib.request", "https://www.brvtal.com.co"):
            self.assertNotIn(sensitive, source)
        self.assertNotIn("http://", PHP_PROBE)
        self.assertIn("https://example.invalid", PHP_PROBE)
        self.assertEqual("en", run_php([{"op": "locale", "value": "EN"}])[0])


if __name__ == "__main__":
    unittest.main()
