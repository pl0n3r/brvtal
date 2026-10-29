#!/usr/bin/env python3
"""BRVTAL #1109: fail-closed evidence for S17-R001..R005.

Reads checked-in sources and tests only. Candidate checks are not proof of
full Media workflows, executed CI, or production; no network, DB, or writes.
"""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
IDS = tuple(f"S17-R{i:03d}" for i in range(1, 6))
SUMMARIES = {
    "S17-R001": "DISCADMIN media selection should be visual and as frictionless",
    "S17-R002": "Administrators should not need to manually copy URLs",
    "S17-R003": "Media may be reused by multiple pieces of content.",
    "S17-R004": "Before deletion, references must be detected and destructive actions guarded.",
    "S17-R005": "The reusable Media Library, picker, metadata, reference detection",
}
MISSING = {
    "S17-R001": "UNKNOWN: no quantitative usability/selection-friction acceptance",
    "S17-R002": "UNKNOWN: no proof covering all routine editor operations",
    "S17-R003": "UNKNOWN: no persisted two-content simultaneous reuse assertion",
    "S17-R004": "UNKNOWN: no actual HTTP DELETE with references and MEDIA_IN_USE",
    "S17-R005": "UNKNOWN: no integrated library-picker-sidecar-reference-delete scenario",
}
SOURCES = {
    "e2e": "tests/e2e/discadmin-media.spec.mjs",
    "ui": "discadmin/media-library.js",
    "api": "api/media-library.php",
    "integrity": "config/media_integrity.php",
    "integration": "tests/integration/media-reference-atomicity.php",
    "optimization": "config/media.php",
}
UNKNOWN = "UNKNOWN"
CANDIDATE = "LOCAL_PARTIAL"


def coverage_rows(markdown: str) -> dict[str, tuple[str, ...]]:
    """Reject duplicate, malformed, or ambiguous rows instead of guessing."""
    result: dict[str, tuple[str, ...]] = {}
    for line in markdown.splitlines():
        match = re.match(r"^\| (S17-R\d{3}) · ", line)
        if not match or match.group(1) not in IDS:
            continue
        key = match.group(1)
        if key in result:
            raise ValueError("UNKNOWN: duplicate coverage row " + key)
        cells = tuple(cell.strip() for cell in line.strip().strip("|").split("|"))
        if len(cells) != 5 or not cells[0].startswith(key + " · "):
            raise ValueError("UNKNOWN: malformed coverage row " + key)
        result[key] = cells
    return result


def read_sources() -> dict[str, str]:
    return {name: (ROOT / path).read_text(encoding="utf-8")
            for name, path in SOURCES.items()}


def partial_evidence(key: str, sources: dict[str, str]) -> bool:
    """Assert existing partial contracts; never promote a §17 row to CUBIERTO."""
    if key not in IDS or any(not isinstance(sources.get(n), str) or not sources[n]
                             for n in SOURCES):
        return False
    e2e, ui, api = sources["e2e"], sources["ui"], sources["api"]
    if key == "S17-R001":
        return ("test('media picker normalizes paths" in e2e
                and "#media-status" in e2e
                and "SELECT MEDIA" in ui)
    if key == "S17-R002":
        return ("test('event save sends selected media path" in e2e
                and "test('artist save sends selected media path" in e2e
                and "media-picker-btn" in e2e)
    if key == "S17-R003":
        return ("brvtal_media_integrity_usage" in sources["integrity"]
                and "combinedUsage" in sources["integration"]
                and "brvtal_media_duplicate_usage" in sources["integration"])
    if key == "S17-R004":
        deletion = api.split("if ($method === 'DELETE')", 1)
        if len(deletion) != 2:
            return False
        block = deletion[1]
        usage = block.find("brvtal_media_integrity_usage($pdo, $row)")
        veto = block.find("'MEDIA_IN_USE'")
        stage = block.find("brvtal_media_stage_delete($row)")
        return (0 <= usage < veto < stage and "409" in block[veto:stage]
                and "brvtal_media_reference_mutex_lock($pdo)" in block[:usage])
    if key == "S17-R005":
        return ("brvtalMediaStoreSidecar" in api
                and "brvtal_media_integrity_usage" in api
                and "test('media inspector previews contexts" in e2e
                and "test('media picker normalizes paths" in e2e
                and "brvtalMediaGenerateVariants" in sources["optimization"])
    return False


