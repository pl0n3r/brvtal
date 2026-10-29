#!/usr/bin/env python3
"""BRVTAL #1165: cinco contratos de estado y roadmap, sin autoridad externa.

Una aserción local de SPEC/matriz no acredita un despliegue ni la historia real
del Issue #533. La evidencia sintética se distingue explícitamente de GitHub.
"""
from __future__ import annotations

import ast
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_SYNTHETIC_ONLY"
GAP = "BRECHA_DOCUMENTADA"
SHA = "a" * 40
CLAUSES = {
    "S50-R001": ("50", 740, "Always distinguish:"),
    "S50-R002": (
        "50", 742,
        "1. **IMPLEMENTED** — code exists; 2. **VALIDATED IN CODE** — syntax/tests/local/CI behavior passed; "
        "3. **DEPLOYED** — automatic production deployment completed; "
        "4. **VALIDATED IN PRODUCTION** — real production behavior was verified.",
    ),
    "S50-R003": ("50", 747, "Never report one status as another."),
    "S51-L754": ("51", 754, "phases;"),
    "S51-R001": ("51", 751, "GitHub Issue **#533** is the canonical BRVTAL execution roadmap."),
}
SOURCE_PATHS = {
    "spec": "docs/BRVTAL-SPEC.md",
    "matrix": "docs/matriz-cobertura.md",
    "agents": "AGENTS.md",
}
STEPS = (
    "IMPLEMENTED",
    "VALIDATED IN CODE",
    "DEPLOYED",
    "VALIDATED IN PRODUCTION",
)
EVIDENCE_KEYS = frozenset({
    "implemented", "tests_passed", "deployed", "production_validated",
    "commit", "test_commit", "deploy_commit", "production_commit",
})


def sources(root: Path = ROOT) -> dict[str, str]:
    """Only checked-in, public, local sources; no requests or production data."""
    return {name: (root / path).read_text(encoding="utf-8")
            for name, path in SOURCE_PATHS.items()}


def matrix_rows(raw: object) -> dict[str, tuple[str, ...]]:
    """Enforce unique source clauses without promoting matrix conclusions."""
    if type(raw) is not str or not raw.strip():
        raise ValueError(UNKNOWN + ": matrix missing")
    rows: dict[str, tuple[str, ...]] = {}
    for line in raw.splitlines():
        match = re.match(r"^\| (S50-R00[1-3]|S51-L754|S51-R001) · ", line)
        if match is None:
            continue
        key = match.group(1)
        section, number, phrase = CLAUSES[key]
        cells = tuple(cell.strip() for cell in line.strip("|").split("|"))
        if (key in rows or len(cells) != 5 or cells[1] != phrase
                or cells[2] != "NO_VERIFICABLE"
                or "[S" + section + " L" + str(number) + "]" not in cells[0]
                or "BRVTAL-SPEC.md#L" + str(number) + ")" not in cells[0]
                or cells[4] != "—"):
            raise ValueError(UNKNOWN + ": invalid/duplicated " + key)
        rows[key] = cells
    if set(rows) != set(CLAUSES):
        raise ValueError(UNKNOWN + ": missing S50/S51 rows")
    return rows


def spec_clause(raw: object, key: object) -> bool:
    if type(raw) is not str or type(key) is not str or key not in CLAUSES:
        return False
    section, _, phrase = CLAUSES[key]
    heading = ("## 50. Definition of status\n" if section == "50"
               else "## 51. Roadmap responsibility and separation\n")
    if raw.count(heading) != 1:
        return False
    block = raw.split(heading, 1)[1].split("\n## ", 1)[0]
    return re.sub(r"\s+", " ", block).count(phrase) == 1


def status_of(record: object) -> str:
    """Pure synthetic model, never a production assertion."""
    if type(record) is not dict or set(record) != EVIDENCE_KEYS:
        return UNKNOWN
    for key in ("implemented", "tests_passed", "deployed", "production_validated"):
        if type(record[key]) is not bool:
            return UNKNOWN
    commit = record["commit"]
    if type(commit) is not str or not re.fullmatch(r"[a-f0-9]{40}", commit):
        return UNKNOWN
    ordered = [record[key] for key in (
        "implemented", "tests_passed", "deployed", "production_validated")]
    if not ordered[0]:
        return UNKNOWN
    if any(ordered[i] and not ordered[i - 1] for i in range(1, 4)):
        return UNKNOWN
    for enabled, proof in (
        ("tests_passed", "test_commit"),
        ("deployed", "deploy_commit"),
        ("production_validated", "production_commit"),
    ):
        value = record[proof]
        if type(value) not in (str, type(None)):
            return UNKNOWN
        if record[enabled] and value != commit:
            return UNKNOWN
        if not record[enabled] and value is not None:
            return UNKNOWN
    return STEPS[sum(ordered) - 1]


def fixture(stage: int) -> dict[str, object]:
    """All identities are fake and intentionally cannot be GitHub evidence."""
    if type(stage) is not int or not 1 <= stage <= 4:
        raise ValueError(UNKNOWN + ": invalid synthetic stage")
    return {
        "implemented": True,
        "tests_passed": stage >= 2,
        "deployed": stage >= 3,
        "production_validated": stage >= 4,
        "commit": SHA,
        "test_commit": SHA if stage >= 2 else None,
        "deploy_commit": SHA if stage >= 3 else None,
        "production_commit": SHA if stage >= 4 else None,
    }


