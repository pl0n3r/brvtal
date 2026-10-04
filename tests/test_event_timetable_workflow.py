from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def php(expression: str) -> subprocess.CompletedProcess[str]:
    script = "chdir($argv[1]); require 'api/event-workflow-lib.php'; " + expression
    return subprocess.run(
        ["php", "-r", script, str(ROOT)],
        cwd=ROOT,
        capture_output=True,
        text=True,
    )


class EventTimetableWorkflowTests(unittest.TestCase):
    def test_editor_serializes_timetable_into_existing_event_workflow_and_audits_atomic_changes(self) -> None:
        contract = subprocess.run(
            ["php", "tests/event-timetable-workflow-contract.php"],
            cwd=ROOT,
            capture_output=True,
            text=True,
        )
        self.assertEqual(contract.returncode, 0, contract.stderr)

        core = (ROOT / "discadmin/content-core.js").read_text(encoding="utf-8")
        workflow = (ROOT / "discadmin/event-workflow.js").read_text(encoding="utf-8")
        markup = (ROOT / "discadmin/content-core.php").read_text(encoding="utf-8")
        server = (ROOT / "api/event-workflow-lib.php").read_text(encoding="utf-8")
        self.assertIn("06 · TIMETABLE", markup)
        self.assertIn("refreshTimetable", core)
        self.assertIn("function timetablePayloads(root)", workflow)
        self.assertIn("submitEventWorkflow(eventData,tickets,lineup,timetable)", workflow)
        self.assertIn("Array.isArray(timetable) ? {timetable} : {}", workflow)
        self.assertIn("'timetable_create'", server)
        self.assertIn("'timetable_update'", server)
        self.assertIn("'timetable_delete'", server)

        linked = php("""$request=brvtal_event_workflow_request([
            'event'=>['title'=>'Editor','status'=>'draft'],
            'ticket_types'=>[],
            'lineup'=>[],
            'timetable'=>[[
                'artist_id'=>7,
                'label'=>'must not become authority',
                'starts_at'=>'2026-08-14 21:00',
                'ends_at'=>'2026-08-14 22:00',
                'timezone'=>'America/Bogota',
                'status'=>'approved'
            ]]
        ]); echo json_encode($request['timetable'][0]);""")
        self.assertEqual(linked.returncode, 0, linked.stderr)
        self.assertIn('"artist_id":7', linked.stdout)
        self.assertIn('"label":null', linked.stdout)

    def test_invalid_or_overlapping_rows_fail_without_partial_event_ticket_lineup_mutation(self) -> None:
        overlap = php("""try {
            brvtal_event_workflow_request([
              'event'=>['title'=>'Overlap','status'=>'draft'],
              'ticket_types'=>[],
              'lineup'=>[],
              'timetable'=>[
                ['label'=>'A','starts_at'=>'2026-08-14 21:00','ends_at'=>'2026-08-14 22:30','timezone'=>'America/Bogota'],
                ['label'=>'B','starts_at'=>'2026-08-14 22:00','ends_at'=>'2026-08-14 23:00','timezone'=>'America/Bogota']
              ]
            ]);
        } catch (InvalidArgumentException $e) { echo $e->getMessage(); exit(0); }
        exit(9);""")
        self.assertEqual(overlap.returncode, 0, overlap.stderr)
        self.assertEqual(overlap.stdout, "TIMETABLE_OVERLAP")

        integration = (ROOT / "tests/integration/event-workflow.php").read_text(encoding="utf-8")
        self.assertIn("TIMETABLE_ARTIST_NOT_FOUND", integration)
        self.assertIn("timetable failure must roll back Event mutation", integration)
        self.assertIn("timetable failure must roll back Ticket mutation", integration)
        self.assertIn("timetable failure must roll back lineup mutation", integration)
        self.assertIn("timetable failure must roll back timetable mutation", integration)
        self.assertIn("overlapping timetable must fail before workflow mutation", integration)


if __name__ == "__main__":
    unittest.main()
