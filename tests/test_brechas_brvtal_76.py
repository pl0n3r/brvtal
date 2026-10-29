#!/usr/bin/env python3
"""BRVTAL #1166: auditable offline coverage of five SPEC §51 roadmap gaps.

The current state/history of GitHub Issue #533 cannot be attested from a
repository checkout. Synthetically testing documentary invariants never means
that a live roadmap, CI, deployment or owner decision has been verified.
"""
from __future__ import annotations

import ast
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
UNKNOWN = "UNKNOWN"
GAP = "BRECHA_DOCUMENTADA"
SYNTAX = "LOCAL_SYNTHETIC_SYNTAX_ONLY"
FILES = {
    "matrix": "docs/matriz-cobertura.md",
    "contrast": "docs/contraste-spec_editor.md",
    "spec": "docs/BRVTAL-SPEC.md",
    "agents": "AGENTS.md",
}
REQUIREMENTS = {
    "S51-R002": (753, "The roadmap contains only execution/progress information:"),
    "S51-R003": (755, "tasks and milestones;"),
    "S51-R004": (756, "✅ completed / 🚧 pending or in progress / ⛔ blocked state;"),
    "S51-R005": (757, "assigned versions;"),
    "S51-R006": (758, "linked Issues and PRs;"),
}
GAPS = {
    "S51-R002": "Issue #533 contains mutable prose; an offline allowlist cannot certify its entire history",
    "S51-R003": "actual task/milestone presence needs an authenticated Issue #533 snapshot",
    "S51-R004": "actual progress states and completed history need an authenticated Issue #533 snapshot",
    "S51-R005": "actual release/version assignments need an authenticated Issue #533 snapshot",
    "S51-R006": "actual Issue/PR references and their state need authenticated GitHub evidence",
}

# Only synthetic markdown: the GitHub issue, API and personal content are not read.
SYNTHETIC_ROADMAP = """## Execution progress
Milestone: M1
- ✅ ~~Task A (v0.8.1) [Issue](https://github.com/pl0n3r/brvtal/issues/101) [PR](https://github.com/pl0n3r/brvtal/pull/102)~~
- 🚧 Task B (v0.8.2) [Issue](https://github.com/pl0n3r/brvtal/issues/103)
- ⛔ Task C awaiting prerequisite [Issue](https://github.com/pl0n3r/brvtal/issues/104)
"""


def sources(root: Path = ROOT) -> dict[str, str]:
    """Read four fixed public-repository documentation files, no network."""
    return {key: (root / relative).read_text(encoding="utf-8")
            if (root / relative).is_file() else ""
            for key, relative in FILES.items()}


def cells(line: str) -> tuple[str, ...]:
    return tuple(cell.strip() for cell in line.strip().strip("|").split("|"))


def matrix_rows(raw: object) -> dict[str, tuple[str, ...]]:
    """Reject missing, duplicate, manipulated or prematurely promoted rows."""
    if type(raw) is not str or not raw.strip():
        raise ValueError(UNKNOWN + ": missing matrix")
    found: dict[str, tuple[str, ...]] = {}
    for line in raw.splitlines():
        match = re.match(r"^\s*\|\s*(S51-R\d{3})\s*·\s*", line)
        if match is None or match.group(1) not in REQUIREMENTS:
            continue
        key = match.group(1)
        number, phrase = REQUIREMENTS[key]
        row = cells(line)
        if (key in found or len(row) != 5
                or not row[0].startswith(f"{key} · [S51 L{number}](")
                or f"BRVTAL-SPEC.md#L{number})" not in row[0]
                or row[1] != phrase or row[2] != "NO_VERIFICABLE"
                or "Sin aserción integral confirmada" not in row[3]
                or "[contraste](contraste-spec_editor.md)" not in row[3]
                or row[4] != "—"):
            raise ValueError(UNKNOWN + ": forged/duplicate matrix row " + key)
        found[key] = row
    if set(found) != set(REQUIREMENTS):
        raise ValueError(UNKNOWN + ": missing matrix requirements")
    return found


