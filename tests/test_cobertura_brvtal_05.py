#!/usr/bin/env python3
"""BRVTAL #1274: run actual offline PHP for Tickets and Archive requirements.

All records are synthetic. No database, live API, browser, secrets, customer
data, deployed archive, provider or production entitlement is exercised.
The 18 B/C requirements remain unverified even if partial code guards pass.
"""
from __future__ import annotations

import json
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
UNKNOWN = "UNKNOWN"

# A: real, directly exercised local PHP behavior of the existing implementation.
OFFLINE = frozenset({"S15-R003", "S16-R004"})

# B: integral feature demands real persisted records, browser, provider, storage,
# or successful authorized lifecycle operations, rather than just a pure helper.
EXTERNAL = {
    "S15-R005": "Real Media Library QR selection and persisted ticket windows require UI/DB evidence.",
    "S15-R007": "Public API with persisted active and sold-out ticket types requires a real response.",
    "S16-R001": "Historical retention needs persisted archive/media and operational evidence.",
    "S16-R002": "Non-disappearance of finished media requires actual storage and public delivery.",
    "S16-R003": "Discovery across events, artists, sets, releases and media needs integration.",
    "S16-R006": "Combined filters and relationships must be checked in a browser and real catalog.",
    "S16-R007": "Canonical routes require actual published slugs and deployed route resolution.",
    "S17-R001": "Visual ease/searchability of the picker requires human/browser UX evidence.",
    "S17-R002": "No manual URL copy in every routine operation requires real admin workflows.",
    "S17-R003": "References from two persisted content records require transactional DB proof.",
    "S17-R004": "MEDIA_IN_USE on real DELETE with references requires authenticated DB/API evidence.",
    "S17-R005": "Whole media library + picker + metadata + sidecars + cleanup needs integration.",
    "S17-R007": "Native audio/video controls and published-only records need rendered UI testing.",
    "S18-R003": "Image derivative with byte-identical preserved original needs real GD/filesystem.",
    "S18-R004": "Image dimensions, quality warnings and contexts require rendered real variants.",
    "S18-R006": "Real GD/WebP generation with and without capability needs imaging runtime proof.",
    "S18-R009": "Safe complete cleanup with persisted references and sidecars needs DB/filesystem.",
}
DECLARATIVE = {
    "S15-R006": "No purchaser/attendee personal data is a systemwide privacy rule; a single normalizer cannot prove it."
}
IDS = OFFLINE | EXTERNAL.keys() | DECLARATIVE.keys()

