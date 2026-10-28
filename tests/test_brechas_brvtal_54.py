#!/usr/bin/env python3
"""BRVTAL #1144: synthetic pre-deploy checks for S38-L599, R001..R004.

This test never connects to a host or DB and never certifies a live deployment.
"""
from __future__ import annotations

import hashlib
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
SPEC = ROOT / "docs/BRVTAL-SPEC.md"
WORKFLOW = ROOT / ".github/workflows/update-release-metadata.yml"
PHP_SUITE = ROOT / "scripts/php85-compatibility.sh"
FACTORY_SUITE = ROOT / "tests/factory-ci-contract.php"
MIGRATION_SUITE = ROOT / "tests/migrations-contract.php"
MEDIA_SUITE = ROOT / "tests/media-integrity-contract.php"
UNKNOWN = "UNKNOWN"
OFFLINE = "OFFLINE_CONTRACT"
CLAUSES = {
    "S38-L599": (599, "integrity;"),
    "S38-R001": (590, "Before a change is considered ready for production, validate as applicable:"),
    "S38-R002": (593, "JavaScript syntax;"),
    "S38-R003": (594, "automated contracts/tests;"),
    "S38-R004": (595, "DB/migrations;"),
}
EXPECTED = frozenset({"preflight", "coordination-contract", "fast", "javascript",
                      "contracts", "database", "integrity"})


def matrix_rows(document: str) -> dict[str, tuple[str, ...]]:
    """Ambiguous rows, wrong anchors or unverified evidence fail closed."""
    found: dict[str, tuple[str, ...]] = {}
    for line in document.splitlines():
        match = re.match(r"^\| (S38-(?:L599|R00[1-4])) · ", line)
        if match is None:
            continue
        ident = match.group(1)
        if ident in found:
            return {}
        cells = tuple(field.strip() for field in line.strip("|").split("|"))
        anchor, phrase = CLAUSES[ident]
        if (len(cells) != 5 or cells[2] != "NO_VERIFICABLE"
                or phrase != cells[1]
                or not cells[0].startswith(f"{ident} · [S38 L{anchor}]")
                or f"/docs/BRVTAL-SPEC.md#L{anchor})" not in cells[0]
                or "Sin aserción contrastada" not in cells[3]):
            return {}
        found[ident] = cells
    return found if set(found) == set(CLAUSES) else {}


def spec_has_contract(source: str, phrase: str) -> bool:
    marker = "## 38. Pre-deploy checks\n"
    if source.count(marker) != 1:
        return False
    section = source.split(marker, 1)[1].split("\n## ", 1)[0]
    return (section.count(phrase) == 1
            and "On update failure, stop." in section
            and "Do not blindly continue dependent changes." in section)


def runtime_contracts(sources: dict[str, str]) -> dict[str, bool]:
    """Verify that canonical CI enforces each named gate; no CI result assumed."""
    ci = sources.get("workflow", "")
    fast = ci.split("  fast:\n", 1)[-1].split("  database:\n", 1)[0]
    database = ci.split("  database:\n", 1)[-1].split("  browser:\n", 1)[0]
    validate = ci.split("  validate:\n", 1)[-1]
    php = sources.get("php", "")
    factory = sources.get("factory", "")
    migrations = sources.get("migrations", "")
    media = sources.get("media", "")
    return {
        "S38-L599": ("media-integrity-contract.php" in php
                       or ("-contract.php" in php and "media_integrity_assert(" in media
                           and "brvtal_media_restore_staged_delete(" in media)),
        "S38-R001": ("name: preflight" in ci and "name: validate" in ci
                       and "Enforce validation gates" in validate
                       and 'require_success fast "$FAST_RESULT"' in validate
                       and 'require_optional database "$RUN_DB" "$DB_RESULT"' in validate),
        "S38-R002": ("name: Validate JavaScript syntax" in fast
                       and "node --check" in fast
                       and "needs.preflight.outputs.run_js == 'true'" in fast),
        "S38-R003": ("bash scripts/php85-compatibility.sh" in fast
                       and "*-contract.php" in php and "if ! php" in php
                       and "FACTORY CI CONTRACT FAILED" in factory),
        "S38-R004": ("name: database" in ci and "mariadb" in ci.lower()
                       and "migration" in database.lower()
                       and "MIGRATION_NON_ADDITIVE_SQL" in migrations
                       and "DROP must be rejected" in migrations),
    }


