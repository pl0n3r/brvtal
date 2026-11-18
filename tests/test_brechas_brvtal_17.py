#!/usr/bin/env python3
"""BRVTAL #1107: five synthetic Tickets/Archive assertions, fail closed.

Local code evidence is never evidence of full delivery, real purchases, historical
retention, CI or production. The coverage matrix stays NO_VERIFICABLE.
"""
from __future__ import annotations

import json
import re
import shutil
import subprocess
import unittest
from functools import lru_cache
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
SPEC = ROOT / "docs/BRVTAL-SPEC.md"
SOURCES = {
    "workflow": "api/event-workflow-lib.php",
    "public": "api/public.php",
    "visibility": "config/public_visibility.php",
    "archive": "api/public-archive.php",
    "editor": "discadmin/event-workflow.js",
}
IDS = ("S15-R003", "S15-R005", "S15-R006", "S15-R007", "S16-R001")
CLAUSES = {
    "S15-R003": "Multiple ticket types are supported.",
    "S15-R005": "Availability windows are validated chronologically and the per-ticket QR can be selected from Media Library without creating a second asset model.",
    "S15-R006": "Do not store purchaser/attendee personal data in this phase.",
    "S15-R007": "Public event data should expose active and sold-out ticket types as appropriate.",
    "S16-R001": "BRVTAL preserves history.",
}
MISSING = {
    "S15-R003": "UNKNOWN: live multiple-ticket workflow not observed",
    "S15-R005": "UNKNOWN: real Media Library QR picker and persisted windows not exercised",
    "S15-R006": "UNKNOWN: production schema and all privacy paths not audited",
    "S15-R007": "UNKNOWN: public HTTP ticket payload not exercised against a database",
    "S16-R001": "UNKNOWN: real historical retention and public archive browser not observed",
}
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_ASSERTION_ONLY"

PHP_PROBE = r"""
require 'api/event-workflow-lib.php';
require 'api/public-archive.php';
$now = new DateTimeImmutable('2026-10-15 12:00:00');
$early = ['name'=>'Synthetic Early', 'status'=>'active', 'currency'=>'COP',
  'price'=>'100', 'qr_image'=>'/synthetic/qr-one.png',
  'available_from'=>'2026-10-01 10:00', 'available_until'=>'2026-11-01 10:00'];
$sold = ['name'=>'Synthetic Sold', 'status'=>'sold_out', 'currency'=>'COP',
  'price'=>'200', 'qr_image'=>'/synthetic/qr-two.png',
  'available_from'=>'2026-10-01 10:00', 'available_until'=>'2026-11-01 10:00'];
$request = brvtal_event_workflow_request([
  'event'=>['title'=>'Synthetic Event', 'status'=>'draft',
           'event_date'=>'2026-11-11 20:00:00'],
  'ticket_types'=>[$early, $sold], 'lineup'=>[]]);
$invalidWindow = brvtal_ticket_window_error([
  'available_from'=>'2026-12-01 10:00:00',
  'available_until'=>'2026-11-01 10:00:00'])['error'] ?? null;
$invalidDate = null;
try {
  brvtal_event_workflow_ticket(array_replace($early, ['available_from'=>'not-a-date']));
} catch (InvalidArgumentException $e) {$invalidDate = $e->getMessage();}
$minimal = brvtal_event_workflow_ticket(array_replace($early, [
  'purchaser_email'=>'SYNTHETIC_ONLY', 'attendee_name'=>'SYNTHETIC_ONLY',
  'card_number'=>'SYNTHETIC_ONLY']));
$availability = [];
foreach (['active','sold_out','draft','inactive'] as $status) {
  $availability[$status] = brvtal_public_ticket_type_is_available(
    array_replace($early, ['status'=>$status]), $now);
}
$availability['malformed'] = brvtal_public_ticket_type_is_available(
  array_replace($early, ['available_until'=>'not-a-date']), $now);
$availability['future'] = brvtal_public_ticket_type_is_available(
  array_replace($early, ['available_from'=>'2026-12-01 10:00']), $now);
$events = [
  ['id'=>1,'status'=>'published','event_date'=>'2026-11-11 20:00:00',
   'ticket_types'=>[['id'=>11]],'ticket_url'=>'https://example.invalid/one'],
  ['id'=>2,'status'=>'finished','event_date'=>'2024-08-01 20:00:00',
   'ticket_types'=>[['id'=>22]],'ticket_url'=>'https://example.invalid/two'],
  ['id'=>3,'status'=>'draft','event_date'=>'2024-09-01 20:00:00'],
];
$partition = brvtal_public_partition_events($events, $now);
$past = $partition['archive'][0] ?? [];
echo json_encode([
  'ticket_names'=>array_column($request['ticket_types'],'name'),
  'ticket_statuses'=>array_column($request['ticket_types'],'status'),
  'qr_images'=>array_column($request['ticket_types'],'qr_image'),
  'invalid_window'=>$invalidWindow,
  'invalid_date'=>$invalidDate,
  'ticket_keys'=>array_keys($minimal),
  'availability'=>$availability,
  'archive_ids'=>array_column($partition['archive'],'id'),
  'active_ids'=>array_column($partition['active'],'id'),
  'years'=>$partition['years'],
  'archive_ticket_cleared'=>($past['ticket_types'] ?? null) === []
    && array_key_exists('ticket_url',$past) && $past['ticket_url'] === null,
], JSON_THROW_ON_ERROR);
"""


