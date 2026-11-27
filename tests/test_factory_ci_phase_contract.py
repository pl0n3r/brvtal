"""La fase del caller Factory CI debe coincidir con la fase canónica de datos.yml (#2257)."""
import json
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def caller_phase(text: str) -> str:
    phases = re.findall(r"^\s+phase:\s*['\"]?([A-Za-z_]+)['\"]?\s*$", text, re.M)
    if len(phases) != 1:
        raise AssertionError(f"se esperaba una sola entrada phase en factory-ci.yml, hay {len(phases)}")
    return phases[0]


class FactoryCiPhaseContract(unittest.TestCase):
    def test_caller_phase_matches_datos(self):
        canonical = json.loads((ROOT / "datos.yml").read_text(encoding="utf-8"))["phase"]
        caller = caller_phase((ROOT / ".github/workflows/factory-ci.yml").read_text(encoding="utf-8"))
        self.assertIn(canonical, ("construccion", "live"))
        self.assertEqual(caller, canonical)

    def test_detects_drift(self):
        drifted = "jobs:\n  factory-ci:\n    with:\n      phase: live\n"
        self.assertEqual(caller_phase(drifted), "live")
        self.assertNotEqual(caller_phase(drifted), "construccion")


if __name__ == "__main__":
    unittest.main()
