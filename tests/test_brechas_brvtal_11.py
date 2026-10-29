#!/usr/bin/env python3
"""BRVTAL #1101: synthetic, fail-closed evidence for §9 Events S09-L106..L110.

Real PHP lifecycle/archive/media/SEO functions are exercised in memory. Source
guards for the admin preview route are checked without session, DB, or HTTP.
Partial evidence is NEVER proof of complete CMS workflow or production.
"""
from __future__ import annotations

import ast
from functools import lru_cache
import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
MATRIX = "docs/matriz-cobertura.md"
SPEC = "docs/BRVTAL-SPEC.md"
EVENT = "config/event_lifecycle.php"
ARCHIVE = "api/public-archive.php"
PREVIEW = "config/public_preview.php"
PREVIEW_API = "api/public-preview.php"
MEDIA = "api/public-media-delivery.php"
SEO = "config/seo_workspace.php"
WORKFLOW_TEST = "tests/event-workflow-contract.php"
ARCHIVE_TEST = "tests/public-archive-contract.php"
PREVIEW_TEST = "tests/public-preview-contract.php"
MEDIA_TEST = "tests/media-library-contract.php"
SEO_TEST = "tests/seo-contract.php"
FILES = (MATRIX, SPEC, EVENT, ARCHIVE, PREVIEW, PREVIEW_API, MEDIA, SEO,
         WORKFLOW_TEST, ARCHIVE_TEST, PREVIEW_TEST, MEDIA_TEST, SEO_TEST)
IDS = ("S09-L106", "S09-L107", "S09-L108", "S09-L109", "S09-L110")
SUMMARIES = dict(zip(IDS, ("publish;", "archive;", "preview;", "media;", "SEO;")))
UNKNOWN = "UNKNOWN"
PARTIAL = "LOCAL_PARTIAL"
GAPS = {
    "S09-L106": "UNKNOWN: publication status, required fields and audit are not proven end-to-end",
    "S09-L107": "UNKNOWN: archive persistence, access control and public delivery not proven together",
    "S09-L108": "UNKNOWN: authenticated draft preview/session and token expiry not exercised",
    "S09-L109": "UNKNOWN: Media Engine references, permissions and variants not integrated",
    "S09-L110": "UNKNOWN: SEO editorial persistence, indexation and public delivery not integrated",
}
# Source guards are only candidate evidence. Runtime PHP checks supply a small
# positive and negative path for publication, archive, media and unknown SEO.
GUARDS = {
    "S09-L106": {
        EVENT: ("brvtal_event_lifecycle_patch", "brvtal_event_publish_at_error",
                "published_at", "EVENT_PUBLISH_AT_REQUIRES_PUBLIC_STATUS"),
        WORKFLOW_TEST: ("EVENT_DATE_REQUIRED", "EVENT_CITY_REQUIRED",
                        "EVENT_DESCRIPTION_REQUIRED"),
    },
    "S09-L107": {
        ARCHIVE: ("brvtal_public_partition_events", "brvtal_public_event_is_visible",
                  "ticket_url", "ticket_types"),
        ARCHIVE_TEST: ("brvtal_public_partition_events", "'archived'", "'draft'"),
    },
    "S09-L108": {
        PREVIEW: ("BRVTAL_PUBLIC_PREVIEW_TTL = 600", "INVALID_PREVIEW_URL",
                  "INVALID_PREVIEW_DATE", "brvtalPublicPreviewLoad",
                  "brvtalMediaImageReferenceState"),
        PREVIEW_API: ("brvtal_admin_require", "brvtal_admin_require_csrf"),
        PREVIEW_TEST: ("BRVTAL_PUBLIC_PREVIEW_TTL = 600",
                       "bin2hex(random_bytes(24))"),
    },
    "S09-L109": {
        MEDIA: ("brvtal_public_media_delivery_sanitize",
                "brvtal_public_media_delivery_safe_path", "image/webp"),
        MEDIA_TEST: ("brvtal_media_local_absolute", "path traversal"),
    },
    "S09-L110": {
        SEO: ("brvtalSeoWorkspaceContentHealth", "state'=>'unavailable'",
              "score'=>null", "effective_description"),
        SEO_TEST: ("brvtalSeoWorkspaceEntityDefinitions", "SEO_SCHEMA_MISSING"),
    },
}
SPEC_BOUNDS = ("- publish;", "- archive;", "- preview;", "- media;", "- SEO;")


