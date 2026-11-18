#!/usr/bin/env python3
"""BRVTAL #1169: offline witnesses for five roadmap/coordination audit gaps.

A repository text contract is not evidence of the live GitHub Issue #533,
owner reprioritization history, coordinator concurrency, CI or production.
Those global observations are deliberately UNKNOWN; no network or credentials.
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
}
UNKNOWN = "UNKNOWN"
GAP = "BRECHA_DOCUMENTADA"
LOCAL = "LOCAL_POLICY_ONLY"
CLAUSES = {
    "S51-R018": "Completed roadmap work remains visible and struck through as historical execution evidence.",
    "S51-R019": "Fixed normative prose may be removed from the roadmap and relocated here or to AGENTS.md without being treated as deleted delivery history.",
    "S51-R020": "An explicit user reprioritization updates Issue #533.",
    "S51-R021": "Progress must not be duplicated as a second priority list in this specification.",
    "S51_1-R008": "Coordination complements the existing maximum of four independent work lines; it does not authorize parallel merges.",
}
EXPECTED = {
    "S51-R018": GAP,       # Historical roadmap state is external to checkout.
    "S51-R019": GAP,       # Relocation history is an external editorial decision.
    "S51-R020": GAP,       # User intent and Issue #533 edits require live evidence.
    "S51-R021": LOCAL,     # Local policy is checkable, global absence is not.
    "S51_1-R008": LOCAL,  # Written serialization policy, not concurrency proof.
}
GAPS = {
    "S51-R018": "roadmap #533 history and strikethrough need authenticated temporal evidence",
    "S51-R019": "editorial relocation needs issue history and an authorized decision",
    "S51-R020": "explicit owner reprioritization needs Issue #533 event history",
    "S51-R021": "a local spec boundary does not prove every future priority update",
    "S51_1-R008": "local policy text does not prove four-way concurrency or serial merges",
}


def read_sources(root: Path = ROOT) -> dict[str, str]:
    """Allowlist only fixed, nonsecret repository documentation."""
    return {name: (root / path).read_text(encoding="utf-8")
            if (root / path).is_file() else ""
            for name, path in FILES.items()}


def _cells(line: str) -> tuple[str, ...]:
    return tuple(part.strip() for part in line.strip().strip("|").split("|"))


def matrix_rows(raw: object) -> dict[str, tuple[str, ...]]:
    """No missing, duplicate, reclassified, or forged coverage rows."""
    if type(raw) is not str or not raw:
        raise ValueError(UNKNOWN + ": missing matrix")
    found: dict[str, tuple[str, ...]] = {}
    for line in raw.splitlines():
        match = re.match(r"^\| (S51(?:_1)?-R\d{3}) · ", line)
        if not match or match.group(1) not in CLAUSES:
            continue
        key = match.group(1)
        cells = _cells(line)
        section = "S51_1" if key.startswith("S51_1") else "S51"
        if (key in found or len(cells) != 5
                or not cells[0].startswith(key + " · [" + section + " L")
                or cells[1] != CLAUSES[key]
                or cells[2] != "NO_VERIFICABLE"
                or "Sin aserción integral confirmada" not in cells[3]
                or "[contraste](contraste-spec_editor.md)" not in cells[3]
                or cells[4] != "—"):
            raise ValueError(UNKNOWN + ": invalid/duplicate row " + key)
        found[key] = cells
    if set(found) != set(CLAUSES):
        raise ValueError(UNKNOWN + ": incomplete matrix")
    return found


def contrast_rows(raw: object) -> dict[str, tuple[str, ...]]:
    if type(raw) is not str or not raw:
        raise ValueError(UNKNOWN + ": missing contrast")
    found: dict[str, tuple[str, ...]] = {}
    for line in raw.splitlines():
        match = re.match(r"^\| (S51(?:_1)?-R\d{3}) \|", line)
        if not match or match.group(1) not in CLAUSES:
            continue
        key = match.group(1)
        cells = _cells(line)
        reason = ("El caller local prueba algunos hooks" if key == "S51_1-R008"
                  else "La clasificación del contenido del roadmap #533 es dinámica")
        if (key in found or len(cells) != 6
                or cells[1] != "NO_VERIFICABLE"
                or cells[2:4] != ("—", "—")
                or "[BRVTAL-SPEC §51](BRVTAL-SPEC.md)" not in cells[4]
                or reason not in cells[5]
                or "No se infiere" not in cells[5]):
            raise ValueError(UNKNOWN + ": invalid/duplicate contrast " + key)
        found[key] = cells
    if set(found) != set(CLAUSES):
        raise ValueError(UNKNOWN + ": incomplete contrast")
    return found


def _section(raw: str, heading: str) -> str:
    token = "## " + heading + "\n"
    if raw.count(token) != 1:
        return ""
    return raw.split(token, 1)[1].split("\n## ", 1)[0]


def local_evidence(key: object, data: object) -> str:
    """Fail closed even when an external state is marked green by a caller."""
    if (type(key) is not str or key not in CLAUSES
            or type(data) is not dict or set(data) != set(FILES)
            or any(type(value) is not str or not value for value in data.values())):
        return UNKNOWN
    try:
        matrix_rows(data["matrix"])
        contrast_rows(data["contrast"])
    except ValueError:
        return UNKNOWN
    roadmap = _section(data["spec"], "51. Roadmap responsibility and separation")
    coordination = _section(data["spec"], "51.1 Multi-agent development coordination")
    body = coordination if key == "S51_1-R008" else roadmap
    if not body or body.count(CLAUSES[key]) != 1:
        return UNKNOWN
    if key == "S51-R021":
        agents = data["agents"]
        if ("GitHub Issue **#533** is the canonical execution roadmap" in agents
                and "Issue #533" in roadmap
                and "**`AGENTS.md`**" in roadmap
                and "**`docs/BRVTAL-SPEC.md`**" in roadmap):
            return LOCAL
        return UNKNOWN
    if key == "S51_1-R008":
        if ("Merges to `main` remain serialized." in coordination
                and "Merges to `main` are always serialized." in data["agents"]
                and "maximum of four independent work lines" in coordination):
            return LOCAL
        return UNKNOWN
    return GAP  # Explicitly documented, not silently promoted to compliance.


def operational_evidence(_key: object, _local: object = None,
                         _external: object = None) -> str:
    """Neither untrusted external input nor local prose establishes global truth."""
    return UNKNOWN


def explicit_gap(key: object, data: object) -> str:
    if (type(key) is not str or key not in GAPS
            or local_evidence(key, data) == UNKNOWN):
        return UNKNOWN
    return f"{key}: UNKNOWN — {GAPS[key]}"


class BrechasBrvtal79Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.data = read_sources()

    def test_contrato_principal(self) -> None:
        """AC-01: all five matrix/contrast/spec rows have honest witnesses."""
        self.assertEqual(set(matrix_rows(self.data["matrix"])), set(CLAUSES))
        self.assertEqual(set(contrast_rows(self.data["contrast"])), set(CLAUSES))
        for key, expected in EXPECTED.items():
            with self.subTest(id=key):
                self.assertEqual(local_evidence(key, self.data), expected)
                self.assertIn(key + ": UNKNOWN", explicit_gap(key, self.data))

    def test_falla_cerrado(self) -> None:
        """AC-02: absent/duplicated/promoted/forged sources are UNKNOWN."""
        for key in CLAUSES:
            matrix = self.data["matrix"]
            contrast = self.data["contrast"]
            row = next(x for x in matrix.splitlines() if x.startswith("| " + key + " · "))
            contrast_row = next(x for x in contrast.splitlines() if x.startswith("| " + key + " |"))
            cases = (
                {**self.data, "matrix": matrix.replace(row, "", 1)},
                {**self.data, "matrix": matrix + "\n" + row},
                {**self.data, "matrix": matrix.replace(row, row.replace("NO_VERIFICABLE", "CUBIERTO", 1), 1)},
                {**self.data, "contrast": contrast.replace(contrast_row, "", 1)},
                {**self.data, "contrast": contrast + "\n" + contrast_row},
                {**self.data, "spec": self.data["spec"].replace(CLAUSES[key], "deleted", 1)},
                {**self.data, "agents": ""},
                {**self.data, "matrix": ""},
            )
            for invalid in cases:
                with self.subTest(id=key, invalid=tuple(k for k in FILES if invalid[k] != self.data[k])):
                    self.assertEqual(local_evidence(key, invalid), UNKNOWN)
                    self.assertEqual(explicit_gap(key, invalid), UNKNOWN)
        self.assertEqual(local_evidence(None, self.data), UNKNOWN)
        self.assertEqual(local_evidence("S51-R999", self.data), UNKNOWN)
        self.assertEqual(local_evidence("S51-R018", {"matrix": "valid"}), UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        """AC-03: fixed allowlist, stdlib-only parser, zero network or PII."""
        self.assertEqual(set(FILES.values()), {
            "docs/matriz-cobertura.md", "docs/contraste-spec_editor.md",
            "docs/BRVTAL-SPEC.md", "AGENTS.md",
        })
        source = Path(__file__).read_text(encoding="utf-8")
        imports = [node for node in ast.walk(ast.parse(source))
                   if isinstance(node, (ast.Import, ast.ImportFrom))]
        allowed = {"__future__", "ast", "pathlib", "re", "unittest"}
        self.assertTrue(all((node.module if isinstance(node, ast.ImportFrom)
                             else alias.name) in allowed
                            for node in imports
                            for alias in (node.names if isinstance(node, ast.Import) else [None])))
        self.assertTrue(all(not path.startswith(("/", "..")) for path in FILES.values()))
        self.assertFalse(any("SECRET" in value or "PRIVATE KEY" in value for value in GAPS.values()))

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: external roadmap/coordination/CI stay UNKNOWN for all IDs."""
        self.assertEqual(set(GAPS), set(CLAUSES))
        for key in CLAUSES:
            with self.subTest(id=key):
                local = local_evidence(key, self.data)
                self.assertIn(local, {GAP, LOCAL})
                self.assertEqual(operational_evidence(key, local, {"state": "success"}), UNKNOWN)
                self.assertEqual(operational_evidence(key, local, None), UNKNOWN)
                self.assertIn("UNKNOWN", explicit_gap(key, self.data))


if __name__ == "__main__":
    unittest.main()
