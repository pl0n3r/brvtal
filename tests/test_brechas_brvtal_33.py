#!/usr/bin/env python3
"""BRVTAL #1123: cinco contratos S27-R004..R008, sintéticos y fail-closed.

La evidencia local no certifica deploy, auditoría con datos reales, migración
productiva ni una tríada de código+prueba+PR fusionado. La matriz no se edita.
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
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_CONTRACT"
IDS = tuple(f"S27-R{i:03d}" for i in range(4, 9))
SUMMARIES = {
    "S27-R004": "Important editorial/admin mutations record actor, action, resource, changed fields and safe before/after snapshots",
    "S27-R005": "Editorial Version History v1 groups those snapshots by content item in a chronological timeline",
    "S27-R006": "The Admin Activity dashboard remains read-only and never exposes direct restore/revert actions.",
    "S27-R007": "Event Version Restore V1 is review-before-save only from the canonical open editor",
    "S27-R008": "The normal Event Save remains the sole persistence boundary",
}
FILES = {
    "matrix": "docs/matriz-cobertura.md",
    "spec": "docs/BRVTAL-SPEC.md",
    "helper": "config/admin_activity.php",
    "endpoint": "api/admin-activity.php",
    "core": "api/index.php",
    "ui": "discadmin/admin-activity.js",
    "editor": "discadmin/content-core.js",
    "restore": "discadmin/event-version-restore.js",
    "contract": "tests/admin-activity-contract.php",
}
CHECKS = {
    "S27-R004": (
        ("helper", "function brvtal_activity_record("),
        ("helper", "admin_name,admin_email,action,resource,resource_id,resource_label,changed_fields,before_json,after_json"),
        ("helper", "brvtal_activity_sanitize_value($meta)"),
        ("contract", "brvtal_activity_is_sensitive_key"),
    ),
    "S27-R005": (
        ("helper", "function brvtalActivityPage("),
        ("helper", "'id < ?'"),
        ("endpoint", "'HISTORY_RESOURCE_REQUIRED'"),
        ("endpoint", "'content_history'"),
        ("ui", "function historyDiff(item)"),
        ("ui", "fetchHistory(resource, resourceId, cursor = null"),
        ("ui", "EDITORIAL VERSION HISTORY"),
    ),
    "S27-R006": (
        ("endpoint", "brvtal_admin_require();"),
        ("endpoint", "'GET'"),
        ("endpoint", "'METHOD_NOT_ALLOWED'"),
        ("ui", "Restore/revert actions are intentionally not available"),
        ("ui", "openHistory(button.dataset.activityResource, button.dataset.activityHistory, button.dataset.activityLabel)"),
    ),
    "S27-R007": (
        ("restore", "const RESTORABLE_FIELDS = Object.freeze("),
        ("restore", "function buildPlan(historyItem, currentEvent)"),
        ("restore", "execution:false"),
        ("editor", "async function stageEventVersionRestore(item,eventId,restoreModule)"),
        ("editor", "!modal?.classList.contains('open')"),
        ("editor", "window.confirm("),
        ("editor", "Nothing will be saved until you press SAVE EVENT."),
        ("editor", "applyEventRestorePlan(plan,eventId)"),
    ),
    "S27-R008": (
        ("core", "brvtal_activity_record($pdo,'update',$resource,$id,$before,$after"),
        ("helper", "'update' && $changed === []"),
        ("editor", "async function saveEvent()"),
        ("editor", "method:currentEvent?'PUT':'POST'"),
        ("editor", "headers:{'X-CSRF-Token':csrf}"),
    ),
}


def sources() -> dict[str, str]:
    return {key: (ROOT / path).read_text(encoding="utf-8")
            for key, path in FILES.items()}


def matrix_rows(document: object) -> dict[str, tuple[str, ...]]:
    """Absencia, duplicados y promoción sin tríada se rechazan explícitamente."""
    if not isinstance(document, str) or not document:
        raise ValueError("UNKNOWN: matriz ausente")
    result: dict[str, tuple[str, ...]] = {}
    for key in IDS:
        matches = [line for line in document.splitlines()
                   if line.startswith(f"| {key} · ")]
        if len(matches) != 1:
            raise ValueError("UNKNOWN: fila ausente o duplicada " + key)
        cells = tuple(x.strip() for x in matches[0].strip("|").split("|"))
        if (len(cells) != 5 or not cells[0].startswith(key + " · [S27 L")
                or not cells[1].startswith(SUMMARIES[key])
                or cells[2] != "NO_VERIFICABLE"
                or not cells[3].startswith("Sin aserción contrastada para")
                or "falta enlazar prueba" not in cells[3]
                or "PR fusionado" not in cells[3]
                or cells[4] != "—"):
            raise ValueError("UNKNOWN: fila ambigua " + key)
        result[key] = cells
    return result


PHP_PROBE = r"""
if (!function_exists('mb_substr')) {
    function mb_substr($s, $start, $len) {return substr($s, $start, $len);}
}
require 'config/admin_activity.php';
$before=['id'=>7,'title'=>'BEFORE','seo_description'=>'old',
 'status'=>'published','updated_at'=>'yesterday','password'=>'synthetic-secret'];
