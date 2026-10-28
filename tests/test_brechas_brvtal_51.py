#!/usr/bin/env python3
"""Issue #1141: five deterministic, offline-only requirements S34-R006..S35-R002.

Fixture evidence does not attest a live Hostinger deployment or release readiness.
"""
from __future__ import annotations

import json
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
AGENTS = ROOT / "AGENTS.md"
README = ROOT / "README.md"
VERSION = ROOT / "config/version.php"
DEPLOYMENT = ROOT / "config/deployment.php"
PACKAGE = ROOT / "package.json"
CI = ROOT / ".github/workflows/update-release-metadata.yml"
RELEASE = ROOT / ".github/workflows/factory-release.yml"
SCOPE = ROOT / "scripts/ci-scope.sh"
CLAUSES = ("S34-R006", "S34-R007", "S34-R008", "S35-R001", "S35-R002")
UNKNOWN = "UNKNOWN"
SHA_A = "a" * 40
SHA_B = "b" * 40
PROBE = r"""
require $argv[1];
echo json_encode([
    'version'=>BRVTAL_APP_VERSION,
    'build'=>BRVTAL_APP_BUILD,
    'date'=>BRVTAL_RELEASE_DATE,
    'source'=>brvtal_deployment_source(),
    'sha'=>brvtal_deployment_sha(),
    'exact'=>brvtalDeploymentIsExact(),
    'release'=>brvtalReleaseIdentity(),
    'cache'=>brvtalDeploymentCacheKey(),
    'public'=>brvtalDeploymentPublicData(),
], JSON_THROW_ON_ERROR);
"""


def safe_env(home: Path, sha: str | None = None) -> dict[str, str]:
    env = {"PATH": os.environ.get("PATH", "/usr/bin:/bin"),
           "HOME": str(home), "LANG": "C", "APP_ENV": "testing"}
    if sha is not None:
        env["BRVTAL_DEPLOY_COMMIT"] = sha
    return env


def run_php(root: Path, sha: str | None = None) -> dict:
    php = shutil.which("php")
    if php is None:
        raise AssertionError("UNKNOWN: PHP unavailable")
    result = subprocess.run(
        [php, "-r", PROBE, str(root / "config/deployment.php")],
        cwd=root, env=safe_env(root, sha), capture_output=True, text=True,
        timeout=15, check=False,
    )
    if result.returncode or result.stderr:
        raise AssertionError("UNKNOWN: isolated PHP contract execution failed")
    try:
        parsed = json.loads(result.stdout)
    except (ValueError, TypeError):
        raise AssertionError("UNKNOWN: invalid PHP fixture result") from None
    if not isinstance(parsed, dict):
        raise AssertionError("UNKNOWN: invalid PHP fixture type")
    return parsed


def make_sandbox(root: Path) -> None:
    cfg = root / "config"
    cfg.mkdir()
    shutil.copyfile(VERSION, cfg / "version.php")
    shutil.copyfile(DEPLOYMENT, cfg / "deployment.php")


def scenarios() -> dict[str, dict]:
    with tempfile.TemporaryDirectory(prefix="brvtal-s34-35-offline-") as temp:
        root = Path(temp)
        make_sandbox(root)
        env_a = run_php(root, SHA_A)
        env_b = run_php(root, SHA_B)
        invalid = run_php(root, "not-a-sha")
        missing = run_php(root)
        ref = root / ".git/refs/heads/main"
        ref.parent.mkdir(parents=True)
        (root / ".git/HEAD").write_text("ref: refs/heads/main\n", encoding="ascii")
        ref.write_text(SHA_B + "\n", encoding="ascii")
        checkout = run_php(root)
        precedence = run_php(root, SHA_A)
        ref.unlink()
        (root / ".git/packed-refs").write_text(
            SHA_B + " refs/heads/main\n", encoding="ascii")
        packed = run_php(root)
        (root / ".git/HEAD").write_text(SHA_B + "\n", encoding="ascii")
        detached = run_php(root)
    return {"env": env_a, "changed": env_b, "invalid": invalid,
            "missing": missing, "git": checkout, "precedence": precedence,
            "packed": packed, "detached": detached}


def matrix_state(content: str, name: str) -> str:
    rows = [line for line in content.splitlines()
            if line.startswith("| " + name + " ·")]
    if len(rows) != 1:
        return UNKNOWN
    fields = [field.strip() for field in rows[0].strip("|").split("|")]
    return fields[2] if len(fields) == 5 and fields[2] == "NO_VERIFICABLE" else UNKNOWN


def evidence(value: object) -> str:
    return "TEST_EXECUTED" if value is True else UNKNOWN


