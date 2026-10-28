#!/usr/bin/env python3
"""BRVTAL #1115: offline, fail-closed evidence for five canonical-preview gaps.

Never turns source inspection or synthetic PHP into browser, CI or production proof.
No network access, database credentials, real media or admin sessions are used.
"""
from __future__ import annotations

import json
import re
import shutil
import subprocess
import unittest
from functools import lru_cache
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
SPEC = ROOT / "docs/BRVTAL-SPEC.md"
IDS = ("S20-R017", "S20-R018", "S20-R019", "S20-R020", "S20-R021")
EXPECTED = {
    "S20-R017": "[Canonical public draft preview] - Production entity routes and preview render through the same brvtalPublicEntityDocument() delivery path.",
    "S20-R018": "Preview may replace the data source, never the public component/template path.",
    "S20-R019": "[Canonical public draft preview] - The preview shell exposes the Concept 05 composition targets",
    "S20-R020": "[Canonical public draft preview] - Raw unsaved URLs, media references, accent values and event dates are validated before rendering.",
    "S20-R021": "Invalid values fail explicitly rather than being silently persisted, published or rendered as invented data.",
}
SOURCES = {
    "config": "config/public_preview.php",
    "delivery": "config/public_entity_delivery.php",
    "index": "index.php",
    "preview": "preview.php",
    "api": "api/public-preview.php",
    "contract": "tests/public-preview-contract.php",
}
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_CONTRACT"

PHP_PROBE = r"""
// Use the versioned synthetic example; never read real local config or open DB.
define('BRVTAL_SENTRY_TESTING', true);
$GLOBALS['brvtalBootstrapConfigPath'] = getcwd() . '/config/config.example.php';
require 'config/public_preview.php';
function result_of(callable $action): string {
    try { $action(); return 'ACCEPTED'; }
    catch (InvalidArgumentException $error) { return $error->getMessage(); }
}
$ok = brvtalPublicPreviewSnapshot('events', [
    'title'=>'Synthetic Event', 'accent'=>'#aabbcc',
    'ticket_url'=>'https://example.invalid/tickets',
    'event_date'=>'2026-11-08 21:00',
]);
echo json_encode([
    'valid'=>$ok,
    'bad_url'=>result_of(fn()=>brvtalPublicPreviewUrl('javascript:alert(1)')),
    'bad_credential_url'=>result_of(fn()=>brvtalPublicPreviewUrl('https://user:pass@example.invalid/')),
    'bad_media'=>result_of(fn()=>brvtalPublicPreviewMedia('data:text/html,invalid')),
    'bad_date'=>result_of(fn()=>brvtalPublicPreviewDatetime('not-a-date')),
    'bad_accent'=>result_of(fn()=>brvtalPublicPreviewSnapshot('events', ['accent'=>'not-a-color'])),
    'bad_type'=>result_of(fn()=>brvtalPublicPreviewSnapshot('unlisted', ['title'=>'Synthetic'])),
], JSON_THROW_ON_ERROR);
"""


def rows(text: str) -> dict[str, tuple[str, ...]]:
    """Reject duplicated and malformed matrix claims, never silently drop rows."""
    found: dict[str, tuple[str, ...]] = {}
    for line in text.splitlines():
        match = re.match(r"^\| (S20-R\d{3}) · ", line)
        if not match or match.group(1) not in IDS:
            continue
        identifier = match.group(1)
        if identifier in found:
            raise ValueError("UNKNOWN: duplicate row " + identifier)
        cells = tuple(part.strip() for part in line.strip().strip("|").split("|"))
        if len(cells) != 5 or not cells[0].startswith(identifier + " · "):
            raise ValueError("UNKNOWN: malformed row " + identifier)
        found[identifier] = cells
    return found


def sources() -> dict[str, str]:
    return {k: (ROOT / filename).read_text(encoding="utf-8")
            for k, filename in SOURCES.items()}


