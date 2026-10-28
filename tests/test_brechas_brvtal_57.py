#!/usr/bin/env python3
"""#1147: offline contracts for release metadata S39-R006 through S39-R010.

Synthetic PHP subprocesses exercise the real release/deployment resolver. They
never observe production, infer deployment readiness, or create a new release.
"""
from __future__ import annotations

from datetime import date
import json
import os
import re
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
VERSION = ROOT / "config/version.php"
DEPLOYMENT = ROOT / "config/deployment.php"
SPEC = ROOT / "docs/BRVTAL-SPEC.md"
CLAUSES = tuple(f"S39-R{i:03d}" for i in range(6, 11))
UNKNOWN = "UNKNOWN"
SHA_A = "abcdef01" + "2" * 32
SHA_B = "76543210" + "a" * 32

PHP_PROBE = r"""
require $argv[1];
echo json_encode([
    'version' => BRVTAL_APP_VERSION,
    'build' => BRVTAL_APP_BUILD,
    'environment' => BRVTAL_APP_ENV,
    'release_date' => BRVTAL_RELEASE_DATE,
    'sha' => brvtal_deployment_sha(),
    'source' => brvtal_deployment_source(),
    'exact' => brvtalDeploymentIsExact(),
    'release' => brvtalReleaseIdentity(),
    'public' => brvtalDeploymentPublicData(),
], JSON_THROW_ON_ERROR);
"""


def matrix_rows(source: str) -> dict[str, list[str]]:
    """All five unique, complete rows are needed; partial/ambiguous = UNKNOWN."""
    rows: dict[str, list[str]] = {}
    for line in source.splitlines():
        match = re.match(r"^\| (S39-R00[6-9]|S39-R010) ·", line)
        if not match:
            continue
        key = match.group(1)
        cells = [cell.strip() for cell in line.strip("|").split("|")]
        if key in rows or len(cells) != 5:
            return {}
        rows[key] = cells
    return rows if set(rows) == set(CLAUSES) else {}


def evidence(value: object) -> str:
    """Only a proven boolean witness, not a label, can pass a synthetic test."""
    return "TEST_EXECUTED" if value is True else UNKNOWN


def safe_env(home: str, commit: str | None = None) -> dict[str, str]:
    env = {"PATH": os.environ.get("PATH", "/usr/bin:/bin"),
           "HOME": home, "LANG": "C", "APP_ENV": "testing"}
    if commit is not None:
        env["BRVTAL_DEPLOY_COMMIT"] = commit
    return env


def modified_metadata(source: str) -> str:
    """Change only compatibility labels, not product semantic version."""
    substitutions = {
        "BRVTAL_APP_BUILD": "synthetic7",
        "BRVTAL_APP_ENV": "SYNTHETIC",
        "BRVTAL_RELEASE_DATE": "2099-02-03",
    }
    for name, value in substitutions.items():
        source, count = re.subn(
            rf"(?m)^(const {name} = )'[^']*'(;)$",
            lambda match: f"{match.group(1)}'{value}'{match.group(2)}",
            source,
        )
        if count != 1:
            raise AssertionError(f"UNKNOWN: ambiguous constant {name}")
    return source


def run_php(version_source: str, commit: str | None = None) -> dict:
    """Run copied real PHP modules with an empty, synthetic environment."""
    binary = shutil.which("php")
    if binary is None:
        raise AssertionError("UNKNOWN: PHP CLI unavailable")
    with tempfile.TemporaryDirectory(prefix="brvtal-s39-synthetic-") as temp:
        config = Path(temp) / "config"
        config.mkdir()
        (config / "version.php").write_text(version_source, encoding="utf-8")
        shutil.copyfile(DEPLOYMENT, config / "deployment.php")
        result = subprocess.run(
            [binary, "-r", PHP_PROBE, str(config / "deployment.php")],
            cwd=temp, env=safe_env(temp, commit), capture_output=True,
            text=True, timeout=20, check=False,
        )
        if result.returncode:
            raise AssertionError("UNKNOWN: isolated PHP execution failed")
        parsed = json.loads(result.stdout)
        if not isinstance(parsed, dict):
            raise AssertionError("UNKNOWN: invalid PHP result")
        return parsed