class BrechasBrvtal51Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: each of the five IDs exercises code or a fail-closed contract."""
        samples = scenarios()
        env, git, fallback = samples["env"], samples["git"], samples["missing"]
        version = VERSION.read_text(encoding="utf-8")
        spec = SPEC.read_text(encoding="utf-8")
        agents = AGENTS.read_text(encoding="utf-8")
        ci = CI.read_text(encoding="utf-8")
        pkg = json.loads(PACKAGE.read_text(encoding="utf-8"))
        verdicts = {
            "S34-R006": (
                len(re.findall(r"(?m)^const BRVTAL_APP_(?:VERSION|BUILD|ENV) = ", version)) == 3
                and len(re.findall(r"(?m)^const BRVTAL_RELEASE_DATE = ", version)) == 1
                and bool(re.fullmatch(r"\d+\.\d+\.\d+", env["version"]))
                and bool(re.fullmatch(r"[a-f0-9]+", env["build"]))
                and env["date"] == fallback["date"]
                and fallback["public"]["commit"] is None
                and fallback["release"] == "v" + env["version"]
                and "release/build metadata in " in spec
            ),
            "S34-R007": (
                env["source"] == "environment"
                and env["sha"] == SHA_A
                and env["public"]["commit"] == SHA_A
                and git["source"] == "git_checkout" and git["sha"] == SHA_B
                and git["exact"] is True
                and samples["precedence"]["sha"] == SHA_A
                and samples["precedence"]["source"] == "environment"
                and samples["packed"]["sha"] == samples["detached"]["sha"] == SHA_B
                and samples["packed"]["source"] == samples["detached"]["source"] == "git_checkout"
                and "runtime deployment identity:" in spec
            ),
            "S34-R008": (
                env["version"] == samples["changed"]["version"] == git["version"]
                == fallback["version"] == pkg["version"]
                and env["release"] == samples["changed"]["release"]
                and env["cache"] != samples["changed"]["cache"]
                and "Do not increment semantic product version on every commit" in spec
                and "Repository-only maintenance: product version may remain unchanged." in ci
                and "tests/*" in SCOPE.read_text(encoding="utf-8")
            ),
            "S35-R001": (
                "Repository: " in spec and "pl0n3r/brvtal" in spec
                and "| Repository | " in agents and "pl0n3r/brvtal" in agents
                and "https://github.com/pl0n3r/brvtal/tree/main" in README.read_text(encoding="utf-8")
                and "BRVTAL_REPOSITORY: " in ci and "github.repository" in ci
                and pkg["name"] == "brvtal"
            ),
            "S35-R002": (
                "Canonical branch: " in spec and "main" in spec
                and "| Canonical branch | " in agents and "main" in agents
                and ci.count("branches: [main]") >= 2
                and "branches: [main]" in RELEASE.read_text(encoding="utf-8")
                and "GitHub main → Hostinger automatic Git deployment" in spec
            ),
        }
        self.assertEqual(set(CLAUSES), set(verdicts))
        for clause, passed in verdicts.items():
            with self.subTest(clause=clause):
                self.assertEqual("TEST_EXECUTED", evidence(passed), clause)

    def test_falla_cerrado(self) -> None:
        """AC-02: no SSH/Git proof means fallback, never an exact commit."""
        samples = scenarios()
        for key in ("invalid", "missing"):
            with self.subTest(case=key):
                result = samples[key]
                self.assertEqual(result["source"], "release_fallback")
                self.assertIs(result["exact"], False)
                self.assertIsNone(result["public"]["commit"])
                self.assertIsNone(result["public"]["short_commit"])
                self.assertEqual(result["cache"], "release-" + result["version"])
        for value in (None, False, 1, {}, "main", SHA_A, "VALIDATED_IN_PRODUCTION"):
            self.assertEqual(UNKNOWN, evidence(value))
        self.assertEqual(UNKNOWN, matrix_state("", CLAUSES[0]))
        self.assertEqual(UNKNOWN, matrix_state(
            "| S35-R002 · x | main | NO_VERIFICABLE | n | — |\n" * 2, "S35-R002"))

    def test_sin_datos_reales(self) -> None:
        """AC-03: synthetic SHA and allowlisted process env; no personal data."""
        with tempfile.TemporaryDirectory(prefix="brvtal-1141-isolation-") as temp:
            root = Path(temp)
            make_sandbox(root)
            marker = "synthetic-secret-never-echo"
            environment = safe_env(root, marker)
            self.assertEqual(set(environment), {
                "PATH", "HOME", "LANG", "APP_ENV", "BRVTAL_DEPLOY_COMMIT"})
            for key in ("GITHUB_TOKEN", "DB_HOST", "DB_PASSWORD", "DATABASE_URL",
                        "APP_KEY", "DEPLOY_SSH_KEY", "HTTP_PROXY", "HTTPS_PROXY"):
                self.assertNotIn(key, environment)
            invalid = run_php(root, marker)
            self.assertEqual(invalid["source"], "release_fallback")
            self.assertNotIn(marker, json.dumps(invalid))
            self.assertEqual(sorted(p.name for p in root.iterdir()), ["config"])
        for dangerous in ("curl_", "new PDO", "https://"):
            self.assertNotIn(dangerous, PROBE)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: no promotion of matrix rows or fixture results to live."""
        matrix = MATRIX.read_text(encoding="utf-8")
        self.assertEqual(len(CLAUSES), 5)
        for clause in CLAUSES:
            with self.subTest(clause=clause):
                self.assertEqual(matrix_state(matrix, clause), "NO_VERIFICABLE")
                self.assertEqual(matrix_state("", clause), UNKNOWN)
                self.assertEqual(evidence(None), UNKNOWN)
        for value in ("release_fallback", "environment", "git_checkout",
                      "main", "pl0n3r/brvtal", "v0.1.0"):
            self.assertEqual(evidence(value), UNKNOWN)
        self.assertIs(scenarios()["missing"]["exact"], False)


if __name__ == "__main__":
    unittest.main()