def roadmap_phases(record: object) -> str:
    """Validate fake roadmap shape only: external #533 history stays UNKNOWN."""
    if type(record) is not dict or set(record) != {"issue", "phases", "authentic"}:
        return UNKNOWN
    phases = record["phases"]
    if (type(record["issue"]) is not int or record["issue"] != 533
            or type(record["authentic"]) is not bool
            or type(phases) is not list or not phases
            or any(type(x) is not str or x not in {
                "pending", "in_progress", "completed", "blocked"}
                for x in phases)):
        return UNKNOWN
    # No local fixture can establish that issue #533 contains these states.
    return UNKNOWN if not record["authentic"] else LOCAL


def clause_evidence(key: object, bundle: object, model: object) -> str:
    """Keep source witnesses separate from live delivery/roadmap verification."""
    if type(key) is not str or key not in CLAUSES:
        return UNKNOWN
    if type(bundle) is not dict or set(bundle) != set(SOURCE_PATHS):
        return UNKNOWN
    if any(type(value) is not str or not value for value in bundle.values()):
        return UNKNOWN
    try:
        matrix_rows(bundle["matrix"])
    except ValueError:
        return UNKNOWN
    if not spec_clause(bundle["spec"], key):
        return UNKNOWN
    if key.startswith("S50-"):
        if (any(label not in bundle["agents"] for label in STEPS)
                or status_of(model) == UNKNOWN):
            return UNKNOWN
        return LOCAL
    if ("GitHub Issue **#533** is the canonical BRVTAL execution roadmap."
            not in bundle["agents"]):
        return UNKNOWN
    if key == "S51-L754":
        return (LOCAL if roadmap_phases(model) == LOCAL else UNKNOWN)
    # A checked-in reference does not authenticate GitHub's live roadmap.
    return GAP


class BrechasBrvtal75Tests(unittest.TestCase):
    def test_contrato_principal(self):
        bundle = sources()
        self.assertEqual(set(matrix_rows(bundle["matrix"])), set(CLAUSES))
        self.assertTrue(all(spec_clause(bundle["spec"], key) for key in CLAUSES))
        self.assertEqual(tuple(status_of(fixture(n)) for n in range(1, 5)),
                         STEPS)
        self.assertEqual(clause_evidence("S50-R001", bundle, fixture(1)), LOCAL)
        self.assertEqual(clause_evidence("S50-R002", bundle, fixture(2)), LOCAL)
        self.assertEqual(clause_evidence("S50-R003", bundle, fixture(4)), LOCAL)
        self.assertEqual(
            clause_evidence("S51-L754", bundle, {
                "issue": 533, "phases": ["pending", "blocked"],
                "authentic": True,
            }), LOCAL)
        self.assertEqual(clause_evidence("S51-R001", bundle, fixture(1)), GAP)

    def test_falla_cerrado(self):
        bundle = sources()
        self.assertEqual(status_of(None), UNKNOWN)
        self.assertEqual(status_of({"implemented": True}), UNKNOWN)
        self.assertEqual(status_of({**fixture(2), "test_commit": "b" * 40}),
                         UNKNOWN)
        self.assertEqual(status_of({**fixture(3), "tests_passed": False}),
                         UNKNOWN)
        self.assertEqual(status_of({**fixture(4), "production_commit": None}),
                         UNKNOWN)
        self.assertEqual(status_of({**fixture(1), "deployed": "true"}), UNKNOWN)
        self.assertEqual(status_of({**fixture(1), "commit": "not-a-sha"}),
                         UNKNOWN)
        self.assertEqual(roadmap_phases({"issue": 533, "phases": [], "authentic": True}),
                         UNKNOWN)
        with self.assertRaisesRegex(ValueError, UNKNOWN):
            matrix_rows(bundle["matrix"] + "\n" + next(
                line for line in bundle["matrix"].splitlines()
                if line.startswith("| S50-R001 · ")))
        self.assertEqual(clause_evidence("S50-R001", {
            **bundle, "spec": ""}, fixture(1)), UNKNOWN)

    def test_sin_datos_reales(self):
        self.assertEqual(set(SOURCE_PATHS), {"spec", "matrix", "agents"})
        self.assertEqual(fixture(4)["commit"], "a" * 40)
        self.assertTrue(all(fixture(4)[key] == SHA for key in (
            "test_commit", "deploy_commit", "production_commit")))
        self.assertFalse(any("secret" in x or "token" in x
                             for x in SOURCE_PATHS.values()))
        self.assertEqual(status_of({**fixture(4), "production_commit": "b" * 40}),
                         UNKNOWN)
        ast.parse((ROOT / "tests/test_brechas_brvtal_75.py").read_text(
            encoding="utf-8"))

    def test_estados_unknown_explicitos(self):
        bundle = sources()
        for key in CLAUSES:
            with self.subTest(clause=key):
                self.assertEqual(clause_evidence(key, bundle, None), UNKNOWN
                                 if key != "S51-R001" else GAP)
                self.assertEqual(clause_evidence(key, {
                    **bundle, "matrix": ""}, fixture(1)), UNKNOWN)
        self.assertEqual(roadmap_phases({
            "issue": 533, "phases": ["pending"], "authentic": False,
        }), UNKNOWN)
        self.assertEqual(roadmap_phases({
            "issue": 533, "phases": ["PRODUCTION GREEN"], "authentic": True,
        }), UNKNOWN)
        self.assertNotEqual(status_of(fixture(3)),
                            "VALIDATED IN PRODUCTION")


if __name__ == "__main__":
    unittest.main()