# Executes the committed code, not an imitation of its logic in Python.
# Data only exists in PHP arrays; the library functions are pure.
PHP_PROBE = r"""
declare(strict_types=1);
require_once 'api/event-workflow-lib.php';
require_once 'api/public-archive.php';

$clock = new DateTimeImmutable('2026-10-15 12:00:00');
$wf = brvtal_event_workflow_request([
    'event' => ['title' => 'Synthetic Night', 'status' => 'draft',
                'event_date' => '2026-12-05 21:00:00'],
    'ticket_types' => [
        ['id' => '21', 'name' => 'Synthetic Early', 'price' => '100',
         'status' => 'active', 'currency' => 'cop'],
        ['id' => '22', 'name' => 'Synthetic Sold', 'price' => '200',
         'status' => 'sold_out', 'currency' => 'usd'],
    ],
    'lineup' => [],
]);
$error = static function (callable $fn): ?string {
    try { $fn(); return null; }
    catch (InvalidArgumentException $exc) { return $exc->getMessage(); }
};
$invalid = [
    'duplicate' => $error(static fn() => brvtal_event_workflow_request([
        'event' => ['title' => 'Synthetic', 'status' => 'draft'],
        'ticket_types' => [['id'=>5, 'name'=>'A'], ['id'=>5, 'name'=>'B']],
        'lineup' => [],
    ])),
    'id' => $error(static fn() => brvtal_event_workflow_ticket([
        'id' => 0, 'name' => 'Synthetic Invalid'
    ])),
    'currency' => $error(static fn() => brvtal_event_workflow_ticket([
        'name' => 'Synthetic Invalid', 'currency' => '123'
    ])),
    'url' => $error(static fn() => brvtal_event_workflow_ticket([
        'name' => 'Synthetic Invalid', 'external_url' => 'javascript:alert(1)'
    ])),
    'many' => $error(static fn() => brvtal_event_workflow_request([
        'event' => ['title' => 'Synthetic', 'status' => 'draft'],
        'ticket_types' => array_fill(0, 201, ['name' => 'Synthetic']),
        'lineup' => [],
    ])),
    'ambiguous' => $error(static fn() => brvtal_event_workflow_request([
        'event' => 'not-an-event', 'ticket_types' => [], 'lineup' => [],
    ])),
];
$events = [
    ['id'=>1, 'title'=>'Synthetic Future', 'status'=>'published',
     'event_date'=>'2026-12-05 21:00:00', 'ticket_types'=>[['id'=>21]]],
    ['id'=>2, 'title'=>'Synthetic Finished', 'status'=>'finished',
     'event_date'=>'2024-08-05 21:00:00', 'cover_image'=>'/synthetic/cover.png',
     'ticket_url'=>'/synthetic/ticket', 'ticket_instructions'=>'old',
     'ticket_qr'=>'/synthetic/qr.png', 'ticket_types'=>[['id'=>22]]],
    ['id'=>3, 'title'=>'Synthetic Past', 'status'=>'published',
     'event_date'=>'2025-10-01 21:00:00', 'archive_year'=>null,
     'ticket_types'=>[['id'=>23]], 'ticket_url'=>'/synthetic/ticket'],
    ['id'=>4, 'title'=>'Synthetic Private', 'status'=>'draft',
     'event_date'=>'2023-01-01 21:00:00'],
    ['id'=>5, 'title'=>'Synthetic Never Published', 'status'=>'archived',
     'event_date'=>'2027-11-01 21:00:00', 'published_at'=>null],
    ['id'=>6, 'title'=>'Synthetic Forbidden Timestamp', 'status'=>'finished',
     'event_date'=>'2022-06-01 21:00:00', 'published_at'=>'not-a-date'],
];
$partition = brvtal_public_partition_events($events, $clock);
$historical = [];
foreach ($partition['archive'] as $event) {
    $historical[(string)$event['id']] = [
        'archive_year' => $event['archive_year'] ?? null,
        'cover_image' => $event['cover_image'] ?? null,
        'ticket_url' => $event['ticket_url'] ?? 'MISSING',
        'ticket_instructions' => $event['ticket_instructions'] ?? 'MISSING',
        'ticket_qr' => $event['ticket_qr'] ?? 'MISSING',
        'ticket_types' => $event['ticket_types'] ?? null,
    ];
}
echo json_encode([
    'ticket_names' => array_column($wf['ticket_types'], 'name'),
    'ticket_ids' => array_column($wf['ticket_types'], 'id'),
    'ticket_statuses' => array_column($wf['ticket_types'], 'status'),
    'ticket_currencies' => array_column($wf['ticket_types'], 'currency'),
    'ticket_prices' => array_column($wf['ticket_types'], 'price'),
    'event_status' => $wf['event']['status'] ?? null,
    'invalid' => $invalid,
    'archive_ids' => array_column($partition['archive'], 'id'),
    'active_ids' => array_column($partition['active'], 'id'),
    'archive_years' => $partition['years'],
    'historical' => $historical,
], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
"""


def real_php() -> dict[str, object]:
    interpreter = shutil.which("php")
    if not interpreter:
        raise AssertionError("UNKNOWN: PHP unavailable")
    result = subprocess.run(
        [interpreter, "-d", "allow_url_fopen=0", "-r", PHP_PROBE],
        cwd=ROOT, capture_output=True, text=True, timeout=30, check=False,
    )
    if result.returncode:
        raise AssertionError("UNKNOWN: local PHP behavior failed")
    try:
        output = json.loads(result.stdout)
    except ValueError as exc:
        raise AssertionError("UNKNOWN: PHP evidence unreadable") from exc
    if not isinstance(output, dict):
        raise AssertionError("UNKNOWN: PHP evidence invalid")
    return output


