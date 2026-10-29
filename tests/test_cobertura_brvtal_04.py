"""#1273: exercise actual offline BRVTAL behavior for S09–S15 SPEC rows.

Only code-backed observations are reported. No matrix edits, browser, DB, HTTP,
external payments, credentials or production attestation take place here.
"""
from __future__ import annotations

from functools import lru_cache
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
IDS = (
    "S09-L122", "S09-L125", "S09-L126", "S09-L127",
    "S12-R001", "S12-R002", "S12-R003", "S12-R005", "S12-R006",
    "S12-R007", "S13-L180", "S13-L181", "S13-R001", "S13-R002",
    "S13-R003", "S13-R004", "S14-R001", "S14-R003",
    "S15-R001", "S15-R002",
)
# A = actual executable behavior (not necessarily an end-to-end guarantee).
# B = requires real database/browser/provider evidence unavailable offline.
# C = editorial/design/organizational statement, not an executable action.
OFFLINE = frozenset((
    "S09-L122", "S09-L125", "S09-L126", "S09-L127",
    "S12-R005", "S12-R007", "S13-R002", "S13-R003", "S15-R002",
))
EXTERNAL = {
    "S12-R001": "full authenticated Artist CRUD/profile requires a real database",
    "S12-R002": "optional fields throughout the complete Artist editor need integration tests",
    "S12-R003": "anonymous-image fallback must be observed in rendered browser DOM",
    "S12-R006": "audit-history preservation and writes need transactional database evidence",
    "S13-R004": "Set–Artist–Event persisted relationships require integration evidence",
    "S15-R001": "absence of internal payment processing needs end-to-end system inspection",
}
DECLARATIVE = {
    "S13-L180": "Sets is an editorial list item, not a function",
    "S13-L181": "Releases is an editorial list item, not a function",
    "S13-R001": "music architecture introduction is a documentation heading",
    "S14-R001": "evolution into a label is product direction, not runtime behavior",
    "S14-R003": "separating Releases from Sets and pages is architecture policy",
}
ABSENT: dict[str, str] = {}

