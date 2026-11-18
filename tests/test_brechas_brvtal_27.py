#!/usr/bin/env python3
"""BRVTAL #1117: five offline coverage contracts for §21 and §24.

Synthetic PHP fixtures and versioned navigation contracts are NOT independent
browser, exact-HEAD CI, production, deployment or database attestations.
The coverage matrix deliberately stays NO_VERIFICABLE.
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
MATRIX = "docs/matriz-cobertura.md"
SPEC = "docs/BRVTAL-SPEC.md"
HELPER = "api/public-related.php"
PUBLIC = "api/public.php"
CORE = "discadmin/index-core.php"
WRAPPER = "discadmin/index.php"
SHELL = "discadmin/admin-shell.js"
BROWSER = "tests/e2e/discadmin-admin-shell.spec.mjs"
PATHS = (MATRIX, SPEC, HELPER, PUBLIC, CORE, WRAPPER, SHELL, BROWSER)
IDS = ("S21-R007", "S24-L368", "S24-L375", "S24-L376", "S24-L377")
SUMMARY = {
    "S21-R007": "Private/draft names, IDs or relationships must never leak",
    "S24-L368": "one shell;",
    "S24-L375": "Dashboard;",
    "S24-L376": "Events;",
    "S24-L377": "Artists;",
}
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_CONTRACT"

# This executes the repository's real public-related.php pure functions.
# Only already-public entities are supplied, as required by the public API.
# Private IDs, names and slugs exist exclusively in the synthetic input.
PHP_PROBE = r"""
require 'api/public-related.php';
$events = [['id'=>10, 'title'=>'Fixture Public Event',
            'slug'=>'fixture-public-event',
            'lineup'=>[['artist_id'=>1], ['artist_id'=>999]]]];
$artists = [['id'=>1,'name'=>'Fixture Public Artist',
             'slug'=>'fixture-public-artist']];
$sets = [
    ['id'=>20,'title'=>'Fixture Set','artist_id'=>999,
     'artist_name'=>'PRIVATE_FIXTURE_ARTIST',
     'artist_slug'=>'PRIVATE_FIXTURE_SLUG',
     'event_id'=>999,'event_title'=>'PRIVATE_FIXTURE_EVENT',
     'event_slug'=>'PRIVATE_FIXTURE_EVENT_SLUG'],
    ['id'=>21,'title'=>'Fixture Public Set','artist_id'=>1,
     'event_id'=>10],
];
$releases = [['id'=>30,'artists'=>[['artist_id'=>1]]]];
$posts = [['id'=>40,'relations'=>[
    ['related_type'=>'artist','related_id'=>1,'sort_order'=>0],
    ['related_type'=>'event','related_id'=>10,'sort_order'=>1],
    ['related_type'=>'artist','related_id'=>999,'sort_order'=>2],
    ['related_type'=>'event','related_id'=>999,'sort_order'=>3],
    ['related_type'=>'release','related_id'=>999,'sort_order'=>4],
    ['related_type'=>'set','related_id'=>999,'sort_order'=>5],
    ['related_type'=>'set','related_id'=>21,'sort_order'=>6],
]]];
$cleanSets = brvtal_public_sanitize_set_relations($sets,$artists,$events,[]);
$cleanPosts = brvtal_public_sanitize_blog_relations(
    $posts,$events,[],$artists,$cleanSets,$releases
);
$graph = brvtal_public_related_graph(
    $events,[],$artists,$cleanSets,$releases
);
echo json_encode(['sets'=>$cleanSets,'posts'=>$cleanPosts,'graph'=>$graph],
                 JSON_THROW_ON_ERROR);
