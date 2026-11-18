#!/usr/bin/env python3
"""#1140: S33-R017 and S34-R002..R005; synthetic, fail-closed evidence only.

The specification's v0.1.0 and DEPLOY abc1234 values are examples, not
claims about the deployed product. Real restore and live status remain UNKNOWN.
"""
from __future__ import annotations

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
ENDPOINT = ROOT / "discadmin/backups.php"
ENGINE = ROOT / "config/backups.php"
VERSION = ROOT / "config/version.php"
DEPLOYMENT = ROOT / "config/deployment.php"
ADMIN = ROOT / "discadmin/index-core.php"
SPEC = ROOT / "docs/BRVTAL-SPEC.md"
CLAUSES = ("S33-R017", "S34-R002", "S34-R003", "S34-R004", "S34-R005")
UNKNOWN = "UNKNOWN"
SHA = "abc1234" + "0" * 33
OTHER_SHA = "fedcba9" + "1" * 33

PROBE = r"""
require $argv[1];
echo json_encode([
    'public'=>brvtalDeploymentPublicData(),
    'version'=>BRVTAL_APP_VERSION,
    'environment'=>BRVTAL_APP_ENV,
    'release'=>brvtalReleaseIdentity(),
    'cache'=>brvtalDeploymentCacheKey(),
], JSON_THROW_ON_ERROR);
"""


def safe_env(directory: str, commit: str | None = None) -> dict[str, str]:
    env = {"PATH": os.environ.get("PATH", "/usr/bin:/bin"),
           "HOME": directory, "LANG": "C", "APP_ENV": "testing"}
    if commit is not None:
        env["BRVTAL_DEPLOY_COMMIT"] = commit
    return env


def run_php(source: str, sandbox: str, *args: str,
            commit: str | None = None) -> dict:
    binary = shutil.which("php")
    if binary is None:
        raise AssertionError("UNKNOWN: PHP executable unavailable")
    result = subprocess.run(
        [binary, "-r", source, *args], cwd=sandbox,
        env=safe_env(sandbox, commit), text=True, capture_output=True,
        timeout=25, check=False,
    )
    if result.returncode:
        raise AssertionError("UNKNOWN: synthetic PHP failed: " + result.stderr[-900:])
    return json.loads(result.stdout)


def scenarios() -> tuple[dict, dict, dict, dict]:
    """Execute real version/deployment PHP isolated from the repository .git."""
    with tempfile.TemporaryDirectory(prefix="brvtal-s34-synthetic-") as tmp:
        config = Path(tmp) / "config"
        config.mkdir()
        shutil.copyfile(VERSION, config / "version.php")
        shutil.copyfile(DEPLOYMENT, config / "deployment.php")
        script = str(config / "deployment.php")
        exact = run_php(PROBE, tmp, script, commit=SHA)
        changed = run_php(PROBE, tmp, script, commit=OTHER_SHA)
        invalid = run_php(PROBE, tmp, script, commit="abc1234")
        missing = run_php(PROBE, tmp, script)
    return exact, changed, invalid, missing


def restore_guard() -> dict:
    """Run only the actual restore-dispatch clause with an inert JSON shim."""
    endpoint = ENDPOINT.read_text(encoding="utf-8")
    match = re.search(
        r"(?m)^if \(\$action === 'restore'\) \{\n(?:[^{}]|\{[^{}]*\})*\n\}",
        endpoint,
    )
    if match is None or endpoint.count("if ($action === 'restore') {") != 1:
        return {"state": UNKNOWN}
    shim = r"""
function json_response($data, $status, $headers) {
    echo json_encode(['payload'=>$data, 'status'=>$status, 'headers'=>$headers], JSON_THROW_ON_ERROR);
}
$action = 'restore';
"""
    with tempfile.TemporaryDirectory(prefix="brvtal-s33-synthetic-") as tmp:
        return run_php(shim + match.group(0), tmp)


def matrix_status(content: str, clause: str) -> str:
    rows = [line for line in content.splitlines()
            if line.startswith("| " + clause + " ·")]
    if len(rows) != 1:
        return UNKNOWN
    cells = [x.strip() for x in rows[0].strip("|").split("|")]
    return cells[2] if len(cells) == 5 else UNKNOWN


def evidence(value: object) -> str:
    return "TEST_EXECUTED" if value is True else UNKNOWN