def safe_env(home: str) -> dict[str, str]:
    """Explicit allowlist: no inherited secrets, DB or proxy configuration."""
    return {"PATH": os.environ.get("PATH", "/usr/bin:/bin"),
            "HOME": home, "LANG": "C", "APP_ENV": "testing"}


def synthetic_node_syntax(code: str) -> str:
    binary = shutil.which("node")
    if not binary:
        return UNKNOWN
    with tempfile.TemporaryDirectory(prefix="brvtal-s38-node-") as tmp:
        filename = Path(tmp) / "synthetic.js"
        filename.write_text(code, encoding="utf-8")
        run = subprocess.run(
            [binary, "--check", str(filename)], cwd=tmp, env=safe_env(tmp),
            capture_output=True, text=True, check=False, timeout=15,
        )
    return OFFLINE if run.returncode == 0 else UNKNOWN


def synthetic_migration(sql: str) -> str:
    """Use the real PHP additive scanner on harmless literal SQL, without a DB."""
    binary = shutil.which("php")
    if not binary:
        return UNKNOWN
    if not isinstance(sql, str) or not sql:
        return UNKNOWN
    script = ("require 'config/migrations.php'; "
              "try { brvtalMigrationAssertAdditiveSql($argv[1]); "
              "echo 'SAFE'; } catch (RuntimeException $e) { echo 'BLOCKED'; }")
    with tempfile.TemporaryDirectory(prefix="brvtal-s38-php-") as tmp:
        proc = subprocess.run(
            [binary, "-r", script, sql], cwd=ROOT, env=safe_env(tmp),
            capture_output=True, text=True, check=False, timeout=20,
        )
    if proc.returncode != 0:
        return UNKNOWN
    return OFFLINE if proc.stdout == "SAFE" else UNKNOWN


def synthetic_integrity(blob: object, checksum: object) -> str:
    if (not isinstance(blob, bytes) or not blob or not isinstance(checksum, str)
            or re.fullmatch(r"[0-9a-f]{64}", checksum) is None):
        return UNKNOWN
    return OFFLINE if hashlib.sha256(blob).hexdigest() == checksum else UNKNOWN


def synthetic_gate(evidence: object) -> str:
    """This is a local CI model, not a decision to deploy or publish."""
    if (not isinstance(evidence, dict) or set(evidence) !=
            {"source", "head_sha", "checks"}
            or evidence["source"] != "synthetic-fixture"
            or not isinstance(evidence["head_sha"], str)
            or re.fullmatch(r"[0-9a-f]{40}", evidence["head_sha"]) is None
            or not isinstance(evidence["checks"], dict)
            or set(evidence["checks"]) != EXPECTED
            or any(status != "success" for status in evidence["checks"].values())):
        return UNKNOWN
    return OFFLINE


def operational_attestation(proof: object) -> str:
    """No doc, synthetic fixture, test run or CI badge proves deployed state."""
    return UNKNOWN