def matrix_rows(raw: object) -> dict[str, tuple[str, ...]]:
    if not isinstance(raw, str):
        raise ValueError("UNKNOWN: unreadable matrix")
    rows: dict[str, tuple[str, ...]] = {}
    for line in raw.splitlines():
        match = re.match(r"^\| (S(?:15|16)-R\d{3}) · ", line)
        if match is None or match.group(1) not in IDS:
            continue
        key = match.group(1)
        parts = tuple(item.strip() for item in line.strip("|").split("|"))
        if key in rows or len(parts) != 5 or not all(parts[:4]):
            raise ValueError("UNKNOWN: duplicate/malformed row " + key)
        rows[key] = parts
    if set(rows) != set(IDS):
        raise ValueError("UNKNOWN: missing target row")
    return rows


@lru_cache(maxsize=1)
def observed() -> dict[str, object]:
    php = shutil.which("php")
    if php is None:
        raise AssertionError("UNKNOWN: PHP runtime unavailable")
    result = subprocess.run([php, "-r", PHP_PROBE], cwd=ROOT, check=False,
                            capture_output=True, text=True, timeout=15)
    if result.returncode:
        raise AssertionError("UNKNOWN: offline PHP probe failed: " + result.stderr[-500:])
    try:
        value = json.loads(result.stdout)
    except ValueError as exc:
        raise AssertionError("UNKNOWN: malformed probe JSON") from exc
    if not isinstance(value, dict):
        raise AssertionError("UNKNOWN: malformed probe result")
    return value


def local_contract(identifier: str, sources: dict[str, str], value: object) -> bool:
    if identifier not in IDS or not isinstance(value, dict):
        return False
    if any(not isinstance(sources.get(key), str) or not sources[key] for key in SOURCES):
        return False
    tickets = (value.get("ticket_names") == ["Synthetic Early", "Synthetic Sold"]
               and value.get("ticket_statuses") == ["active", "sold_out"]
               and "function brvtal_event_workflow_request(" in sources["workflow"])
    window = (value.get("invalid_window") == "INVALID_AVAILABILITY_WINDOW"
              and isinstance(value.get("invalid_date"), str)
              and value.get("qr_images") == ["/synthetic/qr-one.png", "/synthetic/qr-two.png"]
              and "qr_image:read('qr_image')" in sources["editor"])
    private = (isinstance(value.get("ticket_keys"), list)
               and not {"purchaser_email", "attendee_name", "card_number"}
               .intersection(value["ticket_keys"])
               and "FROM event_ticket_types" in sources["public"])
    exposure = (value.get("availability") == {
        "active": True, "sold_out": True, "draft": False,
        "inactive": False, "malformed": False, "future": False}
        and "WHERE status IN ('active','sold_out')" in sources["public"]
        and "brvtal_public_ticket_type_is_available($ticket)" in sources["public"]
        and "$event['ticket_types'] = $ticketsByEvent[$eventKey] ?? [];" in sources["public"])
    history = (value.get("archive_ids") == [2]
               and value.get("active_ids") == [1] and value.get("years") == [2024]
               and value.get("archive_ticket_cleared") is True
               and "function brvtal_public_partition_events(" in sources["archive"])
    return bool({"S15-R003": tickets, "S15-R005": tickets and window,
                 "S15-R006": private, "S15-R007": exposure,
                 "S16-R001": history}[identifier])


