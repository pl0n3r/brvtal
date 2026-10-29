#!/usr/bin/env python3
"""#1288: real offline PHP/JS behavior for S45–S51 without false production claims.

Only the A subset below has executable synthetic local assertions. B requires
an authenticated browser or live GitHub evidence; C is an institutional policy
rather than an independently testable runtime operation. No matrix reads occur.
"""
from __future__ import annotations

import json
import os
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
IDS = frozenset((
    "S45-R004", "S45-R005", "S46-R001", "S46-R002", "S46-R003",
    "S46-R004", "S47-R001", "S47-R002", "S48-R001", "S48-R002",
    "S49-R001", "S49-R002", "S49-R003", "S49-R004", "S49-R005",
    "S50-R001", "S50-R002", "S50-R003", "S51-L754", "S51-R001",
))
OFFLINE = frozenset(("S46-R003", "S46-R004", "S47-R001", "S47-R002"))
EXTERNAL = {
    "S45-R004": "B: authenticated Security workspace requires actual browser navigation",
    "S45-R005": "B: System Status control-room workspace requires browser rendering",
    "S46-R001": "B: complete focus/contrast/keyboard support needs a rendered browser",
    "S46-R002": "B: overall image/video/WebGL/font/network performance needs real browser metrics",
    "S51-R001": "B: roadmap #533's authority needs independently authenticated live GitHub state",
}
DECLARATIVE = {
    "S48-R001": "C: process rule to reuse existing endpoints and services",
    "S48-R002": "C: architecture rule prohibiting duplicated foundations",
    "S49-R001": "C: qualitative Git history principle",
    "S49-R002": "C: qualitative commit scope principle",
    "S49-R003": "C: product-decision and specification governance",
    "S49-R004": "C: preferred branch/PR/squash release process",
    "S49-R005": "C: process rule against direct functional main commits",
    "S50-R001": "C: heading defining evidence-status vocabulary",
    "S50-R002": "C: definitions of four distinct evidence states",
    "S50-R003": "C: communication policy forbidding status conflation",
    "S51-L754": "C: roadmap's declarative list item (phases)",
}
# Synthetic-only SHA; no actual deployment identity or production claim.
SYNTHETIC_SHA = "a1" * 20