def contrast_rows(raw: object) -> dict[str, tuple[str, ...]]:
    if type(raw) is not str or not raw.strip():
        raise ValueError(UNKNOWN + ": missing contrast")
    found: dict[str, tuple[str, ...]] = {}
    for line in raw.splitlines():
        match = re.match(r"^\s*\|\s*(S51-R\d{3})\s*\|", line)
        if match is None or match.group(1) not in REQUIREMENTS:
            continue
        key = match.group(1)
        row = cells(line)
        if (key in found or len(row) != 6 or row[0] != key
                or row[1] != "NO_VERIFICABLE" or row[2:4] != ("—", "—")
                or "BRVTAL-SPEC.md" not in row[4]
                or "roadmap #533 es dinámica" not in row[5]
                or "No se infiere incumplimiento ni live" not in row[5]):
            raise ValueError(UNKNOWN + ": forged/duplicate contrast row " + key)
        found[key] = row
    if set(found) != set(REQUIREMENTS):
        raise ValueError(UNKNOWN + ": missing contrast requirements")
    return found


def section(raw: object) -> str:
    heading = "## 51. Roadmap responsibility and separation\n"
    if type(raw) is not str or raw.count(heading) != 1:
        return ""
    return raw.split(heading, 1)[1].split("\n## ", 1)[0]


def inspect_synthetic_roadmap(raw: object) -> dict[str, bool]:
    """Illustrate syntax checks, never assert live Issue #533 compliance."""
    if type(raw) is not str or not raw.strip():
        return {key: False for key in REQUIREMENTS}
    result = {
        "S51-R002": False,  # Exclusive progress-only content cannot be inferred.
        "S51-R003": bool(re.search(r"(?m)^Milestone:\s+\S+", raw)
                             and re.search(r"(?m)^-\s+.*Task\s+\S+", raw)),
        "S51-R004": all(marker in raw for marker in ("✅ ~~", "🚧 ", "⛔ ")),
        "S51-R005": bool(re.search(r"\bv\d+\.\d+\.\d+\b", raw)),
        "S51-R006": all(re.search(
            rf"https://github\.com/pl0n3r/brvtal/{kind}/[1-9]\d*\b", raw
        ) for kind in ("issues", "pull")),
    }
    return result


def local_evidence(key: object, data: object, probe: object) -> str:
    if (type(key) is not str or key not in REQUIREMENTS
            or type(data) is not dict or set(data) != set(FILES)
            or any(type(item) is not str or not item for item in data.values())
            or type(probe) is not dict or set(probe) != set(REQUIREMENTS)
            or any(type(flag) is not bool for flag in probe.values())):
        return UNKNOWN
    try:
        matrix_rows(data["matrix"])
        contrast_rows(data["contrast"])
    except ValueError:
        return UNKNOWN
    contract = section(data["spec"])
    phrase = REQUIREMENTS[key][1]
    marker = ("The roadmap contains only execution/progress information:\n"
              if key == "S51-R002" else "- " + phrase)
    if (not contract or contract.count(marker) != 1
            or contract.count(phrase) != 1
            or "GitHub Issue **#533**" not in contract
            or "GitHub Issue **#533**" not in data["agents"]):
        return UNKNOWN
    if key == "S51-R002":
        return GAP  # An offline source cannot prove an external dynamic negative.
    return SYNTAX if probe[key] else GAP


def external_status(_key: object, _local: object = None,
                    _github_claim: object = None) -> str:
    """Untrusted claims, a synthetic fixture and CI colors are not authority."""
    return UNKNOWN


def explicit_gap(key: object, data: object, probe: object) -> str:
    if (type(key) is not str or key not in GAPS
            or local_evidence(key, data, probe) == UNKNOWN):
        return UNKNOWN
    return f"{key}: UNKNOWN — {GAPS[key]}"