class BrechasBrvtal57Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """Five distinct clauses exercised against production PHP, offline."""
        rows = matrix_rows(MATRIX.read_text(encoding="utf-8"))
        self.assertEqual(set(CLAUSES), set(rows))
        if shutil.which("php") is None:
            self.skipTest("PHP CLI unavailable: runtime evidence remains UNKNOWN")
        source = VERSION.read_text(encoding="utf-8")
        exact = run_php(source, SHA_A)
        other = run_php(source, SHA_B)
        missing = run_php(source)
        invalid = run_php(source, "not-a-valid-sha")
        changed = run_php(modified_metadata(source), SHA_A)
        spec = SPEC.read_text(encoding="utf-8")
        product = exact["version"]
        self.assertRegex(product, r"^\d+\.\d+\.\d+$")
        date.fromisoformat(exact["release_date"])
        verdicts = {
            # Build is compatibility metadata, never proof of deployed commit.
            "S39-R006": (
                bool(exact["build"]) and exact["build"] == missing["build"]
                and missing["sha"] == exact["build"].lower()
                and missing["source"] == "release_fallback"
                and missing["public"]["commit"] is None
                and missing["public"]["exact"] is False
            ),
            # Environment label is published as metadata, not a deployment source.
            "S39-R007": (
                exact["public"]["environment"] == exact["environment"]
                and changed["environment"] == "SYNTHETIC"
                and changed["public"]["environment"] == "SYNTHETIC"
                and changed["source"] == exact["source"] == "environment"
            ),
            # The release date is explicit metadata independent of the commit.
            "S39-R008": (
                exact["public"]["release_date"] == exact["release_date"]
                and changed["release_date"] == "2099-02-03"
                and changed["public"]["release_date"] == "2099-02-03"
                and changed["release"] == exact["release"]
            ),
            # Only a valid runtime source supplies an exact deployed Git SHA.
            "S39-R009": (
                exact["sha"] == exact["public"]["commit"] == SHA_A
                and exact["exact"] is True
                and exact["source"] == exact["public"]["source"] == "environment"
                and other["sha"] == other["public"]["commit"] == SHA_B
                and invalid["source"] == missing["source"] == "release_fallback"
                and invalid["public"]["commit"] is None
                and invalid["public"]["exact"] is False
            ),
            # Altering only BUILD/ENV/DATE must not increment product semver.
            "S39-R010": (
                changed["version"] == exact["version"] == other["version"]
                and changed["release"] == exact["release"] == "v" + product
                and changed["public"]["release_identity"] == exact["public"]["release_identity"]
                and changed["sha"] == exact["sha"] == SHA_A
                and (changed["build"], changed["environment"], changed["release_date"])
                != (exact["build"], exact["environment"], exact["release_date"])
            ),
        }
        self.assertEqual(set(CLAUSES), set(verdicts))
        self.assertIn("Metadata-only changes are not themselves product features", spec)
        for clause in CLAUSES:
            with self.subTest(clause=clause):
                self.assertEqual("NO_VERIFICABLE", rows[clause][2])
                self.assertEqual("TEST_EXECUTED", evidence(verdicts[clause]), clause)

    def test_falla_cerrado(self) -> None:
        """Missing, duplicate and malformed rows and invalid sources remain UNKNOWN."""
        for value in (None, False, "success", "PRODUCTION", 1, {}, [], 0):
            with self.subTest(value=repr(value)):
                self.assertEqual(UNKNOWN, evidence(value))
        self.assertEqual({}, matrix_rows(""))
        original = MATRIX.read_text(encoding="utf-8")
        rows = matrix_rows(original)
        self.assertEqual(set(CLAUSES), set(rows))
        self.assertEqual({}, matrix_rows("\n".join(
            line for line in original.splitlines() if not line.startswith("| S39-R010 ·")
        )))
        self.assertEqual({}, matrix_rows(original + "\n" + next(
            line for line in original.splitlines() if line.startswith("| S39-R006 ·")
        )))
        self.assertEqual({}, matrix_rows("| S39-R006 · broken |"))
        if shutil.which("php") is not None:
            for invalid in (None, "abc1234", "not-a-valid-sha"):
                with self.subTest(commit=invalid):
                    result = run_php(VERSION.read_text(encoding="utf-8"), invalid)
                    self.assertEqual("release_fallback", result["source"])
                    self.assertIs(result["public"]["exact"], False)
                    self.assertIsNone(result["public"]["commit"])

    def test_sin_datos_reales(self) -> None:
        """No network, inherited credentials, real database, or production I/O."""
        with tempfile.TemporaryDirectory(prefix="brvtal-s39-fixture-") as temp:
            env = safe_env(temp, SHA_A)
            self.assertEqual("testing", env["APP_ENV"])
            self.assertEqual(SHA_A, env["BRVTAL_DEPLOY_COMMIT"])
            for secret in ("DB_PASSWORD", "DATABASE_URL", "MYSQL_PWD", "APP_KEY",
                           "GITHUB_TOKEN", "AWS_SECRET_ACCESS_KEY", "HTTP_PROXY",
                           "HTTPS_PROXY", "COOKIE", "AUTHORIZATION"):
                with self.subTest(secret=secret):
                    self.assertNotIn(secret, env)
        self.assertEqual(40, len(SHA_A))
        self.assertEqual(40, len(SHA_B))
        self.assertNotIn("curl", PHP_PROBE)
        self.assertNotIn("PDO(", PHP_PROBE)
        self.assertNotIn("http", PHP_PROBE)
        self.assertIn("tempfile.TemporaryDirectory(", Path(__file__).read_text(encoding="utf-8"))

    def test_estados_unknown_explicitos(self) -> None:
        """Synthetic tests never certify deployed/live production readiness."""
        rows = matrix_rows(MATRIX.read_text(encoding="utf-8"))
        self.assertEqual(set(CLAUSES), set(rows))
        for clause in CLAUSES:
            with self.subTest(clause=clause):
                self.assertEqual("NO_VERIFICABLE", rows[clause][2])
                self.assertEqual(UNKNOWN, evidence(None))
                self.assertIn("Sin aserción contrastada", rows[clause][3])
        for not_production in ("release_fallback", "PRODUCTION", "ready", SHA_A):
            self.assertEqual(UNKNOWN, evidence(not_production))


if __name__ == "__main__":
    unittest.main()
