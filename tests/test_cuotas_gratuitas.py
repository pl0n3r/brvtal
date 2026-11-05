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
    def test_cuotas_sin_traduccion_automatica(self) -> None:
        normal = scenario("normal")
        rows = {row["id"]: row for row in normal["rows"]}
        self.assertEqual(list(rows), ["mail", "backups", "hosting"])
        self.assertEqual(normal["timezone"], "America/Bogota")
        self.assertEqual(rows["mail"]["used"], 35)
        self.assertEqual(rows["mail"]["limit"], 100)
        self.assertEqual(rows["mail"]["status"], "OK")
        self.assertEqual(rows["mail"]["measured_at"], "11:58 AM, 30/10/2026")
        self.assertEqual(rows["mail"]["source"], "MEDICIÓN CONFIGURADA")
        self.assertEqual(rows["backups"]["status"], "UNKNOWN")
        self.assertEqual(rows["hosting"]["status"], "UNKNOWN")
        self.assertEqual(normal["warning_threshold_percent"], 80)
        self.assertEqual(normal["warnings"], [])

        warning = scenario("warning")
        self.assertEqual(warning["rows"][0]["status"], "WARNING")
        self.assertEqual(warning["warnings"], ["mail"])
        exhausted = scenario("exhausted")
        self.assertEqual(exhausted["rows"][0]["status"], "EXHAUSTED")
        self.assertEqual(exhausted["warnings"], ["mail"])
        unknown = scenario("unknown")
        self.assertTrue(all(row["status"] == "UNKNOWN" for row in unknown["rows"]))
        self.assertTrue(all(row["used"] is None for row in unknown["rows"]))

    def test_umbral_usa_ratio_real_sin_redondear(self) -> None:
        precision = scenario("precision")
        mail = precision["rows"][0]
        self.assertEqual(mail["used"], 1599)
        self.assertEqual(mail["limit"], 2000)
        self.assertEqual(mail["percent"], 80.0)
        self.assertEqual(mail["status"], "OK")
        self.assertEqual(precision["warnings"], [])

    def test_tarjeta_permanece_despues_del_layout(self) -> None:
        js = (ROOT / "discadmin" / "system-status-v2.js").read_text()
        self.assertIn("root.querySelectorAll('.ssv2-grid').forEach(node => node.remove());", js)
        self.assertIn("freeQuotaPanel(quotas)", js)
        for label in ("['mail', 'CORREO']", "['backups', 'COPIAS']", "['hosting', 'HOSTING']"):
            self.assertIn(label, js)
        self.assertNotIn("'deepl'", js.lower())

    def test_sin_secretos_y_fuente_de_metrica(self) -> None:
        normal = scenario("normal")
        text = json.dumps(normal).lower()
        self.assertNotIn("auth_key", text)
        self.assertNotIn("fixture-key", text)
        self.assertEqual(normal["rows"][0]["source"], "MEDICIÓN CONFIGURADA")
        self.assertEqual(normal["rows"][1]["source"], "UNKNOWN")


if __name__ == "__main__":
    unittest.main()
