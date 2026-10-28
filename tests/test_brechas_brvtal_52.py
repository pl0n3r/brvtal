#!/usr/bin/env python3
"""BRVTAL #1142: five offline delivery/hosting gaps; no live claims."""
from __future__ import annotations

import ast
from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_CONTRACT"
CLAUSES = {
    "S35-R003": ("35. GitHub and deployment", "GitHub is the code/source/history/review/deployment source of truth."),
    "S35-R005": ("35. GitHub and deployment", "Do not use FTP as the normal deployment path."),
    "S35-R007": ("35. GitHub and deployment", "Normal delivery flow:"),
    "S36-R002": ("36. Hosting constraints", "Design for shared-hosting constraints and avoid unnecessary infrastructure complexity."),
    "S36-R003": ("36. Hosting constraints", "Host filesystem totals exposed by PHP may describe a shared node and must not be treated as the account quota."),
}
SOURCES = (
    "docs/matriz-cobertura.md", "docs/BRVTAL-SPEC.md", "AGENTS.md",
    "tests/factory-deploy-adapters-contract.php",
    "tests/factory-hostinger-transport-contract.php",
    ".github/workflows/update-release-metadata.yml",
    ".github/workflows/production-deploy-observer.yml",
    "ops/factory/transport.py",
)


def source_text() -> dict[str, str]:
    """Read tracked source only; no HTTP, runtime secrets or environment."""
    return {path: (ROOT / path).read_text(encoding="utf-8") for path in SOURCES}


def matrix_rows(document: object) -> dict[str, tuple[str, ...]]:
    """Missing, duplicate, damaged or promoted rows fail closed."""
    if not isinstance(document, str):
        raise ValueError("UNKNOWN: unreadable matrix")
    rows = {}
    for identifier, (_, statement) in CLAUSES.items():
        lines = [line for line in document.splitlines()
                 if line.startswith(f"| {identifier} ·")]
        if len(lines) != 1:
            raise ValueError(f"UNKNOWN: missing/duplicate {identifier}")
        cells = tuple(cell.strip() for cell in lines[0].strip("|").split("|"))
        if (len(cells) != 5 or not all(cells[:4])
                or not cells[0].startswith(identifier + " · [S")
                or cells[1] != statement or cells[2] != "NO_VERIFICABLE"
                or "Sin aserción contrastada" not in cells[3]):
            raise ValueError(f"UNKNOWN: invalid {identifier}")
        rows[identifier] = cells
    return rows


def section(document: object, name: str) -> str:
    """Scope evidence to its owning section, never unrelated prose."""
    if not isinstance(document, str):
        return ""
    lines = document.splitlines()
    found = [idx for idx, line in enumerate(lines) if line.strip() == "## " + name]
    if len(found) != 1:
        return ""
    end = next((idx for idx in range(found[0] + 1, len(lines))
                if lines[idx].startswith("## ")), len(lines))
    return "\n".join(lines[found[0] + 1:end])


def contract(identifier: str, sources: dict[str, str]) -> str:
    """Local evidence is narrow; production evidence remains UNKNOWN."""
    if identifier not in CLAUSES or not isinstance(sources, dict):
        return UNKNOWN
    if any(not isinstance(sources.get(path), str) or not sources[path].strip()
           for path in SOURCES):
        return UNKNOWN
    try:
        row = matrix_rows(sources["docs/matriz-cobertura.md"])[identifier]
    except (KeyError, TypeError, ValueError):
        return UNKNOWN
    heading, statement = CLAUSES[identifier]
    spec = section(sources["docs/BRVTAL-SPEC.md"], heading)
    if not spec or statement not in spec or row[1] != statement:
        return UNKNOWN
    agents = sources["AGENTS.md"]
    adapter = sources["tests/factory-deploy-adapters-contract.php"]
    host = sources["tests/factory-hostinger-transport-contract.php"]
    transport = sources["ops/factory/transport.py"]
    ci = sources[".github/workflows/update-release-metadata.yml"]
    observer = sources[".github/workflows/production-deploy-observer.yml"]
    if identifier == "S35-R003":
        valid = ("Current merged code/tests on `main`" in agents
                 and "Canonical branch: `main`" in spec
                 and "name: BRVTAL CI" in ci
                 and "pull_request:" in ci and "branches: [main]" in ci)
    elif identifier == "S35-R005":
        valid = ("Do not perform a manual Hostinger deploy unless explicitly requested." in spec
                 and "GitHub main → Hostinger automatic Git deployment" in spec
                 and "StrictHostKeyChecking=yes" in transport
                 and "on:\n  push:\n    branches: [main]" in observer
                 and "ftp:" not in observer.lower())
    elif identifier == "S35-R007":
        valid = ("branch → PR → CI → squash merge → `main` CI → Hostinger auto-deploy → production verification." in spec
                 and "factory_transport backup" in adapter
                 and "factory_transport rollback" in adapter
                 and "name: BRVTAL CI" in ci
                 and "expected_sha:" in observer)
    elif identifier == "S36-R002":
        valid = ("Hostinger Premium shared/managed LiteSpeed" in spec
                 and "no SSH" in spec
                 and "Hostinger shared hosting / LiteSpeed" in agents
                 and "factory-releases" in transport
                 and "factory-shared" in transport)
    else:
        valid = ("approximately 25 GB operational account storage" in spec
                 and "Host filesystem totals exposed by PHP" in spec
                 and "StrictHostKeyChecking=yes" in host
                 and "factory-shared" in host)
    return LOCAL if valid else UNKNOWN


