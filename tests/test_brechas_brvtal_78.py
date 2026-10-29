#!/usr/bin/env python3
"""BRVTAL #1168: offline coverage witnesses for S51-R013..S51-R017.

Repository documentation can attest only local responsibility boundaries.
Live Issue #533, Issue histories, PR reviews, deployments, and CI remain UNKNOWN.
No credentials, HTTP requests, database, personal data, or matrix edits.
"""
from __future__ import annotations

import ast
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
FILES = {
    "matrix": "docs/matriz-cobertura.md",
    "contrast": "docs/contraste-spec_editor.md",
    "spec": "docs/BRVTAL-SPEC.md",
    "agents": "AGENTS.md",
    "readme": "README.md",
}
UNKNOWN = "UNKNOWN"
GAP = "BRECHA_DOCUMENTADA"
LOCAL = "LOCAL_POLICY_ONLY"
CLAUSES = {
    "S51-R013": "**docs/BRVTAL-SPEC.md** — durable product, architecture, functional and technical decisions;",
    "S51-R014": "**Issue #533** — what is planned, active, blocked or completed and in what order;",
    "S51-R015": "**specific Issues** — executable scope and acceptance criteria;",
    "S51-R016": "**PRs** — concrete implementation and validation evidence;",
    "S51-R017": "**README.md** — current deploy snapshot, not cumulative roadmap.",
}
AGENT_ANCHOR = {
    "S51-R013": "**\`docs/BRVTAL-SPEC.md\`** — durable product, architecture",
    "S51-R014": "**GitHub Issue #533** — cross-project execution order and progress only.",
    "S51-R015": "**The specific GitHub Issue** — acceptance criteria for that work item.",
    "S51-R016": "**Newer merged PR decisions/tests** — newer executable intent.",
    "S51-R017": "**\`README.md\`** — operational landing page under README Contract v2:",
}
EXPECTED = {
    "S51-R013": LOCAL,  # Local spec ownership, not future architectural decisions.
    "S51-R014": GAP,    # Current Issue #533 state/history requires GitHub.
    "S51-R015": GAP,    # Other Issues' acceptance requires GitHub.
    "S51-R016": GAP,    # Real reviews/checks/merge commits require GitHub.
    "S51-R017": LOCAL,  # Local README boundary, not exact deployed version.
}
GAPS = {
    "S51-R013": "local specification ownership does not prove all later product changes are reconciled",
    "S51-R014": "roadmap #533 timeline and priorities require authenticated current GitHub evidence",
    "S51-R015": "specific Issue acceptance and completion require authenticated Issue/PR evidence",
    "S51-R016": "PR merge/CI/review provenance requires authenticated exact-HEAD GitHub checks",
    "S51-R017": "README editorial boundaries cannot prove the exact deployed production snapshot",
}
_CONTRAST_REASON = "La clasificación del contenido del roadmap #533 es dinámica e institucional"


def read_sources(root: Path = ROOT) -> dict[str, str]:
    """Read an explicit allowlist of checked-out public repository text only."""
    return {
        name: path.read_text(encoding="utf-8") if path.is_file() else ""
        for name, relative in FILES.items()
        for path in [root / relative]
    }


def _cells(line: str) -> tuple[str, ...]:
    return tuple(part.strip() for part in line.strip().strip("|").split("|"))


def matrix_rows(raw: object) -> dict[str, tuple[str, ...]]:
    if type(raw) is not str or not raw:
        raise ValueError("UNKNOWN: missing coverage matrix")
    found: dict[str, tuple[str, ...]] = {}
    for line in raw.splitlines():
        match = re.match(r"^\| (S51-R\d{3}) · ", line)
        if not match or match.group(1) not in CLAUSES:
            continue
        key = match.group(1)
        cells = _cells(line)
        if (key in found or len(cells) != 5
                or not cells[0].startswith(key + " · [S51 L")
                or cells[1] != CLAUSES[key]
                or cells[2] != "NO_VERIFICABLE"
                or "Sin aserción integral confirmada" not in cells[3]
                or "[contraste](contraste-spec_editor.md)" not in cells[3]
                or _CONTRAST_REASON not in cells[3]
                or "No se infiere incumplimiento ni live." not in cells[3]
                or cells[4] != "—"):
            raise ValueError("UNKNOWN: malformed/duplicate matrix row " + key)
        found[key] = cells
    if set(found) != set(CLAUSES):
        raise ValueError("UNKNOWN: missing matrix rows")
    return found


