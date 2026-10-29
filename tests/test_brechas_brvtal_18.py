#!/usr/bin/env python3
"""BRVTAL #1108: synthetic Archive §16 contract and explicit UNKNOWN gaps.

Runs the checked-in PHP lifecycle function and public Archive JS without a
server, credentials, network, or production data. Local evidence is not proof
of historical media retention across the entire catalog, CI, or production.
"""
from __future__ import annotations

import json
import os
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
    "php": "api/public-archive.php",
    "visibility": "config/public_visibility.php",
    "js": "js/archive.js",
    "contract": "tests/public-archive-contract.php",
}
IDS = ("S16-R002", "S16-R003", "S16-R004", "S16-R006", "S16-R007")
EXPECTED = {
    "S16-R002": "Finished events and historical media must not simply disappear.",
    "S16-R003": "Users should be able to discover prior events, artists, sets, releases and media.",
    "S16-R004": "Public Archive foundation is implemented.",
    "S16-R006": "Archive Discovery v2 supports combined year, text and relationship filters.",
    "S16-R007": "Historical cards link to canonical published Event routes when a valid slug is available.",
}
MISSING = {
    "S16-R002": "UNKNOWN: historical media retention outside Event covers not exercised",
    "S16-R003": "UNKNOWN: full artist, release, set and media catalog discovery unproven",
    "S16-R004": "UNKNOWN: full public Archive browser and delivery integration unproven",
    "S16-R006": "UNKNOWN: real-browser combined filter UX unproven",
    "S16-R007": "UNKNOWN: deployed canonical Event route resolution unproven",
}
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_SYNTHETIC"

PHP_PROBE = r"""
require 'api/public-archive.php';
$now = new DateTimeImmutable('2026-05-01 12:00:00');
$events = [
    ['id'=>1, 'status'=>'finished', 'title'=>'Synthetic Finished',
     'slug'=>'synthetic-finished', 'event_date'=>'2024-06-03 20:00:00',
     'cover_image'=>'/synthetic-cover.jpg', 'related_sets'=>[['id'=>91]],
     'ticket_url'=>'https://example.invalid/tickets', 'ticket_types'=>[['id'=>71]]],
    ['id'=>2, 'status'=>'archived', 'title'=>'Synthetic Old',
     'event_date'=>'2023-09-01 20:00:00'],
    ['id'=>3, 'status'=>'published', 'title'=>'Synthetic Past',
     'event_date'=>'2025-07-01 20:00:00'],
    ['id'=>4, 'status'=>'upcoming', 'title'=>'Synthetic Future',
     'event_date'=>'2027-01-01 20:00:00'],
    ['id'=>5, 'status'=>'draft', 'title'=>'Synthetic Private',
     'event_date'=>'2024-01-01 20:00:00'],
    ['id'=>6, 'status'=>'archived', 'title'=>'Synthetic Unpublished Future',
     'event_date'=>'2028-01-01 20:00:00', 'published_at'=>null],
    ['id'=>7, 'status'=>'finished', 'title'=>'Synthetic Invalid Publication',
     'event_date'=>'2023-01-01 20:00:00', 'published_at'=>'invalid-timestamp'],
];
$partition = brvtal_public_partition_events($events, $now);
$finished = null;
foreach ($partition['archive'] as $event) {
    if ($event['id'] === 1) $finished = $event;
}
echo json_encode([
    'active_ids'=>array_column($partition['active'],'id'),
    'archive_ids'=>array_column($partition['archive'],'id'),
    'years'=>$partition['years'],
    'cover'=>$finished['cover_image'] ?? null,
    'sets'=>$finished['related_sets'] ?? null,
    'ticket_cleared'=>$finished !== null
        && array_key_exists('ticket_url', $finished) && $finished['ticket_url'] === null
        && ($finished['ticket_types'] ?? 'missing') === [],
], JSON_THROW_ON_ERROR);
"""

