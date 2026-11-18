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



def canonical_spec_field(document: str, name: str) -> str | None:
    """Named field value must exist once, never merely elsewhere in the spec."""
    rows = re.findall(rf"(?m)^{re.escape(name)}:\s*\x60([^\x60\n]+)\x60\s*$", document)
    return rows[0] if len(rows) == 1 else None


def canonical_agent_field(document: str, name: str) -> str | None:
    rows = re.findall(
        rf"(?m)^\|\s*{re.escape(name)}\s*\|\s*\x60([^\x60\n]+)\x60\s*\|\s*$",
        document,
    )
    return rows[0] if len(rows) == 1 else None


def workflow_version_route(event: str, deploy_bound: bool, before: str = SHA_A) -> str:
    """Run the literal workflow branch in a sandbox with inert shell functions."""
    workflow = CI.read_text(encoding="utf-8")
    match = re.search(
        r'(?ms)^          if \[\[ "\$BRVTAL_DEPLOY_BOUND" == "true" \]\]; then\n.*?^          fi$',
        workflow,
    )
    if match is None:
        return UNKNOWN
    branch = "\n".join(line[10:] for line in match.group(0).splitlines())
    source = (
        "set -euo pipefail\n"
        "compare_release_refs() { printf 'transition:%s:%s\\n' \"$1\" \"$2\"; }\n"
        "python() { printf 'current:%s\\n' \"$*\"; }\n" + branch
    )
    with tempfile.TemporaryDirectory(prefix="brvtal-1141-route-") as temp:
        root = Path(temp)
        env = safe_env(root)
        env.update({
            "BRVTAL_EVENT": event,
            "BRVTAL_DEPLOY_BOUND": "true" if deploy_bound else "false",
            "BRVTAL_PR_BASE_SHA": SHA_A, "BRVTAL_PR_HEAD_SHA": SHA_B,
            "BRVTAL_BEFORE": before, "BRVTAL_SHA": SHA_B,
        })
        result = subprocess.run(
            ["bash", "-c", source], cwd=root, env=env, capture_output=True,
            text=True, timeout=10, check=False,
        )
        if result.returncode or result.stderr or not result.stdout.strip():
            return UNKNOWN
        return result.stdout.strip().splitlines()[-1]


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
                and workflow_version_route("pull_request", False) ==
                    "current:scripts/release-version.py --check-current"
                and workflow_version_route("pull_request", True) ==
                    "transition:" + SHA_A + ":" + SHA_B
                and workflow_version_route("push", True) ==
                    "transition:" + SHA_A + ":" + SHA_B
                and workflow_version_route("push", True, "0" * 40) ==
                    "current:scripts/release-version.py --check-current"
            ),
            "S35-R001": (
                canonical_spec_field(spec, "Repository") == "pl0n3r/brvtal"
                and canonical_agent_field(agents, "Repository") == "pl0n3r/brvtal"
                and "https://github.com/pl0n3r/brvtal/tree/main" in README.read_text(encoding="utf-8")
                and "BRVTAL_REPOSITORY: " in ci and "github.repository" in ci
                and pkg["name"] == "brvtal"
            ),
            "S35-R002": (
                canonical_spec_field(spec, "Canonical branch") == "main"
                and canonical_agent_field(agents, "Canonical branch") == "main"
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
                self.assertEqual("release_fallback", result["source"])
                self.assertIs(result["exact"], False)
                self.assertIsNone(result["public"]["commit"])
                self.assertIsNone(result["public"]["short_commit"])
                self.assertEqual("release-" + result["version"], result["cache"])
        for value in (None, False, 1, {}, "main", SHA_A, "VALIDATED_IN_PRODUCTION"):
            self.assertEqual(UNKNOWN, evidence(value))
        self.assertEqual(UNKNOWN, matrix_state("", CLAUSES[0]))
        self.assertEqual(UNKNOWN, matrix_state(
            "| S35-R002 · x | main | NO_VERIFICABLE | n | — |\n" * 2, "S35-R002"))

        spec = SPEC.read_text(encoding="utf-8")
        agents = AGENTS.read_text(encoding="utf-8")
        tick = chr(96)
        wrong_repo = spec.replace(
            "Repository: " + tick + "pl0n3r/brvtal" + tick,
            "Repository: " + tick + "wrong/repo" + tick,
        )
        wrong_branch = spec.replace(
            "Canonical branch: " + tick + "main" + tick,
            "Canonical branch: " + tick + "develop" + tick,
        )
        self.assertNotEqual("pl0n3r/brvtal", canonical_spec_field(wrong_repo, "Repository"))
        self.assertNotEqual("main", canonical_spec_field(wrong_branch, "Canonical branch"))
        self.assertIsNone(canonical_spec_field(
            spec + "\nRepository: " + tick + "wrong/repo" + tick, "Repository"))
        self.assertIsNone(canonical_agent_field(
            agents + "\n| Canonical branch | " + tick + "develop" + tick + " |",
            "Canonical branch"))

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
            self.assertEqual("release_fallback", invalid["source"])
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
                self.assertEqual("NO_VERIFICABLE", matrix_state(matrix, clause))
                self.assertEqual(UNKNOWN, matrix_state("", clause))
                self.assertEqual(UNKNOWN, evidence(None))
        for value in ("release_fallback", "environment", "git_checkout",
                      "main", "pl0n3r/brvtal", "v0.1.0"):
            self.assertEqual(UNKNOWN, evidence(value))
        self.assertIs(scenarios()["missing"]["exact"], False)


if __name__ == "__main__":
    unittest.main()
