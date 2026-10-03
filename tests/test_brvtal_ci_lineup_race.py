import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SPEC_PATH = ROOT / "tests/e2e/discadmin-content-core-lineup-integrity.spec.mjs"


class BrvtalCiLineupRaceTests(unittest.TestCase):
    def test_lineup_regression_uses_explicit_hydration_barrier(self):
        spec = SPEC_PATH.read_text(encoding="utf-8")
        self.assertIn("const lineupGate = new Promise", spec)
        self.assertIn("await lineupGate;", spec)
        self.assertIn("releaseLineup();", spec)
        self.assertNotIn("setTimeout(resolve, 220)", spec)


if __name__ == "__main__":
    unittest.main()
