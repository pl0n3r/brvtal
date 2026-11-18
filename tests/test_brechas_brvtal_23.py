#!/usr/bin/env python3
"""BRVTAL #1113: fail-closed local evidence for five §20 Blog coverage rows.

No server, database, production endpoint or user fixture is contacted. The
JavaScript probe runs the *real* Blog module in a sandbox with synthetic data;
source contracts never masquerade as production/CI validation.
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
IDS = ("S20-R002", "S20-R003", "S20-R005", "S20-R006", "S20-R008")
CLAUSES = {
    "S20-R002": "Do not add unnecessary editorial automation.",
    "S20-R003": "[Blog Trash / Recycle Bin] Blog is the first editorial entity with recoverable deletion.",
    "S20-R005": "Active DISCADMIN reads and every public Blog query exclude Trash.",
    "S20-R006": "[Blog Trash / Recycle Bin] DISCADMIN exposes ACTIVE and TRASH as distinct views",
    "S20-R008": "Permanent deletion is a separate destructive action available only for",
}
PATHS = {
    "spec": "docs/BRVTAL-SPEC.md",
    "admin_api": "api/blog.php",
    "public_api": "api/public.php",
    "admin_ui": "discadmin/blog.php",
    "admin_js": "discadmin/blog.js",
    "migration": "database/migration_blog_trash_01.sql",
}
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_CONTRACT"

# Only expose existing module functions in the memory-only VM. No DOM, browser,
# actual network, cookies, credentials, timers or persistent storage required.
JS_PROBE = r"""
'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const raw = fs.readFileSync('discadmin/blog.js', 'utf8');
const anchor = '  return {mount,refresh,openEditor,remove,restore,permanentDelete};';
if (raw.split(anchor).length !== 2) throw Error('UNKNOWN: Blog API surface changed');
const src = raw.replace(anchor,
  '  return {mount,refresh,openEditor,remove,restore,permanentDelete,' +
  '__probe:{setView,currentRows,store}};');
