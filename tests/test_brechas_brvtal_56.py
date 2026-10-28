#!/usr/bin/env python3
"""Offline coverage probes for BRVTAL #1146, S39-R001..S39-R005.

Source-level evidence is LOCAL_PARTIAL, never deploy or E2E attestation.
The coverage matrix remains NO_VERIFICABLE pending a separate reconciliation.
"""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
SOURCES = {
    "ci": ROOT / ".github/workflows/update-release-metadata.yml",
    "release": ROOT / ".github/workflows/factory-release.yml",
    "version": ROOT / "config/version.php",
    "spec": ROOT / "docs/BRVTAL-SPEC.md",
}
CLAUSES = {
    "S39-R001": ".github/workflows/update-release-metadata.yml is historically named but currently runs **BRVTAL CI**.",
    "S39-R002": "It validates PHP/JavaScript, contracts, MariaDB integrations, migration idempotency and browser behavior.",
    "S39-R003": "It does **not** rewrite config/version.php on every commit.",
    "S39-R004": "config/version.php conceptually contains intentional product/release metadata such as:",
    "S39-R005": "BRVTAL_APP_VERSION;",
}
UNKNOWN = "UNKNOWN"
LOCAL_PARTIAL = "LOCAL_PARTIAL"


def coverage_rows(document: str) -> dict[str, tuple[str, ...]]:
    """Reject missing, duplicate or malformed coverage claims."""
    rows: dict[str, tuple[str, ...]] = {}
    for line in document.splitlines():
        match = re.match(r"^\|\s*(S39-R00[1-5])\s*·", line)
        if match is None:
            continue
        clause = match.group(1)
        cells = tuple(cell.strip() for cell in line.strip().strip("|").split("|"))
        if (clause in rows or len(cells) != 5 or not all(cells)
                or cells[1] != CLAUSES[clause]
                or not cells[0].startswith(clause + " ·")
                or cells[2] != "NO_VERIFICABLE"):
            raise ValueError("UNKNOWN: corrupt or duplicate matrix row " + clause)
        rows[clause] = cells
    if set(rows) != set(CLAUSES):
        raise ValueError("UNKNOWN: incomplete S39 matrix coverage")
    return rows


def version_value(version: str) -> str | None:
    versions = re.findall(r"(?m)^const BRVTAL_APP_VERSION = '([0-9]+\.[0-9]+\.[0-9]+)';$", version)
    return versions[0] if len(versions) == 1 else None


def local_evidence(source: dict[str, str]) -> dict[str, str]:
    """Use actual source contracts, but never convert them into live proof."""
    if (set(source) != set(SOURCES)
            or any(not isinstance(value, str) or not value.strip()
                   for value in source.values())):
        return {key: UNKNOWN for key in CLAUSES}
    ci, release, version, spec = (source[key] for key in SOURCES)
    tokens = {
        "S39-R001": (
            ci.startswith("name: BRVTAL CI\n")
            and "run-name: BRVTAL CI" in ci
            and "## 39. CI and release metadata workflow" in spec
        ),
        "S39-R002": all(token in ci for token in (
            "name: fast", "bash scripts/php85-compatibility.sh",
            "node --check", "name: database", "npm run test:integration",
            "Validate Blog migration is idempotent", "name: chromium",
            "name: validate",
        )),
        "S39-R003": (
            "BRVTAL_BASE_RELEASE" in ci
            and "BRVTAL_HEAD_RELEASE" in ci
            and "git show" in ci
            and all(command not in ci for command in (
                "git add config/version.php", "git commit -am",
                "sed -i config/version.php", "cat > config/version.php",
                "tee config/version.php", ">> config/version.php",
            ))
            and "It does **not** rewrite `config/version.php` on every commit." in spec
        ),
        "S39-R004": (
            all("const " + key + " = " in version for key in (
                "BRVTAL_APP_VERSION", "BRVTAL_APP_BUILD",
                "BRVTAL_APP_ENV", "BRVTAL_RELEASE_DATE",
            ))
            and "config/version.php" in spec
        ),
        "S39-R005": (
            version_value(version) is not None
            and "version_source: config/version.php" in release
            and "version_key: BRVTAL_APP_VERSION" in release
            and "- `BRVTAL_APP_VERSION`;" in spec
        ),
    }
    return {key: LOCAL_PARTIAL if passed else UNKNOWN
            for key, passed in tokens.items()}