def read_sources() -> dict[str, str]:
    """The tracked checkout only; no network/database or real user data."""
    return {name: (ROOT / name).read_text(encoding="utf-8") for name in FILES}


def coverage_rows(raw: object) -> dict[str, tuple[str, ...]]:
    """Require exactly five complete, unpromoted rows with exact §9 IDs."""
    if not isinstance(raw, str):
        raise ValueError("UNKNOWN: matrix missing")
    rows: dict[str, tuple[str, ...]] = {}
    for key in IDS:
        prefix = "| " + key + " · "
        hits = [s for s in raw.splitlines() if s.startswith(prefix)]
        if len(hits) != 1:
            raise ValueError("UNKNOWN: missing or duplicated " + key)
        cells = tuple(p.strip() for p in hits[0].strip("|").split("|"))
        expected = SUMMARIES[key]
        if (len(cells) != 5 or not cells[0].startswith(
                key + " · [S09 L" + key.rsplit("L", 1)[1] + "](")
                or "docs/BRVTAL-SPEC.md#L" not in cells[0]
                or cells[1] != expected or cells[2] != "NO_VERIFICABLE"
                or "Sin aserción contrastada para «" + expected + "»" not in cells[3]
                or "tests/event-workflow-contract.php" not in cells[3]
                or "falta enlazar prueba" not in cells[3] or cells[4] != "—"):
            raise ValueError("UNKNOWN: malformed or falsely promoted " + key)
        rows[key] = cells
    return rows


def check_partial(key: object, rows: object, data: object, proof: object) -> str:
    """Partial local contract only; absent/corrupt inputs never pass silently."""
    if key not in IDS or not isinstance(rows, dict) or not isinstance(data, dict):
        return UNKNOWN
    if (set(rows) != set(IDS) or set(data) != set(FILES)
            or any(not isinstance(data[p], str) or not data[p] for p in FILES)):
        return UNKNOWN
    try:
        if coverage_rows(data[MATRIX]) != rows:
            return UNKNOWN
    except ValueError:
        return UNKNOWN
    if any(term not in data[SPEC] for term in SPEC_BOUNDS):
        return UNKNOWN
    for path, required in GUARDS[key].items():
        if any(fragment not in data[path] for fragment in required):
            return UNKNOWN
    if not isinstance(proof, dict):
        return UNKNOWN
    try:
        lifecycle = proof["publish"]
        archive = proof["archive"]
        media = proof["media"]
        seo = proof["seo"]
        expected = {
            "S09-L106": (lifecycle["published_at_owner"] is True
                         and lifecycle["draft_schedule_denied"] is True),
            "S09-L107": (archive["active"] == [2] and archive["archive"] == [3]
                         and archive["no_draft_leak"] is True
                         and archive["no_historical_tickets"] is True),
            "S09-L108": proof["preview_source_only"] is True,
            "S09-L109": (media["safe"] is True and media["reject_traversal"] is True
                         and media["reject_empty"] is True),
            "S09-L110": (seo["unknown"] == "unavailable" and seo["score"] is None),
        }[key]
    except (KeyError, TypeError, IndexError):
        return UNKNOWN
    return PARTIAL if expected else UNKNOWN


def operational(_key: object, _proof: object = None) -> str:
    """Never infer a live event-management feature from synthetic fixtures."""
    return UNKNOWN