NODE_PROBE = r"""
'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('js/archive.js','utf8');
if (!/\}\)\(\);\s*$/.test(source)) throw Error('UNKNOWN: unexpected archive script boundary');
const instrumented = source.replace(/\}\)\(\);\s*$/, `
  globalThis.__testHooks = {archiveCard, applyArchiveFilters, state, searchText, memoryEventCounts};
})();`);
function node(tag) {
  return {tag, dataset:{}, className:'', children:[], attrs:{}, hidden:false,
    appendChild(x){this.children.push(x);return x;},
    append(...xs){this.children.push(...xs);},
    replaceChildren(...xs){this.children=xs;},
    addEventListener(){},
    setAttribute(k,v){this.attrs[k]=v;},
    classList:{toggle(){}},
  };
}
let cards=[];
const root={
  querySelectorAll(sel){return sel==='[data-archive-event]'?cards:[];},
  querySelector(sel){return sel==='[data-archive-results]'?results
    :sel==='[data-archive-empty]'?empty:null;},
};
const results=node('span'), empty=node('div');
const document={
  readyState:'loading', scripts:[], documentElement:{lang:'en',dataset:{}},
  head:{appendChild(){}}, createElement:node,
  querySelector(sel){if(sel==='#eventArchive')return root;
    if(sel==='link[data-related-content-style]')return node('link');
    return null;},
};
const sandbox={window:{addEventListener(){}},document,
  location:{origin:'https://synthetic.invalid',pathname:'/',href:'https://synthetic.invalid/'},
  URL,Intl,Date,console};
vm.runInNewContext(instrumented,sandbox,{filename:'archive.js',timeout:1500});
const {archiveCard,applyArchiveFilters,state,searchText,memoryEventCounts} = sandbox.__testHooks;
const first = archiveCard({id:1,title:'Synthetic Música Night',slug:'synthetic-night',
  event_date:'2025-06-01',archive_year:2025,city:'Synthetic City',
  lineup:[{name:'Synthetic Artist'}], related_sets:[{id:3}],cover_image:''},1);
const second = archiveCard({id:2,title:'Synthetic Other',slug:'',
  event_date:'2024-05-01',archive_year:2024,lineup:[],related_sets:[]},0);
cards=[first,second];
function links(n){return (n.tag==='a'?[n.href]:[]).concat(...n.children.map(links));}
state.archiveYear='2025'; state.archiveRelation='sets'; state.archiveQuery=searchText('MÚSICA');
applyArchiveFilters();
const combined=[!first.hidden,!second.hidden,results.textContent,empty.hidden];
state.archiveQuery=searchText('not-present');
applyArchiveFilters();
const missing=[first.hidden,second.hidden,empty.hidden];
state.archiveQuery='';state.archiveYear='all';state.archiveRelation='memories';
applyArchiveFilters();
const memories=[!first.hidden,second.hidden];
console.log(JSON.stringify({
  combined,missing,memories,
  links_first:links(first),links_second:links(second),
  data_first:first.dataset,data_second:second.dataset,
  memory_counts:[...memoryEventCounts([
    {relations:[{related_type:'event',related_id:1},{related_type:'event',related_id:1}]},
    {relations:[{related_type:'artist',related_id:1}]}
  ]).entries()],
}));
"""


def rows(markdown: str) -> dict[str, tuple[str, ...]]:
    """Require one exact, well-formed row per declared gap."""
    found: dict[str, tuple[str, ...]] = {}
    for line in markdown.splitlines():
        match = re.match(r"^\| (S16-R\d{3}) · ", line)
        if not match or match.group(1) not in IDS:
            continue
        key = match.group(1)
        if key in found:
            raise ValueError("UNKNOWN: duplicate " + key)
        fields = tuple(field.strip() for field in line.strip("|").split("|"))
        if len(fields) != 5 or not fields[0].startswith(key + " · "):
            raise ValueError("UNKNOWN: malformed " + key)
        found[key] = fields
    return found


def run_probe(executable: str, args: list[str], script: str) -> dict[str, object]:
    path = shutil.which(executable)
    if path is None:
        raise AssertionError("UNKNOWN: required offline runtime unavailable: " + executable)
    result = subprocess.run(
        [path, *args, script], cwd=ROOT, capture_output=True, text=True,
        timeout=15, check=False, env={"PATH": os.defpath, "TZ": "UTC"},
    )
    if result.returncode != 0:
        raise AssertionError("UNKNOWN: offline runtime failed: " + result.stderr[-500:])
    try:
        value = json.loads(result.stdout)
    except (TypeError, ValueError) as exc:
        raise AssertionError("UNKNOWN: invalid probe output") from exc
    if not isinstance(value, dict):
        raise AssertionError("UNKNOWN: invalid probe shape")
    return value


@lru_cache(maxsize=1)
def evidence() -> dict[str, dict[str, object]]:
    return {"php": run_probe("php", ["-n", "-r"], PHP_PROBE),
            "js": run_probe("node", ["-e"], NODE_PROBE)}


def local_contract(identifier: str, observed: object) -> bool:
    """Evaluate synthetic assertions, never infer full catalog/deploy coverage."""
    if identifier not in IDS or not isinstance(observed, dict):
        return False
    php, js = observed.get("php"), observed.get("js")
    if not isinstance(php, dict) or not isinstance(js, dict):
        return False
    history = (php.get("archive_ids") == [3, 1, 2]
               and php.get("active_ids") == [4]
               and php.get("years") == [2025, 2024, 2023]
               and php.get("ticket_cleared") is True)
    cards = (js.get("data_first", {}).get("archiveYear") == "2025"
             and js.get("data_first", {}).get("archiveArtists") == "1"
             and js.get("data_first", {}).get("archiveSets") == "1"
             and js.get("data_first", {}).get("archiveMemories") == "1"
             and js.get("memory_counts") == [[1, 1]])
    checks = {
        "S16-R002": history and php.get("cover") == "/synthetic-cover.jpg",
        "S16-R003": cards and php.get("sets") == [{"id": 91}],
        "S16-R004": history and cards,
        "S16-R006": (js.get("combined") == [True, False, "1 RECORD FOUND", True]
                       and js.get("missing") == [True, True, False]
                       and js.get("memories") == [True, True]),
        "S16-R007": (js.get("links_first") == ["/events/synthetic-night", "/?network_type=events&network_id=1#network"]
                       or js.get("links_first") == ["/?network_type=events&network_id=1#network", "/events/synthetic-night"])
                      and js.get("links_second") == [],
    }
    return checks[identifier]