def integral_proof(*, clause: str, local: object,
                   ci: object = None, live: object = None) -> str:
    """No synthetic input, self-assertion or green badge proves live behavior."""
    return UNKNOWN


class BrechasBrvtal56Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.rows = coverage_rows(MATRIX.read_text(encoding="utf-8"))
        cls.sources = {name: path.read_text(encoding="utf-8")
                       for name, path in SOURCES.items()}
        cls.local = local_evidence(cls.sources)

    def test_contrato_principal(self) -> None:
        """AC-01: Each S39 clause has an executable local witness and honest limit."""
        self.assertEqual(set(self.rows), set(CLAUSES))
        for clause, title in CLAUSES.items():
            with self.subTest(clause=clause):
                self.assertEqual(self.rows[clause][1], title)
                self.assertIn("Sin aserción contrastada", self.rows[clause][3])
                self.assertEqual(self.local[clause], LOCAL_PARTIAL)
                self.assertEqual(integral_proof(clause=clause, local=self.local[clause]),
                                 UNKNOWN)

    def test_falla_cerrado(self) -> None:
        """AC-02: Missing, ambiguous, damaged sources cannot silently succeed."""
        matrix = MATRIX.read_text(encoding="utf-8")
        original = next(row for row in matrix.splitlines()
                        if row.startswith("| S39-R001 ·"))
        for corrupt in (matrix.replace(original, ""), matrix + "\n" + original,
                        matrix.replace(original, "| S39-R001 · invalid |")):
            with self.subTest(corrupt_length=len(corrupt)):
                with self.assertRaisesRegex(ValueError, UNKNOWN):
                    coverage_rows(corrupt)
        for source in SOURCES:
            with self.subTest(missing=source):
                damaged = dict(self.sources)
                damaged.pop(source)
                self.assertEqual(set(local_evidence(damaged).values()), {UNKNOWN})
        mutations = {
            "S39-R001": ("ci", "name: BRVTAL CI"),
            "S39-R002": ("ci", "npm run test:integration"),
            "S39-R003": ("ci", "BRVTAL_HEAD_RELEASE"),
            "S39-R004": ("version", "const BRVTAL_APP_ENV = "),
            "S39-R005": ("release", "version_key: BRVTAL_APP_VERSION"),
        }
        for clause, (source, phrase) in mutations.items():
            with self.subTest(clause=clause):
                damaged = dict(self.sources)
                self.assertIn(phrase, damaged[source])
                damaged[source] = damaged[source].replace(phrase, "")
                self.assertEqual(local_evidence(damaged)[clause], UNKNOWN)
        for bad_version in ("", "0.1", "0.1.x", "0.1.2-alpha"):
            self.assertIsNone(version_value("const BRVTAL_APP_VERSION = '" + bad_version + "';"))

    def test_sin_datos_reales(self) -> None:
        """AC-03: Fixed local sources and fake release strings, no network/PII."""
        self.assertTrue(all(path.is_file() for path in (MATRIX, *SOURCES.values())))
        synthetic = {"release": "synthetic:release:001", "version": "0.1.22",
                     "commit": "synthetic:commit:0001"}
        self.assertTrue(synthetic["release"].startswith("synthetic:"))
        self.assertNotIn("@", synthetic["commit"])
        self.assertEqual(integral_proof(clause="S39-R005", local=synthetic), UNKNOWN)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: No green CI, source metadata or placeholder proves deployment."""
        for clause in CLAUSES:
            with self.subTest(clause=clause):
                self.assertEqual(self.rows[clause][2], "NO_VERIFICABLE")
                for spoofed in (None, "success", {"ci": "success"},
                                {"production": True}, {"review": "approved"},
                                {"version": "0.1.22"}, {"all_modules": True}):
                    self.assertEqual(integral_proof(clause=clause, local=self.local[clause],
                                                    ci=spoofed, live=spoofed), UNKNOWN)
        self.assertEqual(integral_proof(clause="S39-UNKNOWN", local=LOCAL_PARTIAL),
                         UNKNOWN)


if __name__ == "__main__":
    unittest.main()