class CoberturaBrvtal05Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.observed = real_php()

    def test_contrato_principal(self) -> None:
        """AC-01: two A rows call actual PHP; 18 remain B/C, not promoted."""
        self.assertEqual(len(IDS), 20)
        self.assertEqual(len(OFFLINE), 2)
        self.assertEqual(len(EXTERNAL), 17)
        self.assertEqual(len(DECLARATIVE), 1)
        # S15-R003: real workflow supports two distinct ticket types.
        observed = self.observed
        self.assertEqual(observed["ticket_names"], [
            "Synthetic Early", "Synthetic Sold"
        ])
        self.assertEqual(observed["ticket_ids"], [21, 22])
        self.assertEqual(observed["ticket_statuses"], ["active", "sold_out"])
        self.assertEqual(observed["ticket_currencies"], ["COP", "USD"])
        self.assertEqual(observed["ticket_prices"], ["100.00", "200.00"])
        self.assertEqual(observed["event_status"], "draft")
        # S16-R004: actual Archive partitions synthetic public Event lifecycles.
        self.assertEqual(observed["active_ids"], [1])
        self.assertEqual(observed["archive_ids"], [3, 2])
        self.assertEqual(observed["archive_years"], [2025, 2024])
        self.assertEqual(observed["historical"]["3"]["archive_year"], 2025)
        self.assertEqual(observed["historical"]["2"]["cover_image"],
                         "/synthetic/cover.png")
        for event in observed["historical"].values():
            self.assertEqual(event["ticket_types"], [])
            for field in ("ticket_url", "ticket_instructions", "ticket_qr"):
                self.assertEqual(event[field], "MISSING")
        # Never assert that a browser, DB, API or data retention is certified.
        self.assertEqual(set(OFFLINE) | set(EXTERNAL) | set(DECLARATIVE), IDS)

    def test_falla_cerrado(self) -> None:
        """AC-02: invalid workflow/permissions and private archive fail closed."""
        errors = self.observed["invalid"]
        self.assertEqual(errors["duplicate"], "DUPLICATE_TICKET_ID")
        self.assertEqual(errors["id"], "INVALID_TICKET_ID")
        self.assertEqual(errors["currency"], "INVALID_CURRENCY")
        self.assertEqual(errors["url"], "INVALID_EXTERNAL_URL")
        self.assertEqual(errors["many"], "TOO_MANY_TICKET_TYPES")
        self.assertEqual(errors["ambiguous"], "INVALID_EVENT_WORKFLOW")
        self.assertNotIn(4, self.observed["active_ids"] + self.observed["archive_ids"])
        self.assertNotIn(5, self.observed["active_ids"] + self.observed["archive_ids"])
        self.assertNotIn(6, self.observed["active_ids"] + self.observed["archive_ids"])
    def test_sin_datos_reales(self) -> None:
        """AC-03: only invented records and pure PHP libraries, no side effects."""
        self.assertEqual(set(OFFLINE) | set(EXTERNAL) | set(DECLARATIVE), IDS)
        self.assertTrue(all(reason for reason in EXTERNAL.values()))
        self.assertTrue(all(reason for reason in DECLARATIVE.values()))
        self.assertNotRegex(PHP_PROBE, r"\b(?:curl_exec|mysqli_connect|file_put_contents|shell_exec)\s*\(")
        self.assertNotIn("$_COOKIE", PHP_PROBE)
        self.assertNotIn("$_SERVER", PHP_PROBE)
        self.assertNotIn("-----BEGIN " + "PRIVATE KEY-----", PHP_PROBE)
        self.assertNotIn("SYNTHETIC_ONLY_CUSTOMER_DATA", json.dumps(self.observed))
        self.assertTrue((ROOT / "api/event-workflow-lib.php").is_file())
        self.assertTrue((ROOT / "api/public-archive.php").is_file())


if __name__ == "__main__":
    unittest.main()