PHP_PROBE = r'''
declare(strict_types=1);
require_once 'config/deployment.php';
require_once 'config/media.php';
require_once 'config/public_home.php';
require_once 'api/public-response.php';

$check = static function (bool $ok, string $id): void {
    if (!$ok) throw new RuntimeException('REAL_BEHAVIOR_FAILED:' . $id);
};
$mode = $argv[1] ?? '';
$covered = [];
if ($mode === 'positive') {
    $identity = brvtalDeploymentPublicData();
    $sha = getenv('BRVTAL_DEPLOY_COMMIT');
    $check($identity['exact'] === true && $identity['source'] === 'environment',
           'exact synthetic identity source');
    $check($identity['commit'] === $sha
           && $identity['short_commit'] === substr($sha, 0, 7)
           && $identity['cache_key'] === substr($sha, 0, 7)
           && brvtalDeploymentCacheKey() === substr($sha, 0, 7),
           'deployment SHA cache invalidation');
    $covered[] = 'S46-R004';

    $check(brvtal_public_next_experience_date_parts([]) ===
           ['DATE TBA', 'TIME TBA'], 'missing date has usable fallback');
    $check(brvtal_public_next_experience_tag(null) ===
           'NEW DATE TO BE ANNOUNCED.', 'no event has visible empty state');
    $now = new DateTimeImmutable('2026-10-29T15:00:00+00:00');
    $check(brvtal_public_select_next_experience([], $now) === null,
           'no events is not a fatal error');
    $check(brvtal_public_select_next_experience([
        ['status'=>'draft','title'=>'SYNTHETIC PRIVATE EVENT']
    ], $now) === null, 'private draft is not public fallback content');
    $check(brvtal_public_envelope([], 200) === ['ok'=>true, 'data'=>[]],
           'empty public response is bounded');
    $covered[] = 'S47-R001';

    $empty = brvtalMediaImageReferenceState(null);
    $check($empty === ['valid'=>true,'usable'=>false,'kind'=>'empty'],
           'optional image remains optional');
    $missing = brvtalMediaImageReferenceState(
        '/uploads/coverage_1288_never_existing_4b7c309d.png');
    $check($missing['valid'] && !$missing['usable']
           && $missing['kind'] === 'local_missing', 'missing image not usable');
    $covered[] = 'S47-R002';
} elseif ($mode === 'negative') {
    $check(brvtal_public_next_experience_date_parts([
        'event_date'=>'definitely-not-a-date'
    ]) === ['DATE TBA','TIME TBA'], 'invalid date fallback');
    $check(brvtal_public_home_http_url('javascript:alert(1)') === '',
           'unsafe URL rejected');
    $check(brvtal_public_home_http_url('file:///etc/passwd') === '',
           'non-http URL rejected');
    foreach ([
        '/uploads/%2e%2e/config.php',
        'https://synthetic:secret@fixture.invalid/x.jpg',
        'javascript:alert(1)',
        '//fixture.invalid/not-local.png',
    ] as $ref) {
        $image = brvtalMediaImageReferenceState($ref);
        $check($image['valid'] === false && $image['usable'] === false,
               'unsafe image URL rejected');
    }
    $check(brvtal_public_envelope([], 503) ===
           ['ok'=>false,'error'=>'REQUEST_FAILED'],
           'failed request cannot appear as success');
    $covered[] = 'DENIED';
} else {
    throw new InvalidArgumentException('unsupported mode');
}
echo json_encode(['covered'=>$covered, 'database'=>'disabled',
                  'network'=>'disabled', 'production'=>'UNKNOWN'],
                 JSON_THROW_ON_ERROR);
'''

# Execute the real tracked motion module in a disposable vm with a fake DOM.
# No fetch, network access, browser storage, app credentials or real data.
NODE_PROBE = r'''
'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('js/public-concept05-motion.js', 'utf8');
const mode = process.argv[1];
const check = (ok, what) => {
    if (!ok) throw new Error('REAL_BEHAVIOR_FAILED:' + what);
};
function exercise(variant) {
    const calls = [];
    const section = {querySelector() { return {tagName: 'H2'}; }};
    const host = {querySelectorAll(selector) {
        if (selector === '.c5-module') return [{tagName: 'SECTION'}];
        if (selector === '.c5-numbered') return [section];
        return [];
    }};
    const gsap = variant === 'no-gsap' ? null : {
        registerPlugin() { calls.push('register'); },
        from() { calls.push('from'); },
        timeline() {
            calls.push('timeline');
            return {fromTo() { calls.push('fromTo'); }};
        },
    };
    const window = {
        gsap, ScrollTrigger: {},
        matchMedia(query) {return {matches:
            (query.includes('reduced-motion') && variant === 'reduced')
            || (query.includes('pointer: coarse') && variant === 'coarse')};},
    };
    const document = {
        readyState: 'complete',
        querySelectorAll() { return [host]; },
        documentElement: {
            getAttribute() {
                return variant === 'theme-reduced' ? 'reduced'
                    : variant === 'theme-minimal' ? 'minimal' : null;
            },
            classList: {contains() { return variant === 'visual-test'; }},
        },
    };
    vm.runInNewContext(source, {window, document}, {timeout: 2000});
    check(typeof window.BRVTAL_CONCEPT05_MOTION_INIT === 'function',
          'real motion entry point exported');
    return calls;
}
let covered = [];
if (mode === 'positive') {
    const normal = exercise('normal');
    check(normal.filter(x => x === 'from').length === 2,
          'normal registered animations');
    check(normal.includes('fromTo') && normal.includes('register'),
          'normal motion timeline registered');
    covered = ['S46-R003'];
} else if (mode === 'negative') {
    for (const variant of [
        'reduced','coarse','theme-reduced','theme-minimal','visual-test','no-gsap'
    ]) {
        check(exercise(variant).length === 0, 'motion disabled: ' + variant);
    }
    covered = ['DENIED'];
} else throw new Error('unsupported mode');
process.stdout.write(JSON.stringify({covered, database:'disabled',
                                    network:'disabled', production:'UNKNOWN'}));
'''


