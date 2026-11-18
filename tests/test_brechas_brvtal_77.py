#!/usr/bin/env python3
"""BRVTAL #1167: five SPEC §51 roadmap evidence gaps, tested offline.

Synthetic evidence checks acceptance syntax; repository documentation cannot
authenticate live Issue #533 history, merge SHA, checks, deployment or reviews.
"""
from __future__ import annotations

import ast
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
UNKNOWN = "UNKNOWN"
GAP = "BRECHA_DOCUMENTADA"
LOCAL = "LOCAL_POLICY_ONLY"

FILES = {
    "matrix": "docs/matriz-cobertura.md",
    "contrast": "docs/contraste-spec_editor.md",
    "spec": "docs/BRVTAL-SPEC.md",
    "agents": "AGENTS.md",
    "readme": "README.md",
}
CLAUSES = {
    "S51-R007": (759, "merge SHA and validation/deployment evidence when relevant;"),
    "S51-R008": (760, "blockers and their resolution;"),
    "S51-R009": (761, "concise progress notes tied to actual state changes."),
    "S51-R010": (763, "The roadmap does **not** store permanent reference text such as policies, conventions, manuals, agent instructions, architectural rules, durable design decisions, security/delivery doctrine or explanations of how the roadmap operates."),
    "S51-R012": (766, "**AGENTS.md** — how agents/sessions work and deliver changes;"),
}
GAPS = {
    "S51-R007": "authentic merge SHA, checks and deployment receipt require GitHub/deploy evidence",
    "S51-R008": "resolution and closure of real blockers require authenticated Issue history",
    "S51-R009": "actual change-to-note chronology requires authenticated GitHub events",
    "S51-R010": "a local policy does not certify absence of permanent prose from live Issue #533",
    "S51-R012": "AGENTS.md ownership in local files cannot verify all future agent behavior",
}
EXPECTED = {
    "S51-R007": GAP,
    "S51-R008": GAP,
    "S51-R009": GAP,
    "S51-R010": GAP,
    "S51-R012": LOCAL,
}
_CONTRAST = "La clasificación del contenido del roadmap #533 es dinámica e institucional"
_SHA40 = re.compile(r"[a-f0-9]{40}\Z")
_ISSUE_REF = re.compile(r"#[1-9][0-9]*\Z")


def read_sources(root: Path = ROOT) -> dict[str, str]:
    """Only fixed repository text; never query GitHub or production."""
    return {
        name: path.read_text(encoding="utf-8") if path.is_file() else ""
        for name, rel in FILES.items()
        for path in [root / rel]
    }


def cells(line: str) -> tuple[str, ...]:
    return tuple(p.strip() for p in line.strip().strip("|").split("|"))


def matrix_rows(raw: object) -> dict[str, tuple[str, ...]]:
    if type(raw) is not str or not raw.strip():
        raise ValueError(UNKNOWN + ": missing matrix")
    found = {}
    for line in raw.splitlines():
        m = re.match(r"^\| (S51-R[0-9]{3}) · ", line)
        if not m or m.group(1) not in CLAUSES:
            continue
        key = m.group(1)
        number, label = CLAUSES[key]
        row = cells(line)
        if (key in found or len(row) != 5
                or not row[0].startswith(f"{key} · [S51 L{number}](")
                or f"BRVTAL-SPEC.md#L{number})" not in row[0]
                or row[1] != label or row[2] != "NO_VERIFICABLE"
                or "Sin aserción integral confirmada" not in row[3]
                or "[contraste](contraste-spec_editor.md)" not in row[3]
                or _CONTRAST not in row[3]
                or "No se infiere incumplimiento ni live." not in row[3]
                or row[4] != "—"):
            raise ValueError(UNKNOWN + ": altered or duplicated " + key)
        found[key] = row
    if set(found) != set(CLAUSES):
        raise ValueError(UNKNOWN + ": missing requirements")
    return found


