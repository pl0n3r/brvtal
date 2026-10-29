#!/usr/bin/env python3
"""#1276: real PHP behavior for public draft and related entities, offline only.

Browser/production-only and editorial rows stay non-accredited; no live data,
network, credentials, mutations to public records or database connections.
"""
from __future__ import annotations

from functools import lru_cache
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
IDS = frozenset((
    "S20-R017", "S20-R018", "S20-R019", "S20-R020", "S20-R021",
    "S20-R022", "S21-R002", "S21-R003", "S21-R004", "S21-R005",
    "S21-R007", "S24-L368", "S24-L375", "S24-L376", "S24-L377",
    "S24-L378", "S24-L379", "S24-L380", "S24-L381", "S24-L382",
))
OFFLINE = frozenset((
    "S20-R020", "S20-R021", "S21-R002", "S21-R003",
    "S21-R004", "S21-R005", "S21-R007",
))
EXTERNAL = {
    "S20-R017": "same production/preview HTTP delivery requires end-to-end routing evidence",
    "S20-R018": "source substitution without duplicated layout requires full HTTP preview evidence",
    "S20-R019": "1440/390 composition requires actual rendered browser viewport evidence",
    "S24-L368": "persistent shared authenticated admin shell needs browser navigation evidence",
    "S24-L375": "Dashboard navigation needs interactive authenticated browser evidence",
    "S24-L376": "Events navigation needs interactive authenticated browser evidence",
    "S24-L377": "Artists navigation needs interactive authenticated browser evidence",
    "S24-L378": "Releases navigation needs interactive authenticated browser evidence",
    "S24-L379": "Sets navigation needs interactive authenticated browser evidence",
    "S24-L380": "Media navigation needs interactive authenticated browser evidence",
    "S24-L381": "Pages navigation needs interactive authenticated browser evidence",
    "S24-L382": "Blog navigation needs interactive authenticated browser evidence",
}
DECLARATIVE = {
    "S20-R022": "editorial distinction between a Blog editing aid and canonical preview",
}
# Only a disposable session name/timezone; no DB, hosting or provider config.
CONFIG = "<?php return ['app'=>['timezone'=>'UTC'], 'security'=>['session_name'=>'BRVTAL_SYNTHETIC']];"

