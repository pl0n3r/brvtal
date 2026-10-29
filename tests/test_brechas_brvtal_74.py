#!/usr/bin/env python3
"""BRVTAL #1164: SPEC S49 change-traceability, synthetic fail-closed witnesses.

This file tests a synthetic change record and *real checked-in* PR/CI caller
contracts. Neither a fabricated PR nor a source string proves actual GitHub
branch protection, historical decisions, squash history or live readiness.
"""
from __future__ import annotations

import ast
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_CONTRACT_ONLY"
GAP = "BRECHA_DOCUMENTADA"
CLAUSES = {
    "S49-R001": (732, "Keep Git history coherent, descriptive and reasonably reversible."),
    "S49-R002": (732, "Avoid accidental giant commits that mix unrelated problems."),
    "S49-R003": (734, "When a product decision supersedes an older decision, update this specification so contradictory requirements do not remain active."),
    "S49-R004": (736, "Functional changes should normally use a dedicated branch/PR and squash merge after CI."),
    "S49-R005": (736, "Direct functional changes to main are not the normal workflow."),
}
FILES = {
    "matrix": "docs/matriz-cobertura.md",
    "spec": "docs/BRVTAL-SPEC.md",
    "contrast": "docs/contraste-spec_resto.md",
    "agents": "AGENTS.md",
    "ci": ".github/workflows/factory-ci.yml",
    "coord": ".github/workflows/work-coordination.yml",
    "release": ".github/workflows/factory-release.yml",
    "release_contract": "tests/factory-release-contract.php",
}


def sources() -> dict[str, str]:
    return {key: (ROOT / path).read_text(encoding="utf-8")
            for key, path in FILES.items()}


def matrix_rows(raw: object) -> dict[str, tuple[str, ...]]:
    """The five canonical rows must remain uniquely NO_VERIFICABLE."""
    if type(raw) is not str or not raw.strip():
        raise ValueError(UNKNOWN + ": missing matrix")
    found: dict[str, tuple[str, ...]] = {}
    for line in raw.splitlines():
        match = re.match(r"^\| (S49-R00[1-5]) · ", line)
        if not match:
            continue
        key = match.group(1)
        number, phrase = CLAUSES[key]
        cells = tuple(part.strip() for part in line.strip("|").split("|"))
        if (key in found or len(cells) != 5 or cells[1] != phrase
                or f"[S49 L{number}]" not in cells[0]
                or f"BRVTAL-SPEC.md#L{number})" not in cells[0]
                or cells[2] != "NO_VERIFICABLE"
                or "Sin aserción contrastada" not in cells[3]
                or "falta enlazar prueba de ese inciso, archivo de código y PR fusionado" not in cells[3]
                or cells[4] != "—"):
            raise ValueError(UNKNOWN + ": ambiguous row " + key)
        found[key] = cells
    if set(found) != set(CLAUSES):
        raise ValueError(UNKNOWN + ": missing/duplicate S49 rows")
    return found


def contrast_rows(raw: object) -> dict[str, tuple[str, ...]]:
    if type(raw) is not str or not raw.strip():
        raise ValueError(UNKNOWN + ": missing contrast")
    found = {}
    for line in raw.splitlines():
        match = re.match(r"^\| (S49-R00[1-5]) \|", line)
        if not match:
            continue
        key = match.group(1)
        cells = tuple(part.strip() for part in line.strip("|").split("|"))
        if (key in found or len(cells) != 6
                or cells[1] != "NO_VERIFICABLE"
                or "no una promesa documental" not in cells[5]
                or "falta aserción específica y PR fusionado" not in cells[5]):
            raise ValueError(UNKNOWN + ": ambiguous contrast " + key)
        found[key] = cells
    if set(found) != set(CLAUSES):
        raise ValueError(UNKNOWN + ": incomplete S49 contrast")
    return found


def spec_clause(raw: object, key: object) -> bool:
    if type(raw) is not str or type(key) is not str or key not in CLAUSES:
        return False
    section = "## 49. Change traceability\n"
    if raw.count(section) != 1:
        return False
    segment = raw.split(section, 1)[1].split("\n## ", 1)[0]
    return segment.replace("`", "").count(CLAUSES[key][1]) == 1


