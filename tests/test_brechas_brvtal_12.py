#!/usr/bin/env python3
"""BRVTAL #1102: real PHP Event/Ticket guards under synthetic, offline inputs.

Only local contract behavior is asserted. GA4 reporting, persistence, live
analytics, production release and full §9 scope remain UNKNOWN.
"""
from __future__ import annotations

import ast
from functools import lru_cache
import json
from pathlib import Path
import re
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
MATRIX = "docs/matriz-cobertura.md"
SPEC = "docs/BRVTAL-SPEC.md"
WORKFLOW = "api/event-workflow-lib.php"
LIFECYCLE = "config/event_lifecycle.php"
PUBLIC = "config/public_visibility.php"
ANALYTICS = "config/event_analytics_signals.php"
WORKFLOW_CONTRACT = "tests/event-workflow-contract.php"
SOURCES = (SPEC, WORKFLOW, LIFECYCLE, PUBLIC, ANALYTICS, WORKFLOW_CONTRACT)
IDS = ("S09-L111", "S09-L112", "S09-L119", "S09-L120", "S09-L121")
PHRASES = {
    "S09-L111": "statistics;",
    "S09-L112": "tickets;",
    "S09-L119": "draft;",
    "S09-L120": "published;",
    "S09-L121": "archived;",
}
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_CONTRACT"

