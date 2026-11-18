from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class EventTimetableTests(unittest.TestCase):
    def run_contract(self) -> str:
        result = subprocess.run(
            ["php", "tests/event-timetable-contract.php"],
            cwd=ROOT,
            capture_output=True,
            text=True,
            timeout=30,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        return result.stdout

    def test_timetable_is_timezone_aware_ordered_and_rejects_overlap(self) -> None:
        self.assertIn("Event timetable contract passed", self.run_contract())

    def test_artist_slots_use_artist_id_without_authoritative_name_duplication(self) -> None:
        output = self.run_contract()
        self.assertIn("Event timetable contract passed", output)
        for path in (
            ROOT / "database/migration_event_timetable_01.sql",
            ROOT / "database/schema.sql",
        ):
            self.assertNotIn("artist_name", path.read_text(encoding="utf-8"))


if __name__ == "__main__":
    unittest.main()
