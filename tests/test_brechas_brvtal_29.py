#!/usr/bin/env python3
"""BRVTAL #1119: isolated contracts for five §24/§26 coverage gaps.

A local fixture/checked-in source contract is not proof of live DISCADMIN,
current production schema, authenticated smoke, or GitHub exact-HEAD CI.
"""
from __future__ import annotations

import re
import shutil
import subprocess
import unittest
from functools import lru_cache
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MATRIX = "docs/matriz-cobertura.md"
SPEC = "docs/BRVTAL-SPEC.md"
SHELL = "discadmin/index-core.php"
NAV = "discadmin/admin-shell.js"
MODULES = "discadmin/admin-modules.js"
SETTINGS = "discadmin/settings-v2.js"
SECURITY = "discadmin/security.js"
TOTP_API = "discadmin/totp-api.php"
AUTH = "config/admin_auth.php"
SQL = "database/migration_totp_foundation.sql"
CORE = "config/totp.php"
CORE_API = "api/index.php"
TOTP_CONTRACT = "tests/totp-enrollment-contract.php"
SETTINGS_CONTRACT = "tests/settings-control-plane-contract.php"
FILES = (MATRIX, SPEC, SHELL, NAV, MODULES, SETTINGS, SECURITY, TOTP_API,
         AUTH, SQL, CORE, CORE_API, TOTP_CONTRACT, SETTINGS_CONTRACT)
IDS = ("S24-L385", "S26-L427", "S26-R001", "S26-R003", "S26-R004")
CLAUSES = {
    "S24-L385": ("24. DISCADMIN architecture", "Settings;"),
    "S26-L427": ("26. Security / 2FA", "CSRF;"),
    "S26-R001": ("26. Security / 2FA",
                 "Security / 2FA is an internal DISCADMIN module sharing the same shell, sidebar, session and workspace."),
    "S26-R003": ("26. Security / 2FA", "Current persistence foundation includes:"),
    "S26-R004": ("26. Security / 2FA", "admins.totp_enabled;"),
}
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_CONTRACT"


def rows(document: object) -> dict[str, tuple[str, ...]]:
    """Never silently drop a missing, duplicate, promoted, or malformed row."""
    if not isinstance(document, str):
        raise ValueError("UNKNOWN: no matrix")
    result: dict[str, tuple[str, ...]] = {}
    for line in document.splitlines():
        match = re.match(r"^\| (S\d\d-[LR]\d{3}) · ", line)
        if match is None or match.group(1) not in IDS:
            continue
        ident = match.group(1)
        cells = tuple(x.strip() for x in line.strip("|").split("|"))
        if ident in result or len(cells) != 5 or not all(cells[:4]):
            raise ValueError("UNKNOWN: duplicate/incomplete " + ident)
        if not cells[0].startswith(ident + " · "):
            raise ValueError("UNKNOWN: invalid anchor " + ident)
        result[ident] = cells
    if set(result) != set(IDS):
        raise ValueError("UNKNOWN: missing matrix row")
    return result


def section(document: object, name: str) -> str:
    if not isinstance(document, str):
        return ""
    header = "## " + name + "\n"
    if document.count(header) != 1:
        return ""
    return document.split(header, 1)[1].split("\n## ", 1)[0]


def read_sources() -> dict[str, str]:
    return {name: (ROOT / name).read_text(encoding="utf-8") for name in FILES}


