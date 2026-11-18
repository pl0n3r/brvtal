#!/usr/bin/env python3
"""BRVTAL #1118: offline navigation contracts for §24 S24-L378..L382.

Exercise real DISCADMIN navigation/routing with synthetic VM state only. A local
contract is not a browser, deployed release, or production attestation.
"""
from __future__ import annotations

import copy
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
SHELL = ROOT / "discadmin/index-core.php"
IDS = ("S24-L378", "S24-L379", "S24-L380", "S24-L381", "S24-L382")
LABELS = {
    "S24-L378": ("releases", "RELEASES", "Releases;"),
    "S24-L379": ("sets", "SETS", "Sets;"),
    "S24-L380": ("media", "MEDIA", "Media;"),
    "S24-L381": ("pages", "PAGES", "Pages;"),
    "S24-L382": ("blog", "BLOG", "Blog;"),
}
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_CONTRACT"

# Only the original functions and navigation block from index-core.php are
# executed. No browser, HTTP, DB, server session, filesystem writes or secrets.
PROBE = r"""
'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('discadmin/index-core.php', 'utf8');
function section(begin, end) {
  const a = source.indexOf(begin);
  if (a < 0 || source.indexOf(begin, a + begin.length) >= 0)
    throw Error('UNKNOWN: missing or ambiguous source begin');
  const b = source.indexOf(end, a + begin.length);
  if (b < 0) throw Error('UNKNOWN: missing source end');
  return source.slice(a, b);
}
const nav = section(' const editorialSections=[', ' const settingsActive=');
const nativeUrl = section('function nativeListUrl(', 'async function login(');
const go = section('async function go(s){', 'function openModal(');
const targets = ['releases', 'sets', 'media', 'pages', 'blog'];
(async () => {
  const output = {};
  for (const selected of targets) {
    const ctx = {
      URLSearchParams,
      state: {authed: true, section: 'dashboard', rows: [], pagination: null, search: ''},
      calls: [], renders: 0,
      async req(url) { this.calls.push(url); return {data: [], pagination: {}}; },
      render() { this.renders++; },
    };
    // Pass explicit stubs as lexical locals: no ambient browser/network.
    const program =
      'const visibleSection=' + JSON.stringify(selected) + ';\n' + nav + '\n' +
      'globalThis.__nav={items:editorialSections, html:editorialNav};\n' +
      nativeUrl + '\n' + go;
    vm.runInNewContext(program, ctx, {timeout: 1500});
    // The original go(s) must select the real target and use the synthetic req.
    // Avoid relying on this inside a sandboxed callback.
    ctx.req = async url => { ctx.calls.push(url); return {data: [], pagination: {}}; };
    ctx.render = () => { ctx.renders++; };
    await ctx.go(selected);
    output[selected] = {
      nav: ctx.__nav, section: ctx.state.section,
      requests: ctx.calls, renders: ctx.renders,
    };
  }
  process.stdout.write(JSON.stringify(output));
})().catch(e => { process.stderr.write(String(e.stack || e)); process.exitCode = 1; });
"""


def matrix_rows(raw: str) -> dict[str, tuple[str, ...]]:
    """Reject duplicate/malformed target rows instead of treating them as proof."""
    found: dict[str, tuple[str, ...]] = {}
    for line in raw.splitlines():
        match = re.match(r"^\| (S24-L\d{3}) · ", line)
        if not match or match.group(1) not in IDS:
            continue
        identifier = match.group(1)
        if identifier in found:
            raise ValueError("UNKNOWN: duplicate row " + identifier)
        fields = tuple(part.strip() for part in line.strip().strip("|").split("|"))
        if len(fields) != 5 or not fields[0].startswith(identifier + " · "):
            raise ValueError("UNKNOWN: malformed row " + identifier)
        found[identifier] = fields
    return found


@lru_cache(maxsize=1)
def observe() -> dict[str, object]:
    """Execute actual committed navigation code with fake in-memory requests."""
    node = shutil.which("node")
    if not node:
        raise AssertionError("UNKNOWN: Node executable is unavailable")
    run = subprocess.run(
        [node, "-e", PROBE], cwd=ROOT, text=True,
        capture_output=True, timeout=20, check=False,
        env={"PATH": "/usr/local/bin:/usr/bin:/bin"},
    )
    if run.returncode:
        raise AssertionError("UNKNOWN: isolated navigation probe failed: " + run.stderr[-600:])
    data = json.loads(run.stdout)
    if not isinstance(data, dict):
        raise AssertionError("UNKNOWN: navigation evidence is not a mapping")
    return data


