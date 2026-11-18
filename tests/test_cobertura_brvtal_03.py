#!/usr/bin/env python3
"""BRVTAL #1272: real offline PHP regression for 20 public/event spec rows.

No live requests, database writes, credentials, real accounts or fabricated
operational GREEN. The B/C classifications are not accreditation.
"""
from __future__ import annotations

import json
from pathlib import Path
import re
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
IDS = {
    "S07-R003", "S07-R005", "S07-R006", "S07-R007", "S07-R008",
    "S07-R009", "S07-R010", "S07-R011", "S08-R001",
    "S09-L105", "S09-L106", "S09-L107", "S09-L108",
    "S09-L109", "S09-L110", "S09-L111", "S09-L112",
    "S09-L119", "S09-L120", "S09-L121",
}
NON_OFFLINE = {
    "S07-R003": ("B", "complete Media/Archive route and real catalog display need browser/source evidence; the historical policy probe is partial"),
    "S07-R010": ("C", "architectural extensibility is a design rule, not an isolated runtime result"),
    "S08-R001": ("C", "art-directed composition requires human qualitative visual assessment"),
    "S09-L108": ("B", "authenticated preview needs an admin session and browser delivery"),
    "S09-L111": ("B", "statistics need authenticated analytics and persisted data"),
}
OFFLINE = IDS - NON_OFFLINE.keys()