$after=['id'=>7,'title'=>'AFTER','seo_description'=>'new',
 'status'=>'published','updated_at'=>'today','api_key'=>'synthetic-key'];
$safeBefore=brvtal_activity_snapshot('events',$before);
$safeAfter=brvtal_activity_snapshot('events',$after);
$nested=brvtal_activity_sanitize_value([
 'token'=>'synthetic','sub'=>['secret'=>'synthetic','label'=>'ok']
]);
$settings=brvtal_activity_snapshot('settings',[
 'setting_key'=>'theme.active','setting_value'=>'synthetic',
 'is_json'=>false
]);
echo json_encode([
 'changed'=>brvtal_activity_changed_fields($safeBefore,$safeAfter),
 'before'=>$safeBefore,'after'=>$safeAfter,
 'label'=>brvtal_activity_label('events',$safeAfter),
 'nested'=>$nested,'settings'=>$settings,
 'is_sensitive'=>brvtal_activity_is_sensitive_key('auth_token')
],JSON_THROW_ON_ERROR);
"""

NODE_PROBE = r"""
'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const window={};
vm.runInNewContext(fs.readFileSync(process.argv[1],'utf8'),
 {window,Object,Set,Number,String,Array,console}, {timeout:2000});
const planner=window.BRVTALEventVersionRestore;
const event={id:7,title:'NOW',status:'published',published_at:'2026-01-01',
 lineup:[{artist_id:1}],timetable:[{id:1}]};
const history={id:41,resource:'events',resource_id:7,action:'update',
 after:{id:7,title:'THEN',status:'draft',published_at:null,
 cancelled_at:'2026-01-01',finished_at:'2026-01-02'}};
const initial=JSON.stringify(event);
const plan=planner.buildPlan(history,event);
assert.equal(plan.ok,true);
assert.equal(plan.event_id,7);
assert.equal(plan.activity_id,41);
assert.equal(plan.execution,false);
assert.deepEqual(Array.from(plan.fields),['title']);
assert.equal(plan.values.title,'THEN');
for(const field of ['status','published_at','cancelled_at','finished_at',
 'lineup','timetable','ticket_types','password']){
 assert.equal(Object.hasOwn(plan.values,field),false);
}
const rejected=[
 {...history,resource_id:8}, {...history,action:'delete'},
 {...history,after:{id:8,title:'FOREIGN'}},
 {...history,after:{id:7,status:'draft'}},
 {...history,after:{id:7,title:{nested:'bad'}}},
 {...history,after:null},
];
for(const item of rejected){assert.equal(planner.buildPlan(item,event).ok,false);}
assert.equal(JSON.stringify(event),initial);
console.log(JSON.stringify({valid:plan.ok,safe_fields:Array.from(plan.fields),
 rejected:rejected.length,mutated:JSON.stringify(event)!==initial,
 execution:plan.execution}));
