#!/usr/bin/env python3
"""BRVTAL #1114: offline evidence for five §20 Blog/preview coverage gaps.

Only synthetic PHP fixtures and repository source are inspected. A local
contract verdict never asserts that CI, production, or the matrix are green.
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
IDS = ("S20-R009", "S20-R010", "S20-R011", "S20-R012", "S20-R013")
EXPECTED = {
    "S20-R009": "[Blog Trash / Recycle Bin] The retention target is **30 days**",
    "S20-R010": "A future retention job requires its own explicit safe design",
    "S20-R011": "Media remains outside Blog Trash semantics",
    "S20-R012": "[Canonical public draft preview] Events, Artists, Releases, Sets, Pages and Blog",
    "S20-R013": "[Canonical public draft preview] - Preview captures the current editor payload",
}
PATHS = {
    "spec": "docs/BRVTAL-SPEC.md",
    "blog": "api/blog.php",
    "media": "api/media-library.php",
    "preview_config": "config/public_preview.php",
    "preview_api": "api/public-preview.php",
    "preview_ui": "discadmin/public-preview.js",
    "preview_contract": "tests/public-preview-contract.php",
    "media_contract": "tests/media-library-contract.php",
}
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_CONTRACT"

# Loading the PHP module only declares its functions. The probe never calls
# storage, database or network endpoints, and invents all input values.
PHP_PROBE = r"""
// Bootstrap loads a non-versioned config.php. Supply a synthetic local
// configuration through the existing test override; never access credentials.
$fixture = tempnam(sys_get_temp_dir(), 'brvtal-preview-fixture-');
if ($fixture === false) throw new RuntimeException('UNKNOWN: no temp fixture');
file_put_contents($fixture, "<?php return ['app'=>['timezone'=>'UTC'], 'security'=>['session_name'=>'BRVTAL_SYNTHETIC']];");
register_shutdown_function(static function () use ($fixture): void { @unlink($fixture); });
define('BRVTAL_SENTRY_TESTING', true);
$GLOBALS['brvtalBootstrapConfigPath'] = $fixture;
require_once 'config/public_preview.php';
$types = brvtalPublicPreviewTypes();
$input = [
    'title' => 'Synthetic Draft', 'body' => 'Synthetic draft body',
    'status' => 'draft', 'unrecognized' => 'DO_NOT_PERSIST'
];
$blog = brvtalPublicPreviewSnapshot('blog', $input);
$event = brvtalPublicPreviewSnapshot('events', [
    'title' => 'Synthetic Event', 'status' => 'draft'
]);
$invalid = null;
try { brvtalPublicPreviewSnapshot('unrecognized', []); }
catch (InvalidArgumentException $e) { $invalid = $e->getMessage(); }
$badUrl = null;
try { brvtalPublicPreviewSnapshot('blog', [
    'title' => 'Synthetic Draft', 'cover_image' => 'javascript:alert(1)'
]); }
catch (InvalidArgumentException $e) { $badUrl = $e->getMessage(); }
echo json_encode([
    'types'=>$types, 'blog'=>$blog, 'event'=>$event,
    'invalid'=>$invalid, 'bad_url'=>$badUrl,
], JSON_THROW_ON_ERROR);
"""


def rows(text: str) -> dict[str, tuple[str, ...]]:
    """Read exact rows, rejecting duplicates and ambiguous table delimiters."""
    found: dict[str, tuple[str, ...]] = {}
    for line in text.splitlines():
        match = re.match(r"^\| (S20-R\d{3}) · ", line)
        if not match or match.group(1) not in IDS:
            continue
        identifier = match.group(1)
        if identifier in found:
            raise ValueError("UNKNOWN: duplicate coverage row " + identifier)
        cells = tuple(cell.strip() for cell in line.strip().strip("|").split("|"))
        if len(cells) != 5 or not cells[0].startswith(identifier + " · "):
            raise ValueError("UNKNOWN: malformed coverage row " + identifier)
        found[identifier] = cells
    return found


def sources() -> dict[str, str]:
    return {key: (ROOT / path).read_text(encoding="utf-8")
            for key, path in PATHS.items()}


@lru_cache(maxsize=1)
def probe() -> dict[str, object]:
    """Exercise real preview normalization with synthetic inputs, offline."""
    php = shutil.which("php")
    if not php:
        raise AssertionError("UNKNOWN: PHP executable unavailable")
    result = subprocess.run(
        [php, "-r", PHP_PROBE], cwd=ROOT, text=True,
        capture_output=True, timeout=15, check=False,
        env={"PATH": "/usr/local/bin:/usr/bin:/bin"},
    )
    if result.returncode:
        raise AssertionError("UNKNOWN: synthetic preview fixture failed: "
                             + result.stderr[-500:])
    data = json.loads(result.stdout)
    if not isinstance(data, dict):
        raise AssertionError("UNKNOWN: malformed preview fixture")
    return data


def verdict(identifier: str, table: dict[str, tuple[str, ...]],
            files: dict[str, str], observed: object) -> dict[str, str]:
    """Fail closed if any local evidence is absent or ambiguous."""
    result = {"code": UNKNOWN, "ci": UNKNOWN, "production": UNKNOWN}
    row = table.get(identifier)
    if (identifier not in IDS or row is None or len(row) != 5
            or not row[1].startswith(EXPECTED[identifier])
            or row[2] != "NO_VERIFICABLE"
            or not all(isinstance(files.get(name), str) and files[name]
                       for name in PATHS)
            or not isinstance(observed, dict) or not observed):
        return result

    spec = files["spec"]
    blog = files["blog"]
    media = files["media"]
    config = files["preview_config"]
    api = files["preview_api"]
    ui = files["preview_ui"]
    contract = files["preview_contract"]
    media_contract = files["media_contract"]

    # R009/R010 remain UNKNOWN: a local negative source check cannot prove
    # absence of every purge job, nor approve a future retention policy.
    if identifier in ("S20-R009", "S20-R010"):
        return result

    if identifier == "S20-R011":
        if ("Media remains outside Blog Trash semantics" in spec
                and "deleted_at=CURRENT_TIMESTAMP" in blog
                and "DELETE FROM blog_posts WHERE id=? AND deleted_at IS NOT NULL" in blog
                and "brvtal_media_integrity_usage" in media
                and "MEDIA_IN_USE" in media
                and "brvtal_media_integrity_usage" in media_contract
                and "MEDIA_IN_USE" in media_contract):
            result["code"] = LOCAL
        return result

    expected_types = {"events", "artists", "releases", "sets", "pages", "blog"}
    data = observed.get("blog")
    event = observed.get("event")
    if (not isinstance(observed.get("types"), list)
            or set(observed["types"]) != expected_types
            or len(observed["types"]) != len(expected_types)
            or not isinstance(data, dict) or not isinstance(event, dict)
            or data.get("type") != "blog" or event.get("type") != "events"
            or not isinstance(data.get("payload"), dict)
            or not isinstance(event.get("payload"), dict)
            or data["payload"].get("title") != "Synthetic Draft"
            or data["payload"].get("body") != "Synthetic draft body"
            or data["payload"].get("status") != "draft"
            or "unrecognized" in data["payload"]
            or event["payload"].get("title") != "Synthetic Event"
            or observed.get("invalid") != "PREVIEW_TYPE_NOT_ALLOWED"
            or observed.get("bad_url") != "INVALID_PREVIEW_MEDIA"):
        return result

    if identifier == "S20-R012":
        if ("previewableLegacyTypes = new Set(['events','artists','sets','pages'])" in ui
                and "function bindLegacy(" in ui
                and "brvtalPublicPreviewSnapshot(" in api
                and "brvtal_admin_require();" in api
                and "brvtal_admin_require_csrf();" in api
                and "PUBLIC PREVIEW" in contract):
            result["code"] = LOCAL
    elif identifier == "S20-R013":
        if ("function brvtalPublicPreviewStore(" in config
                and "$_SESSION['public_previews']" in config
                and "const BRVTAL_PUBLIC_PREVIEW_TTL = 600" in config
                and "BRVTAL_PUBLIC_PREVIEW_MAX_SNAPSHOTS = 12" in config
                and "array_key_exists($field, $payload)" in config
                and "Cache-Control: no-store" in api
                and "INSERT INTO" not in config
                and "UPDATE blog_posts" not in config):
            result["code"] = LOCAL
    return result


class BrechasBrvtal24Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        table, files, observed = rows(MATRIX.read_text(encoding="utf-8")), sources(), probe()
        self.assertEqual(set(IDS), set(table))
        self.assertIn("retention target is **30 days**", files["spec"])
        self.assertIn("does **not** schedule or execute automatic purge", files["spec"])
        self.assertIn("A future retention job requires its own explicit safe design", files["spec"])
        for identifier in IDS:
            with self.subTest(row=identifier):
                self.assertEqual("NO_VERIFICABLE", table[identifier][2])
                expected = UNKNOWN if identifier in ("S20-R009", "S20-R010") else LOCAL
                self.assertEqual(expected, verdict(identifier, table, files, observed)["code"])

    def test_falla_cerrado(self) -> None:
        table, files, observed = rows(MATRIX.read_text(encoding="utf-8")), sources(), probe()
        first = next(line for line in MATRIX.read_text(encoding="utf-8").splitlines()
                     if line.startswith("| S20-R009 · "))
        with self.assertRaisesRegex(ValueError, "duplicate"):
            rows(first + "\n" + first)
        for identifier in IDS:
            with self.subTest(row=identifier):
                self.assertEqual(UNKNOWN, verdict(identifier, {}, files, observed)["code"])
                self.assertEqual(UNKNOWN, verdict(identifier, table, {}, observed)["code"])
                self.assertEqual(UNKNOWN, verdict(identifier, table, files, None)["code"])
                self.assertEqual(UNKNOWN, verdict(identifier, table, files, {})["code"])
        altered = dict(table)
        altered["S20-R013"] = (*table["S20-R013"][:2], "CUBIERTO", *table["S20-R013"][3:])
        self.assertEqual(UNKNOWN, verdict("S20-R013", altered, files, observed)["code"])
        self.assertEqual(UNKNOWN, verdict("S20-R999", table, files, observed)["code"])

    def test_sin_datos_reales(self) -> None:
        own = Path(__file__).read_text(encoding="utf-8")
        observed = probe()
        self.assertIn("Synthetic Draft", PHP_PROBE)
        self.assertIn("Synthetic Event", PHP_PROBE)
        self.assertNotIn("PDO(", PHP_PROBE)
        self.assertNotIn("curl_exec(", PHP_PROBE)
        self.assertNotRegex(own, r"(?m)^(?:import|from)\s+(?:requests|httpx|socket|urllib)\b")
        self.assertEqual("Synthetic Draft", observed["blog"]["payload"]["title"])
        self.assertNotIn("unrecognized", observed["blog"]["payload"])

    def test_estados_unknown_explicitos(self) -> None:
        table, files, observed = rows(MATRIX.read_text(encoding="utf-8")), sources(), probe()
        for identifier in IDS:
            with self.subTest(row=identifier):
                result = verdict(identifier, table, files, observed)
                self.assertEqual(UNKNOWN, result["ci"])
                self.assertEqual(UNKNOWN, result["production"])
                self.assertEqual(UNKNOWN, verdict(identifier, {}, files, observed)["code"])
        for identifier in ("S20-R009", "S20-R010"):
            self.assertEqual(UNKNOWN, verdict(identifier, table, files, observed)["code"])
        absent = dict(observed)
        absent.pop("blog", None)
        self.assertEqual(UNKNOWN, verdict("S20-R013", table, files, absent)["code"])


if __name__ == "__main__":
    unittest.main()