PHP = r"""
declare(strict_types=1);
define('BRVTAL_SENTRY_TESTING', true);
$GLOBALS['brvtalBootstrapConfigPath'] = $argv[2];
require_once 'config/public_preview.php';
require_once 'api/public-related.php';
$mode = $argv[1];
$verified = [];
$check = static function (bool $ok, string $id): void {
    if (!$ok) throw new RuntimeException('REAL_BEHAVIOR_FAILED:' . $id);
};
$record = static function (string $id) use (&$verified): void { $verified[] = $id; };
if ($mode === 'principal') {
    $preview = brvtalPublicPreviewSnapshot('events', [
        'title'=>'SYNTHETIC EVENT', 'ticket_url'=>'https://fixture.invalid/tickets',
        'accent'=>'#FF2038', 'event_date'=>'2026-10-29 21:00',
        'lineup'=>[['artist_id'=>1],['artist_id'=>0]],
        'unapproved_internal_field'=>'PRIVATE_FIXTURE',
    ]);
    $data = $preview['payload'];
    $check($data['ticket_url'] === 'https://fixture.invalid/tickets', 'S20-R020 url');
    $check($data['accent'] === '#FF2038', 'S20-R020 color');
    $check($data['event_date'] === '2026-10-29 21:00:00', 'S20-R020 date');
    $check(count($data['lineup']) === 1, 'S20-R020 lineup');
    $record('S20-R020');
    $check($preview['type'] === 'events', 'S20-R021 type');
    $check(!array_key_exists('unapproved_internal_field', $data), 'S20-R021 allowlist');
    $record('S20-R021');

    $artists = [['id'=>1,'name'=>'PUBLIC ARTIST','slug'=>'public-artist']];
    $events = [['id'=>11,'title'=>'PUBLIC EVENT','slug'=>'public-event',
        'lineup'=>[['artist_id'=>1],['artist_id'=>999]]]];
    $sets = brvtal_public_sanitize_set_relations([
        ['id'=>21,'title'=>'PUBLIC SET','artist_id'=>1,'event_id'=>11],
        ['id'=>22,'title'=>'PRIVATE_LINK_FIXTURE','artist_id'=>999,
         'artist_name'=>'PRIVATE_ARTIST','event_id'=>999,'event_title'=>'PRIVATE_EVENT'],
    ],$artists,$events,[]);
    $releases = [['id'=>31,'title'=>'PUBLIC RELEASE',
        'artists'=>[['artist_id'=>1],['artist_id'=>999]]]];
    $graph = brvtal_public_related_graph($events,[],$artists,$sets,$releases);
    $check($graph['artists']['1']['events'] === [11], 'S21-R002 events');
    $check($graph['artists']['1']['sets'] === [21], 'S21-R002 sets');
    $check($graph['artists']['1']['releases'] === [31], 'S21-R002 releases');
    $record('S21-R002');
    $check($graph['events']['11']['artists'] === [1], 'S21-R003 artists');
    $check($graph['events']['11']['sets'] === [21], 'S21-R003 sets');
    $record('S21-R003');
    $check($graph['sets']['21']['artist'] === 1, 'S21-R004 artist');
    $check($graph['sets']['21']['event'] === 11, 'S21-R004 event');
    $record('S21-R004');
    $check($graph['releases']['31']['artists'] === [1], 'S21-R005 artists');
    $record('S21-R005');
    $check($sets[1]['artist_id'] === null && $sets[1]['artist_name'] === null,
           'S21-R007 private artist');
    $check($sets[1]['event_id'] === null && $sets[1]['event_title'] === null,
           'S21-R007 private event');
    $check($graph['sets']['22'] === ['artist'=>null,'event'=>null],
           'S21-R007 private graph');
    $check(!in_array(999,$graph['events']['11']['artists'],true),
           'S21-R007 private lineup');
    $record('S21-R007');
} elseif ($mode === 'negative') {
    foreach ([
        ['events',['ticket_url'=>'javascript:alert(1)'],'INVALID_PREVIEW_URL'],
        ['events',['ticket_url'=>'https://user:pass'.chr(64).'fixture.invalid/a'],'INVALID_PREVIEW_URL'],
        ['events',['accent'=>'red'],'INVALID_PREVIEW_ACCENT'],
        ['events',['event_date'=>'2026-02-30 21:00'],'INVALID_PREVIEW_DATE'],
        ['invalid_type',['title'=>'X'],'PREVIEW_TYPE_NOT_ALLOWED'],
        ['pages',['content_json'=>'{broken'],'INVALID_PREVIEW_CONTENT_JSON'],
    ] as [$type,$payload,$expected]) {
        $denied = false;
        try { brvtalPublicPreviewSnapshot($type,$payload); }
        catch (InvalidArgumentException $e) {
            $denied = $e->getMessage() === $expected;
        }
        $check($denied,'reject ' . $expected . ':' . $type);
    }
    $token = str_repeat('a',48);
    $check(brvtalPublicPreviewLoad('malformed-token') === null,'malformed token');
    $check(brvtalPublicPreviewLoad($token) === null,'foreign session token');
    $_SESSION['public_previews'][$token] = [
       'expires_at'=>time()-10,'snapshot'=>['type'=>'events','payload'=>['title'=>'EXPIRED']]
    ];
    $check(brvtalPublicPreviewLoad($token) === null,'expired token');
    $check(!isset($_SESSION['public_previews'][$token]),'expired snapshot removed');
    $check(brvtalPublicPreviewList('lineup', [
      ['artist_id'=>0],['artist_id'=>-1],['artist_id'=>1],['artist_id'=>'bad']
    ]) === [['artist_id'=>1,'role'=>'','sort_order'=>2]], 'invalid artist IDs');
    $public = [['id'=>11,'title'=>'PUBLIC EVENT']];
    $artists = [['id'=>1,'name'=>'PUBLIC ARTIST']];
    $sets = brvtal_public_sanitize_set_relations([[
      'id'=>22,'artist_id'=>999,'artist_name'=>'PRIVATE',
      'event_id'=>999,'event_title'=>'PRIVATE'
    ]],$artists,$public,[]);
    $graph = brvtal_public_related_graph($public,[],$artists,$sets,
        [['id'=>31,'artists'=>[['artist_id'=>999]]]]);
    $check($graph['sets']['22'] === ['artist'=>null,'event'=>null], 'private set');
    $check($graph['releases']['31']['artists'] === [], 'private release');
    $posts = brvtal_public_sanitize_blog_relations([[
      'relations'=>[['related_type'=>'artist','related_id'=>999],
        ['related_type'=>'event','related_id'=>11],
        ['related_type'=>'unsupported','related_id'=>1],
        ['related_type'=>'artist','related_id'=>0]]
    ]],$public,[],$artists,$sets,[]);
    $check(count($posts[0]['relations']) === 1 &&
      $posts[0]['relations'][0]['related_id'] === 11,'private blog relation');
    $record('DENIED');
} else {
    throw new InvalidArgumentException('Unsupported mode');
}
echo json_encode(['verified'=>$verified,'database'=>'disabled',
                  'network'=>'disabled','production'=>'UNKNOWN'],JSON_THROW_ON_ERROR);
"""