def verdict(key: str, rows: dict[str, tuple[str, ...]],
            sources: dict[str, str]) -> dict[str, str]:
    """Explicit gap reason remains UNKNOWN even for sound partial evidence."""
    result = {"code": UNKNOWN, "full_scope": UNKNOWN,
              "ci": UNKNOWN, "production": UNKNOWN,
              "candidate": UNKNOWN, "missing": "UNKNOWN: invalid or absent evidence"}
    row = rows.get(key)
    if (key not in IDS or row is None or len(row) != 5
            or not row[1].startswith(SUMMARIES[key])
            or row[2] != "NO_VERIFICABLE"
            or not partial_evidence(key, sources)):
        return result
    result["candidate"] = CANDIDATE
    result["missing"] = MISSING[key]
    return result


class BrechasBrvtal19Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        rows = coverage_rows(MATRIX.read_text(encoding="utf-8"))
        sources = read_sources()
        self.assertEqual(set(rows), set(IDS))
        for key in IDS:
            with self.subTest(row=key):
                self.assertTrue(partial_evidence(key, sources), key)
                result = verdict(key, rows, sources)
                self.assertEqual(CANDIDATE, result["candidate"])
                self.assertEqual(MISSING[key], result["missing"])
                self.assertEqual(UNKNOWN, result["code"])

    def test_falla_cerrado(self) -> None:
        rows = coverage_rows(MATRIX.read_text(encoding="utf-8"))
        sources = read_sources()
        for key in IDS:
            with self.subTest(row=key):
                self.assertEqual(UNKNOWN, verdict(key, {}, sources)["candidate"])
                self.assertEqual(UNKNOWN, verdict(key, rows, {})["candidate"])
                altered = dict(rows)
                altered[key] = (rows[key][0], rows[key][1], "CUBIERTO", *rows[key][3:])
                self.assertEqual(UNKNOWN, verdict(key, altered, sources)["candidate"])
                for name in SOURCES:
                    tampered = {**sources, name: ""}
                    self.assertEqual(UNKNOWN, verdict(key, rows, tampered)["candidate"])
        row = next(line for line in MATRIX.read_text(encoding="utf-8").splitlines()
                   if line.startswith("| S17-R001 · "))
        with self.assertRaisesRegex(ValueError, "duplicate"):
            coverage_rows(row + "\n" + row)
        with self.assertRaisesRegex(ValueError, "malformed"):
            coverage_rows("| S17-R001 · partial | CUBIERTO |")
        self.assertEqual(UNKNOWN, verdict("S17-R999", rows, sources)["candidate"])

    def test_sin_datos_reales(self) -> None:
        own = Path(__file__).read_text(encoding="utf-8")
        self.assertTrue(all(key in own for key in IDS))
        self.assertNotRegex(own, r"(?m)^(?:import|from)\s+(?:requests|httpx|socket|urllib|pymysql)\b")
        self.assertNotIn("-----BEGIN " + "PRIVATE KEY-----", own)
        self.assertNotIn("curl_" + "exec(", own)
        self.assertNotIn("PDO" + "(", own)
        self.assertTrue(all(path.startswith(("docs/", "tests/", "discadmin/", "api/", "config/"))
                            for path in SOURCES.values()))

    def test_estados_unknown_explicitos(self) -> None:
        rows = coverage_rows(MATRIX.read_text(encoding="utf-8"))
        sources = read_sources()
        for key in IDS:
            with self.subTest(row=key):
                result = verdict(key, rows, sources)
                self.assertEqual("NO_VERIFICABLE", rows[key][2])
                self.assertTrue(result["missing"].startswith("UNKNOWN:"))
                for scope in ("code", "full_scope", "ci", "production"):
                    self.assertEqual(UNKNOWN, result[scope], f"{key}: {scope}")
        broken = {**sources, "api": sources["api"].replace("'MEDIA_IN_USE'", "'FORGED_SUCCESS'")}
        self.assertFalse(partial_evidence("S17-R004", broken))
        self.assertEqual(UNKNOWN, verdict("S17-R004", rows, broken)["candidate"])


if __name__ == "__main__":
    unittest.main()