def contrast_rows(raw: object) -> dict[str, tuple[str, ...]]:
    if type(raw) is not str or not raw.strip():
        raise ValueError(UNKNOWN + ": missing contrast")
    found = {}
    for line in raw.splitlines():
        m = re.match(r"^\| (S51-R[0-9]{3}) \|", line)
        if not m or m.group(1) not in CLAUSES:
            continue
        key = m.group(1)
        row = cells(line)
        if (key in found or len(row) != 6
                or row[0] != key or row[1:4] != ("NO_VERIFICABLE", "—", "—")
                or row[4] != "[BRVTAL-SPEC §51](BRVTAL-SPEC.md)"
                or _CONTRAST not in row[5]
                or "No se infiere incumplimiento ni live." not in row[5]):
            raise ValueError(UNKNOWN + ": altered or duplicated contrast " + key)
        found[key] = row
    if set(found) != set(CLAUSES):
        raise ValueError(UNKNOWN + ": missing contrast requirements")
    return found


def spec_clause(key: str) -> str:
    clause = CLAUSES[key][1]
    if key == "S51-R012":
        clause = clause.replace("**AGENTS.md**", "**" + chr(96) + "AGENTS.md" + chr(96) + "**")
    return clause


def synthetic_witness(key: object, item: object) -> bool:
    """Strictly inspect an artificial record. No external claim is accepted."""
    if type(key) is not str or key not in CLAUSES or type(item) is not dict:
        return False
    if key == "S51-R007":
        if (set(item) != {"merge_sha", "validation", "deployed", "deployment_sha"}
                or type(item["merge_sha"]) is not str
                or _SHA40.fullmatch(item["merge_sha"]) is None
                or item["validation"] != "success"
                or type(item["deployed"]) is not bool):
            return False
        if item["deployed"]:
            return (type(item["deployment_sha"]) is str
                    and item["deployment_sha"] == item["merge_sha"])
        return item["deployment_sha"] is None
    if key == "S51-R008":
        return (set(item) == {"blocker", "resolution", "before", "after", "issue"}
                and type(item["blocker"]) is str and bool(item["blocker"].strip())
                and type(item["resolution"]) is str and bool(item["resolution"].strip())
                and type(item["issue"]) is str
                and _ISSUE_REF.fullmatch(item["issue"]) is not None
                and item["before"] == "blocked" and item["after"] == "completed")
    if key == "S51-R009":
        return (set(item) == {"event", "before", "after", "recorded_before",
                              "recorded_after", "note"}
                and type(item["event"]) is str and bool(item["event"].strip())
                and type(item["note"]) is str
                and 1 <= len(item["note"].strip()) <= 160
                and type(item["before"]) is str
                and type(item["after"]) is str
                and item["before"] in {"blocked", "pending", "in_progress"}
                and item["after"] in {"in_progress", "completed"}
                and item["before"] != item["after"]
                and item["recorded_before"] == item["before"]
                and item["recorded_after"] == item["after"])
    if key == "S51-R010":
        if set(item) != {"roadmap_excerpt"} or type(item["roadmap_excerpt"]) is not str:
            return False
        entries = [s.strip() for s in item["roadmap_excerpt"].splitlines() if s.strip()]
        forbidden = re.compile(r"\b(policy|policies|manual|doctrine|architecture|agent instructions|how to operate)\b", re.I)
        return (bool(entries) and all(
            re.match(r"^- [✅🚧⛔] .+ #[1-9][0-9]*\Z", s) is not None
            and forbidden.search(s) is None for s in entries))
    # R012 is a local responsibility contract, never a real agent audit.
    return (set(item) == {"owner", "purpose"}
            and item["owner"] == "AGENTS.md"
            and item["purpose"] == "agent operating instructions")