def matrix_rows(source: str) -> dict[str, tuple[str, ...]]:
    found: dict[str, tuple[str, ...]] = {}
    for line in source.splitlines():
        match = re.match(r"^\| (S\d{2}-[LR]\d{3}) · ", line)
        if not match or match.group(1) not in IDS:
            continue
        key = match.group(1)
        if key in found:
            raise ValueError("UNKNOWN duplicate matrix row " + key)
        columns = tuple(value.strip() for value in line.strip("|").split("|"))
        if len(columns) != 5 or not columns[1] or columns[2] != "NO_VERIFICABLE":
            raise ValueError("UNKNOWN malformed matrix row " + key)
        found[key] = columns
    if frozenset(found) != IDS:
        raise ValueError("UNKNOWN missing matrix rows")
    return found

@lru_cache(maxsize=2)
def php_case(mode: str) -> dict:
    if mode not in ("principal", "negative"):
        raise ValueError("Unsupported acceptance mode")
    php = shutil.which("php")
    if not php:
        raise AssertionError("UNKNOWN: PHP unavailable")
    env = {key: os.environ[key] for key in ("PATH","LANG","LC_ALL") if key in os.environ}
    with tempfile.TemporaryDirectory(prefix="brvtal-synthetic-") as directory:
        config = Path(directory) / "config.php"
        config.write_text(CONFIG, encoding="utf-8")
        run = subprocess.run([php,"-r",PHP,mode,str(config)], cwd=ROOT, env=env,
                             capture_output=True,text=True,timeout=35,check=False)
    if run.returncode or run.stderr.strip():
        raise AssertionError("UNKNOWN PHP regression: " + (run.stderr + run.stdout)[-1400:])
    try:
        data = json.loads(run.stdout)
    except ValueError as exc:
        raise AssertionError("UNKNOWN: PHP non-JSON evidence") from exc
    if not isinstance(data,dict) or any(data.get(k) != v for k,v in (
        ("database","disabled"),("network","disabled"),("production","UNKNOWN")
    )):
        raise AssertionError("UNKNOWN: unsafe or incomplete PHP evidence")
    return data

class CoberturaBrvtal07Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        self.assertEqual(len(IDS),20)
        self.assertEqual(IDS,OFFLINE | EXTERNAL.keys() | DECLARATIVE.keys())
        self.assertTrue(all(EXTERNAL.values()) and all(DECLARATIVE.values()))
        matrix_rows((ROOT/"docs/matriz-cobertura.md").read_text(encoding="utf-8"))
        self.assertEqual(set(php_case("principal")["verified"]),OFFLINE)

    def test_falla_cerrado(self) -> None:
        self.assertEqual(php_case("negative")["verified"],["DENIED"])
        with self.assertRaisesRegex(ValueError,"UNKNOWN"):
            matrix_rows("")
        with self.assertRaisesRegex(ValueError,"UNKNOWN"):
            matrix_rows("| S21-R002 · x | x | NO_VERIFICABLE | foo | bar |\n"
                        "| S21-R002 · x | x | NO_VERIFICABLE | foo | bar |")
        with self.assertRaises(ValueError):
            php_case("unsupported")

    def test_sin_datos_reales(self) -> None:
        self.assertNotIn("curl_",PHP)
        self.assertNotIn("file_get_contents(",PHP)
        self.assertNotRegex(PHP,r"[\w.+-]+@[\w.-]+\.[A-Za-z]{2,}")
        self.assertNotRegex(PHP,r"https?://(?:api|www)\.brvtal\.")
        self.assertNotIn("REPLACE_DB_PASSWORD",CONFIG)
        evidence=php_case("principal")
        self.assertEqual((evidence["database"],evidence["network"]),
                         ("disabled","disabled"))

    def test_ci_contract(self) -> None:
        # This is a workflow shape check, NOT exact-HEAD CI evidence.
        workflow=(ROOT/".github/workflows/update-release-metadata.yml").read_text(encoding="utf-8")
        self.assertIn("name: BRVTAL CI",workflow)
        self.assertIn("    name: validate\n",workflow)
        self.assertIn("    name: fast\n",workflow)

if __name__=="__main__":
    unittest.main()