def contrast_rows(raw: object) -> dict[str, tuple[str, ...]]:
    if type(raw) is not str or not raw:
        raise ValueError("UNKNOWN: missing independent contrast")
    found: dict[str, tuple[str, ...]] = {}
    for line in raw.splitlines():
        match = re.match(r"^\| (S51-R\d{3}) \|", line)
        if not match or match.group(1) not in CLAUSES:
            continue
        key = match.group(1)
        cells = _cells(line)
        if (key in found or len(cells) != 6 or cells[0] != key
                or cells[1:4] != ("NO_VERIFICABLE", "—", "—")
                or cells[4] != "[BRVTAL-SPEC §51](BRVTAL-SPEC.md)"
                or _CONTRAST_REASON not in cells[5]
                or "No se infiere incumplimiento ni live." not in cells[5]):
            raise ValueError("UNKNOWN: malformed/duplicate contrast " + key)
        found[key] = cells
    if set(found) != set(CLAUSES):
        raise ValueError("UNKNOWN: missing contrast rows")
    return found


def section(raw: str, heading: str) -> str:
    token = "## " + heading + "\n"
    if raw.count(token) != 1:
        return ""
    return raw.split(token, 1)[1].split("\n## ", 1)[0]


def local_evidence(key: object, data: object) -> str:
    """Conservative classification; reject ambiguous or incomplete sources."""
    if (type(key) is not str or key not in CLAUSES
            or type(data) is not dict or set(data) != set(FILES)
            or any(type(value) is not str or not value.strip()
                   for value in data.values())):
        return UNKNOWN
    try:
        matrix_rows(data["matrix"])
        contrast_rows(data["contrast"])
    except ValueError:
        return UNKNOWN
    spec = section(data["spec"], "51. Roadmap responsibility and separation")
    agents = section(data["agents"], "0. Source ownership")
    readme = data["readme"]
    if (not spec or not agents or spec.count(CLAUSES[key]) != 1
            or AGENT_ANCHOR[key] not in agents
            or "GitHub Issue **#533** is the canonical BRVTAL execution roadmap." not in spec
            or "Responsibility is separated as follows:" not in spec
            or "**README Contract v2**" not in readme
            or "[Issue #533](https://github.com/pl0n3r/brvtal/issues/533)" not in readme
            or "no duplica estado, progreso ni decisiones de GitHub" not in readme
            or "## Work Queue\n" not in readme
            or "La fuente ejecutable es" not in readme):
        return UNKNOWN
    return EXPECTED[key]


def operational_evidence(_key: object, _local: object = None,
                         _external: object = None) -> str:
    """No local fixture may claim GitHub, governance, CI or deployed truth."""
    return UNKNOWN


def explicit_gap(key: object, data: object) -> str:
    if type(key) is not str or key not in GAPS or local_evidence(key, data) == UNKNOWN:
        return UNKNOWN
    return f"{key}: UNKNOWN — {GAPS[key]}"