def verdict(identifier: str, table: dict[str, tuple[str, ...]],
            spec: str, observed: object) -> dict[str, str]:
    """Attest local navigation only; CI/production remain UNKNOWN."""
    result = {"code": UNKNOWN, "ci": UNKNOWN, "production": UNKNOWN}
    if identifier not in LABELS or not isinstance(observed, dict):
        return result
    row = table.get(identifier)
    if not row or len(row) != 5:
        return result
    route, label, summary = LABELS[identifier]
    if (not row[0].startswith(identifier + " · [S24 L")
            or row[1] != summary or row[2] != "NO_VERIFICABLE"
            or "Sin aserción contrastada" not in row[3]
            or "falta enlazar prueba" not in row[3]
            or row[4] != "—"):
        return result
    lines = spec.splitlines()
    lineno = int(identifier.partition("L")[2])
    if len(lines) < lineno or lines[lineno - 1].strip() != "- " + summary:
        return result
    proof = observed.get(route)
    if not isinstance(proof, dict) or proof.get("section") != route or proof.get("renders") != 1:
        return result
    nav = proof.get("nav")
    if not isinstance(nav, dict) or not isinstance(nav.get("html"), str):
        return result
    items = nav.get("items")
    expected_items = [
        ["dashboard", "DASHBOARD"], ["hero-slider", "BANNERS"],
        ["events", "EVENTS"], ["artists", "ARTISTS"],
        ["releases", "RELEASES"], ["sets", "SETS"],
        ["media", "MEDIA"], ["pages", "PAGES"], ["blog", "BLOG"],
    ]
    if items != expected_items:
        return result
    buttons = re.findall(
        r'''<button class="([^"]*)" data-admin-nav="([^"]+)" onclick="go\('([a-z-]+)'\)">([^<]+)</button>''',
        nav["html"],
    )
    if len(buttons) != len(expected_items) or len({b[1] for b in buttons}) != len(buttons):
        return result
    if any((key, target, text, active) != (item[0], item[0], item[1],
                                             "active" if item[0] == route else "")
           for (active, key, target, text), item in zip(buttons, expected_items)):
        return result
    expected_requests = (["/" + route + "?page=1&page_size=50"]
                         if route in {"sets", "media", "pages"} else [])
    if proof.get("requests") != expected_requests:
        return result
    result["code"] = LOCAL
    return result


class BrechasBrvtal28Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        table = matrix_rows(MATRIX.read_text(encoding="utf-8"))
        spec, observed = SPEC.read_text(encoding="utf-8"), observe()
        self.assertEqual(set(IDS), set(table))
        self.assertEqual(set(route for route, _, _ in LABELS.values()), set(observed))
        self.assertIn("## 24. DISCADMIN architecture", spec)
        for identifier in IDS:
            with self.subTest(brecha=identifier):
                self.assertEqual(LOCAL, verdict(identifier, table, spec, observed)["code"])

    def test_falla_cerrado(self) -> None:
        raw = MATRIX.read_text(encoding="utf-8")
        table, spec, observed = matrix_rows(raw), SPEC.read_text(encoding="utf-8"), observe()
        line = next(row for row in raw.splitlines() if row.startswith("| S24-L378 · "))
        with self.assertRaisesRegex(ValueError, "UNKNOWN: duplicate"):
            matrix_rows(raw + "\n" + line)
        for identifier in IDS:
            with self.subTest(brecha=identifier):
                self.assertEqual(UNKNOWN, verdict(identifier, {}, spec, observed)["code"])
                self.assertEqual(UNKNOWN, verdict(identifier, table, "", observed)["code"])
                self.assertEqual(UNKNOWN, verdict(identifier, table, spec, None)["code"])
                invalid = dict(table)
                invalid[identifier] = (*table[identifier][:2], "CUBIERTO", *table[identifier][3:])
                self.assertEqual(UNKNOWN, verdict(identifier, invalid, spec, observed)["code"])
                corrupted = copy.deepcopy(observed)
                corrupted[LABELS[identifier][0]]["nav"]["html"] = ""
                self.assertEqual(UNKNOWN, verdict(identifier, table, spec, corrupted)["code"])
        self.assertEqual(UNKNOWN, verdict("S24-L999", table, spec, observed)["code"])

    def test_sin_datos_reales(self) -> None:
        own = Path(__file__).read_text(encoding="utf-8")
        self.assertIn("vm.runInNewContext", PROBE)
        self.assertIn("data-admin-nav", SHELL.read_text(encoding="utf-8"))
        self.assertNotIn("require('node:https')", PROBE)
        self.assertNotIn("require('node:net')", PROBE)
        self.assertNotIn("fetch(", PROBE)
        self.assertNotIn("curl_exec(", PROBE)
        self.assertNotRegex(own, r"(?m)^(?:from|import)\s+(?:requests|httpx|socket|urllib)\b")
        self.assertNotIn("BEGIN " + "PRIVATE KEY", own)
        for route, result in observe().items():
            expected = (["/" + route + "?page=1&page_size=50"]
                        if route in ("sets", "media", "pages") else [])
            self.assertEqual(expected, result["requests"])
            self.assertFalse(any(url.startswith(("http:", "https:"))
                                 for url in result["requests"]))

    def test_estados_unknown_explicitos(self) -> None:
        table = matrix_rows(MATRIX.read_text(encoding="utf-8"))
        spec, observed = SPEC.read_text(encoding="utf-8"), observe()
        for identifier in IDS:
            with self.subTest(brecha=identifier):
                result = verdict(identifier, table, spec, observed)
                self.assertEqual(LOCAL, result["code"])
                self.assertEqual(UNKNOWN, result["ci"])
                self.assertEqual(UNKNOWN, result["production"])
                altered = copy.deepcopy(observed)
                altered[LABELS[identifier][0]]["requests"].append("/synthetic/unexpected")
                self.assertEqual(UNKNOWN, verdict(identifier, table, spec, altered)["code"])
                missing = copy.deepcopy(observed)
                del missing[LABELS[identifier][0]]
                self.assertEqual(UNKNOWN, verdict(identifier, table, spec, missing)["code"])


if __name__ == "__main__":
    unittest.main()