"""


@lru_cache(maxsize=1)
def probes() -> dict[str, object]:
    php, node = shutil.which("php"), shutil.which("node")
    if not php or not node:
        raise AssertionError("UNKNOWN: runners PHP/Node ausentes")
    outcome: dict[str, object] = {}
    for name, command in (
        ("php", [php, "-r", PHP_PROBE]),
        ("node", [node, "-e", NODE_PROBE, str(ROOT / FILES["restore"])]),
    ):
        result = subprocess.run(command, cwd=ROOT, capture_output=True,
                                text=True, check=False, timeout=25)
        if result.returncode or result.stderr.strip():
            raise AssertionError(f"UNKNOWN: fixture {name}: {result.stderr[-500:]}")
        value = json.loads(result.stdout)
        if not isinstance(value, dict):
            raise AssertionError("UNKNOWN: evidencia sintética inválida " + name)
        outcome[name] = value
    return outcome


def local_verdict(key: object, rows: object, files: object,
                  observed: object) -> str:
    """Sólo confirma comportamiento local; nunca certifica entrega live."""
    if (not isinstance(key, str) or key not in IDS
            or not isinstance(rows, dict) or key not in rows
            or not isinstance(files, dict) or set(files) != set(FILES)
            or any(not isinstance(s, str) or not s.strip() for s in files.values())
            or not isinstance(observed, dict)):
        return UNKNOWN
    if rows[key][2] != "NO_VERIFICABLE":
        return UNKNOWN
    if SUMMARIES[key] not in files["spec"]:
        return UNKNOWN
    if not all(token in files[name] for name, token in CHECKS[key]):
        return UNKNOWN
    php, node = observed.get("php"), observed.get("node")
    if not isinstance(php, dict) or not isinstance(node, dict):
        return UNKNOWN
    safe = (php.get("changed") == ["seo_description", "title"]
            and php.get("before", {}).get("title") == "BEFORE"
            and php.get("after", {}).get("title") == "AFTER"
            and php.get("label") == "AFTER"
            and php.get("is_sensitive") is True
            and php.get("nested") == {"sub": {"label": "ok"}}
            and php.get("settings") == {"setting_key": "theme.active",
                                        "is_json": False}
            and "password" not in php.get("before", {})
            and "api_key" not in php.get("after", {}))
    if not safe:
        return UNKNOWN
    if key == "S27-R006":
        endpoint = files["endpoint"]
        if re.search(r"\b(?:INSERT\s+INTO|UPDATE\s+admin_activity_log|DELETE\s+FROM\s+admin_activity_log)\b",
                     endpoint, re.IGNORECASE):
            return UNKNOWN
    if key in ("S27-R007", "S27-R008"):
        editor = files["editor"]
        start = editor.find("async function stageEventVersionRestore(")
        end = editor.find("\nasync function openEventHistory()", start)
        if start < 0 or end < 0 or any(x in editor[start:end] for x in
                                       ("saveEvent(", "fetch(", "await api(")):
            return UNKNOWN
        if (node.get("valid") is not True or node.get("safe_fields") != ["title"]
                or node.get("rejected") != 6 or node.get("mutated") is not False
                or node.get("execution") is not False):
            return UNKNOWN
    return LOCAL


def operational(_key: object, _proof: object = None) -> str:
    """Sin observación real, aprobación humana, PR fusionado ni exact-SHA."""
    return UNKNOWN


class BrechasBrvtal33Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.files = sources()
        cls.rows = matrix_rows(cls.files["matrix"])
        cls.observed = probes()

    def test_contrato_principal(self) -> None:
        """AC-01: cinco brechas, con prueba real parcial y alcance explícito."""
        self.assertEqual(set(self.rows), set(IDS))
        self.assertIn("## 27. Administrators and activity",
                      self.files["spec"])
        for key in IDS:
            with self.subTest(key=key):
                self.assertEqual(local_verdict(
                    key, self.rows, self.files, self.observed), LOCAL)
                self.assertEqual(operational(key, self.observed), UNKNOWN)

    def test_falla_cerrado(self) -> None:
        """AC-02: filas falsas, fuentes ausentes o pruebas dañadas = UNKNOWN."""
        document = self.files["matrix"]
        for key in IDS:
            line = next(x for x in document.splitlines()
                        if x.startswith(f"| {key} · "))
            for altered in (document.replace(line, "", 1),
                            document + "\n" + line,
                            document.replace(
                                line, line.replace("NO_VERIFICABLE", "CUBIERTO", 1), 1)):
                with self.subTest(key=key):
                    with self.assertRaisesRegex(ValueError, UNKNOWN):
                        matrix_rows(altered)
            name, token = CHECKS[key][0]
            broken = dict(self.files)
            self.assertIn(token, broken[name])
            broken[name] = broken[name].replace(token, "", 1)
            self.assertEqual(local_verdict(
                key, self.rows, broken, self.observed), UNKNOWN)
            self.assertEqual(local_verdict(key, {}, self.files, self.observed),
                             UNKNOWN)
            self.assertEqual(local_verdict(key, self.rows, {}, self.observed),
                             UNKNOWN)
            self.assertEqual(local_verdict(key, self.rows, self.files, {}),
                             UNKNOWN)
        self.assertEqual(local_verdict("S27-R999", self.rows, self.files,
                                       self.observed), UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        """AC-03: pruebas confinadas a fuentes locales y valores ficticios."""
        for path in FILES.values():
            with self.subTest(path=path):
                self.assertTrue((ROOT / path).is_file())
                self.assertTrue((ROOT / path).resolve().is_relative_to(ROOT.resolve()))
        self.assertNotIn("@", json.dumps(self.observed))
        self.assertNotIn("http", PHP_PROBE.lower())
        self.assertNotIn("fetch(", NODE_PROBE)
        self.assertNotIn("api_key", self.observed["php"]["after"])
        self.assertNotIn("password", self.observed["php"]["before"])

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: el código probado no convierte la matriz en certificación."""
        for key in IDS:
            self.assertEqual(self.rows[key][2], "NO_VERIFICABLE")
            self.assertEqual(operational(
                key, local_verdict(key, self.rows, self.files, self.observed)),
                UNKNOWN)
        self.assertEqual(self.observed["node"]["safe_fields"], ["title"])
        self.assertFalse(self.observed["node"]["mutated"])


if __name__ == "__main__":
    unittest.main()