PHP_PROBE = r"""
declare(strict_types=1);
require_once 'config/public_visibility.php';
require_once 'config/artist_collective_membership.php';
require_once 'config/set_publication.php';
require_once 'api/event-workflow-lib.php';

$mode = $argv[1] ?? '';
$verified = [];
$check = static function (bool $ok, string $context): void {
    if (!$ok) { fwrite(STDERR, 'FAIL: ' . $context); exit(1); }
};
$record = static function (string $id) use (&$verified): void { $verified[] = $id; };
$clock = new DateTimeImmutable('2026-10-01 12:00:00 UTC');

if ($mode === 'principal') {
    $groups = brvtal_public_event_statuses();
    $statusRows = [
        'S09-L122' => ['upcoming', 'active'],
        'S09-L125' => ['sold_out', 'active'],
        'S09-L126' => ['cancelled', 'historical'],
        'S09-L127' => ['finished', 'historical'],
    ];
    foreach ($statusRows as $row => [$status, $group]) {
        $normalized = brvtal_event_workflow_event(['title'=>'Fixture','status'=>$status]);
        $check(($normalized['status'] ?? null) === $status, $row . ': workflow');
        $check(in_array($status, $groups[$group], true), $row . ': lifecycle');
        $event = ['status'=>$status, 'event_date'=>'2026-09-01 21:00:00'];
        $check(brvtal_public_event_is_visible($event, $clock), $row . ': public');
        $record($row);
    }

    // S12-R005: persisted canonical checkbox wins over stale lifecycle fields.
    $row = ['is_collective_member'=>0, 'collective_status'=>'active'];
    $check(!brvtalArtistCollectiveMembershipValue($row), 'legacy authority');
    $normalized = brvtalArtistCollectiveNormalizePayload(['is_collective_member'=>true]);
    $check($normalized['error'] === null
        && $normalized['payload']['is_collective_member'] === 1,
        'canonical boolean normalizes');
    $record('S12-R005');

    // S12-R007: migration bridge maps active, but never alumni/none, to member.
    foreach (['active'=>true, 'alumni'=>false, 'none'=>false] as $legacy=>$expected) {
        $check(brvtalArtistCollectiveMembershipValue(
            ['collective_status'=>$legacy]) === $expected, 'legacy ' . $legacy);
    }
    $record('S12-R007');

    // S13-R002: a real published Set policy accepts HTTPS platform URLs.
    $spotify = ['status'=>'published',
                'external_url'=>'https://open.spotify.com/episode/fixture'];
    $check(brvtal_set_publication_error($spotify) === null, 'spotify URL');
    $record('S13-R002');

    // S13-R003: SoundCloud is NOT the only acceptable listening host.
    foreach ([
        'https://soundcloud.com/example/fixture',
        'https://www.youtube.com/watch?v=fixture',
        'https://bandcamp.com/track/fixture',
    ] as $url) {
        $check(brvtal_set_publication_error(
            ['status'=>'published','external_url'=>$url]) === null,
            'multi-platform URL');
    }
    $record('S13-R003');

    // S15-R002: real ticket workflow retains *external* purchase instructions
    // and a QR reference; no charge, gateway or real checkout is exercised.
    $ticket = brvtal_event_workflow_ticket([
        'name'=>'Synthetic Ticket', 'external_url'=>'https://example.invalid/ticket',
        'payment_instructions'=>'External transfer only',
        'qr_image'=>'/media/synthetic-qr.png', 'currency'=>'cop',
        'price'=>'20000', 'status'=>'active',
    ]);
    $check(($ticket['external_url'] ?? '') === 'https://example.invalid/ticket',
        'ticket external URL');
    $check(($ticket['payment_instructions'] ?? '') === 'External transfer only',
        'ticket payment instructions');
    $check(($ticket['qr_image'] ?? '') === '/media/synthetic-qr.png',
        'ticket QR');
    $check(($ticket['currency'] ?? '') === 'COP', 'ticket currency');
    $record('S15-R002');
} elseif ($mode === 'negative') {
    foreach ([
        ['status'=>'published','external_url'=>''],
        ['status'=>'published','external_url'=>'ftp://example.invalid/audio'],
        ['status'=>'published','external_url'=>'not a URL'],
    ] as $set) {
        $check(brvtal_set_publication_error($set) !== null, 'reject invalid Set');
    }
    $bad = brvtalArtistCollectiveNormalizePayload(['is_collective_member'=>'active']);
    $check(($bad['error']['error'] ?? '') === 'INVALID_COLLECTIVE_MEMBERSHIP',
        'reject legacy status as writable boolean');
    $check(!brvtal_public_event_is_visible(
        ['status'=>'cancelled','event_date'=>'2026-12-01'], $clock),
        'never publish future cancelled draft');
    foreach ([
        ['name'=>'Bad URL','external_url'=>'javascript:alert(1)'],
        ['name'=>'Bad status','status'=>'paid_internally'],
    ] as $payload) {
        $rejected = false;
        try { brvtal_event_workflow_ticket($payload); }
        catch (InvalidArgumentException) { $rejected = true; }
        $check($rejected, 'reject unsupported payment or URL');
    }
    $record('DENIED');
} else {
    fwrite(STDERR, 'Invalid offline mode');
    exit(1);
}
echo json_encode(['verified'=>$verified,'production'=>'UNKNOWN',
                  'database'=>'disabled','network'=>'disabled'], JSON_THROW_ON_ERROR);
"""


