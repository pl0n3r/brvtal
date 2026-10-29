#!/usr/bin/env python3
"""BRVTAL #1105: pruebas sintéticas fail-closed para cinco brechas Music §13.

Las pruebas validan contratos locales, no embeds oficiales, catálogos reales,
CI, navegador, red ni producción. La matriz conserva NO_VERIFICABLE.
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
SOURCES = {
    "routes": "config/public_routes.php",
    "set_policy": "config/set_publication.php",
    "ui": "js/public-sets-library.js",
    "sets_api": "api/index.php",
    "releases_api": "api/releases.php",
    "public_api": "api/public.php",
    "releases_contract": "tests/releases-contract.php",
}
IDS = ("S13-L180", "S13-L181", "S13-R001", "S13-R002", "S13-R003")
CLAUSES = {
    "S13-L180": "Sets;",
    "S13-L181": "Releases.",
    "S13-R001": "Music architecture begins with:",
    "S13-R002": "Sets may come from multiple platforms via external URLs and should use official embeds when stable.",
    "S13-R003": "Do not limit the product to SoundCloud, although SoundCloud is an important content source.",
}
MISSING = {
    "S13-L180": "UNKNOWN: no persisted CMS Set or HTTP public route exercised",
    "S13-L181": "UNKNOWN: release CRUD/persistence and published catalog not executed",
    "S13-R001": "UNKNOWN: integrated Sets + Releases music catalog not exercised",
    "S13-R002": "UNKNOWN: no official embed playback assertion for stable platforms",
    "S13-R003": "UNKNOWN: full provider catalog and real playback not verified",
}
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_PARTIAL"

PHP_PROBE = r"""
require 'config/public_routes.php';
require 'config/set_publication.php';
$defs = brvtal_public_content_definitions(static fn(string $key): ?string => null);
$result = [];
foreach (['sets', 'releases'] as $key) {
    $item = $defs[$key] ?? null;
    $result[$key] = is_array($item) ? [
        'table'=>$item['table'] ?? null,
        'where'=>$item['where'] ?? null,
        'schema_type'=>$item['schema_type'] ?? null
    ] : null;
}
$result['policy'] = [
    'draft'=>brvtal_set_publication_error(['status'=>'draft','external_url'=>'']),
    'empty'=>brvtal_set_publication_error(['status'=>'published','external_url'=>'']),
    'unsafe'=>brvtal_set_publication_error(['status'=>'published','external_url'=>'javascript:alert(1)']),
    'soundcloud'=>brvtal_set_publication_error(['status'=>'published','external_url'=>'https://soundcloud.com/synthetic/set']),
    'youtube'=>brvtal_set_publication_error(['status'=>'published','external_url'=>'https://www.youtube.com/watch?v=synthetic']),
    'spotify'=>brvtal_set_publication_error(['status'=>'published','external_url'=>'https://open.spotify.com/track/synthetic']),
    'other'=>brvtal_set_publication_error(['status'=>'published','external_url'=>'https://player.example.invalid/audio'])
];
echo json_encode($result, JSON_THROW_ON_ERROR);
"""

NODE_PROBE = r"""
'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('js/public-sets-library.js', 'utf8');
const list = {innerHTML:'',querySelectorAll(){return [];}};
const intro = {after(){}};
const dummy = () => ({innerHTML:'',textContent:'',hidden:false,
  querySelectorAll(){return [];},addEventListener(){},
  setAttribute(){},classList:{toggle(){}}});
const controls = {innerHTML:'',querySelector(){return dummy();},
  querySelectorAll(){return [];},remove(){}};
const section = {dataset:{},querySelector(selector){
  if (selector === '.set-list') return list;
  if (selector === '.sets-intro') return intro;
  return null;
}};
const document = {readyState:'loading',documentElement:{dataset:{}},
  querySelector(selector){return selector === '.sets' ? section : null;},
  createElement(){return controls;}};
const window = {location:{href:'https://synthetic.invalid/'},
  addEventListener(){},dispatchEvent(){}};
const context = {window,document,URL,
  CustomEvent:class CustomEvent{constructor(name,options){this.name=name;this.detail=options.detail;}}};
vm.runInNewContext(source,context,{timeout:1500,filename:'public-sets-library.js'});
const library = window.BRVTALPublicSetsLibrary;
if (!library || typeof library.render !== 'function')
  throw Error('UNKNOWN: no public Sets renderer');
