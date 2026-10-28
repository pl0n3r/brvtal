#!/usr/bin/env python3
"""#1145: local, synthetic, fail-closed evidence for SPEC §38 rows 005..009.

No production, credentials, DB or network. This suite tests CI selection and
failure propagation; neither successful CI nor actual deployment is attested.
"""
from __future__ import annotations

from functools import lru_cache
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import textwrap
import unittest

ROOT = Path(__file__).resolve().parents[1]
IDS = tuple(f"S38-R{number:03d}" for number in range(5, 10))
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_CONTRACT"
SOURCES = {
    "matrix": "docs/matriz-cobertura.md",
    "spec": "docs/BRVTAL-SPEC.md",
    "scope": "scripts/ci-scope.sh",
    "workflow": ".github/workflows/update-release-metadata.yml",
    "browser": "tests/e2e/discadmin-totp-login.spec.mjs",
}
CLAUSES = {
    "S38-R005": "critical files;",
    "S38-R006": "browser behavior for critical DISCADMIN/public flows.",
    "S38-R007": "On update failure, stop.",
    "S38-R008": "Do not blindly continue dependent changes.",
    "S38-R009": "Automatic rollback is not currently required.",
}


def rows(source: str) -> dict[str, tuple[str, ...]]:
    """Ambiguous, duplicate, incomplete or out-of-contract matrix = UNKNOWN."""
    result = {}
    for line in source.splitlines():
        match = re.match(r"^\| (S38-R00[5-9]) · ", line)
        if not match:
            continue
        key = match.group(1)
        cells = tuple(cell.strip() for cell in line.strip().strip("|").split("|"))
        if key in result or len(cells) != 5 or cells[1] != CLAUSES[key]:
            return {}
        result[key] = cells
    return result


def sources() -> dict[str, str]:
    """Only read committed source and contract files, never runtime config."""
    result = {}
    for key, path in SOURCES.items():
        file = ROOT / path
        result[key] = file.read_text(encoding="utf-8") if file.is_file() else ""
    return result


def isolated_env(home: str) -> dict[str, str]:
    """No inherited credentials, database settings, or proxy routing."""
    return {"PATH": os.environ.get("PATH", "/usr/bin:/bin"),
            "HOME": home, "LANG": "C", "APP_ENV": "testing"}


@lru_cache(maxsize=12)
def classify(*paths: str) -> dict[str, str]:
    if not paths or any(not p or "\n" in p for p in paths):
        return {}
    if not (ROOT / SOURCES["scope"]).is_file() or not shutil.which("bash"):
        return {}
    with tempfile.TemporaryDirectory(prefix="brvtal-s38-scope-") as home:
        run = subprocess.run(
            ["bash", "scripts/ci-scope.sh", "pull_request"],
            cwd=ROOT, env=isolated_env(home), input="\n".join(paths) + "\n",
            capture_output=True, text=True, check=False, timeout=15,
        )
    if run.returncode != 0:
        return {}
    result = {}
    for line in run.stdout.splitlines():
        key, separator, value = line.partition("=")
        if not separator or not key or key in result:
            return {}
        result[key] = value
    return result


def aggregate_script(workflow: str) -> str | None:
    """Extract the actual validate guard, not a synthetic reimplementation."""
    if workflow.count("      - name: Enforce validation gates\n") != 1:
        return None
    block = workflow.split("      - name: Enforce validation gates\n", 1)[1]
    block = block.split("      - name: Publish validation result\n", 1)[0]
    match = re.search(r"(?m)^        run: \|\n((?:          .*\n|\n)+)", block)
    if match is None:
        return None
    script = textwrap.dedent(match.group(1)).strip() + "\n"
    if not (script.startswith("set -euo pipefail\n")
            and 'require_success preflight "$PREFLIGHT_RESULT"' in script
            and 'require_success fast "$FAST_RESULT"' in script
            and 'require_optional chromium "$RUN_BROWSER" "$BROWSER_RESULT"' in script
            and script.rstrip().endswith('exit "$failures"')):
        return None
    return script


def guard(workflow: str, overrides: dict[str, str] | None = None) -> int | None:
    script = aggregate_script(workflow)
    if script is None or not shutil.which("bash"):
        return None
    with tempfile.TemporaryDirectory(prefix="brvtal-s38-guard-") as home:
        env = isolated_env(home)
        env.update({
            "PREFLIGHT_RESULT": "success", "COORDINATION_RESULT": "success",
            "COORDINATION_PR_RESULT": "success", "EXPECT_COORDINATION_PR": "true",
            "FAST_RESULT": "success", "DB_RESULT": "skipped",
            "BROWSER_RESULT": "skipped", "REALSTACK_RESULT": "skipped",
            "WEBKIT_RESULT": "skipped", "RECOVERY_RESULT": "skipped",
            "RUN_DB": "false", "RUN_BROWSER": "false", "RUN_REALSTACK": "false",
            "RUN_WEBKIT": "false", "RUN_RECOVERY": "false",
        })
        env.update(overrides or {})
        run = subprocess.run(
            ["bash", "-c", script], cwd=home, env=env,
            capture_output=True, text=True, check=False, timeout=10,
        )
    return run.returncode