def matrix_rows(text: object) -> dict[str, tuple[str, ...]]:
    if type(text) is not str or not text:
        raise ValueError("UNKNOWN: missing matrix")
    found: dict[str, tuple[str, ...]] = {}
    for line in text.splitlines():
        match = re.match(r"^\| (S\d{2}-[LR]\d{3}) · ", line)
        if match is None or match.group(1) not in IDS:
            continue
        key = match.group(1)
        if key in found:
            raise ValueError("UNKNOWN: duplicate " + key)
        cells = tuple(x.strip() for x in line.strip("|").split("|"))
        if len(cells) != 5 or not cells[0].startswith(key + " · ") or (
            cells[2] != "NO_VERIFICABLE" or not cells[1] or not cells[3]
        ):
            raise ValueError("UNKNOWN: untrusted coverage " + key)
        found[key] = cells
    if set(found) != set(IDS):
        raise ValueError("UNKNOWN: missing matrix rows")
    return found


@lru_cache(maxsize=2)
def php_case(mode: str) -> dict:
    if mode not in ("principal", "negative"):
        raise ValueError("unsupported mode")
    php = shutil.which("php")
    if not php:
        raise AssertionError("UNKNOWN: PHP not available")
    env = {key: os.environ[key] for key in ("PATH", "LANG", "LC_ALL")
           if key in os.environ}
    completed = subprocess.run(
        [php, "-r", PHP_PROBE, mode],
        cwd=ROOT, env=env, capture_output=True, text=True, timeout=35,
        check=False,
    )
    if completed.returncode:
        raise AssertionError("UNKNOWN: actual PHP behavior: "
                             + completed.stderr[-950:])
    try:
        data = json.loads(completed.stdout)
    except ValueError as exc:
        raise AssertionError("UNKNOWN: invalid PHP evidence") from exc
    if type(data) is not dict or set(data) != {
        "verified", "production", "database", "network"
    } or data["production"] != "UNKNOWN" or (
        data["database"] != "disabled" or data["network"] != "disabled"
    ):
        raise AssertionError("UNKNOWN: incomplete PHP evidence")
    return data


class CoberturaBrvtal04Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        self.assertEqual(len(IDS), 20)
        self.assertEqual(set(IDS), OFFLINE | EXTERNAL.keys() | DECLARATIVE.keys())
        self.assertFalse(ABSENT)
        self.assertTrue(all(EXTERNAL.values()) and all(DECLARATIVE.values()))
        matrix_rows(MATRIX.read_text(encoding="utf-8"))
        self.assertEqual(set(php_case("principal")["verified"]), OFFLINE)

    def test_falla_cerrado(self) -> None:
        self.assertEqual(php_case("negative")["verified"], ["DENIED"])
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            matrix_rows("")
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            matrix_rows("| S09-L122 · x | upcoming; | NO_VERIFICABLE | reason | ref |\n"
                        "| S09-L122 · x | upcoming; | NO_VERIFICABLE | reason | ref |")
        with self.assertRaises(ValueError):
            php_case("production")

    def test_sin_datos_reales(self) -> None:
        self.assertNotIn("password", PHP_PROBE.lower())
        self.assertNotIn("api_key", PHP_PROBE.lower())
        self.assertNotIn("curl_", PHP_PROBE.lower())
        self.assertNotRegex(PHP_PROBE, r"https?://(?:api|www)\.brvtal\.")
        self.assertNotRegex(PHP_PROBE, r"[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}")
        evidence = php_case("principal")
        self.assertEqual((evidence["database"], evidence["network"]),
                         ("disabled", "disabled"))

    def test_ci_contract(self) -> None:
        # Workflow shape is not a replacement for an exact-HEAD GitHub check.
        workflow = (ROOT / ".github/workflows/update-release-metadata.yml").read_text(
            encoding="utf-8"
        )
        self.assertIn("name: BRVTAL CI", workflow)
        self.assertIn("    name: fast\n", workflow)
        self.assertIn("    name: validate\n", workflow)
        self.assertIn("    name: coordination\n", workflow)
        self.assertIn("python3 -m unittest", workflow)


if __name__ == "__main__":
    unittest.main()
