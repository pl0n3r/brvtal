"""BRVTAL #1595: ejecución de contratos existentes para 50 filas, sin red productiva."""
from __future__ import annotations

import re
import subprocess
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
REPORT = ROOT / "docs/verificacion/verificacion-03.md"
CASES = (
    ("S21-R004", "tests/related-content-contract.php", "REGRESION_LOCAL"),
    ("S21-R005", "tests/related-content-contract.php", "REGRESION_LOCAL"),
    ("S21-R007", "tests/related-content-contract.php", "REGRESION_LOCAL"),
    ("S26-R001", "tests/totp-enrollment-contract.php", "REGRESION_LOCAL"),
    ("S26-R003", "tests/totp-enrollment-contract.php", "REGRESION_LOCAL"),
    ("S26-R004", "tests/totp-enrollment-contract.php", "REGRESION_LOCAL"),
    ("S26-R005", "tests/totp-enrollment-contract.php", "REGRESION_LOCAL"),
    ("S26-R006", "tests/totp-enrollment-contract.php", "REGRESION_LOCAL"),
    ("S26-R007", "tests/totp-enrollment-contract.php", "REGRESION_LOCAL"),
    ("S26-R008", "tests/totp-enrollment-contract.php", "REGRESION_LOCAL"),
    ("S26-R009", "tests/totp-enrollment-contract.php", "REGRESION_LOCAL"),
    ("S26-R010", "tests/totp-enrollment-contract.php", "REGRESION_LOCAL"),
    ("S26-R011", "tests/totp-enrollment-contract.php", "REGRESION_LOCAL"),
    ("S26-R012", "tests/totp-enrollment-contract.php", "REGRESION_LOCAL"),
    ("S26-R013", "tests/totp-enrollment-contract.php", "REGRESION_LOCAL"),
    ("S26-R014", "tests/totp-enrollment-contract.php", "REGRESION_LOCAL"),
    ("S26-R015", "tests/totp-enrollment-contract.php", "REGRESION_LOCAL"),
    ("S26-R016", "tests/totp-enrollment-contract.php", "REGRESION_LOCAL"),
    ("S26-R017", "tests/e2e/discadmin-totp-login.spec.mjs", "HISTORICO_NO_REVALIDADO"),
    ("S27-R001", "tests/test_admin_staff_api.py", "REGRESION_LOCAL"),
    ("S27-R002", "tests/test_admin_staff_api.py", "REGRESION_LOCAL"),
    ("S27-R004", "tests/admin-activity-contract.php", "REGRESION_LOCAL"),
    ("S27-R005", "tests/admin-activity-contract.php", "REGRESION_LOCAL"),
    ("S27-R006", "tests/admin-activity-contract.php", "REGRESION_LOCAL"),
    ("S27-R007", "tests/test_event_version_restore_e2e.py", "REGRESION_LOCAL"),
    ("S27-R008", "tests/test_event_version_restore_e2e.py", "REGRESION_LOCAL"),
    ("S27-R009", "tests/test_event_version_restore_plan.py", "REGRESION_LOCAL"),
    ("S27-R010", "tests/test_event_version_restore_discadmin.py", "REGRESION_LOCAL"),
    ("S28-R001", "tests/dashboard-v2-contract.php", "REGRESION_LOCAL"),
    ("S28-R003", "tests/dashboard-v2-contract.php", "REGRESION_LOCAL"),
    ("S28-R004", "tests/dashboard-v2-contract.php", "REGRESION_LOCAL"),
    ("S28-R005", "tests/dashboard-v2-contract.php", "REGRESION_LOCAL"),
    ("S28-R006", "tests/dashboard-v2-contract.php", "REGRESION_LOCAL"),
    ("S28-R007", "tests/dashboard-v2-contract.php", "REGRESION_LOCAL"),
    ("S28-R008", "tests/dashboard-v2-contract.php", "REGRESION_LOCAL"),
    ("S28-R009", "tests/dashboard-v2-contract.php", "REGRESION_LOCAL"),
    ("S28-R010", "tests/dashboard-v2-contract.php", "REGRESION_LOCAL"),
    ("S28-R011", "tests/dashboard-v2-contract.php", "REGRESION_LOCAL"),
    ("S28-R012", "tests/dashboard-v2-contract.php", "REGRESION_LOCAL"),
    ("S28-R013", "tests/dashboard-v2-contract.php", "REGRESION_LOCAL"),
    ("S28-R014", "tests/dashboard-v2-contract.php", "REGRESION_LOCAL"),
    ("S28-R015", "tests/dashboard-v2-contract.php", "REGRESION_LOCAL"),
    ("S28-R016", "tests/dashboard-v2-contract.php", "REGRESION_LOCAL"),
    ("S28-R017", "tests/admin-activity-contract.php", "REGRESION_LOCAL"),
    ("S28-R018", "tests/test_admin_development_signals.py", "REGRESION_LOCAL"),
    ("S28-R019", "tests/test_admin_development_signals.py", "OBSERVADOR_NO_ACREDITADO"),
    ("S28-R020", "tests/test_admin_sonar_signals.py", "OBSERVADOR_NO_ACREDITADO"),
    ("S28-R021", "tests/test_admin_coderabbit_signals.py", "OBSERVADOR_NO_ACREDITADO"),
    ("S28-R022", "tests/test_admin_deploy_signals.py", "OBSERVADOR_NO_ACREDITADO"),
    ("S28-R023", "tests/test_admin_deploy_signals.py", "OBSERVADOR_NO_ACREDITADO"),
)
PHP_CONTRACTS = (
    "tests/related-content-contract.php",
    "tests/totp-enrollment-contract.php",
    "tests/admin-activity-contract.php",
    "tests/dashboard-v2-contract.php",
)
PYTHON_REGRESSIONS = (
    "tests.test_admin_staff_api.AdminStaffApiContractTests.test_signed_staff_only_contract",
    "tests.test_admin_activity_audit_coverage.AdminActivityAuditCoverageTests.test_existing_activity_resources_remain_covered",
    "tests.test_event_version_restore_plan.EventVersionRestorePlanTests.test_wrong_event_create_delete_or_incompatible_snapshot_fails_closed_without_side_effects",
    "tests.test_event_version_restore_discadmin.EventVersionRestoreDiscadminTests.test_new_closed_changed_or_incompatible_event_fails_closed_without_save_or_fetch",
    "tests.test_event_version_restore_e2e.EventVersionRestoreE2ETests.test_restore_never_rewinds_lifecycle_or_relations_and_cancel_discards_staged_changes",
    "tests.test_admin_development_signals.AdminDevelopmentSignalsTests.test_external_failure_is_unavailable_not_fake_healthy",
    "tests.test_admin_sonar_signals.AdminSonarSignalsTests.test_external_failure_or_incomplete_payload_is_unavailable_not_fake_healthy",
    "tests.test_admin_coderabbit_signals.AdminCodeRabbitSignalsTests.test_terminal_review_for_stale_head_is_pending",
    "tests.test_admin_deploy_signals.AdminDeploySignalsTests.test_reuses_canonical_readiness_without_mutation",
)
ROW_RE = re.compile(r"^\| \x60(S\d+-R\d{3})\x60 \| \x60([^\x60]+)\x60 \| ([A-Z_]+) \|")