@lru_cache(maxsize=1)
def probe() -> dict[str, object]:
    php = shutil.which("php")
    if not php:
        raise AssertionError("UNKNOWN: PHP runtime unavailable for synthetic contract")
    run = subprocess.run(
        [php, "-r", PHP_PROBE], cwd=ROOT, check=False, capture_output=True,
        text=True, timeout=20, env={"PATH": "/usr/local/bin:/usr/bin:/bin"},
    )
    if run.returncode:
        raise AssertionError("UNKNOWN: local PHP contract error: " + run.stderr[-400:])
    try:
        result = json.loads(run.stdout)
    except (ValueError, TypeError) as exc:
        raise AssertionError("UNKNOWN: unparseable local PHP result") from exc
    if not isinstance(result, dict):
        raise AssertionError("UNKNOWN: non-object local PHP result")
    return result


def verdict(identifier: str, table: dict[str, tuple[str, ...]],
            files: dict[str, str], measured: object) -> dict[str, str]:
    """An isolated code contract never certifies visual parity or production."""
    out = {"code": UNKNOWN, "browser": UNKNOWN, "ci": UNKNOWN,
           "production": UNKNOWN}
    row = table.get(identifier)
    if (identifier not in IDS or row is None or len(row) != 5
            or row[1] != EXPECTED[identifier]
            and not (identifier == "S20-R019"
                     and row[1].startswith(EXPECTED[identifier]))
            or row[2] != "NO_VERIFICABLE"
            or not all(isinstance(files.get(k), str) and files[k]
                       for k in SOURCES)
            or not isinstance(measured, dict)):
        return out
    delivery, index, preview = (files[k] for k in ("delivery", "index", "preview"))
    config, api, contract = (files[k] for k in ("config", "api", "contract"))
    shared = ("function brvtalPublicEntityDocument(" in delivery
              and "brvtal_public_entity_page($page, $seo, $analytics)" in delivery
              and "brvtalPublicEntityDocument(" in index
              and "brvtalPublicEntityDocument(" in preview
              and "brvtalPublicPreviewPage($pdo, $snapshot)" in preview
              and "srcdoc=" not in preview)
    valid = measured.get("valid")
    synthetic = (isinstance(valid, dict)
                 and valid.get("type") == "events"
                 and isinstance(valid.get("payload"), dict)
                 and valid["payload"].get("title") == "Synthetic Event"
                 and valid["payload"].get("ticket_url") == "https://example.invalid/tickets"
                 and valid["payload"].get("accent") == "#aabbcc"
                 and valid["payload"].get("event_date") == "2026-11-08 21:00:00")
    failures = {
        "bad_url": "INVALID_PREVIEW_URL",
        "bad_credential_url": "INVALID_PREVIEW_URL",
        "bad_media": "INVALID_PREVIEW_MEDIA",
        "bad_date": "INVALID_PREVIEW_DATE",
        "bad_accent": "INVALID_PREVIEW_ACCENT",
        "bad_type": "PREVIEW_TYPE_NOT_ALLOWED",
    }
    rejects_invalid = all(measured.get(k) == v for k, v in failures.items())
    if not (synthetic and rejects_invalid):
        return out
    checks = {
        "S20-R017": shared and "function brvtalPublicEntityDocument" in contract,
        "S20-R018": shared and synthetic and "brvtalPublicPreviewLoad($token)" in preview,
        "S20-R019": shared and all(x in preview for x in (
            'data-width="1440" data-height="900"',
            'data-width="390" data-height="844"',
            'const scale=Math.min(1,available/target.width)',
        )),
        "S20-R020": synthetic and rejects_invalid and all(x in config for x in (
            "brvtalPublicPreviewUrl(", "brvtalPublicPreviewMedia(",
            "brvtalPublicPreviewDatetime(", "INVALID_PREVIEW_ACCENT",
            "brvtalMediaImageReferenceState",
        )),
        "S20-R021": rejects_invalid and all(x in api for x in (
            "brvtal_admin_require();", "brvtal_admin_require_csrf();",
            "catch (InvalidArgumentException $e)", "http_response_code(422)",
        )) and "INSERT INTO" not in config and "UPDATE " not in config,
    }
    if checks[identifier]:
        out["code"] = LOCAL
    return out