# PHP -r executes real pure-domain functions on synthetic values. It makes no
# HTTP request and neither opens a DB connection nor writes a file.
PHP_PROBE = r'''
require_once 'config/event_lifecycle.php';
require_once 'api/public-archive.php';
require_once 'api/public-media-delivery.php';
require_once 'config/seo_workspace.php';
$now = new DateTimeImmutable('2026-10-28 12:00:00');
$publish = brvtal_event_lifecycle_patch(
  [], ['status'=>'published','published_at'=>'2020-01-01 00:00:00'], $now
);
$invalid = brvtal_event_publish_at_error(
  [], ['status'=>'draft','publish_at'=>'2026-11-01 12:00:00'], $now
);
$events = [
  ['id'=>1,'status'=>'draft','event_date'=>'2026-10-20 20:00:00'],
  ['id'=>2,'status'=>'published','event_date'=>'2026-10-30 20:00:00',
    'published_at'=>'2026-10-01 12:00:00'],
  ['id'=>3,'status'=>'archived','event_date'=>'2026-09-01 20:00:00',
    'published_at'=>'2026-08-01 12:00:00','ticket_url'=>'https://example.test/',
    'ticket_instructions'=>'mock','ticket_qr'=>'/synthetic.png',
    'ticket_types'=>[['id'=>7]]],
];
$partition = brvtal_public_partition_events($events, $now);
$card = brvtal_public_media_delivery_sanitize([
  'original'=>['path'=>'/uploads/synthetic/original.png','width'=>1200,'height'=>800],
  'variants'=>['card'=>['path'=>'/uploads/synthetic/card.webp',
     'width'=>800,'height'=>600,'mime_type'=>'image/webp']],
]);
$bad = brvtal_public_media_delivery_sanitize([
  'original'=>['path'=>'/uploads/../private.png','width'=>800,'height'=>600],
  'variants'=>['card'=>['path'=>'/uploads/synthetic/card.webp',
     'width'=>400,'height'=>300,'mime_type'=>'image/webp']],
]);
$unknown = brvtalSeoWorkspaceContentHealth([]);
echo json_encode([
  'publish'=>[
    'published_at_owner'=>(($publish['published_at']??null)==='2026-10-28 12:00:00'),
    'draft_schedule_denied'=>(($invalid['error']??null)==='EVENT_PUBLISH_AT_REQUIRES_PUBLIC_STATUS')
  ],
  'archive'=>[
    'active'=>array_column($partition['active'],'id'),
    'archive'=>array_column($partition['archive'],'id'),
    'no_draft_leak'=>count($partition['active'])+count($partition['archive'])===2,
    'no_historical_tickets'=>($partition['archive'][0]['ticket_url']??'fallback')===null
      && ($partition['archive'][0]['ticket_types']??null)===[]
  ],
  'media'=>[
    'safe'=>($card['variants']['card']['src']??null)==='/uploads/synthetic/card.webp',
    'reject_traversal'=>$bad===null,
    'reject_empty'=>brvtal_public_media_delivery_sanitize([])===null
  ],
  'seo'=>['unknown'=>$unknown['state']??null,'score'=>$unknown['score']??'not-null'],
  'preview_source_only'=>true
], JSON_THROW_ON_ERROR);
'''


@lru_cache(maxsize=1)
def php_probe() -> dict:
    php = shutil.which("php")
    if not php:
        raise AssertionError("UNKNOWN: PHP CLI unavailable")
    process = subprocess.run(
        [php, "-n", "-d", "allow_url_fopen=0", "-r", PHP_PROBE],
        cwd=ROOT, capture_output=True, text=True, check=False, timeout=20,
    )
    if process.returncode != 0 or process.stderr.strip():
        raise AssertionError("UNKNOWN: PHP probe failed: " + process.stderr[-450:])
    try:
        result = json.loads(process.stdout)
    except ValueError as exc:
        raise AssertionError("UNKNOWN: PHP output not valid JSON") from exc
    if not isinstance(result, dict):
        raise AssertionError("UNKNOWN: invalid PHP result")
    return result