# This executes committed PHP domain functions rather than a Python imitation.
PHP = r"""
require_once 'config/public_routes.php';
require_once 'config/public_seo.php';
require_once 'api/pages-contract.php';
require_once 'api/event-workflow-lib.php';
$base='https://www.brvtal.com.co';
$now=new DateTimeImmutable('2026-10-29 12:00:00+00:00');
$routes=brvtal_public_content_definitions(static fn(string $key): ?string => null);
$seo=[];
foreach (['artists','sets','releases','blog','pages'] as $type) {
  $record=['id'=>7,'slug'=>'synthetic-example','title'=>'Synthetic Example',
    'description'=>'Synthetic public text','image'=>'',
    'external_url'=>'https://example.invalid/set',
    'release_date'=>'2026-10-01',
    'published_at'=>'2026-10-01 12:00:00',
    'schema_type'=>$routes[$type]['schema_type'],'route_type'=>$type,
    'status'=>'published','locale'=>'en'];
  $recordSeo=brvtal_public_seo_document($record,$base);
  $tags=brvtal_public_seo_tags($recordSeo);
  $seo[$type]=[
    'canonical'=>$recordSeo['canonical'],'type'=>$recordSeo['schema']['@type'],
    'canonical_tag'=>str_contains($tags,'<link rel="canonical" href="'.$recordSeo['canonical'].'">'),
    'jsonld'=>str_contains($tags,'application/ld+json'),
  ];
}
$states=[];
foreach (['draft','published','archived','bad-status'] as $state) {
  try {
    $states[$state]=brvtal_event_workflow_event(['title'=>'Synthetic','status'=>$state])['status'];
  } catch (InvalidArgumentException $e) { $states[$state]=$e->getMessage(); }
}
$visibility=[
 'draft'=>brvtal_public_event_is_visible(['status'=>'draft'],$now),
 'published'=>brvtal_public_event_is_visible(['status'=>'published'],$now),
 'archived_past'=>brvtal_public_event_is_visible(['status'=>'archived','event_date'=>'2026-10-01 21:00:00'],$now),
 'archived_future'=>brvtal_public_event_is_visible(['status'=>'archived','event_date'=>'2026-12-01 21:00:00'],$now),
 'future_not_before'=>brvtal_public_event_is_visible(['status'=>'published','published_at'=>'2026-12-01 21:00:00'],$now),
 'historical'=>brvtal_public_event_is_historical(['status'=>'archived','event_date'=>'2026-10-01 21:00:00'],$now),
];
$publication=[
 'draft'=>brvtal_event_state_error(['title'=>'Draft','status'=>'draft']),
 'valid'=>brvtal_event_state_error(['title'=>'Synthetic','status'=>'published','event_date'=>'2026-11-12 20:00:00','city'=>'Synthetic City','description'=>'Synthetic editorial description']),
 'missing_date'=>brvtal_event_state_error(['title'=>'Synthetic','status'=>'published','city'=>'Synthetic City']),
 'missing_description'=>brvtal_event_state_error(['title'=>'Synthetic','status'=>'published','city'=>'Synthetic City','event_date'=>'2026-11-12 20:00:00']),
];
$ticket=brvtal_event_workflow_ticket(['id'=>'7','name'=>'Synthetic Presale',
 'price'=>'15000','currency'=>'cop','status'=>'active',
 'external_url'=>'https://example.invalid/ticket',
 'available_from'=>'2026-11-01T10:00','available_until'=>'2026-11-12T10:00']);
$badTicket=false;
try { brvtal_event_workflow_ticket(['name'=>'Invalid','external_url'=>'javascript:alert(1)']); }
catch (InvalidArgumentException) { $badTicket=true; }
$duplicate=false;
try { brvtal_event_workflow_request(['event'=>['title'=>'Synthetic','status'=>'draft'],
 'ticket_types'=>[['id'=>9,'name'=>'A'],['id'=>9,'name'=>'B']],'lineup'=>[]]); }
catch (InvalidArgumentException) { $duplicate=true; }
$pages=[
 'valid'=>brvtal_page_publication_error(['status'=>'published','locale'=>'en','content_json'=>'{"blocks":[{"type":"heading","content":"About"}]}']),
 'invalid'=>brvtal_page_publication_error(['status'=>'published','locale'=>'en','content_json'=>'{"blocks":[{"type":"embed","content":"unsafe"}]}']),
 'draft'=>brvtal_page_publication_error(['status'=>'draft','locale'=>'en','content_json'=>'{"blocks":[{"type":"embed","content":"unsafe"}]}']),
];
echo json_encode(['routes'=>$routes,'seo'=>$seo,'notfound'=>brvtal_public_not_found_seo($base,'artists','not-found'),
  'statuses'=>$states,'visibility'=>$visibility,'publication'=>$publication,
  'ticket'=>$ticket,'window'=>brvtal_ticket_window_error($ticket),
  'bad_ticket'=>$badTicket,'duplicate_ticket'=>$duplicate,'pages'=>$pages,
  'invalid_media'=>brvtalContentVisualShapeError('events',['cover_image'=>'javascript:alert(1)'])
],JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),"\n";
"""


def exercise_php() -> dict:
    binary = shutil.which("php")
    if not binary:
        raise AssertionError("UNKNOWN: missing PHP interpreter")
    run = subprocess.run(
        [binary, "-d", "allow_url_fopen=0", "-r", PHP],
        cwd=ROOT, capture_output=True, text=True, timeout=40, check=False,
    )
    if run.returncode:
        raise AssertionError("UNKNOWN: PHP execution failed: " + run.stderr[-550:])
    try:
        data = json.loads(run.stdout)
    except json.JSONDecodeError as exc:
        raise AssertionError("UNKNOWN: PHP output not parseable") from exc
    if not isinstance(data, dict):
        raise AssertionError("UNKNOWN: missing PHP contract data")
    return data


