#!/usr/bin/env python3
"""BRVTAL #1103: synthetic, offline evidence for S09-L122..S12-R001.

The event lifecycle contracts and Artist membership slice are testable here.
Full Artist CRUD, persistence, release and production remain UNKNOWN.
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
VISIBILITY = "config/public_visibility.php"
WORKFLOW = "api/event-workflow-lib.php"
LIFECYCLE = "config/event_lifecycle.php"
MEMBERSHIP = "config/artist_collective_membership.php"
API = "api/index.php"
EVENT_CONTRACT = "tests/event-workflow-contract.php"
ARTIST_CONTRACT = "tests/artist-collective-lifecycle-contract.php"
SOURCES = (SPEC, VISIBILITY, WORKFLOW, LIFECYCLE, MEMBERSHIP, API,
           EVENT_CONTRACT, ARTIST_CONTRACT)
PHRASES = {
    "S09-L122": "upcoming;",
    "S09-L125": "sold_out;",
    "S09-L126": "cancelled;",
    "S09-L127": "finished.",
    "S12-R001": "Artists have full CRUD and a rich profile model.",
}
STATUSES = {
    "S09-L122": "upcoming",
    "S09-L125": "sold_out",
    "S09-L126": "cancelled",
    "S09-L127": "finished",
}
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_CONTRACT_PARTIAL"

# PHP only loads pure in-repository contracts, not the API front controller.
# The clock and inputs are fixed and fabricated; no DB, HTTP or filesystem writes.
PHP_PROBE = r'''
require 'api/event-workflow-lib.php';
require 'config/artist_collective_membership.php';
$now = new DateTimeImmutable('2026-10-28 12:00:00+00:00');
$base = [
    'title'=>'Synthetic Event', 'city'=>'Example City',
    'event_date'=>'2026-11-12 20:00:00',
    'description'=>'Invented event with no participants'
];
$states = [];
foreach (['upcoming','sold_out','cancelled','finished'] as $name) {
    $event = array_replace($base, ['status'=>$name]);
    $past = array_replace($event, ['event_date'=>'2026-01-12 20:00:00']);
    $futureBoundary = array_replace($event, ['published_at'=>'2026-11-01 12:00:00']);
    $saved = brvtal_event_workflow_event($event);
    $states[$name] = [
        'normalized'=>($saved['status'] ?? null)===$name,
        'accepted'=>brvtal_event_state_error($event)===null,
        'visible_future'=>brvtal_public_event_is_visible($event,$now),
        'future_boundary_hidden'=>!brvtal_public_event_is_visible($futureBoundary,$now),
        'past_visible'=>brvtal_public_event_is_visible($past,$now),
        'past_historical'=>brvtal_public_event_is_historical($past,$now),
        'ticketing_future'=>brvtal_public_event_allows_ticketing($event,$now),
        'invalid_status_rejected'=>false,
    ];
    try {
        brvtal_event_workflow_event(array_replace($base,['status'=>'unknown_fake']));
    } catch (InvalidArgumentException $error) {
        $states[$name]['invalid_status_rejected']=$error->getMessage()==='INVALID_STATUS';
    }
}
$cancel = brvtal_event_lifecycle_patch(
    ['status'=>'published'],['status'=>'cancelled','cancelled_at'=>'2000-01-01'],$now
);
$finish = brvtal_event_lifecycle_patch(
    ['status'=>'published'],['status'=>'finished','finished_at'=>'2000-01-01'],$now
);
$member = brvtalArtistCollectiveNormalizePayload(['is_collective_member'=>true]);
$nonMember = brvtalArtistCollectiveNormalizePayload(['is_collective_member'=>false]);
$invalid = brvtalArtistCollectiveNormalizePayload(['is_collective_member'=>'active']);
echo json_encode([
    'states'=>$states,
    'transitions'=>[
        'cancelled_at'=>$cancel['cancelled_at']??null,
        'finished_at'=>$finish['finished_at']??null,
    ],
    'artist'=>[
        'member'=>($member['payload']['is_collective_member']??null)===1
            && $member['error']===null,
        'nonmember'=>($nonMember['payload']['is_collective_member']??null)===0
            && $nonMember['error']===null,
        'invalid_rejected'=>($invalid['error']['error']??null)==='INVALID_COLLECTIVE_MEMBERSHIP',
        'canonical_over_legacy'=>!brvtalArtistCollectiveMembershipValue(
            ['is_collective_member'=>0,'collective_status'=>'active']
        ),
    ],
], JSON_THROW_ON_ERROR);
'''


def matrix_rows(raw: object) -> dict[str, tuple[str, ...]]:
    """Reject missing, ambiguous, duplicated and promoted coverage rows."""
    if not isinstance(raw, str):
        raise ValueError("UNKNOWN: no matrix")
    grouped: dict[str, list[tuple[str, ...]]] = {key: [] for key in PHRASES}
    for line in raw.splitlines():
        if not line.lstrip().startswith("|"):
            continue
        cells = tuple(cell.strip() for cell in line.strip().strip("|").split("|"))
        identifier = cells[0].split("·", 1)[0].strip() if cells else ""
        if identifier in grouped:
            grouped[identifier].append(cells)
    rows: dict[str, tuple[str, ...]] = {}
    for identifier, phrase in PHRASES.items():
        candidates = grouped[identifier]
        if len(candidates) != 1:
            raise ValueError("UNKNOWN: missing/duplicate " + identifier)
        cells = candidates[0]
        contract = EVENT_CONTRACT if identifier in STATUSES else ARTIST_CONTRACT
        if (len(cells) != 5 or not cells[0].startswith(identifier + " · ")
                or cells[1] != phrase or cells[2] != "NO_VERIFICABLE"
                or f"Sin aserción contrastada para «{phrase}»" not in cells[3]
                or contract not in cells[3] or "PR fusionado" not in cells[3]
                or cells[4] != "—"):
            raise ValueError("UNKNOWN: invalid row " + identifier)
        rows[identifier] = cells
    return rows


def sources() -> dict[str, str]:
    return {path: (ROOT / path).read_text(encoding="utf-8") for path in SOURCES}


@lru_cache(maxsize=1)
def probe() -> dict[str, object]:
    php = shutil.which("php")
    if php is None:
        raise AssertionError("UNKNOWN: PHP CLI unavailable")
    result = subprocess.run(
        [php, "-d", "allow_url_fopen=0", "-r", PHP_PROBE],
        cwd=ROOT, text=True, capture_output=True, timeout=20, check=False
    )
    if result.returncode or result.stderr.strip():
        raise AssertionError("UNKNOWN: offline PHP failed: " + result.stderr[-700:])
    decoded = json.loads(result.stdout)
    if type(decoded) is not dict:
        raise AssertionError("UNKNOWN: invalid result")
    return decoded


def verdict(identifier: object, table: object, data: object,
            observed: object) -> dict[str, str]:
    result = {"local": UNKNOWN, "full_scope": UNKNOWN,
              "ci": UNKNOWN, "production": UNKNOWN}
    if (identifier not in PHRASES or type(table) is not dict
            or type(data) is not dict or type(observed) is not dict):
        return result
    if (identifier not in table or len(table[identifier]) != 5
            or table[identifier][1] != PHRASES[identifier]
            or table[identifier][2] != "NO_VERIFICABLE"):
        return result
    if any(type(data.get(path)) is not str or not data[path] for path in SOURCES):
        return result
    spec = data[SPEC]
    if (spec.splitlines().count("- " + PHRASES[identifier]) != 1
            if identifier in STATUSES
            else spec.count(PHRASES[identifier]) != 1):
        return result
    if (identifier in STATUSES
            and not all(anchor in data[path] for path, anchor in (
                (VISIBILITY, "function brvtal_public_event_is_visible"),
                (VISIBILITY, "function brvtal_public_event_allows_ticketing"),
                (WORKFLOW, "function brvtal_event_workflow_event"),
                (LIFECYCLE, "function brvtal_event_lifecycle_patch"),
                (EVENT_CONTRACT, "brvtal_event_state_error")))):
        return result
    if identifier == "S12-R001":
        if not all(anchor in data[path] for path, anchor in (
            (MEMBERSHIP, "function brvtalArtistCollectiveNormalizePayload"),
            (API, "'artists' => ["),
            (ARTIST_CONTRACT, "brvtalArtistCollectiveMembershipValue"))):
            return result
        try:
            valid = all(observed["artist"][name] is True for name in (
                "member", "nonmember", "invalid_rejected", "canonical_over_legacy"))
        except (TypeError, KeyError, AttributeError):
            return result
    else:
        status = STATUSES[identifier]
        try:
            state = observed["states"][status]
            valid = all(state[name] is True for name in (
                "normalized", "accepted", "future_boundary_hidden",
                "past_visible", "past_historical", "invalid_status_rejected"))
            active = status in {"upcoming", "sold_out"}
            valid &= state["visible_future"] is active
            valid &= state["ticketing_future"] is active
            if status == "cancelled":
                valid &= observed["transitions"]["cancelled_at"] == "2026-10-28 12:00:00"
            if status == "finished":
                valid &= observed["transitions"]["finished_at"] == "2026-10-28 12:00:00"
        except (TypeError, KeyError, AttributeError):
            return result
    if valid:
        result["local"] = LOCAL
    # No local fixture proves end-to-end Artist CRUD, sales, deployed SHA or live.
    return result


class BrechasBrvtal13Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: five exact rows; real synthetic PHP status/membership behavior."""
        table = matrix_rows((ROOT / MATRIX).read_text(encoding="utf-8"))
        data, observed = sources(), probe()
        self.assertEqual(set(PHRASES), set(table))
        for identifier in PHRASES:
            with self.subTest(identifier=identifier):
                self.assertEqual(LOCAL, verdict(identifier, table, data, observed)["local"])

    def test_falla_cerrado(self) -> None:
        """AC-02: corrupted sources, evidence or duplicate rows cannot pass."""
        raw = (ROOT / MATRIX).read_text(encoding="utf-8")
        table, data, observed = matrix_rows(raw), sources(), probe()
        line = next(line for line in raw.splitlines()
                    if line.startswith("| S09-L122 · "))
        for changed in (raw + "\n" + line,
                        raw.replace(line, ""),
                        raw.replace(line, line.replace("NO_VERIFICABLE", "CUBIERTO")),
                        raw + "\n" + line.replace(" · ", "    · ", 1)):
            with self.assertRaisesRegex(ValueError, "UNKNOWN"):
                matrix_rows(changed)
        for identifier in PHRASES:
            with self.subTest(identifier=identifier):
                for bad in (None, {}, {"ci": "success"}):
                    self.assertEqual(UNKNOWN, verdict(identifier, bad, data, observed)["local"])
                    self.assertEqual(UNKNOWN, verdict(identifier, table, bad, observed)["local"])
                    self.assertEqual(UNKNOWN, verdict(identifier, table, data, bad)["local"])
                mutated = {**data, SPEC: data[SPEC].replace(PHRASES[identifier], "REMOVED", 1)}
                self.assertEqual(UNKNOWN, verdict(identifier, table, mutated, observed)["local"])
        tampered = {**observed, "states": {**observed["states"], "upcoming": {}}}
        self.assertEqual(UNKNOWN, verdict("S09-L122", table, data, tampered)["local"])
        self.assertEqual(UNKNOWN, verdict("S09-L999", table, data, observed)["local"])

    def test_sin_datos_reales(self) -> None:
        """AC-03: offline synthetic inputs, no credentials or external calls."""
        script = Path(__file__).read_text(encoding="utf-8")
        tree = ast.parse(script)
        imports = {alias.name.split(".")[0] for node in ast.walk(tree)
                   if isinstance(node, ast.Import) for alias in node.names}
        imports |= {node.module.split(".")[0] for node in ast.walk(tree)
                    if isinstance(node, ast.ImportFrom) and node.module}
        self.assertFalse(imports & {"socket", "requests", "urllib", "httpx", "pymysql"})
        for forbidden in ("curl_exec(", "PDO(", "file_put_contents(", "shell_exec("):
            self.assertNotIn(forbidden, PHP_PROBE)
        self.assertNotIn("access_token", json.dumps(probe()))
        self.assertEqual(5, len(PHRASES))

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: local evidence never becomes full-CRUD or live certainty."""
        table = matrix_rows((ROOT / MATRIX).read_text(encoding="utf-8"))
        data, observed = sources(), probe()
        for identifier in PHRASES:
            with self.subTest(identifier=identifier):
                item = verdict(identifier, table, data, observed)
                self.assertEqual(UNKNOWN, item["full_scope"])
                self.assertEqual(UNKNOWN, item["ci"])
                self.assertEqual(UNKNOWN, item["production"])
                self.assertEqual(UNKNOWN, verdict(identifier, table, data, {})["local"])
        self.assertTrue(observed["artist"]["invalid_rejected"])
        self.assertFalse(observed["states"]["cancelled"]["ticketing_future"])
        self.assertFalse(observed["states"]["finished"]["visible_future"])


if __name__ == "__main__":
    unittest.main()
