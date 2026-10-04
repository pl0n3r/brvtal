#!/usr/bin/env python3
"""Contrato del gate de programación pública de Events."""

from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
PUBLIC_VISIBILITY = ROOT / "config" / "public_visibility.php"
EVENT_LIFECYCLE = ROOT / "config" / "event_lifecycle.php"
CONTENT_VALIDATION = ROOT / "api" / "content-validation.php"
CORE_API = ROOT / "api" / "index.php"
WORKFLOW = ROOT / "api" / "event-workflow-lib.php"
VERSION = ROOT / "config" / "version.php"
PACKAGE = ROOT / "package.json"


class EventPublicationScheduleTests(unittest.TestCase):
    def run_php(self, source: str) -> str:
        result = subprocess.run(
            ["php", "-r", source],
            cwd=ROOT,
            check=False,
            text=True,
            capture_output=True,
            timeout=30,
        )
        self.assertEqual(result.returncode, 0, result.stderr or result.stdout)
        return result.stdout.strip()

    def test_future_publish_at_is_hidden_until_boundary_then_visible(self) -> None:
        out = self.run_php(r'''
require "config/public_visibility.php";
$event = [
  "status"=>"published",
  "event_date"=>"2026-11-01 21:00:00",
  "published_at"=>"2026-10-04 18:30:00",
];
$before = new DateTimeImmutable("2026-10-04 18:29:59");
$boundary = new DateTimeImmutable("2026-10-04 18:30:00");
$bad = $event;
$bad["published_at"] = "not-a-date";
echo json_encode([
  brvtal_public_event_is_visible($event, $before),
  brvtal_public_event_is_visible($event, $boundary),
  brvtal_public_event_is_visible($bad, $boundary),
]);
''')
        self.assertEqual(json.loads(out), [False, True, False])

    def test_historical_event_never_uses_future_published_at_as_publication_evidence(self) -> None:
        out = self.run_php(r'''
require "config/event_lifecycle.php";
$now = new DateTimeImmutable("2026-10-04 18:00:00");
$future = [
  "status"=>"published",
  "event_date"=>"2026-11-01 21:00:00",
  "published_at"=>"2026-10-04 18:30:00",
];
$patch = brvtal_event_lifecycle_patch($future, ["status"=>"cancelled"], $now);
$state = array_replace($future, $patch);
echo json_encode([
  brvtal_public_event_is_visible($future, $now),
  array_key_exists("published_at", $patch) ? $patch["published_at"] : "missing",
  brvtal_public_event_is_visible($state, new DateTimeImmutable("2026-10-04 19:00:00")),
]);
''')
        self.assertEqual(json.loads(out), [False, None, False])

    def test_lifecycle_consumes_virtual_publish_at_without_client_control_of_published_at(self) -> None:
        out = self.run_php(r'''
require "config/event_lifecycle.php";
$now = new DateTimeImmutable("2026-10-04 18:00:00");
$future = "2026-10-04 19:00:00";
$scheduled = brvtal_event_lifecycle_patch([], [
  "status"=>"published",
  "publish_at"=>$future,
  "published_at"=>"2000-01-01 00:00:00",
], $now);
$direct = brvtal_event_lifecycle_patch([], [
  "status"=>"published",
  "published_at"=>"2000-01-01 00:00:00",
], $now);
echo json_encode([
  $scheduled["published_at"] ?? null,
  array_key_exists("publish_at", $scheduled),
  $direct["published_at"] ?? null,
]);
''')
        self.assertEqual(
            json.loads(out),
            ["2026-10-04 19:00:00", False, "2026-10-04 18:00:00"],
        )

    def test_future_schedule_requires_active_public_status_and_blank_can_publish_now(self) -> None:
        out = self.run_php(r'''
require "config/event_lifecycle.php";
$now = new DateTimeImmutable("2026-10-04 18:00:00");
$future = "2026-10-04 19:00:00";
$past = "2026-10-04 17:00:00";
$draftError = brvtal_event_publish_at_error([], ["status"=>"draft","publish_at"=>$future], $now);
$pastError = brvtal_event_publish_at_error([], ["status"=>"published","publish_at"=>$past], $now);
$valid = brvtal_event_publish_at_error([], ["status"=>"published","publish_at"=>$future], $now);
$before = ["status"=>"published","published_at"=>$future,"event_date"=>"2026-11-01 21:00:00"];
$publishNow = brvtal_event_lifecycle_patch($before, ["publish_at"=>null], $now);
echo json_encode([
  $draftError["error"] ?? null,
  $pastError["error"] ?? null,
  $valid,
  $publishNow["published_at"] ?? null,
]);
''')
        self.assertEqual(
            json.loads(out),
            [
                "EVENT_PUBLISH_AT_REQUIRES_PUBLIC_STATUS",
                "EVENT_PUBLISH_AT_MUST_BE_FUTURE",
                None,
                "2026-10-04 18:00:00",
            ],
        )

    def test_core_and_workflow_contracts_accept_virtual_publish_at_without_schema_change(self) -> None:
        validation = CONTENT_VALIDATION.read_text(encoding="utf-8")
        core = CORE_API.read_text(encoding="utf-8")
        workflow = WORKFLOW.read_text(encoding="utf-8")
        lifecycle = EVENT_LIFECYCLE.read_text(encoding="utf-8")
        visibility = PUBLIC_VISIBILITY.read_text(encoding="utf-8")
        version = VERSION.read_text(encoding="utf-8")
        package = json.loads(PACKAGE.read_text(encoding="utf-8"))

        self.assertIn("'publish_at' => [", validation)
        self.assertIn("in_array($status, brvtal_public_event_statuses()['active'], true)", validation)
        self.assertIn("'archive_year','status','sort_order','publish_at'", core)
        self.assertGreaterEqual(core.count("brvtal_event_publish_at_error("), 2)
        self.assertIn("if (array_key_exists('publish_at', $input))", workflow)
        self.assertGreaterEqual(workflow.count("brvtal_event_publish_at_error("), 2)
        self.assertGreaterEqual(workflow.count("brvtalContentVisualPublicationError('events', $finalEvent)"), 2)
        self.assertIn("unset($patch['publish_at']);", lifecycle)
        self.assertIn("unset($patch[$field]);", lifecycle)
        self.assertIn("$publishedAt > $now", visibility)
        self.assertNotIn("scheduled_publish_at", lifecycle)
        self.assertNotIn("ALTER TABLE", lifecycle)
        self.assertIn("BRVTAL_APP_VERSION = '0.1.119'", version)
        self.assertEqual(package["version"], "0.1.119")
        self.assertEqual(
            package["scripts"]["test:event-publication-schedule"],
            "python3 -m unittest tests/test_event_publication_schedule.py",
        )
        self.assertIn(
            "npm run test:event-publication-schedule",
            package["scripts"]["test:integration"],
        )


if __name__ == "__main__":
    unittest.main()