class BrechasBrvtal25Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        table, files, measured = rows(MATRIX.read_text(encoding="utf-8")), sources(), probe()
        self.assertEqual(set(IDS), set(table))
        spec = SPEC.read_text(encoding="utf-8")
        self.assertIn("### Canonical public draft preview", spec)
        self.assertIn("1440 desktop", spec)
        self.assertIn("390 mobile", spec)
        self.assertIn("brvtalPublicEntityDocument()", spec)
        for identifier in IDS:
            with self.subTest(row=identifier):
                self.assertEqual(LOCAL, verdict(identifier, table, files, measured)["code"])

    def test_falla_cerrado(self) -> None:
        text = MATRIX.read_text(encoding="utf-8")
        table, files, measured = rows(text), sources(), probe()
        first = next(line for line in text.splitlines()
                     if line.startswith("| S20-R017 · "))
        with self.assertRaisesRegex(ValueError, "duplicate"):
            rows(first + "\n" + first)
        self.assertNotIn("S20-R017", rows("\n".join(x for x in text.splitlines() if x != first)))
        for identifier in IDS:
            with self.subTest(row=identifier):
                self.assertEqual(UNKNOWN, verdict(identifier, {}, files, measured)["code"])
                self.assertEqual(UNKNOWN, verdict(identifier, table, {}, measured)["code"])
                self.assertEqual(UNKNOWN, verdict(identifier, table, files, None)["code"])
                self.assertEqual(UNKNOWN, verdict(identifier, table, files, {})["code"])
                bad = dict(measured)
                bad["bad_media"] = "ACCEPTED"
                if identifier in ("S20-R020", "S20-R021"):
                    self.assertEqual(UNKNOWN, verdict(identifier, table, files, bad)["code"])
        row = dict(table)
        row["S20-R017"] = (*table["S20-R017"][:2], "CUBIERTO", *table["S20-R017"][3:])
        self.assertEqual(UNKNOWN, verdict("S20-R017", row, files, measured)["code"])
        broken = dict(files)
        broken["preview"] = "noncanonical renderer"
        self.assertEqual(UNKNOWN, verdict("S20-R017", table, broken, measured)["code"])
        self.assertEqual(UNKNOWN, verdict("S20-R999", table, files, measured)["code"])

    def test_sin_datos_reales(self) -> None:
        own = Path(__file__).read_text(encoding="utf-8")
        self.assertIn("Synthetic Event", PHP_PROBE)
        self.assertIn("example.invalid", PHP_PROBE)
        self.assertIn("config.example.php", PHP_PROBE)
        self.assertNotIn("PDO(", PHP_PROBE)
        self.assertNotIn("curl_", PHP_PROBE)
        self.assertNotIn("file_get_contents(", PHP_PROBE)
        self.assertNotRegex(own, r"(?m)^(?:import|from)\s+(?:requests|httpx|socket|urllib)\b")
        self.assertNotIn("BEGIN " + "PRIVATE KEY", own)
        self.assertEqual("Synthetic Event", probe()["valid"]["payload"]["title"])

    def test_estados_unknown_explicitos(self) -> None:
        table, files, measured = rows(MATRIX.read_text(encoding="utf-8")), sources(), probe()
        for identifier in IDS:
            with self.subTest(row=identifier):
                outcome = verdict(identifier, table, files, measured)
                self.assertEqual(UNKNOWN, outcome["browser"])
                self.assertEqual(UNKNOWN, outcome["ci"])
                self.assertEqual(UNKNOWN, outcome["production"])
                self.assertEqual(UNKNOWN, verdict(identifier, {}, files, measured)["code"])
        self.assertIn("noindex,nofollow", files["preview"])
        self.assertIn("Cache-Control: no-store", files["preview"])


if __name__ == "__main__":
    unittest.main()