class BrechasBrvtal76Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.data = sources()
        cls.probe = inspect_synthetic_roadmap(SYNTHETIC_ROADMAP)

    def test_contrato_principal(self) -> None:
        """AC-01: each of the five original requirements has a witness/gap."""
        self.assertEqual(set(matrix_rows(self.data["matrix"])), set(REQUIREMENTS))
        self.assertEqual(set(contrast_rows(self.data["contrast"])), set(REQUIREMENTS))
        self.assertEqual(set(GAPS), set(REQUIREMENTS))
        for key in REQUIREMENTS:
            with self.subTest(id=key):
                self.assertEqual(local_evidence(key, self.data, self.probe),
                                 GAP if key == "S51-R002" else SYNTAX)
                self.assertIn(f"{key}: UNKNOWN", explicit_gap(key, self.data, self.probe))

    def test_falla_cerrado(self) -> None:
        """AC-02: absent/ambiguous/forged sources and probes fail closed."""
        for key in REQUIREMENTS:
            row = next(line for line in self.data["matrix"].splitlines()
                       if line.startswith(f"| {key} · "))
            contrast = next(line for line in self.data["contrast"].splitlines()
                            if line.startswith(f"| {key} |"))
            variants = (
                {**self.data, "matrix": self.data["matrix"].replace(row, "", 1)},
                {**self.data, "matrix": self.data["matrix"] + "\n" + row},
                {**self.data, "matrix": self.data["matrix"].replace(
                    row, row.replace("NO_VERIFICABLE", "CUBIERTO", 1), 1)},
                {**self.data, "contrast": self.data["contrast"].replace(contrast, "", 1)},
                {**self.data, "contrast": self.data["contrast"] + "\n" + contrast},
                {**self.data, "spec": self.data["spec"].replace(
                    REQUIREMENTS[key][1], "tampered", 1)},
                {**self.data, "agents": ""},
            )
            for invalid in variants:
                with self.subTest(id=key):
                    self.assertEqual(local_evidence(key, invalid, self.probe), UNKNOWN)
                    self.assertEqual(explicit_gap(key, invalid, self.probe), UNKNOWN)
        for key in REQUIREMENTS:
            self.assertEqual(local_evidence(key, {}, self.probe), UNKNOWN)
            self.assertEqual(local_evidence(key, self.data, {}), UNKNOWN)
            self.assertEqual(local_evidence(key, self.data,
                {**self.probe, key: "success"}), UNKNOWN)
            self.assertEqual(inspect_synthetic_roadmap(None)[key], False)
        for key, snippet in (
            ("S51-R003", "Milestone: M1"),
            ("S51-R004", "✅ Completed"),
            ("S51-R005", "Version unassigned"),
            ("S51-R006", "[PR](https://example.test/pull/1)"),
        ):
            self.assertFalse(inspect_synthetic_roadmap(snippet)[key])
        self.assertEqual(local_evidence("S51-R999", self.data, self.probe), UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        """AC-03: fixed local allowlist and stdlib; no API, secrets or PII."""
        self.assertEqual(set(FILES.values()), {
            "docs/matriz-cobertura.md", "docs/contraste-spec_editor.md",
            "docs/BRVTAL-SPEC.md", "AGENTS.md",
        })
        self.assertTrue(all(not Path(p).is_absolute() and ".." not in Path(p).parts
                            for p in FILES.values()))
        source = Path(__file__).read_text(encoding="utf-8")
        imported = set()
        for node in ast.walk(ast.parse(source)):
            if isinstance(node, ast.Import):
                imported.update(alias.name.split(".")[0] for alias in node.names)
            elif isinstance(node, ast.ImportFrom) and node.module:
                imported.add(node.module.split(".")[0])
        self.assertLessEqual(imported, {"__future__", "ast", "pathlib", "re", "unittest"})
        self.assertNotIn("@example.com", SYNTHETIC_ROADMAP)
        self.assertNotIn("password", SYNTHETIC_ROADMAP.lower())

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: no fixture or caller-provided green claim certifies GitHub."""
        for key in REQUIREMENTS:
            with self.subTest(id=key):
                self.assertEqual(matrix_rows(self.data["matrix"])[key][2],
                                 "NO_VERIFICABLE")
                local = local_evidence(key, self.data, self.probe)
                for claim in (None, True, "success", {"ci": "success"},
                              {"issue_533": "complete"}, {"owner": "approved"},
                              {"merged": True}, {"production": "GREEN"}):
                    self.assertEqual(external_status(key, local, claim), UNKNOWN)
                self.assertTrue(explicit_gap(key, self.data, self.probe).startswith(
                    f"{key}: UNKNOWN"))
        self.assertFalse(self.probe["S51-R002"])
        self.assertTrue(all(self.probe[key] for key in REQUIREMENTS if key != "S51-R002"))


if __name__ == "__main__":
    unittest.main()