const calls = [];
let typed = null;
const sandbox = {
  window: {
    addEventListener() {},
    BRVTALAdminAuthBoundary: {csrfToken: async () => 'synthetic-csrf'}
  },
  document: {getElementById() { return null; }, querySelectorAll() { return []; }},
  prompt() {return typed;}, confirm() {return true;},
  setTimeout() {throw Error('UNKNOWN: timer forbidden');},
  clearTimeout() {},
  fetch: async (url, options={}) => {
    const event = {url, method: options.method || 'GET', body: options.body || null};
    calls.push(event);
    return {ok:true, json: async () => ({ok:true, data:[]})};
  }
};
vm.runInNewContext(src, sandbox, {timeout: 1500});
const api = sandbox.window.BRVTALBlog;
const p = api.__probe;
p.store.posts = [{id:1,slug:'active-synthetic'}];
p.store.trash = [{id:2,slug:'trash-synthetic'}];
p.setView('active');
const active = p.currentRows().map(x => x.id);
p.setView('trash');
const trash = p.currentRows().map(x => x.id);
(async () => {
  typed = 'wrong';
  await api.permanentDelete(2);
  const afterWrong = calls.length;
  typed = null;
  await api.permanentDelete(2);
  const afterCancelled = calls.length;
  typed = 'trash-synthetic';
  await api.permanentDelete(1); // ACTIVE must not delete forever.
  const afterActive = calls.length;
  await api.permanentDelete(2);
  const deletes = calls.filter(x => x.method === 'DELETE');
  process.stdout.write(JSON.stringify({
    active,trash,afterWrong,afterCancelled,afterActive,
    deletes,requests:calls.length,
    noHttp: calls.every(x => x.url.startsWith('/api/') || x.url.startsWith('?'))
  }));
})().catch(e => {console.error(String(e)); process.exitCode=1;});
"""


def rows(text: str) -> dict[str, tuple[str, ...]]:
    result: dict[str, tuple[str, ...]] = {}
    for line in text.splitlines():
        m = re.match(r"^\| (S20-R\d{3}) · ", line)
        if m is None or m.group(1) not in IDS:
            continue
        key = m.group(1)
        if key in result:
            raise ValueError("UNKNOWN: duplicate " + key)
        cells = tuple(v.strip() for v in line.strip().strip("|").split("|"))
        if len(cells) != 5 or not cells[0].startswith(key + " · "):
            raise ValueError("UNKNOWN: malformed " + key)
        result[key] = cells
    return result


def sources() -> dict[str, str]:
    return {key: (ROOT / path).read_text(encoding="utf-8") for key, path in PATHS.items()}


@lru_cache(maxsize=1)
def observe() -> dict[str, object]:
    node = shutil.which("node")
    if node is None:
        raise AssertionError("UNKNOWN: Node runtime missing")
    result = subprocess.run([node, "-e", JS_PROBE], cwd=ROOT, text=True,
                            capture_output=True, timeout=15, check=False,
                            env={"PATH": "/usr/local/bin:/usr/bin:/bin"})
    if result.returncode != 0:
        raise AssertionError("UNKNOWN: isolated Blog VM failed: " + result.stderr[-450:])
    data = json.loads(result.stdout)
    if not isinstance(data, dict):
        raise AssertionError("UNKNOWN: malformed synthetic Blog observation")
    return data


def verdict(identifier: str, table: dict[str, tuple[str, ...]],
            files: dict[str, str], observed: object) -> dict[str, str]:
    result = {"code": UNKNOWN, "ci": UNKNOWN, "production": UNKNOWN}
    row = table.get(identifier)
    if (identifier not in IDS or row is None or len(row) != 5
            or not row[1].startswith(CLAUSES[identifier])
            or row[2] != "NO_VERIFICABLE"
            or any(not isinstance(files.get(k), str) or not files[k] for k in PATHS)
            or not isinstance(observed, dict) or observed.get("noHttp") is not True):
        return result
    spec, api, public = files["spec"], files["admin_api"], files["public_api"]
    ui, js, migration = files["admin_ui"], files["admin_js"], files["migration"]

    # R002: absence-of-feature is not proven by checking a few paths.
    if identifier == "S20-R002":
        return result

    if identifier == "S20-R003":
        # Recoverable Blog deletion exists, but historical "first editorial
        # entity" precedence cannot be established from an offline contract.
        if ("Blog is the first editorial entity with recoverable deletion" in spec
                and "ADD COLUMN IF NOT EXISTS deleted_at" in migration
                and "SET deleted_at=CURRENT_TIMESTAMP,deleted_by_admin_id=?" in api
                and "SET deleted_at=NULL,deleted_by_admin_id=NULL" in api
                and "'trash','blog'" in api and "'restore','blog'" in api):
            return result
        return result

    if identifier == "S20-R005":
        admin_filter = "$condition = $trashed ? 'p.deleted_at IS NOT NULL' : 'p.deleted_at IS NULL';"
        if (api.count(admin_filter) == 2
                and "brvtal_blog_fetch($pdo, $id, $trash)" in api
                and "brvtal_blog_list($pdo, $trash)" in api
                and "brvtal_public_column_exists($pdo, 'blog_posts', 'deleted_at')" in public
                and public.count("WHERE status='published' AND deleted_at IS NULL") == 1
                and public.count("WHERE p.status='published' AND p.deleted_at IS NULL") == 2
                and "if (!$posts) return [];" in public):
            result["code"] = LOCAL
        return result

    if identifier == "S20-R006":
        if (observed.get("active") == [1] and observed.get("trash") == [2]
                and 'id="blog-active-view"' in ui
                and 'id="blog-trash-view"' in ui
                and "request('?trash=1')" in js
                and "setView('active')" in js and "setView('trash')" in js):
            result["code"] = LOCAL
        return result

    if identifier == "S20-R008":
        deletions = observed.get("deletes")
        if (observed.get("afterWrong") == 0
                and observed.get("afterCancelled") == 0
                and observed.get("afterActive") == 0
                and isinstance(deletions, list) and len(deletions) == 1
                and isinstance(deletions[0], dict)
                and deletions[0].get("method") == "DELETE"
                and deletions[0].get("url") == "/api/blog.php?id=2&permanent=1"
                and json.loads(deletions[0].get("body", "null")) == {"confirm_slug": "trash-synthetic"}
                and "brvtal_blog_fetch($pdo, $id, true)" in api
                and "hash_equals((string)$before['slug'], trim($confirmSlug))" in api
                and "DELETE FROM blog_posts WHERE id=? AND deleted_at IS NOT NULL" in api
                and "BLOG_PERMANENT_DELETE_CONFIRMATION_REQUIRED" in api
                and "brvtal_admin_require_csrf();" in api):
            result["code"] = LOCAL
        return result
    return result


class BrechasBrvtal23Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        table, files, observed = rows((ROOT / "docs/matriz-cobertura.md").read_text(encoding="utf-8")), sources(), observe()
        self.assertEqual(set(IDS), set(table))
        for identifier in IDS:
            with self.subTest(row=identifier):
                self.assertEqual("NO_VERIFICABLE", table[identifier][2])
                expected = LOCAL if identifier in ("S20-R005", "S20-R006", "S20-R008") else UNKNOWN
                self.assertEqual(expected, verdict(identifier, table, files, observed)["code"])
        self.assertIn("SET deleted_at=NULL,deleted_by_admin_id=NULL", files["admin_api"])
        self.assertIn("'restore','blog'", files["admin_api"])
        self.assertIn("Blog is the first editorial entity", files["spec"])

    def test_falla_cerrado(self) -> None:
        table, files, observed = rows((ROOT / "docs/matriz-cobertura.md").read_text(encoding="utf-8")), sources(), observe()
        row = next(x for x in (ROOT / "docs/matriz-cobertura.md").read_text(encoding="utf-8").splitlines()
                   if x.startswith("| S20-R005 · "))
        with self.assertRaisesRegex(ValueError, "duplicate"):
            rows(row + "\n" + row)
        for identifier in IDS:
            with self.subTest(row=identifier):
                self.assertEqual(UNKNOWN, verdict(identifier, {}, files, observed)["code"])
                self.assertEqual(UNKNOWN, verdict(identifier, table, {}, observed)["code"])
                self.assertEqual(UNKNOWN, verdict(identifier, table, files, None)["code"])
                self.assertEqual(UNKNOWN, verdict(identifier, table, files, {"noHttp": False})["code"])
        public = files["public_api"].replace("p.deleted_at IS NULL", "p.deleted_at IS NOT NULL", 1)
        self.assertEqual(UNKNOWN, verdict("S20-R005", table, {**files, "public_api": public}, observed)["code"])
        api = files["admin_api"].replace("hash_equals((string)$before['slug'], trim($confirmSlug))", "true", 1)
        self.assertEqual(UNKNOWN, verdict("S20-R008", table, {**files, "admin_api": api}, observed)["code"])
        bad = {**observed, "trash": [1]}
        self.assertEqual(UNKNOWN, verdict("S20-R006", table, files, bad)["code"])
        self.assertEqual(UNKNOWN, verdict("S20-R999", table, files, observed)["code"])

    def test_sin_datos_reales(self) -> None:
        observed = observe()
        self.assertEqual([1], observed["active"])
        self.assertEqual([2], observed["trash"])
        self.assertTrue(observed["noHttp"])
        self.assertIn("synthetic-csrf", JS_PROBE)
        self.assertNotRegex(Path(__file__).read_text(encoding="utf-8"),
                            r"(?m)^(?:import|from)\s+(?:requests|httpx|socket|urllib)\b")
        self.assertNotIn("curl_exec(", JS_PROBE)
        self.assertNotIn("node:https", JS_PROBE)
        self.assertNotIn("PRIVATE KEY", JS_PROBE)

    def test_estados_unknown_explicitos(self) -> None:
        table, files, observed = rows((ROOT / "docs/matriz-cobertura.md").read_text(encoding="utf-8")), sources(), observe()
        for identifier in IDS:
            with self.subTest(row=identifier):
                result = verdict(identifier, table, files, observed)
                self.assertEqual(UNKNOWN, result["ci"])
                self.assertEqual(UNKNOWN, result["production"])
                self.assertEqual(UNKNOWN, verdict(identifier, table, files, None)["code"])
        for identifier in ("S20-R002", "S20-R003"):
            self.assertEqual(UNKNOWN, verdict(identifier, table, files, observed)["code"])
        altered = dict(table)
        altered["S20-R005"] = (*table["S20-R005"][:2], "CUBIERTO", *table["S20-R005"][3:])
        self.assertEqual(UNKNOWN, verdict("S20-R005", altered, files, observed)["code"])


if __name__ == "__main__":
    unittest.main()