class BrechasBrvtal50Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        exact, changed, invalid, missing = scenarios()
        endpoint = ENDPOINT.read_text(encoding="utf-8")
        engine = ENGINE.read_text(encoding="utf-8")
        admin = ADMIN.read_text(encoding="utf-8")
        spec = SPEC.read_text(encoding="utf-8")
        denied = restore_guard()
        product = exact["version"]
        public = exact["public"]
        verdicts = {
            # No live restore is allowed. A future safeguarded restore remains UNKNOWN.
            "S33-R017": (
                denied.get("status") == 405
                and denied.get("payload") == {"ok": False, "error": "RESTORE_NOT_SUPPORTED"}
                and "no-store" in denied.get("headers", {}).get("Cache-Control", "")
                and "brvtal_admin_require();" in endpoint.split("$method =", 1)[0]
                and "'restore_supported' => false" in endpoint
                and "'restore_supported' => false" in engine
            ),
            "S34-R002": (
                bool(re.fullmatch(r"\d+\.\d+\.\d+", product))
                and public["version"] == product
                and public["environment"] == exact["environment"] == "PRODUCTION"
                and exact["release"] == "v" + product
                and 'data-testid="admin-product-version"' in admin
                and 'data-testid="admin-product-environment"' in admin
                and "htmlspecialchars(BRVTAL_APP_VERSION" in admin
                and "strtolower(BRVTAL_APP_ENV)" in admin
                and "v0.1.0 · PRODUCTION" in spec
            ),
            "S34-R003": (
                public["exact"] is True
                and public["commit"] == SHA
                and public["short_commit"] == "abc1234"
                and exact["cache"] == "abc1234"
                and 'data-testid="admin-deploy-source"' in admin
                and "brvtalDeploymentIsExact()" in admin
                and "brvtalDeploymentCacheKey()" in admin
                and "SOURCE UNAVAILABLE" in admin
                and "DEPLOY abc1234" in spec
            ),
            "S34-R004": (
                product == changed["version"] == missing["version"]
                and public["source"] == changed["public"]["source"] == "environment"
                and changed["public"]["commit"] == OTHER_SHA
                and exact["cache"] != changed["cache"]
                and missing["public"]["exact"] is False
                and missing["public"]["commit"] is None
                and missing["cache"] == "release-" + product
                and "Product version and deployment source are different concepts:" in spec
            ),
            "S34-R005": (
                "const BRVTAL_APP_VERSION = '" in VERSION.read_text(encoding="utf-8")
                and exact["release"] == changed["release"] == missing["release"]
                and exact["release"] == "v" + product
                and invalid["version"] == product
                and "BRVTAL_APP_VERSION" in spec
            ),
        }
        self.assertEqual(set(CLAUSES), set(verdicts))
        for clause, passed in verdicts.items():
            with self.subTest(clause=clause):
                self.assertEqual("TEST_EXECUTED", evidence(passed), clause)

    def test_falla_cerrado(self) -> None:
        exact, _, invalid, missing = scenarios()
        for case in (invalid, missing):
            with self.subTest(source=case["public"]["source"]):
                data = case["public"]
                self.assertEqual("release_fallback", data["source"])
                self.assertIs(data["exact"], False)
                self.assertIsNone(data["commit"])
                self.assertIsNone(data["short_commit"])
                self.assertEqual(case["cache"], "release-" + case["version"])
                self.assertNotEqual(exact["cache"], case["cache"])
        for item in (None, False, 1, {}, "ready", "PRODUCTION"):
            self.assertEqual(UNKNOWN, evidence(item))
        self.assertEqual(UNKNOWN, matrix_status("", CLAUSES[0]))
        self.assertEqual(UNKNOWN, matrix_status(
            "| S33-R017 · src | clause | NO_VERIFICABLE | note | — |\n"
            "| S33-R017 · src | clause | CUBIERTO | note | — |", CLAUSES[0]))
        self.assertEqual(UNKNOWN, restore_guard().get("state", UNKNOWN))

    def test_sin_datos_reales(self) -> None:
        with tempfile.TemporaryDirectory(prefix="brvtal-synthetic-") as temp:
            env = safe_env(temp, SHA)
            for key in ("DATABASE_URL", "DB_HOST", "DB_PASSWORD", "MYSQL_PWD",
                        "GITHUB_TOKEN", "APP_KEY", "HTTP_PROXY", "HTTPS_PROXY",
                        "COOKIE", "AUTHORIZATION"):
                with self.subTest(key=key):
                    self.assertNotIn(key, env)
            self.assertEqual("testing", env["APP_ENV"])
        self.assertEqual(40, len(SHA))
        self.assertTrue(set(SHA) <= set("0123456789abcdef"))
        self.assertNotIn("new PDO(", PROBE)
        self.assertNotIn("curl_", PROBE)
        self.assertNotIn("https://", PROBE)

    def test_estados_unknown_explicitos(self) -> None:
        matrix = MATRIX.read_text(encoding="utf-8")
        self.assertEqual(5, len(CLAUSES))
        for clause in CLAUSES:
            with self.subTest(clause=clause):
                self.assertEqual("NO_VERIFICABLE", matrix_status(matrix, clause))
                self.assertEqual(UNKNOWN, matrix_status("", clause))
                self.assertEqual(UNKNOWN, evidence(None))
        # None of these synthetic examples establishes production readiness.
        for not_evidence in ("v0.1.0 · PRODUCTION", "DEPLOY abc1234",
                             "RESTORE_NOT_SUPPORTED"):
            self.assertEqual(UNKNOWN, evidence(not_evidence))


if __name__ == "__main__":
    unittest.main()