# Executed in a single isolated PHP process: no PDO, HTTP, filesystem writes,
# credentials or real Event/Buyer data. Analytics has a deliberately empty
# configuration and a requester which would raise on any network attempt.
PHP_PROBE = r'''
require 'api/event-workflow-lib.php';
require 'config/event_analytics_signals.php';
$clock = new DateTimeImmutable('2026-10-22 12:00:00+00:00');
$draft = ['title'=>'Fixture Draft','status'=>'draft'];
$public = [
  'title'=>'Fixture Night', 'status'=>'published',
  'event_date'=>'2026-11-02 20:00:00','city'=>'Pereira',
  'description'=>'Synthetic description'
];
$archivedFuture = array_replace($public,['status'=>'archived']);
$archivedPast = array_replace($archivedFuture,['event_date'=>'2026-04-01 20:00:00']);
$archivedPublished = array_replace($archivedFuture,['published_at'=>'2026-10-21 10:00:00']);
$futureScheduled = array_replace($public,['published_at'=>'2026-10-25 00:00:00']);
$ticket = brvtal_event_workflow_ticket([
  'id'=>'8','name'=>'Fixture Presale', 'status'=>'active',
  'price'=>'20000','currency'=>'cop',
  'available_from'=>'2026-10-20 00:00',
  'available_until'=>'2026-10-25 23:00'
]);
$badTicket = false;
try { brvtal_event_workflow_ticket(['name'=>'Fixture','currency'=>'not-a-currency']); }
catch (InvalidArgumentException $e) { $badTicket = $e->getMessage()==='INVALID_CURRENCY'; }
$duplicateTicket = false;
try {
 brvtal_event_workflow_request([
  'event'=>$draft,'ticket_types'=>[
    ['id'=>8,'name'=>'Fixture A'],['id'=>8,'name'=>'Fixture B']],
  'lineup'=>[]
 ]);
} catch (InvalidArgumentException $e) {
 $duplicateTicket = $e->getMessage()==='DUPLICATE_TICKET_ID';
}
$requests=0;
$request = function() use (&$requests): array {
 $requests++;throw new RuntimeException('NETWORK_FORBIDDEN');
};
$emptyConfig = ['property_id'=>'','client_email'=>'','private_key'=>''];
$analyticsEvent = ['id'=>42,'slug'=>'fixture-night','status'=>'published'];
$analytics = brvtalEventAnalyticsSignals(
 $analyticsEvent,'/events/fixture-night',$request,
 static fn()=>null, static function(array $row):void{},
 static fn():int=>1790000000, static fn():string=>'unreachable', $emptyConfig
);
$privateAnalytics = brvtalEventAnalyticsSignals(
 array_replace($analyticsEvent,['status'=>'draft']),
 '/events/fixture-night',$request,
 static fn()=>null, static function(array $row):void{},
 static fn():int=>1790000000, static fn():string=>'unreachable', $emptyConfig
);
$workflowDraft = brvtal_event_workflow_request([
 'event'=>$draft,'ticket_types'=>[],'lineup'=>[]
]);
$publishedPatch = brvtal_event_lifecycle_patch(
 ['status'=>'draft'], ['status'=>'published','published_at'=>'2000-01-01 00:00:00'], $clock
);
echo json_encode([
 'stats'=>[ 'public'=>$analytics, 'draft'=>$privateAnalytics,
            'request_count'=>$requests],
 'tickets'=>[ 'ticket'=>$ticket,
  'valid_window'=>brvtal_ticket_window_error($ticket)===null,
  'invalid_window'=>brvtal_ticket_window_error(array_replace($ticket,[
    'available_until'=>'2026-10-19 00:00:00']))!==null,
  'duplicate_denied'=>$duplicateTicket,'currency_denied'=>$badTicket,
  'available'=>brvtal_public_ticket_type_is_available($ticket,$clock),
  'draft_denied'=>!brvtal_public_ticket_type_is_available(
    array_replace($ticket,['status'=>'draft']),$clock),
  'expired_denied'=>!brvtal_public_ticket_type_is_available(
    array_replace($ticket,['available_until'=>'2026-10-21 10:00:00']),$clock),
  'published_event_allows'=>brvtal_public_event_allows_ticketing($public,$clock),
  'archived_event_denied'=>!brvtal_public_event_allows_ticketing($archivedPast,$clock)
 ],
 'draft'=>[
  'accepted'=>brvtal_event_state_error($draft)===null,
  'normalized'=>$workflowDraft['event']['status']??null,
  'public_denied'=>!brvtal_public_event_is_visible(
    array_replace($draft,['event_date'=>'2026-04-01 00:00:00']),$clock)
 ],
 'published'=>[
  'accepted'=>brvtal_event_state_error($public)===null,
  'missing_date'=>brvtal_event_state_error([
    'title'=>'Fixture','status'=>'published','city'=>'Pereira'])['error']??null,
  'visible'=>brvtal_public_event_is_visible($public,$clock),
  'schedule_hidden'=>!brvtal_public_event_is_visible($futureScheduled,$clock),
  'server_owned_timestamp'=>($publishedPatch['published_at']??null)
      ===$clock->format('Y-m-d H:i:s')
 ],
 'archived'=>[
  'editable'=>brvtal_event_state_error([
    'title'=>'Fixture','status'=>'archived'])===null,
  'future_hidden'=>!brvtal_public_event_is_visible($archivedFuture,$clock),
  'past_visible'=>brvtal_public_event_is_visible($archivedPast,$clock),
  'past_historical'=>brvtal_public_event_is_historical($archivedPast,$clock),
  'prior_public_visible'=>brvtal_public_event_is_visible($archivedPublished,$clock)
 ]
], JSON_THROW_ON_ERROR);
'''


def rows(raw: object) -> dict[str, tuple[str, ...]]:
    """A malformed/duplicated matrix row never becomes accepted evidence."""
    if type(raw) is not str:
        raise ValueError("UNKNOWN: missing coverage matrix")
    found: dict[str, tuple[str, ...]] = {}
    for line in raw.splitlines():
        match = re.match(r"^\| (S09-L\d{3}) · ", line)
        if match is None or match.group(1) not in IDS:
            continue
        key = match.group(1)
        cells = tuple(value.strip() for value in line.strip("|").split("|"))
        if (key in found or len(cells) != 5
                or not cells[0].startswith(key + " · ")
                or cells[1] != PHRASES[key]
                or cells[2] != "NO_VERIFICABLE"
                or f"Sin aserción contrastada para «{PHRASES[key]}»" not in cells[3]
                or WORKFLOW_CONTRACT not in cells[3]
                or "PR fusionado" not in cells[3]
                or cells[4] != "—"):
            raise ValueError("UNKNOWN: duplicate or malformed " + key)
        found[key] = cells
    if set(found) != set(IDS):
        raise ValueError("UNKNOWN: missing S09 coverage rows")
    return found