def evidence(clause: str, matrix: dict[str, tuple[str, ...]],
             content: dict[str, str]) -> dict[str, str]:
    state = {"code": UNKNOWN, "ci": UNKNOWN, "production": UNKNOWN}
    row = matrix.get(clause)
    if (clause not in IDS or row is None or len(row) != 5
            or row[2] != "NO_VERIFICABLE" or set(content) != set(SOURCES)
            or any(not isinstance(v, str) or not v for v in content.values())
            or CLAUSES[clause] not in content["spec"]):
        return state
    workflow = content["workflow"]
    if clause == "S38-R005":
        critical = classify("config/public_home.php")
        admin = classify("discadmin/index.php")
        ok = (critical.get("run_db") == "true"
              and critical.get("run_browser") == "true"
              and critical.get("run_realstack") == "true"
              and critical.get("deploy_bound") == "true"
              and admin.get("run_browser") == "true"
              and admin.get("run_realstack") == "true"
              and admin.get("run_webkit") == "true")
    elif clause == "S38-R006":
        public = classify("api/hero-slider.php")
        auth = classify("tests/e2e/discadmin-totp-login.spec.mjs")
        ok = (public.get("run_browser") == "true"
              and auth.get("run_browser") == "true"
              and auth.get("run_webkit") == "true"
              and "npx playwright test --project=chromium" in workflow
              and "npx playwright test tests/e2e/discadmin-totp-login.spec.mjs --project=webkit-totp" in workflow
              and "test('TOTP login uses canonical auth route" in content["browser"])
    elif clause == "S38-R007":
        ok = (guard(workflow) == 0
              and guard(workflow, {"FAST_RESULT": "failure"}) == 1
              and guard(workflow, {"RUN_BROWSER": "true", "BROWSER_RESULT": "failure"}) == 1
              and guard(workflow, {"RUN_BROWSER": "true", "BROWSER_RESULT": "pending"}) == 1)
    elif clause == "S38-R008":
        ok = ("needs: [preflight, coordination, coordination-pr, fast, database, browser, realstack, webkit, recovery]" in workflow
              and "  validate:\n    name: validate\n    if: always()" in workflow
              and guard(workflow, {"PREFLIGHT_RESULT": "failure"}) == 1
              and guard(workflow, {"COORDINATION_PR_RESULT": "failure"}) == 1
              and guard(workflow, {"RUN_BROWSER": "true", "BROWSER_RESULT": "skipped"}) == 1)
    else:
        # S38-R009: a lack of rollback requirement is policy, not a proven
        # automatic recovery mechanism. Do not promote absence to evidence.
        return state
    if ok:
        state["code"] = LOCAL
    return state


class BrechasBrvtal55Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        content = sources()
        matrix = rows(content["matrix"])
        self.assertEqual(set(matrix), set(IDS))
        self.assertEqual(set(CLAUSES), set(IDS))
        for clause in IDS:
            with self.subTest(clause=clause):
                self.assertEqual(matrix[clause][2], "NO_VERIFICABLE")
                self.assertEqual(evidence(clause, matrix, content)["code"],
                                 UNKNOWN if clause == "S38-R009" else LOCAL)
                if clause == "S38-R009":
                    self.assertIn(CLAUSES[clause], content["spec"])

    def test_falla_cerrado(self) -> None:
        content = sources()
        matrix = rows(content["matrix"])
        for clause in IDS:
            with self.subTest(clause=clause):
                self.assertEqual(evidence(clause, {}, content)["code"], UNKNOWN)
                missing = dict(content)
                missing["workflow"] = ""
                self.assertEqual(evidence(clause, matrix, missing)["code"], UNKNOWN)
                altered = dict(matrix)
                row = list(matrix[clause])
                row[2] = "CUBIERTO"
                altered[clause] = tuple(row)
                self.assertEqual(evidence(clause, altered, content)["code"], UNKNOWN)
        sample = next(line for line in content["matrix"].splitlines()
                      if line.startswith("| S38-R005 ·"))
        self.assertEqual(rows(sample + "\n" + sample), {})
        self.assertEqual(rows(""), {})
        self.assertEqual(classify(""), {})
        self.assertIsNone(guard(""))
        self.assertIsNone(guard("      - name: Enforce validation gates\n"))
        self.assertEqual(UNKNOWN, evidence("S38-R000", matrix, content)["code"])

    def test_sin_datos_reales(self) -> None:
        self.assertEqual(set(SOURCES), {"matrix", "spec", "scope", "workflow", "browser"})
        with tempfile.TemporaryDirectory(prefix="brvtal-s38-env-") as home:
            env = isolated_env(home)
            self.assertEqual(env["APP_ENV"], "testing")
            for key in ("DB_HOST", "DB_PASSWORD", "DATABASE_URL", "GITHUB_TOKEN",
                        "GH_TOKEN", "APP_KEY", "DEPLOY_TOKEN", "HTTP_PROXY",
                        "HTTPS_PROXY", "MYSQL_PWD", "COOKIE"):
                with self.subTest(key=key):
                    self.assertNotIn(key, env)
        self.assertTrue(all(not path.endswith("config/config.php") for path in SOURCES.values()))
        self.assertTrue(all((ROOT / path).is_file() for path in SOURCES.values()))

    def test_estados_unknown_explicitos(self) -> None:
        content = sources()
        matrix = rows(content["matrix"])
        for clause in IDS:
            with self.subTest(clause=clause):
                state = evidence(clause, matrix, content)
                self.assertEqual(state["ci"], UNKNOWN)
                self.assertEqual(state["production"], UNKNOWN)
                self.assertIn(state["code"], (UNKNOWN, LOCAL))
                self.assertEqual(matrix[clause][2], "NO_VERIFICABLE")
        self.assertEqual(evidence("S38-R009", matrix, content)["code"], UNKNOWN)
        self.assertNotEqual(LOCAL, evidence("S38-R009", matrix, content)["ci"])


if __name__ == "__main__":
    unittest.main()