FIXTURES = {
    "S51-R007": {"merge_sha": "a" * 40, "validation": "success",
                 "deployed": False, "deployment_sha": None},
    "S51-R008": {"blocker": "CI failure", "resolution": "Fixed failing check",
                 "before": "blocked", "after": "completed", "issue": "#101"},
    "S51-R009": {"event": "issue-101-change", "before": "pending",
                 "after": "in_progress", "recorded_before": "pending",
                 "recorded_after": "in_progress", "note": "Started after reservation"},
    "S51-R010": {"roadmap_excerpt": "- 🚧 Implement issue #101\n- ✅ Completed PR #102"},
    "S51-R012": {"owner": "AGENTS.md", "purpose": "agent operating instructions"},
}


def local_evidence(key: object, sources: object) -> str:
    """Documentary ownership only; no live roadmap history is in this input."""
    if (type(key) is not str or key not in CLAUSES
            or type(sources) is not dict or set(sources) != set(FILES)
            or any(type(value) is not str or not value.strip()
                   for value in sources.values())):
        return UNKNOWN
    try:
        matrix_rows(sources["matrix"])
        contrast_rows(sources["contrast"])
    except ValueError:
        return UNKNOWN
    spec = sources["spec"]
    number, _label = CLAUSES[key]
    spec_lines = spec.splitlines()
    if (len(spec_lines) < number
            or spec_lines[number - 1] != "- " + spec_clause(key)
            or spec.count(spec_clause(key)) != 1
            or "## 51. Roadmap responsibility and separation" not in spec
            or "GitHub Issue **#533** is the canonical BRVTAL execution roadmap." not in spec
            or "Responsibility is separated as follows:" not in spec
            or "## 0. Source ownership" not in sources["agents"]
            or "### Ownership rule" not in sources["agents"]
            or "**GitHub Issue #533** — cross-project execution order and progress only." not in sources["agents"]
            or "**The specific GitHub Issue** — acceptance criteria for that work item." not in sources["agents"]
            or "**README Contract v2**" not in sources["readme"]
            or "[Issue #533](https://github.com/pl0n3r/brvtal/issues/533)" not in sources["readme"]
            or not synthetic_witness(key, FIXTURES[key])):
        return UNKNOWN
    if key == "S51-R012":
        if ("**`AGENTS.md`** — how agents/sessions work and deliver changes;" not in spec
                or "**`AGENTS.md`** — agent operating contract." not in sources["agents"]):
            return UNKNOWN
    return EXPECTED[key]


def operational_evidence(_key: object, _external: object = None) -> str:
    """Synthetic success can never validate actual GitHub/live facts."""
    return UNKNOWN


def explicit_gap(key: object, data: object) -> str:
    if type(key) is not str or key not in GAPS or local_evidence(key, data) == UNKNOWN:
        return UNKNOWN
    return f"{key}: UNKNOWN — {GAPS[key]}"