def yaml_event_block(raw: object, event: str) -> tuple[str, ...] | None:
    """Read one event inside the top-level 'on:' mapping, excluding comments.

    This is deliberately a small subset for the checked-in simple workflow
    format, not a general-purpose YAML parser. Ambiguity fails closed.
    """
    if type(raw) is not str or type(event) is not str or not event:
        return None
    lines = [line.rstrip() for line in raw.splitlines()
             if line.strip() and not line.lstrip().startswith("#")]
    roots = [i for i, line in enumerate(lines) if line == "on:"]
    if len(roots) != 1:
        return None
    start = roots[0] + 1
    finish = next((i for i in range(start, len(lines))
                   if not lines[i].startswith(" ")), len(lines))
    section = lines[start:finish]
    events = [i for i, line in enumerate(section)
              if line == "  " + event + ":"]
    if len(events) != 1:
        return None
    begin = events[0] + 1
    end = next((i for i in range(begin, len(section))
                if section[i].startswith("  ")
                and not section[i].startswith("    ")), len(section))
    return tuple(section[begin:end])


def workflow_contract(data: object) -> bool:
    """Structural witness of checked-in PR CI; not a GitHub protection read."""
    if type(data) is not dict or set(data) != set(FILES):
        return False
    if any(type(data[k]) is not str or not data[k] for k in FILES):
        return False
    ci, coord = data["ci"], data["coord"]
    release, agent, release_test = (
        data["release"], data["agents"], data["release_contract"])
    ci_event = yaml_event_block(ci, "pull_request")
    coordination_event = yaml_event_block(coord, "pull_request")
    release_event = yaml_event_block(release, "push")
    return (
        ci_event is not None and "    branches: [main]" in ci_event
        and "uses: pl0n3r/factory/.github/workflows/ci.yml@v1" in ci
        and coordination_event is not None
        and "github.event.pull_request.head.repo.full_name == github.repository" in coord
        and "uses: pl0n3r/factory/.github/workflows/coordinacion.yml@v1" in coord
        and "Merges to `main` are always serialized." in agent
        and "Squash merge." in agent
        and release_event is not None and "    branches: [main]" in release_event
        and "    paths:" in release_event and "      - config/version.php" in release_event
        and "branches: [main]" in release_test
        and "release caller must run only for config/version.php changes on main" in release_test
    )


def fixture() -> dict[str, object]:
    """Simulated, complete PR evidence; never a representation of GitHub."""
    sha = "a" * 40
    return {
        "message": "test(cobertura): enforce one S49 test contract",
        "parent_sha": "b" * 40,
        "revertable": True,
        "files": ["tests/test_brechas_brvtal_74.py"],
        "authorized_paths": ["tests/test_brechas_brvtal_74.py"],
        "categories": ["tests"],
        "decision_old": {"id": "D-OLD", "status": "superseded"},
        "decision_new": {"id": "D-NEW", "status": "active"},
        "spec_updated": True,
        "contradictions": [],
        "functional": True,
        "branch": "work/issue-1164",
        "pr": 100,
        "head_sha": sha,
        "ci_sha": sha,
        "ci_conclusion": "success",
        "merge_method": "squash",
        "direct_main_push": False,
    }


