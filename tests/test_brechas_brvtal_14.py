#!/usr/bin/env python3
"""BRVTAL #1104: five §12 artist/collective gaps, synthetic and fail-closed.

Local contract evidence is never full workflow acceptance, CI, persistence,
a deployed build, migration execution or production observation.
"""
from __future__ import annotations

import ast
import json
import shutil
import subprocess
import unittest
from functools import lru_cache
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MATRIX = "docs/matriz-cobertura.md"
SPEC = "docs/BRVTAL-SPEC.md"
CONTRAST = "docs/contraste-spec_resto.md"
MEMBERSHIP = "config/artist_collective_membership.php"
MIGRATION = "database/migration_artist_collective_membership_01.sql"
CONTRACT = "tests/artist-collective-lifecycle-contract.php"
API = "api/index.php"
ADMIN = "discadmin/index-core.php"
MODULES = "discadmin/admin-modules.js"
SOURCES = (MATRIX, SPEC, CONTRAST, MEMBERSHIP, MIGRATION, CONTRACT, API, ADMIN, MODULES)
UNKNOWN = "UNKNOWN"
PARTIAL = "LOCAL_PARTIAL"
GAPS = {
    "S12-R002": ("Most fields should remain optional.", "172"),
    "S12-R003": ("Artists without images use a BRVTAL-consistent anonymous/incognito fallback rather than broken images.", "172"),
    "S12-R005": ("Legacy collective status/order/joined/left fields are migration-era data, not current-state authority or admin controls.", "174"),
    "S12-R006": ("Existing artist_collective_history rows remain preserved as an audit trail; checkbox transitions may append/close history automatically, but history never drives current membership.", "174"),
    "S12-R007": ("During migration, legacy active maps to checked, while alumni and none map to unchecked because they are not current members.", "174"),
}
# Exact source evidence for partial guards. These are not production attestations.
MARKERS = {
    "S12-R002": (MEMBERSHIP, "brvtalArtistCollectiveNormalizePayload"),
    "S12-R003": (MODULES, "function placeholderFor(img)"),
    "S12-R005": (ADMIN, "This checkbox is the only current-membership authority"),
    "S12-R006": (MEMBERSHIP, "INSERT INTO artist_collective_history"),
    "S12-R007": (MIGRATION, "@membership_added=1 AND @legacy_status_exists=1"),
}

# Run the real, dependency-free PHP membership functions; synthetic arrays only.
PHP_PROBE = r'''
require 'config/artist_collective_membership.php';
$empty = brvtalArtistCollectiveNormalizePayload([]);
$draft = brvtalArtistCollectiveNormalizePayload(['status'=>'draft']);
$checked = brvtalArtistCollectiveNormalizePayload(['is_collective_member'=>true]);
$unchecked = brvtalArtistCollectiveNormalizePayload(['is_collective_member'=>false]);
$bad = brvtalArtistCollectiveNormalizePayload(['is_collective_member'=>'active']);
$legacy = [];
foreach (['active','alumni','none'] as $status) {
    $legacy[$status] = brvtalArtistCollectiveMembershipValue(['collective_status'=>$status]);
}
$canonical = brvtalArtistCollectiveMembershipValue([
    'is_collective_member'=>0, 'collective_status'=>'active']);
$historyIgnored = brvtalArtistCollectiveMembershipValue([
    'is_collective_member'=>0, 'collective_status'=>'active',
    'collective_joined_at'=>'2020-01-01', 'collective_left_at'=>null,
]);
$changed = brvtalArtistCollectiveStateChanged(
    ['is_collective_member'=>0], ['is_collective_member'=>1]);
$notChanged = brvtalArtistCollectiveStateChanged(
    ['is_collective_member'=>0, 'collective_status'=>'active'],
    ['is_collective_member'=>0, 'collective_status'=>'alumni']);
echo json_encode([
    'empty'=>$empty, 'draft'=>$draft, 'checked'=>$checked,
    'unchecked'=>$unchecked, 'bad'=>$bad, 'legacy'=>$legacy,
    'canonical_overrides_legacy'=>$canonical,
    'history_cannot_override'=>$historyIgnored,
    'checkbox_changed'=>$changed, 'legacy_only_not_changed'=>$notChanged,
], JSON_THROW_ON_ERROR);
'''


def sources() -> dict[str, str]:
    return {path: (ROOT / path).read_text(encoding="utf-8") for path in SOURCES}


