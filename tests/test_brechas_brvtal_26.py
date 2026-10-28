#!/usr/bin/env python3
"""BRVTAL #1116: offline §20-§21 coverage of preview and public relations.

Only synthetic, in-process PHP arrays are used. Code-level checks never claim
that CI, the live site, or the coverage matrix were already reconciled.
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
IDS = ("S20-R022", "S21-R002", "S21-R003", "S21-R004", "S21-R005")
EXPECTED = {
    "S20-R022": "[Canonical public draft preview] - The lightweight Blog body-only preview",
    "S21-R002": "Artist → Events / Sets / Releases",
    "S21-R003": "Event → Artists / Sets",
    "S21-R004": "Set → Artist / Event",
    "S21-R005": "Release → Artists",
}
PATHS = {
    "helper": "api/public-related.php",
    "public": "api/public.php",
    "blog": "discadmin/blog.js",
    "preview": "preview.php",
    "index": "index.php",
    "preview_contract": "tests/public-preview-contract.php",
}
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_CONTRACT"

# The helper only declares functions; it cannot open an admin session or a DB.
# The caller supplies already-public pools, as the production API must do.
PHP_PROBE = r"""
require 'api/public-related.php';
$active = [[
    'id'=>10, 'lineup'=>[
        ['artist_id'=>1], ['artist_id'=>2], ['artist_id'=>1],
        ['artist_id'=>999]
    ],
]];
$archive = [['id'=>11, 'lineup'=>[['artist_id'=>2]]]];
$artists = [
    ['id'=>1, 'name'=>'Synthetic Artist A', 'slug'=>'artist-a'],
    ['id'=>2, 'name'=>'Synthetic Artist B', 'slug'=>'artist-b'],
];
$rawSets = [
    ['id'=>20, 'artist_id'=>1, 'event_id'=>10],
    ['id'=>21, 'artist_id'=>2, 'event_id'=>11],
    ['id'=>22, 'artist_id'=>999, 'event_id'=>999],
];
$releases = [
    ['id'=>30, 'artists'=>[
        ['artist_id'=>1], ['artist_id'=>999], ['artist_id'=>1]
    ]],
    ['id'=>31, 'artists'=>[['artist_id'=>2], ['artist_id'=>999]]],
];
$sets = brvtal_public_sanitize_set_relations(
    $rawSets, $artists, $active, $archive
);
$graph = brvtal_public_related_graph(
    $active, $archive, $artists, $sets, $releases
);
$empty = brvtal_public_related_graph([], [], [], [], []);
echo json_encode([
    'graph'=>$graph,
    'sanitized'=>$sets,
    'empty'=>$empty,
], JSON_THROW_ON_ERROR);
"""


def rows(text: str) -> dict[str, tuple[str, ...]]:
    """Parse only the five exact matrix rows, rejecting duplicates/malformed data."""
    found: dict[str, tuple[str, ...]] = {}
    for line in text.splitlines():
        match = re.match(r"^\| (S(?:20|21)-R\d{3}) · ", line)
        if not match or match.group(1) not in IDS:
            continue
        identifier = match.group(1)
        if identifier in found:
            raise ValueError("UNKNOWN: duplicate coverage row " + identifier)
        cells = tuple(part.strip() for part in line.strip().strip("|").split("|"))
        if len(cells) != 5 or not cells[0].startswith(identifier + " · "):
            raise ValueError("UNKNOWN: malformed coverage row " + identifier)
        found[identifier] = cells
    return found


def sources() -> dict[str, str]:
    return {
        name: (ROOT / path).read_text(encoding="utf-8")
        for name, path in PATHS.items()
    }


@lru_cache(maxsize=1)
def probe() -> dict[str, object]:
    """Execute the real public-graph helper with local, invented fixtures."""
    php = shutil.which("php")
    if not php:
        raise AssertionError("UNKNOWN: PHP executable unavailable")
    result = subprocess.run(
        [php, "-n", "-r", PHP_PROBE], cwd=ROOT,
        text=True, capture_output=True, timeout=15, check=False,
        env={"PATH": "/usr/local/bin:/usr/bin:/bin"},
    )
    if result.returncode:
        raise AssertionError("UNKNOWN: PHP fixture failed: " + result.stderr[-500:])
    answer = json.loads(result.stdout)
    if not isinstance(answer, dict):
        raise AssertionError("UNKNOWN: invalid synthetic graph evidence")
    return answer


def verdict(identifier: str, table: dict[str, tuple[str, ...]],
            files: dict[str, str], observed: object) -> dict[str, str]:
    """Fail closed: never infer full browser/production or CI from a fixture."""
    result = {"code": UNKNOWN, "ci": UNKNOWN, "production": UNKNOWN}
    row = table.get(identifier)
    if (identifier not in IDS or row is None or len(row) != 5
            or not row[1].startswith(EXPECTED[identifier])
            or row[2] != "NO_VERIFICABLE"
            or not all(isinstance(files.get(name), str) and files[name]
                       for name in PATHS)
            or not isinstance(observed, dict)):
        return result

    # The Blog editor's iframe srcdoc is an isolated body-only convenience.
    # The full preview uses the production entity delivery function instead.
    # Static source checks do NOT establish visual parity in real browsers.
    if identifier == "S20-R022":
        return result

    helper, public = files["helper"], files["public"]
    if ("function brvtal_public_related_graph(" not in helper
            or "function brvtal_public_sanitize_set_relations(" not in helper
            or "brvtal_public_sanitize_set_relations" not in public
            or "brvtal_public_related_graph(" not in public):
        return result
    graph, sanitized, empty = (
        observed.get("graph"), observed.get("sanitized"), observed.get("empty")
    )
    if (not isinstance(graph, dict) or not isinstance(sanitized, list)
            or not isinstance(empty, dict)
            or not all(isinstance(graph.get(k), dict)
                       for k in ("artists", "events", "sets", "releases"))
            or any(graph[k].get("999") is not None
                   for k in ("artists", "events", "sets", "releases"))
            or not all(empty.get(k) in ({}, [])
                       for k in ("artists", "events", "sets", "releases"))
            or empty.get("counts") != dict.fromkeys(
                ("event_artist", "event_set", "artist_set", "artist_release"), 0
            )):
        return result

    artists, events, sets, releases = (
        graph["artists"], graph["events"], graph["sets"], graph["releases"]
    )
    checks = {
        "S21-R002": (
            artists.get("1") == {"events": [10], "sets": [20], "releases": [30]}
            and artists.get("2") == {
                "events": [10, 11], "sets": [21], "releases": [31]
            }
        ),
        "S21-R003": (
            events.get("10") == {"artists": [1, 2], "sets": [20]}
            and events.get("11") == {"artists": [2], "sets": [21]}
            and graph.get("counts", {}).get("event_artist") == 3
            and graph.get("counts", {}).get("event_set") == 2
        ),
        "S21-R004": (
            sets.get("20") == {"artist": 1, "event": 10}
            and sets.get("21") == {"artist": 2, "event": 11}
            and sets.get("22") == {"artist": None, "event": None}
            and sanitized[2].get("artist_id") is None
            and sanitized[2].get("event_id") is None
        ) if len(sanitized) > 2 else False,
        "S21-R005": (
            releases.get("30") == {"artists": [1]}
            and releases.get("31") == {"artists": [2]}
            and graph.get("counts", {}).get("artist_release") == 2
        ),
    }
    if checks.get(identifier, False):
        result["code"] = LOCAL
    return result


class BrechasBrvtal26Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        table, observed, files = rows(MATRIX.read_text(encoding="utf-8")), probe(), sources()
        spec = SPEC.read_text(encoding="utf-8")
        self.assertEqual(set(IDS), set(table))
        self.assertIn("### Canonical public draft preview", spec)
        self.assertIn("## 21. Related content", spec)
        self.assertIn("function renderBodyPreview(", files["blog"])
        self.assertIn("preview.srcdoc=", files["blog"])
        self.assertIn("brvtalPublicEntityDocument(", files["preview"])
        self.assertIn("brvtalPublicEntityDocument(", files["index"])
        self.assertIn("srcdoc=", files["preview_contract"])
        for identifier in IDS:
            with self.subTest(row=identifier):
                self.assertEqual("NO_VERIFICABLE", table[identifier][2])
                expected = UNKNOWN if identifier == "S20-R022" else LOCAL
                self.assertEqual(expected, verdict(identifier, table, files, observed)["code"])

    def test_falla_cerrado(self) -> None:
        table, observed, files = rows(MATRIX.read_text(encoding="utf-8")), probe(), sources()
        first = next(x for x in MATRIX.read_text(encoding="utf-8").splitlines()
                     if x.startswith("| S21-R002 · "))
        with self.assertRaisesRegex(ValueError, "duplicate"):
            rows(first + "\n" + first)
        for identifier in IDS:
            with self.subTest(row=identifier):
                self.assertEqual(UNKNOWN, verdict(identifier, {}, files, observed)["code"])
                self.assertEqual(UNKNOWN, verdict(identifier, table, {}, observed)["code"])
                self.assertEqual(UNKNOWN, verdict(identifier, table, files, None)["code"])
                self.assertEqual(UNKNOWN, verdict(identifier, table, files,
                                                  {"graph": {}})["code"])
        self.assertEqual(UNKNOWN, verdict("S21-R999", table, files, observed)["code"])
        malformed = dict(table)
        malformed["S21-R002"] = (*table["S21-R002"][:2], "CUBIERTO", *table["S21-R002"][3:])
        self.assertEqual(UNKNOWN, verdict("S21-R002", malformed, files, observed)["code"])

    def test_sin_datos_reales(self) -> None:
        own = Path(__file__).read_text(encoding="utf-8")
        observed = probe()
        self.assertIn("Synthetic Artist A", PHP_PROBE)
        self.assertIn("Synthetic Artist B", PHP_PROBE)
        self.assertNotIn("PDO(", PHP_PROBE)
        self.assertNotIn("curl_exec(", PHP_PROBE)
        self.assertNotRegex(own, r"(?m)^(?:import|from)\s+(?:requests|httpx|socket|urllib)\b")
        self.assertNotIn("BEGIN " + "PRIVATE KEY", own)
        self.assertEqual([1], observed["graph"]["releases"]["30"]["artists"])

    def test_estados_unknown_explicitos(self) -> None:
        table, observed, files = rows(MATRIX.read_text(encoding="utf-8")), probe(), sources()
        for identifier in IDS:
            with self.subTest(row=identifier):
                classified = verdict(identifier, table, files, observed)
                self.assertEqual(UNKNOWN, classified["ci"])
                self.assertEqual(UNKNOWN, classified["production"])
                self.assertEqual(UNKNOWN, verdict(identifier, {}, files, observed)["code"])
        # Real-browser visual parity cannot be inferred from a source contract.
        self.assertEqual(UNKNOWN, verdict("S20-R022", table, files, observed)["code"])
        self.assertIn("preview.srcdoc=", files["blog"])
        self.assertIn("noindex,nofollow", files["preview"])
        absent = dict(observed)
        absent.pop("sanitized", None)
        self.assertEqual(UNKNOWN, verdict("S21-R004", table, files, absent)["code"])


if __name__ == "__main__":
    unittest.main()