class BrechasBrvtal78Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.data = read_sources()

    def test_contrato_principal(self) -> None:
        """AC-01: five precise matrix/contrast/spec clauses and honest witnesses."""
        self.assertEqual(set(matrix_rows(self.data["matrix"])), set(CLAUSES))
        self.assertEqual(set(contrast_rows(self.data["contrast"])), set(CLAUSES))
        for key, expected in EXPECTED.items():
            with self.subTest(requirement=key):
                self.assertEqual(local_evidence(key, self.data), expected)
                self.assertIn(key + ": UNKNOWN", explicit_gap(key, self.data))
        self.assertEqual(set(GAPS), set(CLAUSES))

    def test_falla_cerrado(self) -> None:
        """AC-02: missing, duplicated, promoted, inconsistent sources fail closed."""
        for key in CLAUSES:
            matrix = self.data["matrix"]
            contrast = self.data["contrast"]
            row = next(line for line in matrix.splitlines()
                       if line.startswith("| " + key + " · "))
            contrast_row = next(line for line in contrast.splitlines()
                                if line.startswith("| " + key + " |"))
            cases = (
                {**self.data, "matrix": matrix.replace(row, "", 1)},
                {**self.data, "matrix": matrix + "\n" + row},
                {**self.data, "matrix": matrix.replace(
                    row, row.replace("NO_VERIFICABLE", "CUBIERTO", 1), 1)},
                {**self.data, "contrast": contrast.replace(contrast_row, "", 1)},
                {**self.data, "contrast": contrast + "\n" + contrast_row},
                {**self.data, "contrast": contrast.replace(
                    contrast_row, contrast_row.replace(
                        "NO_VERIFICABLE", "CUBIERTO", 1), 1)},
                {**self.data, "spec": self.data["spec"].replace(
                    CLAUSES[key], "missing responsibility", 1)},
                {**self.data, "agents": self.data["agents"].replace(
                    AGENT_ANCHOR[key], "")},
                {**self.data, "readme": self.data["readme"].replace(
                    "**README Contract v2**", "")},
                {**self.data, "matrix": ""},
            )
            for changed in cases:
                with self.subTest(requirement=key, changed=tuple(
                    name for name in FILES if changed[name] != self.data[name])):
                    self.assertEqual(local_evidence(key, changed), UNKNOWN)
                    self.assertEqual(explicit_gap(key, changed), UNKNOWN)
        self.assertEqual(local_evidence("S51-R999", self.data), UNKNOWN)
        self.assertEqual(local_evidence(None, self.data), UNKNOWN)
        self.assertEqual(local_evidence("S51-R013", {"matrix": "OK"}), UNKNOWN)
        self.assertEqual(local_evidence("S51-R013", {
            **self.data, "external": "APPROVED"}), UNKNOWN)
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            matrix_rows(None)
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            contrast_rows("")

    def test_sin_datos_reales(self) -> None:
        """AC-03: standard-library and fixed text paths; no network/credentials."""
        self.assertEqual(set(FILES.values()), {
            "docs/matriz-cobertura.md", "docs/contraste-spec_editor.md",
            "docs/BRVTAL-SPEC.md", "AGENTS.md", "README.md",
        })
        self.assertTrue(all(not path.startswith(("/", ".."))
                            for path in FILES.values()))
        source = Path(__file__).read_text(encoding="utf-8")
        imports = [node for node in ast.walk(ast.parse(source))
                   if isinstance(node, (ast.Import, ast.ImportFrom))]
        allowed = {"__future__", "ast", "pathlib", "re", "unittest"}
        self.assertTrue(all((node.module if isinstance(node, ast.ImportFrom)
                             else alias.name) in allowed
                            for node in imports
                            for alias in (node.names if isinstance(node, ast.Import)
                                          else [None])))
        self.assertNotIn("password", " ".join(GAPS.values()).lower())
        self.assertEqual(operational_evidence("S51-R017", {
            "deployment": "VERIFIED", "owner": "APPROVED"}), UNKNOWN)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: external Issue/PR/CI/deploy state is never implied by prose."""
        for key, expected in EXPECTED.items():
            with self.subTest(requirement=key):
                local = local_evidence(key, self.data)
                self.assertEqual(expected, local)
                for claimed in (None, {}, {"ci": "success", "sha": "a" * 40},
                                {"production": "VERIFIED", "roadmap": "completed"},
                                {"review": "approved", "issue": "merged"}):
                    self.assertEqual(operational_evidence(key, local, claimed), UNKNOWN)
                self.assertIn("UNKNOWN", explicit_gap(key, self.data))


if __name__ == "__main__":
    unittest.main()