class BrechasBrvtal77Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.sources = read_sources()

    def test_contrato_principal(self) -> None:
        self.assertEqual(set(matrix_rows(self.sources["matrix"])), set(CLAUSES))
        self.assertEqual(set(contrast_rows(self.sources["contrast"])), set(CLAUSES))
        self.assertEqual(set(GAPS), set(CLAUSES))
        for key, expected in EXPECTED.items():
            with self.subTest(requirement=key):
                self.assertTrue(synthetic_witness(key, FIXTURES[key]))
                self.assertEqual(expected, local_evidence(key, self.sources))
                self.assertIn(key + ": UNKNOWN", explicit_gap(key, self.sources))

    def test_falla_cerrado(self) -> None:
        for key in CLAUSES:
            matrix = self.sources["matrix"]
            contrast = self.sources["contrast"]
            row = next(line for line in matrix.splitlines()
                       if line.startswith("| " + key + " · "))
            other = next(line for line in contrast.splitlines()
                         if line.startswith("| " + key + " |"))
            cases = (
                {**self.sources, "matrix": matrix.replace(row, "", 1)},
                {**self.sources, "matrix": matrix + "\n" + row},
                {**self.sources, "matrix": matrix.replace(
                    row, row.replace("NO_VERIFICABLE", "CUBIERTO", 1), 1)},
                {**self.sources, "contrast": contrast.replace(other, "", 1)},
                {**self.sources, "contrast": contrast + "\n" + other},
                {**self.sources, "spec": self.sources["spec"].replace(
                    spec_clause(key), "missing specification", 1)},
                {**self.sources, "agents": ""},
                {**self.sources, "readme": ""},
            )
            for changed in cases:
                with self.subTest(requirement=key):
                    self.assertEqual(UNKNOWN, local_evidence(key, changed))
                    self.assertEqual(UNKNOWN, explicit_gap(key, changed))
            self.assertFalse(synthetic_witness(key, {}))
            self.assertFalse(synthetic_witness(key, None))
        self.assertFalse(synthetic_witness("S51-R007", {
            **FIXTURES["S51-R007"], "merge_sha": "unverified"}))
        self.assertFalse(synthetic_witness("S51-R007", {
            **FIXTURES["S51-R007"], "deployed": True}))
        self.assertFalse(synthetic_witness("S51-R008", {
            **FIXTURES["S51-R008"], "resolution": ""}))
        self.assertFalse(synthetic_witness("S51-R009", {
            **FIXTURES["S51-R009"], "recorded_after": "pending"}))
        self.assertFalse(synthetic_witness("S51-R010", {
            "roadmap_excerpt": "- ✅ Policy: mandatory security doctrine #101"}))
        self.assertFalse(synthetic_witness("S51-R012", {
            "owner": "README.md", "purpose": "agent operating instructions"}))
        self.assertEqual(UNKNOWN, local_evidence("S51-R999", self.sources))
        with self.assertRaisesRegex(ValueError, UNKNOWN):
            matrix_rows(None)
        with self.assertRaisesRegex(ValueError, UNKNOWN):
            contrast_rows("")

    def test_sin_datos_reales(self) -> None:
        raw = Path(__file__).read_text(encoding="utf-8")
        tree = ast.parse(raw)
        allowed = {"__future__", "ast", "pathlib", "re", "unittest"}
        for node in ast.walk(tree):
            if isinstance(node, ast.Import):
                for alias in node.names:
                    self.assertIn(alias.name.split(".")[0], allowed)
            elif isinstance(node, ast.ImportFrom) and node.module:
                self.assertIn(node.module.split(".")[0], allowed)
        self.assertEqual(set(FILES.values()), {
            "docs/matriz-cobertura.md", "docs/contraste-spec_editor.md",
            "docs/BRVTAL-SPEC.md", "AGENTS.md", "README.md"})
        self.assertTrue(all(not path.startswith(("/", ".."))
                            for path in FILES.values()))
        self.assertNotIn("BEGIN " + "PRIVATE KEY", raw)
        self.assertEqual(UNKNOWN, operational_evidence("S51-R007", {
            "merge": "verified", "production": "ready"}))

    def test_estados_unknown_explicitos(self) -> None:
        for key in CLAUSES:
            with self.subTest(requirement=key):
                self.assertEqual("NO_VERIFICABLE", matrix_rows(
                    self.sources["matrix"])[key][2])
                for forged in (None, {}, {"ci": "success", "sha": "a" * 40},
                               {"deployment": "verified", "owner": "approved"},
                               {"roadmap_533": "compliant", "review": "approved"}):
                    self.assertEqual(UNKNOWN, operational_evidence(key, forged))
                self.assertIn("UNKNOWN", explicit_gap(key, self.sources))
        self.assertEqual(UNKNOWN, local_evidence(None, self.sources))
        self.assertEqual(UNKNOWN, local_evidence("S51-R010", {
            **self.sources, "unexpected": "approved"}))


if __name__ == "__main__":
    unittest.main()