def synthetic_witness(key: object, record: object) -> str:
    """Fail closed on any required fact missing or forged by a fixture.

    LOCAL here means only that this *test model* accepted synthetic input.
    It is not a determination that the actual repository followed the rule.
    """
    if type(key) is not str or key not in CLAUSES or type(record) is not dict:
        return UNKNOWN
    expected = set(fixture())
    if set(record) != expected or any(v is None for v in record.values()):
        return UNKNOWN
    msg = record["message"]
    if key == "S49-R001":
        good = (type(msg) is str and
                re.fullmatch(r"[a-z]+\([a-z0-9_-]+\): [A-Za-z0-9].{9,}", msg) is not None
                and type(record["parent_sha"]) is str
                and re.fullmatch(r"[0-9a-f]{40}", record["parent_sha"]) is not None
                and record["revertable"] is True)
    elif key == "S49-R002":
        # "Unrelated" is modeled by claimed file scope, NOT a universal
        # numeric commit-size threshold invented by this test.
        files, allowed, categories = (record["files"],
                                      record["authorized_paths"],
                                      record["categories"])
        good = (type(files) is list and type(allowed) is list
                and type(categories) is list and bool(files)
                and len(files) == len(set(files))
                and all(type(p) is str and p for p in files)
                and set(files) == set(allowed)
                and categories == ["tests"])
    elif key == "S49-R003":
        old, new = record["decision_old"], record["decision_new"]
        good = (type(old) is dict and type(new) is dict
                and set(old) == {"id", "status"}
                and set(new) == {"id", "status"}
                and old["id"] != new["id"]
                and old["status"] == "superseded"
                and new["status"] == "active"
                and record["spec_updated"] is True
                and record["contradictions"] == [])
    elif key == "S49-R004":
        good = (record["functional"] is True
                and type(record["branch"]) is str
                and record["branch"].startswith("work/issue-")
                and record["branch"] != "main"
                and type(record["pr"]) is int and record["pr"] > 0
                and type(record["head_sha"]) is str
                and re.fullmatch(r"[0-9a-f]{40}", record["head_sha"]) is not None
                and record["head_sha"] == record["ci_sha"]
                and record["ci_conclusion"] == "success"
                and record["merge_method"] == "squash")
    else:
        good = (record["functional"] is True
                and record["branch"] != "main"
                and record["direct_main_push"] is False
                and type(record["pr"]) is int and record["pr"] > 0)
    return LOCAL if good else UNKNOWN


def documented_gap(key: object, data: object, observed: object) -> str:
    """Classify local contracts vs absent *actual* history evidence."""
    if (type(key) is not str or key not in CLAUSES
            or type(data) is not dict or set(data) != set(FILES)
            or any(type(s) is not str or not s for s in data.values())
            or type(observed) is not dict or set(observed) != set(CLAUSES)):
        return UNKNOWN
    try:
        matrix_rows(data["matrix"])
        contrast_rows(data["contrast"])
    except ValueError:
        return UNKNOWN
    if not spec_clause(data["spec"], key):
        return UNKNOWN
    if any(observed[k] != LOCAL for k in CLAUSES):
        return UNKNOWN
    if key == "S49-R004":
        return LOCAL if workflow_contract(data) else UNKNOWN
    # No authenticated complete GitHub history, decision audit or branch
    # protection is present in the offline fixture. Refuse promotion.
    return GAP if workflow_contract(data) else UNKNOWN


def operational(_key: object, _evidence: object = None) -> str:
    return UNKNOWN