def read_rows(text: str) -> dict[str, tuple[str, str]]:
    result = {}
    for line in text.splitlines():
        match = ROW_RE.match(line)
        if match:
            identifier, path, kind = match.groups()
            if identifier in result:
                raise ValueError(f"fila duplicada: {identifier}")
            result[identifier] = (path, kind)
    return result


class VerificacionBrvtal03Tests(unittest.TestCase):
    def test_inventario_exactamente_50_y_trazabilidad(self):
        self.assertEqual(len(CASES), 50)
        expected = {name: (path, kind) for name, path, kind in CASES}
        self.assertEqual(len(expected), 50)
        report = REPORT.read_text(encoding="utf-8")
        self.assertEqual(read_rows(report), expected)
        self.assertIn("no acredita producción", report.lower())
        self.assertIn("docs/matriz-cobertura.md", report)

    def test_testigos_reales_y_negativos(self):
        for identifier, path, kind in CASES:
            with self.subTest(identifier=identifier):
                self.assertTrue((ROOT / path).is_file(), path)
                source = (ROOT / path).read_text(encoding="utf-8")
                self.assertTrue(source.strip(), path)
                if path.endswith(".php"):
                    self.assertRegex(source, r"(?:_expect|_assert)\(")
                if path.endswith(".py"):
                    self.assertRegex(source, r"def test_")
                if path.endswith(".mjs"):
                    self.assertIn("test(", source)
        relations = (ROOT / PHP_CONTRACTS[0]).read_text(encoding="utf-8")
        self.assertIn("Private artist relation must be removed from a public Set", relations)
        self.assertIn("Private event relation must be removed from a public Set", relations)
        totp = (ROOT / PHP_CONTRACTS[1]).read_text(encoding="utf-8")
        self.assertIn("PROTECTED_SETTING", totp)
        self.assertIn("security.totp_encryption_key", totp)
        activity = (ROOT / PHP_CONTRACTS[2]).read_text(encoding="utf-8")
        self.assertIn("sensitive", activity.lower())
        dashboard = (ROOT / PHP_CONTRACTS[3]).read_text(encoding="utf-8")
        self.assertIn("read-only", dashboard)
        deploy = (ROOT / "config/admin_deploy_signals.php").read_text(encoding="utf-8")
        self.assertIn("catch (Throwable)", deploy)
        self.assertIn("'readiness'=>'degraded'", deploy)
        self.assertIn("'schema_up_to_date'=>false", deploy)

    def test_mutaciones_sinteticas_del_inventario_fallan(self):
        report = REPORT.read_text(encoding="utf-8")
        parsed = read_rows(report)
        self.assertEqual(len(parsed), 50)
        drop = next(line for line in report.splitlines() if ROW_RE.match(line))
        self.assertNotEqual(read_rows(report.replace(drop, "", 1)), parsed)
        with self.assertRaises(ValueError):
            read_rows(report + "\n" + drop)
        self.assertNotEqual(
            read_rows(report.replace(drop, drop.replace("REGRESION_LOCAL", "CUBIERTO", 1), 1)),
            {key: (path, kind) for key, path, kind in CASES},
        )

    def test_contratos_php_de_productos_existentes(self):
        for path in PHP_CONTRACTS:
            with self.subTest(path=path):
                result = subprocess.run(
                    ["php", str(ROOT / path)], cwd=ROOT, capture_output=True,
                    text=True, timeout=90, check=False,
                )
                self.assertEqual(result.returncode, 0, f"{path}\n{result.stdout}\n{result.stderr}")

    def test_regresiones_python_existentes(self):
        result = subprocess.run(
            [sys.executable, "-m", "unittest", *PYTHON_REGRESSIONS],
            cwd=ROOT, capture_output=True, text=True, timeout=150, check=False,
        )
        self.assertEqual(result.returncode, 0, f"{result.stdout}\n{result.stderr}")

    def test_webkit_y_observadores_no_certificados(self):
        categories = {name: kind for name, _, kind in CASES}
        self.assertEqual(categories["S26-R017"], "HISTORICO_NO_REVALIDADO")
        for number in range(19, 24):
            self.assertEqual(categories[f"S28-R{number:03d}"], "OBSERVADOR_NO_ACREDITADO")
        document = REPORT.read_text(encoding="utf-8")
        self.assertIn("WebKit", document)
        self.assertIn("SHA exacto", document)


if __name__ == "__main__":
    unittest.main()