class CoberturaBrvtal03Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.data = exercise_php()

    def test_contrato_principal(self) -> None:
        self.assertEqual(20, len(IDS))
        self.assertEqual(15, len(OFFLINE))
        self.assertEqual(5, len(NON_OFFLINE))
        data = self.data
        # S07-R003: historical archive classification (partial, not live Media).
        self.assertTrue(data["visibility"]["archived_past"])
        self.assertTrue(data["visibility"]["historical"])
        # S07-R005..R009: actual route registry + canonical SEO formatter.
        for id_, kind, table, schema in (
            ("S07-R005", "artists", "artists", "MusicGroup"),
            ("S07-R006", "sets", "sets_media", "MusicRecording"),
            ("S07-R007", "releases", "releases", "MusicAlbum"),
            ("S07-R008", "blog", "blog_posts", "BlogPosting"),
            ("S07-R009", "pages", "pages", "WebPage"),
        ):
            with self.subTest(row=id_):
                route = data["routes"][kind]
                self.assertEqual(table, route["table"])
                self.assertEqual(schema, route["schema_type"])
                self.assertIn("status='published'", route["where"])
                seo = data["seo"][kind]
                self.assertEqual(schema, seo["type"])
                self.assertEqual(f"https://www.brvtal.com.co/{kind}/synthetic-example",
                                 seo["canonical"])
                self.assertTrue(seo["canonical_tag"])
                self.assertTrue(seo["jsonld"])
        # S07-R011: canonical not-found SEO (HTTP 404/noindex is not certified).
        self.assertEqual("https://www.brvtal.com.co/artists/not-found",
                         data["notfound"]["canonical"])
        self.assertIn("Not Found", data["notfound"]["title"])
        # S09-L105/106/107 & L119/120/121: real lifecycle policy and workflow.
        for status in ("draft", "published", "archived"):
            self.assertEqual(status, data["statuses"][status])
        self.assertFalse(data["visibility"]["draft"])
        self.assertTrue(data["visibility"]["published"])
        self.assertTrue(data["visibility"]["archived_past"])
        self.assertIsNone(data["publication"]["draft"])
        self.assertIsNone(data["publication"]["valid"])
        # S09-L109: real media reference shape check.
        self.assertEqual({"error": "INVALID_MEDIA_REFERENCE", "field": "cover_image"},
                         data["invalid_media"])
        # S09-L110: SEO tags generated by real PHP, not copied from source.
        self.assertTrue(all(x["jsonld"] for x in data["seo"].values()))
        # S09-L112: ticket normalization and window validation.
        ticket = data["ticket"]
        self.assertEqual(7, ticket["id"])
        self.assertEqual("COP", ticket["currency"])
        self.assertEqual("15000.00", ticket["price"])
        self.assertEqual("active", ticket["status"])
        self.assertIsNone(data["window"])

    def test_falla_cerrado(self) -> None:
        data = self.data
        self.assertEqual("INVALID_STATUS", data["statuses"]["bad-status"])
        self.assertFalse(data["visibility"]["archived_future"])
        self.assertFalse(data["visibility"]["future_not_before"])
        self.assertEqual("EVENT_DATE_REQUIRED", data["publication"]["missing_date"]["error"])
        self.assertEqual("EVENT_DESCRIPTION_REQUIRED",
                         data["publication"]["missing_description"]["error"])
        self.assertTrue(data["bad_ticket"])
        self.assertTrue(data["duplicate_ticket"])
        self.assertIsNone(data["pages"]["valid"])
        self.assertIsNone(data["pages"]["draft"])
        self.assertEqual("PAGE_CONTENT_STRUCTURE_UNSUPPORTED", data["pages"]["invalid"])
        self.assertNotIn("draft", data["routes"]["pages"]["where"])

    def test_sin_datos_reales(self) -> None:
        self.assertEqual(IDS, OFFLINE | NON_OFFLINE.keys())
        self.assertEqual({"B", "C"}, {kind for kind, _ in NON_OFFLINE.values()})
        self.assertTrue(all(reason for _, reason in NON_OFFLINE.values()))
        self.assertNotRegex(PHP, r"\b(?:curl_exec|mysqli_connect|shell_exec)\s*\(")
        self.assertNotIn("$_COOKIE", PHP)
        self.assertNotIn("$_SERVER", PHP)
        self.assertTrue((ROOT / "docs/BRVTAL-SPEC.md").is_file())
        self.assertTrue((ROOT / "api/event-workflow-lib.php").is_file())


if __name__ == "__main__":
    unittest.main()