def source_verdict(ident: object, files: object) -> str:
    """Never translate prose or source presence into a deployed assertion."""
    if ident not in IDS or not isinstance(files, dict):
        return UNKNOWN
    if any(not isinstance(files.get(p), str) or not files[p] for p in FILES):
        return UNKNOWN
    try:
        row = rows(files[MATRIX])[ident]
    except (ValueError, KeyError):
        return UNKNOWN
    section_name, clause = CLAUSES[ident]
    # The specification formats the SQL column as inline code.
    spec_clause = "`admins.totp_enabled`;" if ident == "S26-R004" else clause
    block = section(files[SPEC], section_name)
    if (row[1] != clause or row[2] != "NO_VERIFICABLE"
            or "Sin aserción contrastada" not in row[3]
            or clause not in row[3] or block.count(spec_clause) != 1):
        return UNKNOWN
    shell, nav = files[SHELL], files[NAV]
    if ident == "S24-L385":
        good = ("data-admin-nav=\"settings\"" in shell
                and "onclick=\"go('settings')\"" in shell
                and "s==='settings'" in shell
                and '<div class="shell">' in shell
                and "document.querySelector('#app .shell')" in nav)
    elif ident == "S26-R001":
        good = ("s==='content-core'||s==='security'" in shell
                and "BRVTALAdminModules.load(s)" in shell
                and 'id="admin-module-host"' in shell
                and "SECURITY / 2FA" in shell
                and "The canonical shell owns navigation" in files[MODULES]
                and "data-settings-security-host" in files[SETTINGS]
                and "section.dataset.settingsEmbeddedSecurity = '1'" in files[SECURITY])
    elif ident == "S26-L427":
        api, auth, security = files[TOTP_API], files[AUTH], files[SECURITY]
        post = api.find("REQUEST_METHOD")
        guard = api.find("brvtal_admin_require_csrf();")
        mutation = api.find("if($action==='confirm')")
        good = (post >= 0 and guard > post and mutation > guard
                and "brvtal_admin_require();" in api[:guard]
                and "function brvtal_admin_require_csrf(" in auth
                and "hash_equals($expected, $token)" in auth
                and "http_response_code(419)" in auth
                and "'error' => 'CSRF'" in auth
                and "'X-CSRF-Token':csrf" in security)
    elif ident == "S26-R003":
        sql, core, api, settings = (files[SQL], files[CORE],
                                    files[CORE_API], files[SETTINGS])
        good = (all(token in sql for token in
                    ("ALTER TABLE admins", "totp_enabled TINYINT(1)",
                     "totp_secret_enc TEXT", "totp_confirmed_at DATETIME",
                     "CREATE TABLE IF NOT EXISTS admin_recovery_codes",
                     "code_hash VARCHAR(255)"))
                and "setting_key='security.totp_encryption_key'" in core
                and "setting_key<>'security.totp_encryption_key'" in api
                and "PROTECTED_SETTING" in api
                and "data-settings-security-host" in settings)
    else:  # S26-R004
        sql, api = files[SQL], files[TOTP_API]
        good = ("totp_enabled TINYINT(1) NOT NULL DEFAULT 0" in sql
                and "SELECT id,is_active,totp_enabled FROM admins WHERE id=? FOR UPDATE" in api
                and "UPDATE admins SET totp_enabled=1" in api
                and "WHERE id=? AND totp_enabled=0" in api
                and "UPDATE admins SET totp_enabled=0" in api
                and "brvtal_totp_rate_limit_scope($id,'disable')" in api
                and "'ALREADY_ENABLED'" in api)
    return LOCAL if good else UNKNOWN


def production_attestation(_ident: object, _evidence: object = None) -> str:
    """A local contract cannot prove production DB, deploy or exact GitHub CI."""
    return UNKNOWN


# The checked-in navigation helper is executed as-is in an isolated Node VM.
# No browser, storage, network, identity, or production application is loaded.
SHELL_PROBE = r"""
'use strict';
const fs=require('node:fs'),vm=require('node:vm'),assert=require('node:assert/strict');
const listeners={},marks=new Set(),body=new Set();
const cls=set=>({contains:x=>set.has(x),toggle(x,on){
  if(on)set.add(x);else set.delete(x);
}});
let expanded='',restored=0,navFocus=0,net=0;
const menu={setAttribute(k,v){if(k==='aria-expanded')expanded=v;},
  focus(){restored++;}};
const target={focus(){navFocus++;}};
const shell={classList:cls(marks),querySelector(k){
  if(k==='.admin-menu-toggle')return menu;
  if(k==='.nav .active'||k==='.nav button')return target;
  return null;
}};
const document={body:{classList:cls(body)},querySelector(k){
  if(k==='#app .shell')return shell;return null;
},addEventListener(k,fn){listeners[k]=fn;}};
const context={document,window:{},matchMedia(){return{
  matches:true,addEventListener(k,fn){listeners.media=fn;}};},
  fetch(){net++;throw Error('NETWORK_FORBIDDEN');}};
vm.runInNewContext(fs.readFileSync('discadmin/admin-shell.js','utf8'),context,
                   {timeout:1500});
context.window.toggleAdminNav();
assert.equal(marks.has('admin-nav-open'),true);
assert.equal(body.has('admin-nav-open'),true);
assert.equal(expanded,'true');
assert.equal(navFocus,1);
let stopped=0;
listeners.keydown({key:'Escape',preventDefault(){stopped++;}});
assert.equal(stopped,1);assert.equal(marks.has('admin-nav-open'),false);
assert.equal(body.has('admin-nav-open'),false);assert.equal(expanded,'false');
assert.equal(restored,1);
context.window.toggleAdminNav();
listeners.click({target:{closest(k){return k.includes('.side .nav button');}}});
assert.equal(marks.has('admin-nav-open'),false);
assert.equal(restored,1);assert.equal(net,0);
console.log('BR1119-SHELL-OFFLINE-OK');
"""


