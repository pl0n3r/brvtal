#!/usr/bin/env python3
"""#1143: local, fail-closed evidence for BRVTAL production safety checks.

Offline assertions NEVER establish staging, production secret hygiene, actual
migrations, deploy readiness, or CI results. No network or real credentials.
"""
from __future__ import annotations

import os
import re
import shutil
import subprocess
import unittest
from functools import lru_cache
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
IDS = ("S37-R002", "S37-R003", "S38-L592", "S38-L597", "S38-L598")
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_CONTRACT"
FILES = {
    "matrix": "docs/matriz-cobertura.md",
    "spec": "docs/BRVTAL-SPEC.md",
    "migration": ".github/workflows/production-migration-reconcile.yml",
    "transport": "ops/factory/transport.py",
    "example": "config/config.example.php",
    "bootstrap": "config/bootstrap.php",
    "ignore": ".gitignore",
    "factory": "tests/factory-ci-contract.php",
}
PHP_LINT = ("config/version.php", "config/deployment.php", "tests/factory-ci-contract.php")
EXPECTED = {
    "S37-R002": LOCAL,
    "S37-R003": UNKNOWN,
    "S38-L592": LOCAL,
    "S38-L597": LOCAL,
    "S38-L598": LOCAL,
}


def rows(text: str) -> dict[str, tuple[str, ...]]:
    results: dict[str, tuple[str, ...]] = {}
    for line in text.splitlines():
        match = re.match(r"^\| (S(?:37-R\d{3}|38-L\d{3})) · ", line)
        if not match or match.group(1) not in IDS:
            continue
        key = match.group(1)
        if key in results:
            raise ValueError("duplicate requirement: " + key)
        parts = tuple(s.strip() for s in line.strip().strip("|").split("|"))
        if len(parts) != 5 or not parts[0].startswith(key + " · "):
            raise ValueError("malformed requirement: " + key)
        results[key] = parts
    return results


def source_text() -> dict[str, str]:
    return {key: (ROOT / path).read_text(encoding="utf-8")
            for key, path in FILES.items()}


@lru_cache(maxsize=1)
def php_syntax() -> dict[str, bool]:
    """PHP -l only; does not include/execute PHP application code."""
    binary = shutil.which("php")
    if binary is None:
        return {}
    env = {"PATH": os.environ.get("PATH", "/usr/bin:/bin"), "LANG": "C"}
    statuses = {}
    for path in PHP_LINT:
        result = subprocess.run(
            [binary, "-l", path], cwd=ROOT, env=env,
            capture_output=True, text=True, timeout=20, check=False,
        )
        statuses[path] = (result.returncode == 0
                          and "No syntax errors detected" in result.stdout)
    return statuses


def example_secrets_are_placeholders(source: str) -> bool:
    assignments = re.findall(
        r"'(pass|csrf_key|encryption_key)'\s*=>\s*'([^']*)'", source,
    )
    return dict(assignments) == {
        "pass": "REPLACE_DB_PASSWORD",
        "csrf_key": "REPLACE_WITH_LONG_RANDOM_SECRET",
        "encryption_key": "REPLACE_WITH_32_PLUS_BYTE_RANDOM_SECRET",
    } and len(assignments) == 3 and "'sentry_dsn' => ''" in source


def evidence(clause: str, matrix_rows: dict[str, tuple[str, ...]],
             sources: dict[str, str], syntax: dict[str, bool] | None) -> dict[str, str]:
    result = {"code": UNKNOWN, "ci": UNKNOWN, "production": UNKNOWN}
    row = matrix_rows.get(clause)
    if (clause not in IDS or row is None or len(row) != 5
            or row[2] != "NO_VERIFICABLE"
            or not isinstance(syntax, dict)
            or set(sources) != set(FILES)
            or not all(isinstance(t, str) and t for t in sources.values())):
        return result
    spec = sources["spec"]
    if clause == "S37-R003":
        # A possible future environment is not an implemented staging system.
        return result
    if clause == "S37-R002":
        workflow, transport = sources["migration"], sources["transport"]
        ok = (
            "Do not run destructive migrations" in spec
            and "github.event.issue.number == 681" in workflow
            and "github.event.comment.user.login == github.repository_owner" in workflow
            and "github.event.comment.body == '/reconcile-production-migrations'" in workflow
            and "Inspect migration reconciliation plan" in workflow
            and 'if plan["blocked"]:' in workflow
            and "Backup, reconcile registry and verify readiness" in workflow
            and 'if ($plan["blocked"] !== []) {' in transport
            and "brvtal_backup_create(db(), [" in transport
            and "BRVTAL_MIGRATION_RECONCILE_BACKUP_READY=1" in transport
        )
    elif clause == "S38-L592":
        ok = ("- PHP syntax;" in spec and set(syntax) == set(PHP_LINT)
              and all(syntax.values()))
    elif clause == "S38-L597":
        ok = ("- config;" in spec
              and "if (!is_file($configPath)) {" in sources["bootstrap"]
              and "exit('BRVTAL: falta config/config.php.');" in sources["bootstrap"]
              and "/config/config.php" in sources["ignore"]
              and example_secrets_are_placeholders(sources["example"]))
    else:  # S38-L598
        ok = ("- secrets;" in spec
              and "/config/config.php" in sources["ignore"]
              and ".env.*" in sources["ignore"]
              and "!.env.example" in sources["ignore"]
              and example_secrets_are_placeholders(sources["example"]))
    if ok:
        result["code"] = LOCAL
    return result