def matrix_rows(text: object) -> dict[str, tuple[str, ...]]:
    if type(text) is not str:
        raise ValueError("UNKNOWN: unreadable matrix")
    result: dict[str, tuple[str, ...]] = {}
    for key, (expected, line) in GAPS.items():
        matches = [row for row in text.splitlines() if row.startswith(f"| {key} · ")]
        if len(matches) != 1:
            raise ValueError("UNKNOWN: absent or duplicated " + key)
        fields = tuple(x.strip() for x in matches[0].strip("|").split("|"))
        if (len(fields) != 5 or fields[1] != expected
                or f"[S12 L{line}]" not in fields[0]
                or fields[2] != "NO_VERIFICABLE"
                or f"Sin aserción contrastada para «" not in fields[3]
                or "falta enlazar prueba de ese inciso" not in fields[3]
                or fields[4] != "—"):
            raise ValueError("UNKNOWN: invalid " + key)
        result[key] = fields
    return result


def section(text: object) -> str:
    if type(text) is not str:
        return ""
    heading = "## 12. Artists"
    if text.splitlines().count(heading) != 1:
        return ""
    fragment = text.split(heading, 1)[1]
    return fragment.split("\n## ", 1)[0]


def local_guard(key: object, data: object, probe: object) -> str:
    if (type(key) is not str or key not in GAPS or type(data) is not dict
            or type(probe) is not dict or set(data) != set(SOURCES)
            or any(type(data[path]) is not str or not data[path] for path in SOURCES)):
        return UNKNOWN
    try:
        rows = matrix_rows(data[MATRIX])
    except ValueError:
        return UNKNOWN
    if (rows[key][2] != "NO_VERIFICABLE" or GAPS[key][0] not in section(data[SPEC])
            or f"| {key} | NO_VERIFICABLE |" not in data[CONTRAST]):
        return UNKNOWN
    file, marker = MARKERS[key]
    if marker not in data[file]:
        return UNKNOWN
    required = (
        "brvtalArtistCollectiveMembershipValue",
        "brvtalArtistCollectiveNormalizePayload",
        "brvtalArtistCollectiveStateChanged",
        "brvtalArtistCollectiveSyncHistory",
    )
    if not all(s in data[MEMBERSHIP] for s in required):
        return UNKNOWN
    try:
        if key == "S12-R002":
            ok = (probe["empty"] == {"payload": {}, "error": None}
                  and probe["draft"] == {"payload": {"status": "draft"}, "error": None}
                  and "field('name','Name *',r.name)" in data[ADMIN]
                  and "field('slug','Slug',r.slug)" in data[ADMIN])
        elif key == "S12-R003":
            ok = ("div.textContent = 'NO IMG'" in data[MODULES]
                  and "img.replaceWith(div)" in data[MODULES]
                  and "if (!photo) return;" in data[MODULES]
                  and "function placeholderFor(img)" in data[MODULES])
        elif key == "S12-R005":
            ok = (probe["canonical_overrides_legacy"] is False
                  and probe["bad"]["error"]["error"] == "INVALID_COLLECTIVE_MEMBERSHIP"
                  and "'is_collective_member'" in data[API]
                  and "is_collective_member:Boolean" in data[MODULES]
                  and "COLLECTIVE STATUS" not in data[ADMIN])
        elif key == "S12-R006":
            history_sql = " ".join(data[MEMBERSHIP].lower().split())
            ok = (probe["history_cannot_override"] is False
                  and probe["checkbox_changed"] is True
                  and probe["legacy_only_not_changed"] is False
                  and "update artist_collective_history" in history_sql
                  and "delete from artist_collective_history" not in history_sql
                  and "truncate table artist_collective_history" not in history_sql
                  and "truncate artist_collective_history" not in history_sql
                  and "artist_collective_history" not in data[MIGRATION].lower())
        else:
            ok = (probe["legacy"] == {"active": True, "alumni": False, "none": False}
                  and probe["checked"]["payload"]["is_collective_member"] == 1
                  and probe["unchecked"]["payload"]["is_collective_member"] == 0
                  and "collective_status='active' THEN 1 ELSE 0" in data[MIGRATION]
                  and "@membership_added=1 AND @legacy_status_exists=1" in data[MIGRATION])
    except (TypeError, KeyError):
        return UNKNOWN
    return PARTIAL if ok else UNKNOWN


def operational_state(_key: object, _evidence: object = None) -> str:
    return UNKNOWN


@lru_cache(maxsize=1)
def probe_php() -> dict[str, object]:
    php = shutil.which("php")
    if php is None:
        raise AssertionError("UNKNOWN: no PHP interpreter")
    run = subprocess.run([php, "-n", "-d", "allow_url_fopen=0", "-r", PHP_PROBE],
                         cwd=ROOT, capture_output=True, text=True, check=False, timeout=30)
    if run.returncode or run.stderr.strip():
        raise AssertionError("UNKNOWN: PHP test failed: " + run.stderr[-450:])
    try:
        result = json.loads(run.stdout)
    except ValueError as exc:
        raise AssertionError("UNKNOWN: invalid PHP output") from exc
    if type(result) is not dict:
        raise AssertionError("UNKNOWN: non-object PHP result")
    return result