def execute(command: list[str], *, php: bool = False) -> dict:
    binary = shutil.which(command[0])
    if not binary:
        raise AssertionError('UNKNOWN: required offline runtime unavailable')
    env = {key: os.environ[key] for key in ('PATH', 'LANG', 'LC_ALL')
           if key in os.environ}
    if php:
        env['BRVTAL_DEPLOY_COMMIT'] = SYNTHETIC_SHA
    run = subprocess.run([binary, *command[1:]], cwd=ROOT, env=env,
                         capture_output=True, text=True, timeout=40, check=False)
    if run.returncode or run.stderr.strip():
        raise AssertionError('UNKNOWN: offline execution failed: '
                             + (run.stderr + run.stdout)[-1200:])
    try:
        payload = json.loads(run.stdout)
    except ValueError as exc:
        raise AssertionError('UNKNOWN: invalid executable evidence') from exc
    if not isinstance(payload, dict) or any(payload.get(key) != val for key, val in (
        ('database', 'disabled'), ('network', 'disabled'),
        ('production', 'UNKNOWN'),
    )) or not isinstance(payload.get('covered'), list):
        raise AssertionError('UNKNOWN: incomplete/offline evidence')
    return payload


def php_case(mode: str) -> dict:
    if mode not in ('positive', 'negative'):
        raise ValueError('unsupported PHP mode')
    return execute(['php', '-r', PHP_PROBE, mode], php=True)


def node_case(mode: str) -> dict:
    if mode not in ('positive', 'negative'):
        raise ValueError('unsupported JavaScript mode')
    return execute(['node', '-e', NODE_PROBE, mode])


class CoberturaBrvtal19Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        self.assertEqual(len(IDS), 20)
        self.assertEqual(IDS, OFFLINE | EXTERNAL.keys() | DECLARATIVE.keys())
        self.assertTrue(all(EXTERNAL.values()) and all(DECLARATIVE.values()))
        php = php_case('positive')['covered']
        js = node_case('positive')['covered']
        self.assertEqual(set(php) | set(js), OFFLINE)
        self.assertEqual(len(php + js), len(OFFLINE))

    def test_falla_cerrado(self) -> None:
        self.assertEqual(php_case('negative')['covered'], ['DENIED'])
        self.assertEqual(node_case('negative')['covered'], ['DENIED'])
        for runner in (php_case, node_case):
            with self.assertRaises(ValueError):
                runner('unsupported')
        self.assertFalse(set(EXTERNAL) & OFFLINE)
        self.assertFalse(set(DECLARATIVE) & OFFLINE)

    def test_sin_datos_reales(self) -> None:
        self.assertEqual(len(SYNTHETIC_SHA), 40)
        self.assertNotIn('curl_', PHP_PROBE)
        self.assertNotIn('fetch(', NODE_PROBE)
        self.assertNotIn('XMLHttpRequest', NODE_PROBE)
        self.assertNotIn('docs/matriz-cobertura', PHP_PROBE)
        self.assertNotIn('docs/matriz-cobertura', NODE_PROBE)
        self.assertNotIn('brvtal.com.co', PHP_PROBE + NODE_PROBE)
        self.assertNotIn('REPLACE_DB_PASSWORD', PHP_PROBE + NODE_PROBE)
        for runner in (php_case, node_case):
            evidence = runner('positive')
            self.assertEqual(evidence['database'], 'disabled')
            self.assertEqual(evidence['network'], 'disabled')
            self.assertEqual(evidence['production'], 'UNKNOWN')


if __name__ == '__main__':
    unittest.main()
