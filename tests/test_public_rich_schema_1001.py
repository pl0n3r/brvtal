"""BRVTAL #1001: executable Factory acceptance for real PHP rich-schema tests."""
from __future__ import annotations

from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
SCHEMA_CONTRACT = ROOT / "tests" / "public-rich-schema-contract.php"


class RichSchema1001Tests(unittest.TestCase):
    def test_php_future_event_schema(self) -> None:
        # This must execute PHP itself, not infer success from source inspection.
        php = shutil.which("php")
        self.assertIsNotNone(php, "PHP CLI is required for executable acceptance")
        run = subprocess.run(
            [php, str(SCHEMA_CONTRACT)],
            cwd=ROOT,
            capture_output=True,
            text=True,
            check=False,
            timeout=30,
        )
        self.assertEqual(
            run.returncode, 0,
            f"PHP public rich-schema contract failed\nSTDOUT:\n{run.stdout}\nSTDERR:\n{run.stderr}",
        )
        self.assertIn("BRVTAL entity-specific public JSON-LD contracts passed.", run.stdout)
        source = SCHEMA_CONTRACT.read_text(encoding="utf-8")
        self.assertIn("new DateTimeZone('America/Bogota')", source)
        self.assertIn("'event_date' => $futureEventDate", source)
        self.assertIn("str_replace(' ', 'T', $futureEventDate)", source)
        self.assertIn("https://schema.org/EventScheduled", source)
        self.assertIn("https://schema.org/EventCancelled", source)
        self.assertIn("https://schema.org/EventCompleted", source)


if __name__ == "__main__":
    unittest.main()