def verdict(identifier: str, rows: dict[str, tuple[str, ...]],
            spec: str, sources: dict[str, str], value: object) -> dict[str, str]:
    state = {"candidate": UNKNOWN, "full_scope": UNKNOWN, "ci": UNKNOWN,
             "production": UNKNOWN, "missing": "UNKNOWN: evidence incomplete"}
    row = rows.get(identifier)
    if (row is None or identifier not in IDS or len(row) != 5
            or row[1] != CLAUSES[identifier] or row[2] != "NO_VERIFICABLE"
            or not row[0].startswith(identifier + " · ")
            or "Sin aserción contrastada" not in row[3]
            or row[4] != "—" or CLAUSES[identifier] not in spec
            or not local_contract(identifier, sources, value)):
        return state
    state["candidate"] = LOCAL
    state["missing"] = MISSING[identifier]
    return state


class BrechasBrvtal17Tests(unittest.TestCase):
    def setUp(self) -> None:
        self.rows = matrix_rows(MATRIX.read_text(encoding="utf-8"))
        self.spec = SPEC.read_text(encoding="utf-8")
        self.sources = {key: (ROOT / path).read_text(encoding="utf-8")
                        for key, path in SOURCES.items()}
        self.value = observed()

    def test_contrato_principal(self) -> None:
        self.assertEqual(set(IDS), set(self.rows))
        for identifier in IDS:
            with self.subTest(row=identifier):
                state = verdict(identifier, self.rows, self.spec, self.sources, self.value)
                self.assertTrue(local_contract(identifier, self.sources, self.value))
                self.assertEqual(LOCAL, state["candidate"])
                self.assertEqual(MISSING[identifier], state["missing"])

    def test_falla_cerrado(self) -> None:
        for identifier in IDS:
            with self.subTest(row=identifier):
                self.assertEqual(UNKNOWN, verdict(identifier, {}, self.spec, self.sources, self.value)["candidate"])
                self.assertEqual(UNKNOWN, verdict(identifier, self.rows, self.spec, {}, self.value)["candidate"])
                self.assertEqual(UNKNOWN, verdict(identifier, self.rows, self.spec, self.sources, None)["candidate"])
                changed = dict(self.rows)
                changed[identifier] = (self.rows[identifier][0], self.rows[identifier][1],
                                       "CUBIERTO", self.rows[identifier][3], self.rows[identifier][4])
                self.assertEqual(UNKNOWN, verdict(identifier, changed, self.spec, self.sources, self.value)["candidate"])
        first = next(line for line in MATRIX.read_text(encoding="utf-8").splitlines()
                     if line.startswith("| S15-R003 · "))
        with self.assertRaisesRegex(ValueError, "duplicate"):
            matrix_rows(first + "\n" + first)
        altered = {**self.value, "availability": {}}
        self.assertEqual(UNKNOWN, verdict("S15-R007", self.rows, self.spec, self.sources, altered)["candidate"])

    def test_sin_datos_reales(self) -> None:
        own = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(own, r"(?m)^(?:import|from)\s+(?:requests|httpx|socket|urllib|pymysql)\b")
        self.assertNotIn("-----BEGIN " + "PRIVATE KEY-----", own)
        self.assertNotIn("PDO(", PHP_PROBE)
        self.assertNotIn("curl_exec(", PHP_PROBE)
        self.assertIn("SYNTHETIC_ONLY", PHP_PROBE)
        self.assertTrue(all(path.startswith(("api/", "config/", "discadmin/"))
                            for path in SOURCES.values()))

    def test_estados_unknown_explicitos(self) -> None:
        for identifier in IDS:
            with self.subTest(row=identifier):
                state = verdict(identifier, self.rows, self.spec, self.sources, self.value)
                self.assertEqual("NO_VERIFICABLE", self.rows[identifier][2])
                self.assertTrue(state["missing"].startswith("UNKNOWN:"))
                for gate in ("full_scope", "ci", "production"):
                    self.assertEqual(UNKNOWN, state[gate])
        mutated = {**self.value, "archive_ids": []}
        self.assertEqual(UNKNOWN, verdict("S16-R001", self.rows, self.spec, self.sources, mutated)["candidate"])


if __name__ == "__main__":
    unittest.main()
