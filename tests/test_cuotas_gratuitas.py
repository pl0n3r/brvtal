from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def scenario(name: str) -> dict:
    result = subprocess.run(
        ["php", str(ROOT / "tests" / "free_quotas_scenarios.php"), name],
        cwd=ROOT, text=True, capture_output=True, timeout=20, check=False,
    )
    if result.returncode:
        raise AssertionError(result.stdout + result.stderr)
    return json.loads(result.stdout)


class CuotasGratuitasTests(unittest.TestCase):
    def test_criterio_principal(self) -> None:
        normal = scenario("normal")
        rows = {row["id"]: row for row in normal["rows"]}
        self.assertEqual(normal["timezone"], "America/Bogota")
        self.assertEqual(rows["deepl"]["used"], 35)
        self.assertEqual(rows["deepl"]["limit"], 100)
        self.assertEqual(rows["deepl"]["status"], "OK")
        self.assertEqual(rows["mail"]["status"], "UNKNOWN")
        self.assertEqual(rows["backups"]["status"], "UNKNOWN")
        self.assertEqual(rows["hosting"]["status"], "UNKNOWN")
        self.assertEqual(rows["deepl"]["measured_at"], "11:58 AM, 30/10/2026")
        self.assertEqual(normal["warning_threshold_percent"], 80)
        self.assertEqual(normal["warnings"], [])

        warning = scenario("warning")
        warn_rows = {row["id"]: row for row in warning["rows"]}
        self.assertEqual(warn_rows["deepl"]["status"], "WARNING")
        self.assertEqual(warn_rows["mail"]["status"], "OK")
        self.assertEqual(warning["warnings"], ["deepl"])
        self.assertEqual(warn_rows["mail"]["measured_at"], "11:58 AM, 30/10/2026")

        exhausted = scenario("exhausted")
        exhausted_row = exhausted["rows"][0]
        self.assertEqual(exhausted_row["status"], "EXHAUSTED")
        self.assertEqual(exhausted["warnings"], ["deepl"])

        unknown = scenario("unknown")
        self.assertTrue(all(row["status"] == "UNKNOWN" for row in unknown["rows"]))
        self.assertTrue(all(row["used"] is None for row in unknown["rows"]))

    def test_presupuesto_deepl_corta_sin_red(self) -> None:
        script = ROOT / "tests" / "deepl_real_transport_scenarios.php"
        result = subprocess.run(["php", str(script), "budget"], cwd=ROOT,
                                text=True, capture_output=True, timeout=20, check=False)
        if result.returncode:
            raise AssertionError(result.stdout + result.stderr)
        payload = json.loads(result.stdout)
        self.assertEqual(payload["exhausted"], "deepl_budget_exhausted")
        self.assertEqual(payload["calls_at_limit"], 2)
        self.assertEqual(payload["malformed_http_calls"], 0)

    def test_sin_secretos_y_fuente_de_metrica(self) -> None:
        normal = scenario("normal")
        text = json.dumps(normal).lower()
        self.assertNotIn("deepl_auth_key", text)
        self.assertNotIn("fixture-key", text)
        self.assertEqual(normal["rows"][0]["source"], "CONTADOR LOCAL")
        self.assertEqual(normal["rows"][1]["source"], "UNKNOWN")


if __name__ == "__main__":
    unittest.main()