class BrechasBrvtal11Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: every S09 row has an exercised behavior or explicit gap."""
        data = read_sources()
        rows = coverage_rows(data[MATRIX])
        proof = php_probe()
        self.assertEqual(set(IDS), set(rows))
        self.assertEqual(set(IDS), set(GUARDS))
        self.assertEqual(set(IDS), set(GAPS))
        for key in IDS:
            with self.subTest(key=key):
                self.assertEqual(PARTIAL, check_partial(key, rows, data, proof))
                self.assertTrue(GAPS[key].startswith("UNKNOWN:"))
                self.assertEqual(UNKNOWN, operational(key, proof))

    def test_falla_cerrado(self) -> None:
        """AC-02: missing, duplicated, forged or broken inputs remain UNKNOWN."""
        data = read_sources()
        rows = coverage_rows(data[MATRIX])
        proof = php_probe()
        self.assertEqual(UNKNOWN, check_partial("S09-L999", rows, data, proof))
        self.assertEqual(UNKNOWN, check_partial(IDS[0], {}, data, proof))
        self.assertEqual(UNKNOWN, check_partial(IDS[0], rows, {}, proof))
        for key in IDS:
            row = next(x for x in data[MATRIX].splitlines()
                       if x.startswith("| " + key + " · "))
            mutants = (
                data[MATRIX].replace(row, "", 1),
                data[MATRIX] + "\n" + row,
                data[MATRIX].replace(row, row.replace("NO_VERIFICABLE", "CUBIERTO"), 1),
            )
            for mutation in mutants:
                with self.subTest(key=key):
                    with self.assertRaisesRegex(ValueError, UNKNOWN):
                        coverage_rows(mutation)
            for path in GUARDS[key]:
                damaged = dict(data)
                damaged[path] = ""
                self.assertEqual(UNKNOWN, check_partial(key, rows, damaged, proof))
            self.assertEqual(UNKNOWN, check_partial(key, rows, data, {}))
        forged = dict(proof)
        forged["media"] = dict(proof["media"], reject_traversal=False)
        self.assertEqual(UNKNOWN, check_partial("S09-L109", rows, data, forged))

    def test_sin_datos_reales(self) -> None:
        """AC-03: no secrets, PII, network requests, SQL or filesystem writes."""
        script = (ROOT / "tests/test_brechas_brvtal_11.py").read_text(encoding="utf-8")
        tree = ast.parse(script)
        imports = {a.name.split(".")[0] for node in ast.walk(tree)
                   if isinstance(node, ast.Import) for a in node.names}
        imports |= {node.module.split(".")[0] for node in ast.walk(tree)
                    if isinstance(node, ast.ImportFrom) and node.module}
        self.assertFalse(imports & {"socket", "urllib", "requests", "httpx", "pymysql"})
        for forbidden in ("curl_", "PDO(", "file_put_contents(", "INSERT INTO ",
                          "http://", "file_get_contents(", "DB_PASSWORD", "APP_SECRET"):
            self.assertNotIn(forbidden, PHP_PROBE)
        self.assertEqual(set(FILES), set(read_sources()))
        self.assertTrue(php_probe()["media"]["reject_traversal"])

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04 (test): local proofs cannot certify live/CI/production."""
        data, proof = read_sources(), php_probe()
        rows = coverage_rows(data[MATRIX])
        for key in IDS:
            self.assertEqual(PARTIAL, check_partial(key, rows, data, proof))
            for fake in (None, {}, {"ci": "success"}, {"merged": True},
                         {"production": "GREEN"}, {"approved": True},
                         {"state": "VALIDATED_IN_PRODUCTION"}):
                with self.subTest(key=key, fake=str(fake)):
                    self.assertEqual(UNKNOWN, operational(key, fake))
        self.assertIsNone(proof["seo"]["score"])


if __name__ == "__main__":
    unittest.main()