class BrechasBrvtal74Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.data = sources()
        cls.probe = {key: synthetic_witness(key, fixture()) for key in CLAUSES}

    def test_contrato_principal(self) -> None:
        self.assertEqual(set(CLAUSES), set(matrix_rows(self.data["matrix"])))
        self.assertEqual(set(CLAUSES), set(contrast_rows(self.data["contrast"])))
        self.assertEqual({LOCAL}, set(self.probe.values()))
        self.assertTrue(workflow_contract(self.data))
        for key in CLAUSES:
            with self.subTest(clause=key):
                self.assertTrue(spec_clause(self.data["spec"], key))
                expected = LOCAL if key == "S49-R004" else GAP
                self.assertEqual(expected, documented_gap(key, self.data, self.probe))
                self.assertEqual(UNKNOWN, operational(key, self.probe))

    def test_falla_cerrado(self) -> None:
        for key in CLAUSES:
            line = next(row for row in self.data["matrix"].splitlines()
                        if row.startswith("| " + key + " · "))
            for bad in (
                self.data["matrix"].replace(line, "", 1),
                self.data["matrix"] + "\n" + line,
                self.data["matrix"].replace(
                    line, line.replace("NO_VERIFICABLE", "CUBIERTO"), 1),
            ):
                with self.subTest(clause=key), self.assertRaisesRegex(
                        ValueError, UNKNOWN):
                    matrix_rows(bad)
                self.assertEqual(UNKNOWN, documented_gap(
                    key, {**self.data, "matrix": bad}, self.probe))
            self.assertEqual(UNKNOWN, documented_gap(
                key, {**self.data, "spec": ""}, self.probe))
            self.assertEqual(UNKNOWN, documented_gap(
                key, {**self.data, "ci": ""}, self.probe))
            self.assertEqual(UNKNOWN, documented_gap(key, self.data, {}))
        # Each required source must remain a nonempty, authentic marker.
        for name, marker in (
            ("ci", "    branches: [main]"),
            ("coord", "  pull_request:"),
            ("agents", "Squash merge."),
            ("release", "      - config/version.php"),
            ("release_contract",
             "release caller must run only for config/version.php changes on main"),
        ):
            broken = {**self.data, name: self.data[name].replace(marker, "INVALID", 1)}
            self.assertNotEqual(self.data[name], broken[name])
            self.assertFalse(workflow_contract(broken), name)
            self.assertEqual(UNKNOWN, documented_gap(
                "S49-R004", broken, self.probe), name)

        # A commented-out event or a branch nested under a wrong event
        # cannot certify either PR CI or version-only release.
        for name, event in (("ci", "pull_request"), ("release", "push")):
            commented = {**self.data, name: self.data[name].replace(
                "  " + event + ":", "  # " + event + ":", 1)}
            self.assertFalse(workflow_contract(commented), name)
            wrong = {**self.data, name: self.data[name].replace(
                "    branches: [main]", "    branches: [feature]", 1)}
            self.assertFalse(workflow_contract(wrong), name)

        contrast = self.data["contrast"]
        row = next(x for x in contrast.splitlines()
                   if x.startswith("| S49-R001 |"))
        for corrupt in (
            contrast + "\n" + row,
            contrast.replace(row, row.replace("NO_VERIFICABLE", "CUBIERTO", 1), 1),
            contrast.replace(row, row.replace(
                "no una promesa documental", "sin validación documental", 1), 1),
        ):
            with self.assertRaisesRegex(ValueError, UNKNOWN):
                contrast_rows(corrupt)
        self.assertEqual(UNKNOWN, synthetic_witness(
            "S49-R001", {**fixture(), "message": None}))
        changes = {
            "S49-R001": {"message": "fix"},
            "S49-R002": {"files": ["tests/test_brechas_brvtal_74.py",
                                   "config/version.php"]},
            "S49-R003": {"contradictions": ["two active interpretations"]},
            "S49-R004": {"ci_sha": "b" * 40},
            "S49-R005": {"direct_main_push": True},
        }
        for key, patch in changes.items():
            self.assertEqual(UNKNOWN, synthetic_witness(
                key, {**fixture(), **patch}), key)
            partial = fixture()
            partial.pop(next(iter(partial)))
            self.assertEqual(UNKNOWN, synthetic_witness(key, partial))
        self.assertEqual(UNKNOWN, documented_gap("S49-R999", self.data, self.probe))

    def test_sin_datos_reales(self) -> None:
        source = Path(__file__).read_text(encoding="utf-8")
        syntax = ast.parse(source)
        imported = {alias.name.split(".")[0] for node in ast.walk(syntax)
                    if isinstance(node, ast.Import) for alias in node.names}
        imported |= {node.module.split(".")[0] for node in ast.walk(syntax)
                     if isinstance(node, ast.ImportFrom) and node.module}
        self.assertFalse(imported & {
            "requests", "httpx", "socket", "urllib", "subprocess", "playwright",
            "selenium", "pymysql", "mysql",
        })
        self.assertNotIn("BEGIN " + "PRIVATE KEY", source)
        self.assertEqual(set(FILES), set(self.data))
        self.assertTrue(all(not Path(p).is_absolute() for p in FILES.values()))

    def test_estados_unknown_explicitos(self) -> None:
        for key in CLAUSES:
            self.assertEqual("NO_VERIFICABLE",
                             matrix_rows(self.data["matrix"])[key][2])
            for invented in (None, True, {}, {"CI": "success"},
                             {"merge": "squash", "protection": "green"},
                             {"production": "healthy"}):
                self.assertEqual(UNKNOWN, operational(key, invented))
        self.assertEqual(GAP, documented_gap(
            "S49-R003", self.data, self.probe))
        self.assertEqual(GAP, documented_gap(
            "S49-R005", self.data, self.probe))
        self.assertEqual(UNKNOWN, synthetic_witness(None, fixture()))


if __name__ == "__main__":
    unittest.main()