def sources() -> dict[str, str]:
    return {path: (ROOT / path).read_text(encoding="utf-8") for path in SOURCES}


@lru_cache(maxsize=1)
def probe() -> dict[str, object]:
    php = shutil.which("php")
    if php is None:
        raise AssertionError("UNKNOWN: PHP CLI missing")
    run = subprocess.run([php, "-d", "allow_url_fopen=0", "-r", PHP_PROBE],
                         cwd=ROOT, text=True, capture_output=True,
                         check=False, timeout=20)
    if run.returncode or run.stderr.strip():
        raise AssertionError("UNKNOWN: offline PHP failed: " + run.stderr[-700:])
    result = json.loads(run.stdout)
    if type(result) is not dict:
        raise AssertionError("UNKNOWN: invalid PHP result")
    return result


def verdict(key: object, table: object, data: object, observed: object) -> dict[str, str]:
    result = {"local": UNKNOWN, "full_scope": UNKNOWN,
              "ci": UNKNOWN, "production": UNKNOWN}
    if key not in IDS or type(table) is not dict or type(data) is not dict or type(observed) is not dict:
        return result
    if key not in table or len(table[key]) != 5 or table[key][1] != PHRASES[key] or table[key][2] != "NO_VERIFICABLE":
        return result
    if any(type(data.get(path)) is not str or not data[path] for path in SOURCES):
        return result
    spec = data[SPEC]
    if any(spec.splitlines().count("- " + phrase) != 1 for phrase in PHRASES.values()):
        return result
    for path, symbol in ((WORKFLOW, "brvtal_event_workflow_request"),
                         (PUBLIC, "brvtal_public_event_is_visible"),
                         (LIFECYCLE, "brvtal_event_lifecycle_patch"),
                         (ANALYTICS, "brvtalEventAnalyticsSignals"),
                         (WORKFLOW_CONTRACT, "brvtal_event_workflow_ticket")):
        if symbol not in data[path]:
            return result
    try:
        evidence = {
            "S09-L111": (
                observed["stats"]["public"]["state"] == "NOT CONFIGURED"
                and observed["stats"]["draft"]["state"] == "UNAVAILABLE"
                and observed["stats"]["public"]["read_only"] is True
                and observed["stats"]["request_count"] == 0),
            "S09-L112": (
                observed["tickets"]["ticket"]["currency"] == "COP"
                and observed["tickets"]["ticket"]["id"] == 8
                and all(observed["tickets"][k] is True for k in (
                    "valid_window", "invalid_window", "duplicate_denied",
                    "currency_denied", "available", "draft_denied",
                    "expired_denied", "published_event_allows", "archived_event_denied"))),
            "S09-L119": all(observed["draft"][k] is True for k in (
                "accepted", "public_denied"))
                and observed["draft"]["normalized"] == "draft",
            "S09-L120": all(observed["published"][k] is True for k in (
                "accepted", "visible", "schedule_hidden", "server_owned_timestamp"))
                and observed["published"]["missing_date"] == "EVENT_DATE_REQUIRED",
            "S09-L121": all(observed["archived"][k] is True for k in (
                "editable", "future_hidden", "past_visible",
                "past_historical", "prior_public_visible")),
        }
    except (KeyError, TypeError, IndexError, AttributeError):
        return result
    if evidence[key]:
        result["local"] = LOCAL
    # No local fixture certifies GA4 connectivity, persisted tickets, live
    # publication, archived history, exact-SHA CI, or production.
    return result


