from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def php(expression: str) -> subprocess.CompletedProcess[str]:
    script = (
        "chdir($argv[1]); "
        "require 'api/event-workflow-lib.php'; "
        f"{expression}"
    )
    return subprocess.run(
        ["php", "-r", script, str(ROOT)],
        cwd=ROOT,
        capture_output=True,
        text=True,
    )


class EventTimetableTests(unittest.TestCase):
    def test_timetable_is_timezone_aware_ordered_and_rejects_overlap(self) -> None:
        expression = """$rows=brvtal_event_timetable([
            ['label'=>'LATE','starts_at'=>'2026-08-15T01:00','ends_at'=>'2026-08-15T02:20','timezone'=>'America/Bogota','status'=>'approved','sort_order'=>9],
            ['label'=>'OPEN','starts_at'=>'2026-08-14T21:00','ends_at'=>'2026-08-14T22:20','timezone'=>'America/Bogota','status'=>'draft','sort_order'=>3],
            ['artist_id'=>7,'starts_at'=>'2026-08-14T22:20','ends_at'=>'2026-08-14T23:40','timezone'=>'America/Bogota','status'=>'approved','sort_order'=>1],
        ]); echo json_encode($rows);"""
        result = php(expression)
        self.assertEqual(result.returncode, 0, result.stderr)
        rows = json.loads(result.stdout)
        self.assertEqual([row["sort_order"] for row in rows], [0, 1, 2])
        self.assertEqual(rows[0]["label"], "OPEN")
        self.assertEqual(rows[0]["starts_at_utc"], "2026-08-15 02:00:00")
        self.assertEqual(rows[2]["ends_at_utc"], "2026-08-15 07:20:00")
        self.assertEqual(rows[0]["timezone"], "America/Bogota")

        overlap = php("""try {
            brvtal_event_timetable([
              ['label'=>'A','starts_at'=>'2026-08-14 21:00','ends_at'=>'2026-08-14 22:30','timezone'=>'America/Bogota'],
              ['label'=>'B','starts_at'=>'2026-08-14 22:00','ends_at'=>'2026-08-14 23:00','timezone'=>'America/Bogota'],
            ]);
        } catch (InvalidArgumentException $e) { echo $e->getMessage(); exit(0); }
        exit(9);""")
        self.assertEqual(overlap.returncode, 0, overlap.stderr)
        self.assertEqual(overlap.stdout, "TIMETABLE_OVERLAP")

    def test_artist_slots_use_artist_id_without_authoritative_name_duplication(self) -> None:
        migration = (ROOT / "database/migration_event_timetable_01.sql").read_text(encoding="utf-8")
        schema = (ROOT / "database/schema.sql").read_text(encoding="utf-8")
        for sql in (migration, schema):
            self.assertIn("artist_id INT UNSIGNED NULL", sql)
            self.assertIn("fk_event_timetable_artist", sql)
            self.assertNotIn("artist_name", sql)

        rejected = php("""try {
            brvtal_event_timetable([[
              'artist_id'=>7,
              'artist_name'=>'DUPLICATED NAME',
              'starts_at'=>'2026-08-14 21:00',
              'ends_at'=>'2026-08-14 22:00',
              'timezone'=>'America/Bogota'
            ]]);
        } catch (InvalidArgumentException $e) { echo $e->getMessage(); exit(0); }
        exit(9);""")
        self.assertEqual(rejected.returncode, 0, rejected.stderr)
        self.assertEqual(rejected.stdout, "INVALID_TIMETABLE_FIELD")

        accepted = php("""$rows=brvtal_event_timetable([[
            'artist_id'=>7,
            'label'=>'CLOSING SLOT',
            'starts_at'=>'2026-08-14 23:00',
            'ends_at'=>'2026-08-15 00:20',
            'timezone'=>'America/Bogota',
            'status'=>'approved'
        ]]); echo json_encode($rows[0]);""")
        self.assertEqual(accepted.returncode, 0, accepted.stderr)
        row = json.loads(accepted.stdout)
        self.assertEqual(row["artist_id"], 7)
        self.assertNotIn("artist_name", row)

        contract = subprocess.run(
            ["php", "tests/event-timetable-contract.php"],
            cwd=ROOT,
            capture_output=True,
            text=True,
        )
        self.assertEqual(contract.returncode, 0, contract.stderr)


if __name__ == "__main__":
    unittest.main()