@lru_cache(maxsize=1)
def run_contracts() -> tuple[str, ...]:
    php, node = shutil.which("php"), shutil.which("node")
    if not php or not node:
        raise AssertionError("UNKNOWN: PHP/Node runner unavailable")
    commands = (
        ([php, TOTP_CONTRACT], "BRVTAL TOTP enrollment contract tests passed."),
        ([php, SETTINGS_CONTRACT], ""),
        ([node, "-e", SHELL_PROBE], "BR1119-SHELL-OFFLINE-OK"),
    )
    results = []
    for argv, marker in commands:
        result = subprocess.run(argv, cwd=ROOT, capture_output=True, text=True,
                                timeout=25, check=False,
                                env={"PATH": "/usr/local/bin:/usr/bin:/bin"})
        message = (result.stdout + result.stderr)[-1500:]
        if result.returncode or (marker and marker not in result.stdout):
            raise AssertionError("UNKNOWN: isolated contract failed: " + message)
        results.append(result.stdout)
    return tuple(results)


class BrechasBrvtal29Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.files = read_sources()

    def test_contrato_principal(self) -> None:
        """AC-01: five exact rows with meaningful source and runtime probes."""
        self.assertEqual(set(IDS), set(rows(self.files[MATRIX])))
        for ident in IDS:
            with self.subTest(clause=ident):
                self.assertEqual(source_verdict(ident, self.files), LOCAL)
        self.assertEqual(len(run_contracts()), 3)

    def test_falla_cerrado(self) -> None:
        """AC-02: missing/ambiguous provenance and guard drift yield UNKNOWN."""
        for ident in IDS:
            with self.subTest(clause=ident):
                for name in (MATRIX, SPEC):
                    mutated = dict(self.files)
                    mutated[name] = ""
                    self.assertEqual(source_verdict(ident, mutated), UNKNOWN)
                line = next(x for x in self.files[MATRIX].splitlines()
                            if x.startswith("| " + ident + " · "))
                for new_matrix in (
                    self.files[MATRIX].replace(line + "\n", "", 1),
                    self.files[MATRIX] + "\n" + line,
                    self.files[MATRIX].replace(line, line.replace(
                        "NO_VERIFICABLE", "CUBIERTO", 1), 1),
                ):
                    mutated = dict(self.files)
                    mutated[MATRIX] = new_matrix
                    self.assertEqual(source_verdict(ident, mutated), UNKNOWN)
                block = section(self.files[SPEC], CLAUSES[ident][0])
                spec_clause = ("`admins.totp_enabled`;" if ident == "S26-R004"
                               else CLAUSES[ident][1])
                self.assertIn(spec_clause, block)
                mutated = dict(self.files)
                mutated[SPEC] = mutated[SPEC].replace(
                    spec_clause, "MISSING_CONTRACT", 1)
                self.assertEqual(source_verdict(ident, mutated), UNKNOWN)
                mutated = dict(self.files)
                trace = (TOTP_API if ident in ("S26-L427", "S26-R004")
                         else SHELL if ident in ("S24-L385", "S26-R001")
                         else SQL)
                mutated[trace] = ""
                self.assertEqual(source_verdict(ident, mutated), UNKNOWN)
        self.assertEqual(source_verdict("S26-R999", self.files), UNKNOWN)
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            rows(self.files[MATRIX] + "\n" + next(
                x for x in self.files[MATRIX].splitlines()
                if x.startswith("| S24-L385 · ")))

    def test_sin_datos_reales(self) -> None:
        """AC-03: only versioned code and in-memory UI synthetic fixtures."""
        own = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(own, r"(?m)^(?:import|from)\s+(?:requests|httpx|socket|urllib)\b")
        self.assertNotRegex(own, r"-----BEGIN (?:RSA |OPENSSH )?PRIVATE KEY-----")
        self.assertNotRegex(own, r"[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}")
        self.assertEqual(set(FILES), set(self.files))
        self.assertTrue(all((ROOT / name).is_file() for name in FILES))
        self.assertIn("NETWORK_FORBIDDEN", SHELL_PROBE)
        self.assertEqual(production_attestation("S26-R003"), UNKNOWN)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: source smoke cannot certify prod, CI or 2FA enrollment."""
        for ident in IDS:
            with self.subTest(clause=ident):
                self.assertEqual(production_attestation(ident, self.files), UNKNOWN)
                mutated = dict(self.files)
                mutated[CORE] = ""
                self.assertEqual(source_verdict(ident, mutated), UNKNOWN)
                self.assertEqual(source_verdict(ident, self.files), LOCAL)


if __name__ == "__main__":
    unittest.main()
