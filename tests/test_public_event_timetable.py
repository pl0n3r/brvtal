from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def php(expression: str) -> subprocess.CompletedProcess[str]:
    script = "chdir($argv[1]); require 'config/public_page.php'; " + expression
    return subprocess.run(
        ["php", "-r", script, str(ROOT)],
        cwd=ROOT,
        capture_output=True,
        text=True,
    )


class PublicEventTimetableTests(unittest.TestCase):
    def test_public_event_exposes_only_approved_ordered_timetable_rows(self) -> None:
        contract = subprocess.run(
            ["php", "tests/public-event-timetable-contract.php"],
            cwd=ROOT,
            capture_output=True,
            text=True,
        )
        self.assertEqual(contract.returncode, 0, contract.stderr)

        result = php("""$rows=[
          ['id'=>2,'event_id'=>9,'artist_id'=>null,'label'=>'SECOND','starts_at_utc'=>'2026-08-15 03:00:00','ends_at_utc'=>'2026-08-15 04:00:00','timezone'=>'America/Bogota','status'=>'approved','sort_order'=>2],
          ['id'=>1,'event_id'=>9,'artist_id'=>4,'label'=>'IGNORED','starts_at_utc'=>'2026-08-15 02:00:00','ends_at_utc'=>'2026-08-15 03:00:00','timezone'=>'America/Bogota','status'=>'approved','sort_order'=>1,'artist_name'=>'FIRST','artist_slug'=>'first','artist_image'=>'','artist_status'=>'published'],
          ['id'=>3,'event_id'=>9,'artist_id'=>null,'label'=>'DRAFT','starts_at_utc'=>'2026-08-15 01:00:00','ends_at_utc'=>'2026-08-15 02:00:00','timezone'=>'America/Bogota','status'=>'draft','sort_order'=>0]
        ]; echo json_encode(brvtalPublicEventTimetableRows($rows));""")
        self.assertEqual(result.returncode, 0, result.stderr)
        rows = json.loads(result.stdout)
        self.assertEqual([row["title"] for row in rows], ["FIRST", "SECOND"])
        self.assertNotIn("artist_id", rows[0])
        self.assertEqual(rows[0]["route_type"], "artists")

    def test_draft_private_or_invalid_schedule_rows_fail_closed_without_leaking_artist_data(self) -> None:
        result = php("""$rows=[
          ['id'=>1,'event_id'=>9,'artist_id'=>8,'label'=>'PRIVATE FALLBACK','starts_at_utc'=>'2026-08-15 02:00:00','ends_at_utc'=>'2026-08-15 03:00:00','timezone'=>'America/Bogota','status'=>'approved','sort_order'=>1,'artist_name'=>null,'artist_slug'=>null,'artist_image'=>null,'artist_status'=>null],
          ['id'=>2,'event_id'=>9,'artist_id'=>null,'label'=>'DRAFT','starts_at_utc'=>'2026-08-15 03:00:00','ends_at_utc'=>'2026-08-15 04:00:00','timezone'=>'America/Bogota','status'=>'draft','sort_order'=>2],
          ['id'=>3,'event_id'=>9,'artist_id'=>null,'label'=>'BAD WINDOW','starts_at_utc'=>'2026-08-15 05:00:00','ends_at_utc'=>'2026-08-15 04:00:00','timezone'=>'America/Bogota','status'=>'approved','sort_order'=>3],
          ['id'=>4,'event_id'=>9,'artist_id'=>null,'label'=>'BAD ZONE','starts_at_utc'=>'2026-08-15 05:00:00','ends_at_utc'=>'2026-08-15 06:00:00','timezone'=>'Not/AZone','status'=>'approved','sort_order'=>4]
        ]; echo json_encode(brvtalPublicEventTimetableRows($rows));""")
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(json.loads(result.stdout), [])

        page = (ROOT / "config/public_page.php").read_text(encoding="utf-8")
        api = (ROOT / "api/public.php").read_text(encoding="utf-8")
        self.assertIn("brvtal_public_event_is_visible($detail)", page)
        self.assertIn("a.status='published'", api)
        partition = api.index("$partition = brvtal_public_partition_events($allEvents);")
        timetable_query = api.index("$timetableStatement = $pdo->prepare(")
        self.assertLess(partition, timetable_query)
        self.assertIn("foreach (array_merge($events, $archiveEvents) as $publicEvent)", api)
        self.assertIn("WHERE t.event_id IN ({$timetablePlaceholders}) AND t.status='approved'", api)


if __name__ == "__main__":
    unittest.main()
