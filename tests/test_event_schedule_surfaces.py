#!/usr/bin/env python3
"""Regresión de superficies públicas para Events con publicación programada."""

from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
PUBLIC_API = ROOT / "api" / "public.php"
PUBLIC_PAGE = ROOT / "config" / "public_page.php"
PUBLIC_SITEMAP = ROOT / "config" / "public_sitemap.php"
INDEXNOW = ROOT / "config" / "indexnow.php"
ADMIN_API = ROOT / "api" / "index.php"
SPEC = ROOT / "docs" / "BRVTAL-SPEC.md"
VERSION = ROOT / "config" / "version.php"
PACKAGE = ROOT / "package.json"


class EventScheduleSurfaceTests(unittest.TestCase):
    def run_php(self, source: str) -> object:
        result = subprocess.run(
            ["php", "-r", source],
            cwd=ROOT,
            check=False,
            text=True,
            capture_output=True,
            timeout=30,
        )
        self.assertEqual(result.returncode, 0, result.stderr or result.stdout)
        return json.loads(result.stdout)

    def test_future_scheduled_event_is_fail_closed_across_public_surfaces(self) -> None:
        result = self.run_php(r'''
date_default_timezone_set("America/Bogota");
require_once "api/public-archive.php";
require_once "api/public-related.php";
require_once "config/public_sitemap.php";
require_once "config/indexnow.php";
$now = new DateTimeImmutable("2099-01-01 12:00:00");
$event = [
  "id"=>901,
  "title"=>"FUTURE SCHEDULED",
  "slug"=>"future-scheduled",
  "status"=>"published",
  "event_date"=>"2999-12-31 21:00:00",
  "published_at"=>"2999-12-01 12:00:00",
  "sort_order"=>0,
  "updated_at"=>"2099-01-01 12:00:00",
];
$partition = brvtal_public_partition_events([$event], $now);
$graph = brvtal_public_related_graph(
  $partition["active"],
  $partition["archive"],
  [],
  [],
  []
);
$sitemap = brvtalPublicSitemapLocalizedContentUrls(
  "https://www.brvtal.com.co",
  "events",
  [$event],
  true
);
echo json_encode([
  "visible"=>brvtal_public_event_is_visible($event, $now),
  "active"=>count($partition["active"]),
  "archive"=>count($partition["archive"]),
  "related_events"=>count($graph["events"]),
  "ticketing"=>brvtal_public_event_allows_ticketing($event, $now),
  "sitemap"=>count($sitemap),
  "indexnow"=>brvtalIndexNowChangeUrls("events", null, $event),
]);
''')
        self.assertEqual(
            result,
            {
                "visible": False,
                "active": 0,
                "archive": 0,
                "related_events": 0,
                "ticketing": False,
                "sitemap": 0,
                "indexnow": [],
            },
        )

        api = PUBLIC_API.read_text(encoding="utf-8")
        page = PUBLIC_PAGE.read_text(encoding="utf-8")
        sitemap = PUBLIC_SITEMAP.read_text(encoding="utf-8")
        self.assertIn("$partition = brvtal_public_partition_events($allEvents);", api)
        self.assertLess(
            api.index("$partition = brvtal_public_partition_events($allEvents);"),
            api.index("$timetableStatement = $pdo->prepare("),
        )
        self.assertIn(
            "static fn(array $event): bool => brvtal_public_event_is_visible($event)",
            page,
        )
        self.assertIn("if (brvtal_public_event_is_visible($detail))", page)
        self.assertIn(
            "if ($requiresEventVisibility && !brvtal_public_event_is_visible($row))",
            sitemap,
        )

    def test_boundary_crossing_requires_no_mutation_or_cron(self) -> None:
        result = self.run_php(r'''
require_once "api/public-archive.php";
$event = [
  "id"=>902,
  "slug"=>"boundary-night",
  "status"=>"published",
  "event_date"=>"2099-02-01 21:00:00",
  "published_at"=>"2099-01-01 18:30:00",
  "sort_order"=>0,
];
$original = $event;
$before = new DateTimeImmutable("2099-01-01 18:29:59");
$boundary = new DateTimeImmutable("2099-01-01 18:30:00");
$beforePartition = brvtal_public_partition_events([$event], $before);
$boundaryPartition = brvtal_public_partition_events([$event], $boundary);
echo json_encode([
  "before_visible"=>brvtal_public_event_is_visible($event, $before),
  "boundary_visible"=>brvtal_public_event_is_visible($event, $boundary),
  "before_count"=>count($beforePartition["active"]) + count($beforePartition["archive"]),
  "boundary_count"=>count($boundaryPartition["active"]) + count($boundaryPartition["archive"]),
  "unchanged"=>$event === $original,
]);
''')
        self.assertEqual(
            result,
            {
                "before_visible": False,
                "boundary_visible": True,
                "before_count": 0,
                "boundary_count": 1,
                "unchanged": True,
            },
        )

    def test_cancel_before_boundary_never_leaks_as_historical(self) -> None:
        result = self.run_php(r'''
require_once "config/event_lifecycle.php";
require_once "api/public-archive.php";
$now = new DateTimeImmutable("2099-01-01 12:00:00");
$scheduled = [
  "id"=>903,
  "slug"=>"cancel-before-boundary",
  "status"=>"published",
  "event_date"=>"2099-02-01 21:00:00",
  "published_at"=>"2099-01-01 18:30:00",
  "sort_order"=>0,
];
$patch = brvtal_event_lifecycle_patch($scheduled, ["status"=>"cancelled"], $now);
$cancelled = array_replace($scheduled, $patch);
$later = new DateTimeImmutable("2099-01-02 12:00:00");
$partition = brvtal_public_partition_events([$cancelled], $later);
echo json_encode([
  "published_at_present"=>array_key_exists("published_at", $cancelled),
  "published_at"=>$cancelled["published_at"],
  "visible"=>brvtal_public_event_is_visible($cancelled, $later),
  "active"=>count($partition["active"]),
  "archive"=>count($partition["archive"]),
]);
''')
        self.assertEqual(
            result,
            {
                "published_at_present": True,
                "published_at": None,
                "visible": False,
                "active": 0,
                "archive": 0,
            },
        )

    def test_indexnow_is_notification_only_for_schedule_changes(self) -> None:
        result = self.run_php(r'''
date_default_timezone_set("America/Bogota");
require_once "config/indexnow.php";
$future = [
  "id"=>904,
  "slug"=>"indexnow-scheduled",
  "status"=>"published",
  "event_date"=>"2999-12-31 21:00:00",
  "published_at"=>"2999-12-01 12:00:00",
];
$publicNow = $future;
$publicNow["published_at"] = "2000-01-01 00:00:00";
echo json_encode([
  "future"=>brvtalIndexNowChangeUrls("events", null, $future),
  "publish_now"=>brvtalIndexNowChangeUrls("events", $future, $publicNow),
]);
''')
        self.assertEqual(result["future"], [])
        self.assertEqual(
            result["publish_now"],
            [
                "https://www.brvtal.com.co/events/indexnow-scheduled",
                "https://www.brvtal.com.co/",
            ],
        )

        indexnow = INDEXNOW.read_text(encoding="utf-8")
        admin = ADMIN_API.read_text(encoding="utf-8")
        self.assertIn(
            "brvtalIndexNowNotifyChange($pdo, $resource, $before, $after);",
            admin,
        )
        self.assertIn("register_shutdown_function", indexnow)
        self.assertIn("fastcgi_finish_request", indexnow)
        self.assertNotIn("cron", indexnow.lower())
        self.assertNotIn("worker", indexnow.lower())

    def test_spec_declares_request_time_publication_gate(self) -> None:
        spec = SPEC.read_text(encoding="utf-8")
        version = VERSION.read_text(encoding="utf-8")
        package = json.loads(PACKAGE.read_text(encoding="utf-8"))

        self.assertIn("### Event publication scheduling", spec)
        self.assertIn("request-time not-before gate", spec)
        self.assertIn("clients never control `published_at` directly", spec)
        self.assertIn("without a database mutation or scheduled job", spec)
        self.assertIn("IndexNow remains an editorial-change notification mechanism, not a scheduler", spec)
        release_line = next(
            line for line in version.splitlines()
            if line.startswith("const BRVTAL_APP_VERSION = ")
        )
        release_version = release_line.split("'")[1]
        self.assertGreaterEqual(
            tuple(int(part) for part in release_version.split(".")),
            (0, 1, 121),
        )
        self.assertEqual(package["version"], release_version)
        self.assertEqual(
            package["scripts"]["test:event-schedule-surfaces"],
            "python3 -m unittest tests/test_event_schedule_surfaces.py",
        )
        self.assertIn(
            "npm run test:event-schedule-surfaces",
            package["scripts"]["test:integration"],
        )


if __name__ == "__main__":
    unittest.main()