def account_quota(_filesystem_total: object, _claim: object = None) -> str:
    """PHP node totals and approximate documentation cannot prove account quota."""
    return UNKNOWN


def operational_evidence(_requirement: object, _claim: object = None) -> str:
    """GitHub/source fixtures cannot certify deployment, live flow or account quota."""
    return UNKNOWN


class BrechasBrvtal52Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: exercise each of five distinct local contracts."""
        sources = source_text()
        rows = matrix_rows(sources["docs/matriz-cobertura.md"])
        self.assertEqual(set(CLAUSES), set(rows))
        for identifier in CLAUSES:
            with self.subTest(identifier=identifier):
                self.assertEqual(LOCAL, contract(identifier, sources))
                self.assertEqual("NO_VERIFICABLE", rows[identifier][2])
                self.assertEqual(UNKNOWN, operational_evidence(identifier))

    def test_falla_cerrado(self) -> None:
        """AC-02: ambiguous matrix, missing source or tampering is UNKNOWN."""
        sources = source_text()
        matrix = sources["docs/matriz-cobertura.md"]
        for identifier in CLAUSES:
            row = next(x for x in matrix.splitlines() if x.startswith(f"| {identifier} ·"))
            for bad in (matrix.replace(row + "\n", "", 1),
                        matrix + "\n" + row,
                        matrix.replace(row, row.replace("NO_VERIFICABLE", "CUBIERTO"), 1)):
                with self.subTest(identifier=identifier), self.assertRaisesRegex(ValueError, "UNKNOWN"):
                    matrix_rows(bad)
        mutations = {
            "S35-R003": ("AGENTS.md", "Current merged code/tests on `main`", "Unknown source"),
            "S35-R005": ("ops/factory/transport.py", "StrictHostKeyChecking=yes", "StrictHostKeyChecking=no"),
            "S35-R007": (".github/workflows/update-release-metadata.yml", "pull_request:", "manual_only:"),
            "S36-R002": ("docs/BRVTAL-SPEC.md", "Design for shared-hosting constraints", "Ignore shared hosting"),
            "S36-R003": ("docs/BRVTAL-SPEC.md", "Host filesystem totals exposed by PHP", "PHP totals are account quota"),
        }
        for identifier, (path, original, modified) in mutations.items():
            changed = dict(sources)
            self.assertIn(original, changed[path])
            changed[path] = changed[path].replace(original, modified, 1)
            with self.subTest(tamper=identifier):
                self.assertEqual(UNKNOWN, contract(identifier, changed))
        self.assertEqual(UNKNOWN, contract("S99-R999", sources))
        self.assertEqual(UNKNOWN, contract("S35-R003", {}))

    def test_sin_datos_reales(self) -> None:
        """AC-03: local, deterministic and without network/DB/user data."""
        example = {"filesystem_total": 10**12, "quota": "not_verified", "sha": "synthetic"}
        self.assertEqual(UNKNOWN, account_quota(example["filesystem_total"], example))
        self.assertEqual(set(SOURCES), set(source_text()))
        code = Path(__file__).read_text(encoding="utf-8")
        tree = ast.parse(code)
        imported = {alias.name.split(".")[0] for item in ast.walk(tree)
                    if isinstance(item, ast.Import) for alias in item.names}
        imported |= {item.module.split(".")[0] for item in ast.walk(tree)
                     if isinstance(item, ast.ImportFrom) and item.module}
        self.assertFalse(imported & {"requests", "urllib", "socket", "http", "subprocess"})
        self.assertNotIn("os.environ", code)
        self.assertNotIn("new PDO(", code)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: CI green, synthetic deploy and host totals are never live proof."""
        sources = source_text()
        claims = (None, {}, {"ci": "success"}, {"pr": "merged"},
                  {"ftp": "disabled"}, {"deployment": "observed"},
                  {"status": "VALIDATED_IN_PRODUCTION"}, {"disk_total": 10**12},
                  {"account_quota": "25 GB"})
        for identifier in CLAUSES:
            with self.subTest(identifier=identifier):
                self.assertEqual(LOCAL, contract(identifier, sources))
                for claim in claims:
                    self.assertEqual(UNKNOWN, operational_evidence(identifier, claim))
                    self.assertEqual(UNKNOWN, account_quota(10**12, claim))


if __name__ == "__main__":
    unittest.main()