"""


def matrix_rows(document: str) -> dict[str, tuple[str, ...]]:
    """Reject missing, duplicated, malformed or falsely promoted coverage."""
    found: dict[str, tuple[str, ...]] = {}
    for line in document.splitlines():
        match = re.match(r"^\| (S(?:21-R007|24-L(?:368|375|376|377))) · ", line)
        if match is None:
            continue
        key = match.group(1)
        if key in found:
            raise ValueError("UNKNOWN: duplicate coverage row " + key)
        cells = tuple(cell.strip() for cell in line.strip().strip("|").split("|"))
        if len(cells) != 5 or not cells[0].startswith(key + " · "):
            raise ValueError("UNKNOWN: malformed coverage row " + key)
        found[key] = cells
    if set(found) != set(IDS):
        raise ValueError("UNKNOWN: missing coverage rows")
    return found


def sources() -> dict[str, str]:
    return {name: (ROOT / name).read_text(encoding="utf-8")
            for name in PATHS}


@lru_cache(maxsize=1)
def public_probe() -> dict[str, object]:
    php = shutil.which("php")
    if not php:
        raise AssertionError("UNKNOWN: PHP executable unavailable")
    run = subprocess.run(
        [php, "-n", "-r", PHP_PROBE], cwd=ROOT, text=True,
        capture_output=True, timeout=15, check=False,
        env={"PATH": "/usr/local/bin:/usr/bin:/bin"},
    )
    if run.returncode:
        raise AssertionError("UNKNOWN: synthetic PHP fixture failed: " + run.stderr[-350:])
    value = json.loads(run.stdout)
    if not isinstance(value, dict):
        raise AssertionError("UNKNOWN: invalid fixture result")
    return value


def menu_items(core: str) -> list[tuple[str, str]] | None:
    """Extract one actual admin navigation declaration, never invent routes."""
    matches = re.findall(r"const editorialSections=\[([\s\S]*?)\n \];", core)
    if len(matches) != 1:
        return None
    pairs = re.findall(r"\['([a-z-]+)','([A-Z /]+)'\]", matches[0])
    if len(pairs) < 3 or len(set(pairs)) != len(pairs):
        return None
    # Every entry must be parsed; silently losing one would be false proof.
    if len(re.findall(r"\['", matches[0])) != len(pairs):
        return None
    return pairs


def verdict(key: str, rows: dict[str, tuple[str, ...]],
            files: dict[str, str], observed: object) -> dict[str, str]:
    result = {"code": UNKNOWN, "browser": UNKNOWN,
              "ci": UNKNOWN, "production": UNKNOWN}
    row = rows.get(key)
    if (key not in IDS or not isinstance(row, tuple) or len(row) != 5
            or not row[0].startswith(key + " · ")
            or not row[1].startswith(SUMMARY[key])
            or row[2] != "NO_VERIFICABLE"
            or not all(isinstance(files.get(name), str) and files[name]
                       for name in PATHS)):
        return result

    spec, core, public = files[SPEC], files[CORE], files[PUBLIC]
    if key == "S21-R007":
        if (not isinstance(observed, dict)
                or "Private/draft names, IDs or relationships must never leak" not in spec
                or "WHERE status='published'" not in public
                or "AND a.status='published'" not in public
                or "brvtal_public_sanitize_set_relations" not in public
                or "brvtal_public_sanitize_blog_relations" not in public
                or "function brvtal_public_sanitize_blog_relations(" not in files[HELPER]
                or "function brvtal_public_related_graph(" not in files[HELPER]):
            return result
        clean_sets, posts, graph = (
            observed.get("sets"), observed.get("posts"), observed.get("graph"))
        if (not isinstance(clean_sets, list) or len(clean_sets) != 2
                or not isinstance(posts, list) or len(posts) != 1
                or not isinstance(graph, dict)):
            return result
        relations = posts[0].get("relations")
        if (not isinstance(relations, list)
                or [(r.get("related_type"), r.get("related_id")) for r in relations]
                   != [("artist", 1), ("event", 10), ("set", 21)]
                or any(clean_sets[0].get(field) is not None
                       for field in ("artist_id", "artist_name", "artist_slug",
                                     "event_id", "event_title", "event_slug"))
                or clean_sets[1].get("artist_id") != 1
                or clean_sets[1].get("event_id") != 10
                or graph.get("events", {}).get("10", {}).get("artists") != [1]
                or any(private in json.dumps(observed) for private in
                       ("PRIVATE_FIXTURE_", '"999"', ": 999", ":999"))):
            return result
        result["code"] = LOCAL
        return result

    if "## 24. DISCADMIN architecture" not in spec:
        return result
    if (core.count('<div class="shell">') != 1
            or core.count('<aside class="side" id="admin-primary-nav"') != 1
            or core.count('<main class="main">') != 1
            or "require __DIR__ . '/index-core.php';" not in files[WRAPPER]
            or '<div class="shell">' in files[WRAPPER]
            or "const editorialSections=[" not in core
            or "const editorialNav=editorialSections.map(" not in core
            or 'data-admin-nav="' not in core
            or "window.closeAdminNav" not in files[SHELL]):
        return result
    if key == "S24-L368":
        browser = files[BROWSER]
        if ("mobile navigation uses the existing sidebar" in browser
                and "Escape closes the mobile menu" in browser
                and "admin-primary-nav" in browser
                and "one shell;" in spec):
            result["code"] = LOCAL
        return result

    links = menu_items(core)
    expected = {
        "S24-L375": ("dashboard", "DASHBOARD"),
        "S24-L376": ("events", "EVENTS"),
        "S24-L377": ("artists", "ARTISTS"),
    }
    target = expected[key]
    if (links is not None and links.count(target) == 1
            and links[:3] == [("dashboard", "DASHBOARD"),
                              ("hero-slider", "BANNERS"), ("events", "EVENTS")]
            and ("artists", "ARTISTS") in links
            and "onclick=\"go('" in core
            and target[1].title() + ";" in spec):
        result["code"] = LOCAL
    return result


def operational_attestation(evidence: object = None) -> str:
    """A local fixture cannot attest live, deployment or exact-HEAD CI."""
    return UNKNOWN


class BrechasBrvtal27Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.files = sources()
        cls.rows = matrix_rows(cls.files[MATRIX])
        cls.observed = public_probe()

    def test_contrato_principal(self) -> None:
        """AC-01: all five rows have independent sourced local assertions."""
        self.assertEqual(set(self.rows), set(IDS))
        for key in IDS:
            with self.subTest(row=key):
                self.assertEqual(LOCAL, verdict(
                    key, self.rows, self.files, self.observed)["code"], key)
        self.assertEqual(menu_items(self.files[CORE])[:4], [
            ("dashboard", "DASHBOARD"), ("hero-slider", "BANNERS"),
            ("events", "EVENTS"), ("artists", "ARTISTS"),
        ])

    def test_falla_cerrado(self) -> None:
        """AC-02: missing, duplicate, spoofed or truncated evidence stays UNKNOWN."""
        source = self.files[MATRIX]
        original = next(line for line in source.splitlines()
                        if line.startswith("| S21-R007 · "))
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            matrix_rows(source + "\n" + original)
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            matrix_rows(source.replace(original + "\n", "", 1))
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            matrix_rows(source.replace(original, "| S21-R007 · malformed |", 1))
        for key in IDS:
            with self.subTest(row=key):
                self.assertEqual(UNKNOWN, verdict(key, {}, self.files, self.observed)["code"])
                changed = dict(self.rows)
                changed[key] = tuple("CUBIERTO" if i == 2 else cell
                                     for i, cell in enumerate(changed[key]))
                self.assertEqual(UNKNOWN, verdict(
                    key, changed, self.files, self.observed)["code"])
                self.assertEqual(UNKNOWN, verdict(
                    key, self.rows, {**self.files, CORE: ""}, self.observed)["code"])
        self.assertEqual(UNKNOWN, verdict(
            "S21-R007", self.rows, self.files, {"sets": [], "posts": []})["code"])
        exposed = json.loads(json.dumps(self.observed))
        exposed["sets"][0]["artist_name"] = "PRIVATE_FIXTURE_ARTIST"
        self.assertEqual(UNKNOWN, verdict(
            "S21-R007", self.rows, self.files, exposed)["code"])
        self.assertIsNone(menu_items("const editorialSections=[];"))

    def test_sin_datos_reales(self) -> None:
        """AC-03: PHP fixture uses invented objects, no DB/network or PII."""
        own = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(own, r"(?m)^(?:import|from)\s+(?:requests|urllib|httpx|socket)\b")
        self.assertNotRegex(own, r"-----BEGIN (?:RSA |OPENSSH )?PRIVATE KEY-----")
        self.assertNotRegex(own, r"[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}")
        self.assertNotIn("PDO(", PHP_PROBE)
        self.assertNotIn("curl_exec(", PHP_PROBE)
        self.assertIn("Fixture Public Artist", PHP_PROBE)
        for path in PATHS:
            self.assertFalse(Path(path).is_absolute())
            self.assertTrue((ROOT / path).is_file())

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: source+fixtures are not CI, browser or production evidence."""
        for key in IDS:
            with self.subTest(row=key):
                self.assertEqual("NO_VERIFICABLE", self.rows[key][2])
                result = verdict(key, self.rows, self.files, self.observed)
                self.assertEqual(result, {"code": LOCAL, "browser": UNKNOWN,
                                          "ci": UNKNOWN, "production": UNKNOWN})
                self.assertEqual(operational_attestation({
                    "ci": "success", "browser": "passed",
                    "state": "VALIDATED_IN_PRODUCTION"}), UNKNOWN)
        self.assertEqual(operational_attestation(), UNKNOWN)


if __name__ == "__main__":
    unittest.main()