class BrechasBrvtal53Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        source = source_text()
        matrix_rows = rows(source["matrix"])
        self.assertEqual(set(matrix_rows), set(IDS))
        statuses = php_syntax()
        for clause in IDS:
            with self.subTest(clause=clause):
                self.assertEqual(
                    evidence(clause, matrix_rows, source, statuses)["code"],
                    EXPECTED[clause],
                )
        self.assertIn("Staging may be added later.", source["spec"])

    def test_falla_cerrado(self) -> None:
        source = source_text()
        matrix_rows = rows(source["matrix"])
        statuses = php_syntax()
        for clause in IDS:
            with self.subTest(clause=clause):
                self.assertEqual(evidence(clause, {}, source, statuses)["code"], UNKNOWN)
                self.assertEqual(evidence(clause, matrix_rows, {}, statuses)["code"], UNKNOWN)
                self.assertEqual(evidence(clause, matrix_rows, source, None)["code"], UNKNOWN)
                changed = dict(matrix_rows)
                altered = list(matrix_rows[clause])
                altered[2] = "CUBIERTO"
                changed[clause] = tuple(altered)
                self.assertEqual(evidence(clause, changed, source, statuses)["code"], UNKNOWN)
        self.assertEqual(evidence("S38-UNKNOWN", matrix_rows, source, statuses)["code"], UNKNOWN)
        bad = source["example"].replace("REPLACE_DB_PASSWORD", "synthetic-not-a-real-password")
        self.assertFalse(example_secrets_are_placeholders(bad))
        changed = dict(source)
        changed["example"] = bad
        self.assertEqual(evidence("S38-L598", matrix_rows, changed, statuses)["code"], UNKNOWN)
        no_php = dict(statuses)
        no_php[PHP_LINT[0]] = False
        self.assertEqual(evidence("S38-L592", matrix_rows, source, no_php)["code"], UNKNOWN)
        duplicate = next(x for x in source["matrix"].splitlines() if x.startswith("| S37-R002 ·"))
        with self.assertRaisesRegex(ValueError, "duplicate"):
            rows(duplicate + "\n" + duplicate)

    def test_sin_datos_reales(self) -> None:
        self.assertTrue(set(PHP_LINT) <= {"config/version.php", "config/deployment.php",
                                            "tests/factory-ci-contract.php"})
        self.assertTrue(all((ROOT / p).is_file() for p in PHP_LINT))
        own = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(own, r"(?m)^(?:import|from)\s+(?:requests|httpx|urllib|socket)\b")
        self.assertNotIn("-----BEGIN " + "PRIVATE KEY-----", own)
        # No application bootstrap, network, deploy, config.php or secret scan of runtime.
        self.assertNotIn("curl_" + "exec(", own)
        self.assertNotIn("config/config.php" + "\nrequire", own)

    def test_estados_unknown_explicitos(self) -> None:
        source = source_text()
        matrix_rows = rows(source["matrix"])
        statuses = php_syntax()
        for clause in IDS:
            with self.subTest(clause=clause):
                self.assertEqual(matrix_rows[clause][2], "NO_VERIFICABLE")
                state = evidence(clause, matrix_rows, source, statuses)
                self.assertEqual(state["ci"], UNKNOWN)
                self.assertEqual(state["production"], UNKNOWN)
                self.assertEqual(state["code"], EXPECTED[clause])
        self.assertEqual(UNKNOWN, evidence("S37-R003", matrix_rows, source, statuses)["code"])


if __name__ == "__main__":
    unittest.main()