class BrechasBrvtal14Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: five exact rows, independent local behavior or a known gap."""
        data, result = sources(), probe_php()
        self.assertEqual(len(GAPS), 5)
        self.assertEqual(set(matrix_rows(data[MATRIX])), set(GAPS))
        for key in GAPS:
            with self.subTest(id=key):
                self.assertEqual(local_guard(key, data, result), PARTIAL)
                self.assertEqual(operational_state(key, result), UNKNOWN)

    def test_falla_cerrado(self) -> None:
        """AC-02: absent, conflicting, promoted or forged evidence is UNKNOWN."""
        data, result = sources(), probe_php()
        for key in GAPS:
            line = next(x for x in data[MATRIX].splitlines() if x.startswith(f"| {key} · "))
            for mutant in (data[MATRIX].replace(line, ""),
                           data[MATRIX] + "\n" + line,
                           data[MATRIX].replace(line, line.replace("NO_VERIFICABLE", "CUBIERTO")),
                           data[MATRIX].replace(line, line.replace(GAPS[key][0], "FAKE COVERAGE"))):
                with self.subTest(id=key):
                    with self.assertRaisesRegex(ValueError, "UNKNOWN"):
                        matrix_rows(mutant)
                    self.assertEqual(local_guard(key, {**data, MATRIX: mutant}, result), UNKNOWN)
            file, marker = MARKERS[key]
            self.assertEqual(local_guard(key, {**data, file: data[file].replace(marker, "")}, result), UNKNOWN)
            self.assertEqual(local_guard(key, {**data, SPEC: data[SPEC].replace(GAPS[key][0], "")}, result), UNKNOWN)
            self.assertEqual(local_guard(key, data, {}), UNKNOWN)
            self.assertEqual(local_guard(key, {**data, file: ""}, result), UNKNOWN)
        for destructive_sql in (
            "delete from artist_collective_history",
            "DELETE  FROM artist_collective_history",
            "DELETE FROM\n artist_collective_history",
            "TRUNCATE TABLE artist_collective_history",
            "truncate artist_collective_history",
        ):
            with self.subTest(sql=destructive_sql):
                mutated = {**data, MEMBERSHIP: data[MEMBERSHIP] + "\n" + destructive_sql}
                self.assertEqual(local_guard("S12-R006", mutated, result), UNKNOWN)
        self.assertEqual(local_guard("S12-R006",
                                     {**data, MIGRATION: data[MIGRATION] + "\nARTIST_COLLECTIVE_HISTORY"},
                                     result), UNKNOWN)
        self.assertEqual(local_guard("S12-R999", data, result), UNKNOWN)
        self.assertEqual(local_guard("S12-R007", {}, result), UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        """AC-03: scope is read-only, synthetic and network/DB-free."""
        path = ROOT / "tests/test_brechas_brvtal_14.py"
        tree = ast.parse(path.read_text(encoding="utf-8"))
        modules: set[str] = set()
        for node in ast.walk(tree):
            if isinstance(node, ast.Import):
                modules.update(i.name.split(".")[0] for i in node.names)
            elif isinstance(node, ast.ImportFrom):
                modules.add((node.module or "").split(".")[0])
        self.assertLessEqual(modules, {"__future__", "ast", "json", "shutil", "subprocess", "unittest", "functools", "pathlib"})
        self.assertNotIn("new PDO", PHP_PROBE)
        self.assertNotIn("curl_exec", PHP_PROBE)
        self.assertNotIn("file_get_contents('http", PHP_PROBE)
        self.assertTrue(all(p.startswith(("docs/", "config/", "database/", "tests/", "api/", "discadmin/")) for p in SOURCES))
        self.assertEqual(operational_state("S12-R006", {"database_rows": 2}), UNKNOWN)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: local guard cannot certify CI, deploy, history or readiness."""
        data, observed = sources(), probe_php()
        for key in GAPS:
            with self.subTest(id=key):
                self.assertEqual(local_guard(key, data, observed), PARTIAL)
                for evidence in (None, {}, {"sha": "fake", "ci": "success"},
                                 {"production": "GREEN", "history": "preserved"},
                                 {"tenant": "synthetic", "approved": True}):
                    self.assertEqual(operational_state(key, evidence), UNKNOWN)
        self.assertEqual(local_guard("S12-R005", data, {**observed, "canonical_overrides_legacy": True}), UNKNOWN)
        self.assertEqual(local_guard("S12-R007", data, {**observed, "legacy": {"active": True, "alumni": True, "none": False}}), UNKNOWN)


if __name__ == "__main__":
    unittest.main()