class BrechasBrvtal12Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        table, data, observed = rows((ROOT / MATRIX).read_text(encoding="utf-8")), sources(), probe()
        self.assertEqual(set(IDS), set(table))
        for key in IDS:
            with self.subTest(row=key):
                self.assertEqual(PHRASES[key], table[key][1])
                self.assertEqual("NO_VERIFICABLE", table[key][2])
                self.assertEqual(LOCAL, verdict(key, table, data, observed)["local"])
        self.assertEqual("NOT CONFIGURED", observed["stats"]["public"]["state"])
        self.assertTrue(observed["tickets"]["duplicate_denied"])

    def test_falla_cerrado(self) -> None:
        original = (ROOT / MATRIX).read_text(encoding="utf-8")
        table, data, observed = rows(original), sources(), probe()
        line = next(s for s in original.splitlines() if s.startswith("| S09-L111 · "))
        with self.assertRaisesRegex(ValueError, "duplicate"):
            rows(original + "\n" + line)
        with self.assertRaisesRegex(ValueError, "missing"):
            rows("\n".join(s for s in original.splitlines() if not s.startswith("| S09-L121 · ")))
        with self.assertRaisesRegex(ValueError, "malformed"):
            rows(original.replace(line, line.replace("NO_VERIFICABLE", "CUBIERTO")))
        for key in IDS:
            with self.subTest(row=key):
                for bad in (None, {}, {"ci": "success"}):
                    self.assertEqual(UNKNOWN, verdict(key, bad, data, observed)["local"])
                    self.assertEqual(UNKNOWN, verdict(key, table, bad, observed)["local"])
                    self.assertEqual(UNKNOWN, verdict(key, table, data, bad)["local"])
                changed = {**table, key: (table[key][0], table[key][1], "CUBIERTO", *table[key][3:])}
                self.assertEqual(UNKNOWN, verdict(key, changed, data, observed)["local"])
        modified = {**observed, "tickets": {**observed["tickets"], "duplicate_denied": False}}
        self.assertEqual(UNKNOWN, verdict("S09-L112", table, data, modified)["local"])
        self.assertEqual(UNKNOWN, verdict("S09-L999", table, data, observed)["local"])

    def test_sin_datos_reales(self) -> None:
        observed = probe()
        own = Path(__file__).read_text(encoding="utf-8")
        tree = ast.parse(own)
        names = {a.name.split(".")[0] for node in ast.walk(tree)
                 if isinstance(node, ast.Import) for a in node.names}
        names |= {node.module.split(".")[0] for node in ast.walk(tree)
                  if isinstance(node, ast.ImportFrom) and node.module}
        self.assertFalse(names & {"requests", "httpx", "urllib", "socket", "playwright", "pymysql"})
        for token in ("curl_exec(", "PDO(", "file_put_contents(", "shell_exec(", "HttpClient"):
            self.assertNotIn(token, PHP_PROBE)
        self.assertEqual(0, observed["stats"]["request_count"])
        self.assertEqual("/events/fixture-night", observed["stats"]["public"]["canonical_path"])
        self.assertEqual({"users": None, "sessions": None, "views": None},
                         observed["stats"]["public"]["metrics"])
        self.assertNotIn("access_token", json.dumps(observed))

    def test_estados_unknown_explicitos(self) -> None:
        table, data, observed = rows((ROOT / MATRIX).read_text(encoding="utf-8")), sources(), probe()
        for key in IDS:
            with self.subTest(row=key):
                status = verdict(key, table, data, observed)
                self.assertEqual(UNKNOWN, status["ci"])
                self.assertEqual(UNKNOWN, status["production"])
                self.assertEqual(UNKNOWN, status["full_scope"])
                self.assertEqual(UNKNOWN, verdict(key, table, data, {})["local"])
        self.assertEqual("UNAVAILABLE", observed["stats"]["draft"]["state"])
        self.assertTrue(observed["archived"]["future_hidden"])
        self.assertTrue(observed["published"]["schedule_hidden"])


if __name__ == "__main__":
    unittest.main()