const items = [
  {id:1,title:'Synthetic SoundCloud',slug:'soundcloud',status:'published',platform:'soundcloud',external_url:'https://soundcloud.com/synthetic/set'},
  {id:2,title:'Synthetic YouTube',slug:'youtube',status:'published',platform:'youtube',external_url:'https://www.youtube.com/watch?v=synthetic'},
  {id:3,title:'Synthetic Spotify',slug:'spotify',status:'published',platform:'spotify',external_url:'https://open.spotify.com/track/synthetic'},
  {id:4,title:'Synthetic Other',slug:'other',status:'published',platform:'other',external_url:'https://player.example.invalid/audio'},
  {id:5,title:'Synthetic Unsafe',slug:'unsafe',status:'published',platform:'other',external_url:'javascript:alert(1)'},
  {id:6,title:'Synthetic Private',slug:'private',status:'draft',platform:'soundcloud',external_url:'https://soundcloud.com/synthetic/private'}
];
console.log(JSON.stringify({
  rendered:library.render(items),count:section.dataset.publicSetCount,
  html:list.innerHTML,mode:document.documentElement.dataset.publicSets
}));
"""


def matrix_rows(raw: object) -> dict[str, tuple[str, ...]]:
    """Faltantes, duplicados, filas malformadas o promociones fallan cerrado."""
    if not isinstance(raw, str):
        raise ValueError("UNKNOWN: matrix unavailable")
    found = {}
    for line in raw.splitlines():
        match = re.match(r"^\| (S13-[LR]\d{3}) · ", line)
        if not match or match.group(1) not in IDS:
            continue
        key = match.group(1)
        columns = tuple(part.strip() for part in line.strip("|").split("|"))
        if key in found or len(columns) != 5 or not all(columns[:4]):
            raise ValueError("UNKNOWN: duplicate or malformed " + key)
        found[key] = columns
    if set(found) != set(IDS):
        raise ValueError("UNKNOWN: missing target rows")
    return found


def probe(runtime: str, args: list[str], script: str) -> dict[str, object]:
    binary = shutil.which(runtime)
    if not binary:
        raise AssertionError("UNKNOWN: missing offline runtime " + runtime)
    run = subprocess.run([binary, *args, script], cwd=ROOT,
                         capture_output=True, text=True, timeout=15, check=False)
    if run.returncode:
        raise AssertionError("UNKNOWN: offline probe failed: " + run.stderr[-600:])
    try:
        value = json.loads(run.stdout)
    except ValueError as exc:
        raise AssertionError("UNKNOWN: invalid probe JSON") from exc
    if not isinstance(value, dict):
        raise AssertionError("UNKNOWN: malformed probe output")
    return value


@lru_cache(maxsize=1)
def observations() -> dict[str, object]:
    return {"php": probe("php", ["-n", "-r"], PHP_PROBE),
            "js": probe("node", ["-e"], NODE_PROBE)}


def source_texts() -> dict[str, str]:
    return {key: (ROOT / path).read_text(encoding="utf-8")
            for key, path in SOURCES.items()}


def local_evidence(key: str, sources: dict[str, str], observed: object) -> bool:
    if (key not in IDS or not isinstance(observed, dict)
            or any(not isinstance(sources.get(name), str) or not sources[name]
                   for name in SOURCES)):
        return False
    php, js = observed.get("php"), observed.get("js")
    if not isinstance(php, dict) or not isinstance(js, dict):
        return False
    types = (php.get("sets") == {
        "table": "sets_media", "where": "status='published'",
        "schema_type": "MusicRecording"
    } and php.get("releases") == {
        "table": "releases", "where": "status='published'",
        "schema_type": "MusicAlbum"
    })
    policy = php.get("policy")
    valid_policy = (isinstance(policy, dict)
                    and policy.get("draft") is None
                    and policy.get("empty") == "SET_LISTENING_URL_REQUIRED"
                    and policy.get("unsafe") == "INVALID_SET_LISTENING_URL"
                    and all(policy.get(name) is None for name in
                            ("soundcloud", "youtube", "spotify", "other")))
    html = js.get("html")
    rendered = (js.get("rendered") is True and js.get("count") == "5"
                and js.get("mode") == "library" and isinstance(html, str)
                and all("Synthetic " + name in html for name in
                        ("SoundCloud", "YouTube", "Spotify", "Other", "Unsafe"))
                and "Synthetic Private" not in html
                and "javascript:alert" not in html)
    platforms = (rendered and all(name + " / BRVTAL SOUND" in html
                                 for name in ("SOUNDCLOUD", "YOUTUBE", "SPOTIFY", "OTHER"))
                 and all(url in html for url in (
                     "https://soundcloud.com/synthetic/set",
                     "https://www.youtube.com/watch?v=synthetic",
                     "https://open.spotify.com/track/synthetic",
                     "https://player.example.invalid/audio")))
    releases = ("function brvtal_release_payload(" in sources["releases_api"]
                and "brvtal_admin_require();" in sources["releases_api"]
                and "WHERE status='published'" in sources["public_api"]
                and "CREATE TABLE IF NOT EXISTS releases" in sources["releases_contract"])
    supports_providers = all("'" + name + "'" in sources["sets_api"] for name in
                             ("soundcloud", "youtube", "spotify", "other"))
    checks = {
        "S13-L180": types and valid_policy and rendered,
        "S13-L181": types and releases,
        "S13-R001": types and valid_policy and releases,
        "S13-R002": valid_policy and platforms and "embed_url" in sources["sets_api"],
        "S13-R003": valid_policy and platforms and supports_providers,
    }
    return bool(checks[key])


def verdict(key: str, rows: dict[str, tuple[str, ...]], spec: str,
            sources: dict[str, str], observed: object) -> dict[str, str]:
    result = {"candidate": UNKNOWN, "full_scope": UNKNOWN, "ci": UNKNOWN,
              "production": UNKNOWN, "missing": "UNKNOWN: invalid or absent evidence"}
    row = rows.get(key)
    if (key not in IDS or row is None or len(row) != 5
            or not row[0].startswith(key + " · [S13 L")
            or row[1] != CLAUSES[key] or row[2] != "NO_VERIFICABLE"
            or "Sin aserción contrastada" not in row[3] or row[4] != "—"
            or "## 13. Sets / Music" not in spec or CLAUSES[key] not in spec
            or not local_evidence(key, sources, observed)):
        return result
    result["candidate"] = LOCAL
    result["missing"] = MISSING[key]
    return result


class BrechasBrvtal15Tests(unittest.TestCase):
    def setUp(self) -> None:
        self.rows = matrix_rows(MATRIX.read_text(encoding="utf-8"))
        self.spec = SPEC.read_text(encoding="utf-8")
        self.sources = source_texts()
        self.observed = observations()

    def test_contrato_principal(self) -> None:
        self.assertEqual(set(IDS), set(self.rows))
        for key in IDS:
            with self.subTest(row=key):
                result = verdict(key, self.rows, self.spec, self.sources, self.observed)
                self.assertEqual(LOCAL, result["candidate"])
                self.assertEqual(MISSING[key], result["missing"])
                self.assertEqual(UNKNOWN, result["full_scope"])

    def test_falla_cerrado(self) -> None:
        for key in IDS:
            with self.subTest(row=key):
                self.assertEqual(UNKNOWN, verdict(
                    key, {}, self.spec, self.sources, self.observed)["candidate"])
                self.assertEqual(UNKNOWN, verdict(
                    key, self.rows, self.spec, {}, self.observed)["candidate"])
                self.assertEqual(UNKNOWN, verdict(
                    key, self.rows, self.spec, self.sources, None)["candidate"])
                changed = dict(self.rows)
                changed[key] = (*self.rows[key][:2], "CUBIERTO", *self.rows[key][3:])
                self.assertEqual(UNKNOWN, verdict(
                    key, changed, self.spec, self.sources, self.observed)["candidate"])
        row = next(x for x in MATRIX.read_text(encoding="utf-8").splitlines()
                   if x.startswith("| S13-L180 · "))
        duplicate_matrix = MATRIX.read_text(encoding="utf-8") + "\n" + row
        with self.assertRaisesRegex(ValueError, "duplicate"):
            matrix_rows(duplicate_matrix)
        with self.assertRaisesRegex(ValueError, "missing"):
            matrix_rows("")
        self.assertEqual(UNKNOWN, verdict(
            "S13-R999", self.rows, self.spec, self.sources, self.observed)["candidate"])

    def test_sin_datos_reales(self) -> None:
        own = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(own, r"(?m)^(?:import|from)\s+(?:requests|httpx|urllib|socket|pymysql)\b")
        self.assertNotIn("-----BEGIN " + "PRIVATE KEY-----", own)
        self.assertNotIn("curl_exec(", PHP_PROBE)
        self.assertNotIn("PDO(", PHP_PROBE)
        self.assertIn("synthetic.invalid", NODE_PROBE)
        self.assertTrue(all(path.startswith(("api/", "config/", "js/", "tests/"))
                            for path in SOURCES.values()))

    def test_estados_unknown_explicitos(self) -> None:
        for key in IDS:
            with self.subTest(row=key):
                result = verdict(key, self.rows, self.spec, self.sources, self.observed)
                self.assertEqual("NO_VERIFICABLE", self.rows[key][2])
                self.assertTrue(result["missing"].startswith("UNKNOWN:"))
                for scope in ("full_scope", "ci", "production"):
                    self.assertEqual(UNKNOWN, result[scope])
        no_route = {**self.observed, "php": {**self.observed["php"], "sets": None}}
        for key in IDS:
            self.assertEqual(UNKNOWN, verdict(
                key, self.rows, self.spec, self.sources, no_route)["candidate"])
        no_links = {**self.observed, "js": {**self.observed["js"], "count": "0"}}
        self.assertEqual(UNKNOWN, verdict(
            "S13-R003", self.rows, self.spec, self.sources, no_links)["candidate"])


if __name__ == "__main__":
    unittest.main()