class BrechasBrvtal54Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.matrix = MATRIX.read_text(encoding="utf-8")
        cls.spec = SPEC.read_text(encoding="utf-8")
        cls.sources = {
            "workflow": WORKFLOW.read_text(encoding="utf-8"),
            "php": PHP_SUITE.read_text(encoding="utf-8"),
            "factory": FACTORY_SUITE.read_text(encoding="utf-8"),
            "migrations": MIGRATION_SUITE.read_text(encoding="utf-8"),
            "media": MEDIA_SUITE.read_text(encoding="utf-8"),
        }

    def test_contrato_principal(self) -> None:
        """AC-01: all five SPEC rows are connected to distinct real CI contracts."""
        rows = matrix_rows(self.matrix)
        self.assertEqual(set(CLAUSES), set(rows))
        gates = runtime_contracts(self.sources)
        self.assertEqual(set(gates), set(CLAUSES))
        for ident, (_, phrase) in CLAUSES.items():
            with self.subTest(id=ident):
                self.assertTrue(spec_has_contract(self.spec, phrase))
                self.assertTrue(gates[ident], ident)
                self.assertEqual(rows[ident][2], "NO_VERIFICABLE")
                self.assertEqual(operational_attestation(gates[ident]), UNKNOWN)
        # R002: execute the same syntax parser CI uses, on synthetic JS only.
        node = synthetic_node_syntax("const synthetic = 1 + 2;\n")
        self.assertIn(node, (OFFLINE, UNKNOWN))
        self.assertEqual(synthetic_node_syntax("function broken( {\n"), UNKNOWN)
        # R003: a complete isolated positive decision must name every gate.
        record = {"source": "synthetic-fixture", "head_sha": "a" * 40,
                  "checks": {key: "success" for key in EXPECTED}}
        self.assertEqual(synthetic_gate(record), OFFLINE)
        # R004: call real additive-only scanner without a database.
        additive = synthetic_migration("CREATE TABLE IF NOT EXISTS synthetic_probe (id INT);")
        self.assertIn(additive, (OFFLINE, UNKNOWN))
        self.assertEqual(synthetic_migration("DROP TABLE synthetic_probe;"), UNKNOWN)
        # L599: checksum protects a disposable byte string, not live media.
        blob = b"BRVTAL synthetic integrity fixture"
        self.assertEqual(synthetic_integrity(blob, hashlib.sha256(blob).hexdigest()),
                         OFFLINE)

    def test_falla_cerrado(self) -> None:
        """AC-02: missing, partial, duplicated or inconsistent evidence → UNKNOWN."""
        rows = matrix_rows(self.matrix)
        first = next(line for line in self.matrix.splitlines()
                     if line.startswith("| S38-R001 · "))
        self.assertEqual({}, matrix_rows(self.matrix + "\n" + first))
        self.assertEqual({}, matrix_rows(self.matrix.replace(first + "\n", "", 1)))
        self.assertEqual({}, matrix_rows(self.matrix.replace(
            first, first.replace("#L590)", "#L591)"), 1)))
        bad_source = dict(self.sources)
        bad_source["workflow"] = ""
        self.assertFalse(runtime_contracts(bad_source)["S38-R002"])
        for ident in CLAUSES:
            with self.subTest(id=ident):
                self.assertEqual(rows[ident][2], "NO_VERIFICABLE")
                self.assertEqual(operational_attestation(None), UNKNOWN)
        good = {"source": "synthetic-fixture", "head_sha": "a" * 40,
                "checks": {key: "success" for key in EXPECTED}}
        for changed in ({**good, "source": "production"},
                        {**good, "head_sha": "missing"},
                        {**good, "checks": {"fast": "success"}},
                        {**good, "checks": {**good["checks"], "database": "skipped"}}):
            self.assertEqual(synthetic_gate(changed), UNKNOWN)
        for blob, digest in ((b"one", None), (b"two", "x" * 64),
                             (None, "a" * 64)):
            self.assertEqual(synthetic_integrity(blob, digest), UNKNOWN)
        self.assertEqual(synthetic_migration("DELETE FROM fake_table;"), UNKNOWN)
        self.assertFalse(spec_has_contract("", CLAUSES["S38-R002"][1]))

    def test_sin_datos_reales(self) -> None:
        """AC-03: no network, credentials, real customer or production state."""
        env = safe_env("/tmp/brvtal-synthetic-test")
        for secret in ("DB_HOST", "DB_PASSWORD", "DATABASE_URL", "MYSQL_PWD",
                       "GITHUB_TOKEN", "APP_KEY", "HTTP_PROXY", "HTTPS_PROXY",
                       "AUTHORIZATION", "COOKIE"):
            with self.subTest(secret=secret):
                self.assertNotIn(secret, env)
        self.assertEqual("testing", env["APP_ENV"])
        source = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(source, r"(?m)^\s*(?:import|from)\s+(?:requests|httpx|socket|urllib)\b")
        self.assertIn("tempfile.TemporaryDirectory", source)
        self.assertNotIn("mysqli_connect(", source)
        self.assertNotIn("new PDO(", source)
        self.assertNotIn("https://api.github.com", source)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: offline contracts cannot certify live, even with CI success."""
        rows = matrix_rows(self.matrix)
        self.assertEqual(set(CLAUSES), set(rows))
        for ident in CLAUSES:
            with self.subTest(id=ident):
                self.assertEqual(rows[ident][2], "NO_VERIFICABLE")
                for claimed in (None, True, {"ci": "success"},
                                {"deployed": True, "sha": "a" * 40},
                                {"source": "README.md", "ready": True}):
                    self.assertEqual(operational_attestation(claimed), UNKNOWN)
        self.assertEqual(synthetic_gate({"source": "synthetic-fixture"}), UNKNOWN)
        self.assertEqual(synthetic_integrity(b"", hashlib.sha256(b"").hexdigest()),
                         UNKNOWN)


if __name__ == "__main__":
    unittest.main()