def verdict(identifier: str, table: dict[str, tuple[str, ...]],
            sources: dict[str, str], observed: object) -> dict[str, str]:
    state = {"candidate": UNKNOWN, "full_scope": UNKNOWN,
             "ci": UNKNOWN, "production": UNKNOWN,
             "missing": "UNKNOWN: missing or invalid evidence"}
    row = table.get(identifier)
    if (identifier not in IDS or row is None or len(row) != 5
            or row[1] != EXPECTED[identifier] or row[2] != "NO_VERIFICABLE"
            or not all(isinstance(sources.get(key), str) and sources[key]
                       for key in SOURCES)
            or "function brvtal_public_partition_events(" not in sources["php"]
            or "function archiveCard(" not in sources["js"]
            or "function applyArchiveFilters(" not in sources["js"]
            or "brvtal_public_partition_events" not in sources["contract"]
            or not local_contract(identifier, observed)):
        return state
    state["candidate"] = LOCAL
    state["missing"] = MISSING[identifier]
    return state


class BrechasBrvtal18Tests(unittest.TestCase):
    def setUp(self) -> None:
        self.table = rows(MATRIX.read_text(encoding="utf-8"))
        self.sources = {key: (ROOT / path).read_text(encoding="utf-8")
                        for key, path in SOURCES.items()}
        self.observed = evidence()

    def test_contrato_principal(self) -> None:
        self.assertEqual(set(IDS), set(self.table))
        self.assertIn("## 16. Archive", SPEC.read_text(encoding="utf-8"))
        for identifier in IDS:
            with self.subTest(row=identifier):
                result = verdict(identifier, self.table, self.sources, self.observed)
                self.assertTrue(local_contract(identifier, self.observed))
                self.assertEqual(LOCAL, result["candidate"])
                self.assertEqual(MISSING[identifier], result["missing"])
                self.assertEqual(UNKNOWN, result["full_scope"])

    def test_falla_cerrado(self) -> None:
        for identifier in IDS:
            with self.subTest(row=identifier):
                self.assertEqual(UNKNOWN, verdict(identifier, {}, self.sources, self.observed)["candidate"])
                self.assertEqual(UNKNOWN, verdict(identifier, self.table, {}, self.observed)["candidate"])
                self.assertEqual(UNKNOWN, verdict(identifier, self.table, self.sources, None)["candidate"])
                changed = dict(self.table)
                changed[identifier] = (*self.table[identifier][:2], "CUBIERTO", *self.table[identifier][3:])
                self.assertEqual(UNKNOWN, verdict(identifier, changed, self.sources, self.observed)["candidate"])
        first = next(line for line in MATRIX.read_text(encoding="utf-8").splitlines()
                     if line.startswith("| S16-R002 · "))
        with self.assertRaisesRegex(ValueError, "duplicate"):
            rows(first + "\n" + first)
        with self.assertRaisesRegex(ValueError, "malformed"):
            rows("| S16-R002 · partial | CUBIERTO |")
        self.assertEqual(UNKNOWN, verdict("S16-R999", self.table, self.sources, self.observed)["candidate"])

    def test_sin_datos_reales(self) -> None:
        own = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(own, r"(?m)^(?:import|from)\s+(?:requests|httpx|socket|urllib|pymysql)\b")
        self.assertNotIn("-----BEGIN " + "PRIVATE KEY-----", own)
        self.assertNotIn("PDO(", PHP_PROBE)
        self.assertNotIn("curl_exec(", PHP_PROBE)
        self.assertIn("synthetic.invalid", NODE_PROBE)
        self.assertTrue(all(path.startswith(("api/", "config/", "js/", "tests/"))
                            for path in SOURCES.values()))

    def test_estados_unknown_explicitos(self) -> None:
        for identifier in IDS:
            with self.subTest(row=identifier):
                output = verdict(identifier, self.table, self.sources, self.observed)
                self.assertEqual("NO_VERIFICABLE", self.table[identifier][2])
                self.assertTrue(output["missing"].startswith("UNKNOWN:"))
                for field in ("full_scope", "ci", "production"):
                    self.assertEqual(UNKNOWN, output[field])
        mutated = {**self.observed, "php": {**self.observed["php"], "archive_ids": []}}
        self.assertFalse(local_contract("S16-R002", mutated))
        self.assertEqual(UNKNOWN, verdict("S16-R002", self.table, self.sources, mutated)["candidate"])
        no_filter = {**self.observed, "js": {**self.observed["js"], "missing": [False, False, True]}}
        self.assertEqual(UNKNOWN, verdict("S16-R006", self.table, self.sources, no_filter)["candidate"])


if __name__ == "__main__":
    unittest.main()
