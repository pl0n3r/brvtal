#!/usr/bin/env python3
"""Fail-closed public synthetic Concept 05 A/B evidence exporter and tests."""
from __future__ import annotations

import argparse
import base64
import binascii
import hashlib
import json
import os
from pathlib import Path
import re
import struct
import tempfile
import unittest

TITLE = 'Concept 05 attaches Sound and Memories owner-v2 comparison evidence at 390 and 1440'
SPEC = 'public-concept05-sound-memories.spec.mjs'
BOARD_TITLE = 'C05 owner v2 board real partial layout 390 and 1440 evidence'
BOARD_SPEC = 'public-concept05-fidelity-matrix.spec.mjs'
PHP_SHELL_TITLE = 'real PHP partial composition captures desktop and mobile against owner reference'
PHP_SHELL_SPEC = 'public-concept05-shell.spec.mjs'
PHP_SHELL_NOTE = b'PHP_COMPOSED_LOCAL_ASSETS_SYNTHETIC_DATA_ONLY'
SOURCE = Path('tests/e2e') / SPEC
FILES = {'owner-v2-reference': 'owner-v2-reference.png',
         'c05-owner-v2-ab-manifest': 'c05-owner-v2-ab-manifest.json'}
for _width in (390, 1440):
    for _block in ('sound', 'memories', 'fullpage'):
        FILES[f'c05-{_block}-{_width}'] = f'c05-{_block}-{_width}.png'
PNG = b'\x89PNG\r\n\x1a\n'
SHA = re.compile(r'[0-9a-f]{40}\Z')


def digest(data: bytes) -> str:
    return hashlib.sha256(data).hexdigest()


def specs(suites: list) -> list:
    found = []
    for suite in suites:
        if not isinstance(suite, dict):
            raise ValueError('INVALID_SUITE')
        found.extend(suite.get('specs', []))
        found.extend(specs(suite.get('suites', [])))
    return found


def png_width(data: bytes) -> int:
    if len(data) < 24 or not data.startswith(PNG) or data[12:16] != b'IHDR':
        raise ValueError('INVALID_PNG_HEADER')
    width, height = struct.unpack('>II', data[16:24])
    if not 1 <= width <= 20000 or not 1 <= height <= 30000:
        raise ValueError('INVALID_PNG_DIMENSIONS')
    return width


def decode_selected(attachments: list) -> dict[str, bytes]:
    if not isinstance(attachments, list) or len(attachments) != len(FILES):
        raise ValueError('INVALID_ATTACHMENT_COUNT')
    decoded = {}
    for item in attachments:
        if not isinstance(item, dict) or set(item) - {'name', 'contentType', 'body'}:
            raise ValueError('UNTRUSTED_ATTACHMENT_FIELDS')
        name = item.get('name')
        if not isinstance(name, str) or name not in FILES or name in decoded:
            raise ValueError('UNEXPECTED_OR_DUPLICATE_ATTACHMENT')
        mime = 'application/json' if name.endswith('manifest') else 'image/png'
        if item.get('contentType') != mime or not isinstance(item.get('body'), str):
            raise ValueError('INVALID_ATTACHMENT_BODY')
        try:
            payload = base64.b64decode(item['body'], validate=True)
        except (ValueError, binascii.Error) as exc:
            raise ValueError('INVALID_ATTACHMENT_BASE64') from exc
        if not payload or len(payload) > 16 * 1024 * 1024:
            raise ValueError('INVALID_ATTACHMENT_SIZE')
        if mime == 'image/png':
            actual_width = png_width(payload)
            if name.startswith('c05-fullpage-') and actual_width != int(name.split('-')[-1]):
                raise ValueError('FULLPAGE_WIDTH_MISMATCH')
        decoded[name] = payload
    if set(decoded) != set(FILES):
        raise ValueError('MISSING_ATTACHMENT')
    return decoded


def validate_manifest(data: dict[str, bytes]) -> dict:
    try:
        manifest = json.loads(data['c05-owner-v2-ab-manifest'])
    except (ValueError, UnicodeDecodeError) as exc:
        raise ValueError('INVALID_MANIFEST_JSON') from exc
    if (not isinstance(manifest, dict)
            or manifest.get('schema') != 'brvtal-c05-sound-memories-evidence-v1'
            or manifest.get('review_status') != 'PENDING_HUMAN_AB_COMPARISON'
            or manifest.get('accepted_visual_deviations', 'missing') is not None
            or manifest.get('reference') != 'docs/reference/home-concept05-owner-reference-v2.png'
            or 'synthetic' not in str(manifest.get('source', '')).lower()
            or manifest.get('reference_sha256') != digest(data['owner-v2-reference'])):
        raise ValueError('UNTRUSTED_MANIFEST')
    captures = manifest.get('captures')
    if not isinstance(captures, list) or len(captures) != 6:
        raise ValueError('INVALID_MANIFEST_CAPTURES')
    seen = set()
    for item in captures:
        if not isinstance(item, dict) or set(item) != {'viewport', 'block', 'sha256'}:
            raise ValueError('INVALID_CAPTURE_ENTRY')
        width, block = item['viewport'], item['block']
        if type(width) is not int or width not in (390, 1440) or block not in ('sound', 'memories', 'fullpage'):
            raise ValueError('INVALID_CAPTURE_IDENTITY')
        name = f'c05-{block}-{width}'
        if name in seen or item['sha256'] != digest(data[name]):
            raise ValueError('CAPTURE_HASH_MISMATCH')
        seen.add(name)
    if seen != set(FILES) - {'owner-v2-reference', 'c05-owner-v2-ab-manifest'}:
        raise ValueError('INCOMPLETE_CAPTURE_SET')
    return manifest



def extract_board_captures(report: dict, reference: bytes) -> dict[str, bytes] | None:
    """Read only the successful public C05 board test, never private/auth artifacts."""
    selected = [item for item in specs(report['suites'])
                if isinstance(item, dict) and item.get('title') == BOARD_TITLE]
    if not selected:
        return None
    if (len(selected) != 1 or selected[0].get('file') not in
            (BOARD_SPEC, str(Path('tests/e2e') / BOARD_SPEC))):
        raise ValueError('INVALID_BOARD_SPEC_IDENTITY')
    case = selected[0]
    tests = case.get('tests')
    if case.get('ok') is not True or not isinstance(tests, list) or len(tests) != 1:
        raise ValueError('BOARD_SPEC_NOT_GREEN')
    test = tests[0]
    results = test.get('results') if isinstance(test, dict) else None
    if (test.get('projectName') != 'chromium' or test.get('expectedStatus') != 'passed'
            or not isinstance(results, list) or not results or results[-1].get('status') != 'passed'):
        raise ValueError('BOARD_CHROMIUM_NOT_PASSED')
    items = results[-1].get('attachments')
    expected = {'owner-reference-v2', 'board-390-actual', 'board-1440-actual',
                'board-comparison-note'}
    if not isinstance(items, list) or len(items) != len(expected):
        raise ValueError('INVALID_BOARD_ATTACHMENT_COUNT')
    data = {}
    for item in items:
        if not isinstance(item, dict) or set(item) - {'name', 'contentType', 'body'}:
            raise ValueError('UNTRUSTED_BOARD_ATTACHMENT_FIELDS')
        name = item.get('name')
        if name not in expected or name in data or not isinstance(item.get('body'), str):
            raise ValueError('INVALID_BOARD_ATTACHMENT_IDENTITY')
        mime = 'text/plain' if name == 'board-comparison-note' else 'image/png'
        if item.get('contentType') != mime:
            raise ValueError('INVALID_BOARD_ATTACHMENT_MIME')
        try:
            content = base64.b64decode(item['body'], validate=True)
        except (ValueError, binascii.Error) as exc:
            raise ValueError('INVALID_BOARD_ATTACHMENT_ENCODING') from exc
        if not content or len(content) > 16 * 1024 * 1024:
            raise ValueError('INVALID_BOARD_ATTACHMENT_SIZE')
        if mime == 'image/png':
            png_width(content)
        data[name] = content
    if set(data) != expected or data['owner-reference-v2'] != reference:
        raise ValueError('BOARD_REFERENCE_IDENTITY_MISMATCH')
    note = data['board-comparison-note'].decode('utf-8')
    if not note.startswith('Real desktop/mobile screenshots and owner reference attached'):
        raise ValueError('UNTRUSTED_BOARD_COMPARISON_NOTE')
    for width in (390, 1440):
        capture = data[f'board-{width}-actual']
        w, h = struct.unpack('>II', capture[16:24])
        if w != width or h < 900:
            raise ValueError('BOARD_FULLPAGE_DIMENSIONS_MISMATCH')
    return data


def extract_php_shell_captures(report: dict) -> dict[str, bytes] | None:
    """Read successful public PHP-composed Chromium evidence; never private files."""
    matches = [s for s in specs(report['suites'])
               if isinstance(s, dict) and s.get('title') == PHP_SHELL_TITLE]
    if not matches:
        return None
    if (len(matches) != 1 or matches[0].get('file') not in
            (PHP_SHELL_SPEC, str(Path('tests/e2e') / PHP_SHELL_SPEC))):
        raise ValueError('INVALID_PHP_SHELL_SPEC_IDENTITY')
    case = matches[0]
    tests = case.get('tests')
    if case.get('ok') is not True or not isinstance(tests, list) or len(tests) != 1:
        raise ValueError('PHP_SHELL_NOT_GREEN')
    test = tests[0]
    if not isinstance(test, dict):
        raise ValueError('INVALID_PHP_SHELL_TEST')
    results = test.get('results')
    if (test.get('projectName') != 'chromium' or test.get('expectedStatus') != 'passed'
            or not isinstance(results, list) or not results
            or results[-1].get('status') != 'passed'):
        raise ValueError('PHP_SHELL_CHROMIUM_NOT_PASSED')
    items = results[-1].get('attachments')
    expected = {'php-c05-desktop-1440', 'php-c05-mobile-390', 'php-c05-source-note'}
    if not isinstance(items, list) or len(items) != len(expected):
        raise ValueError('INVALID_PHP_SHELL_ATTACHMENTS')
    decoded = {}
    for item in items:
        if not isinstance(item, dict) or set(item) - {'name', 'contentType', 'body'}:
            raise ValueError('UNTRUSTED_PHP_SHELL_ATTACHMENT_FIELDS')
        name = item.get('name')
        if name not in expected or name in decoded or not isinstance(item.get('body'), str):
            raise ValueError('INVALID_PHP_SHELL_ATTACHMENT_IDENTITY')
        mime = 'text/plain' if name == 'php-c05-source-note' else 'image/png'
        if item.get('contentType') != mime:
            raise ValueError('INVALID_PHP_SHELL_ATTACHMENT_MIME')
        try:
            payload = base64.b64decode(item['body'], validate=True)
        except (ValueError, binascii.Error) as exc:
            raise ValueError('INVALID_PHP_SHELL_ATTACHMENT_ENCODING') from exc
        if not payload or len(payload) > 16 * 1024 * 1024:
            raise ValueError('INVALID_PHP_SHELL_ATTACHMENT_SIZE')
        decoded[name] = payload
    if set(decoded) != expected or decoded['php-c05-source-note'] != PHP_SHELL_NOTE:
        raise ValueError('PHP_SHELL_SOURCE_NOT_AUTHENTICATED')
    for width, name in ((1440, 'php-c05-desktop-1440'), (390, 'php-c05-mobile-390')):
        payload = decoded[name]
        if png_width(payload) != width:
            raise ValueError('PHP_SHELL_VIEWPORT_WIDTH_MISMATCH')
        _, height = struct.unpack('>II', payload[16:24])
        if height < (900 if width == 1440 else 600):
            raise ValueError('PHP_SHELL_VIEWPORT_HEIGHT_MISMATCH')
    return decoded


def export(report: dict, source: str, destination: Path, checkout_sha: str, pr_head_sha: str) -> str:
    if not all(isinstance(x, str) and SHA.fullmatch(x) for x in (checkout_sha, pr_head_sha)):
        raise ValueError('INVALID_SHA_IDENTITY')
    if not isinstance(report, dict) or not isinstance(report.get('suites'), list):
        raise ValueError('INVALID_PLAYWRIGHT_REPORT')
    selected = [s for s in specs(report['suites']) if isinstance(s, dict) and s.get('title') == TITLE]
    source_text = Path(source).read_text(encoding='utf-8')
    if TITLE not in source_text:
        if selected:
            raise ValueError('SOURCE_REPORT_MISMATCH')
        return 'NOT_APPLICABLE'
    if len(selected) != 1 or selected[0].get('file') not in (SPEC, str(SOURCE).replace('\\', '/')):
        raise ValueError('MISSING_OR_WRONG_PUBLIC_SPEC')
    case = selected[0]
    tests = case.get('tests')
    if case.get('ok') is not True or not isinstance(tests, list) or len(tests) != 1:
        raise ValueError('PUBLIC_SPEC_NOT_GREEN')
    test = tests[0]
    results = test.get('results') if isinstance(test, dict) else None
    if (test.get('projectName') != 'chromium' or test.get('expectedStatus') != 'passed'
            or not isinstance(results, list) or not results or results[-1].get('status') != 'passed'):
        raise ValueError('PUBLIC_CHROMIUM_NOT_PASSED')
    decoded = decode_selected(results[-1].get('attachments'))
    manifest = validate_manifest(decoded)
    board = extract_board_captures(report, decoded['owner-v2-reference'])
    if board is not None:
        # The existing success artifact already uploads c05-fullpage-{width}.png.
        # Replace only those two formerly Sound/Memories-only screenshots with
        # authentic full-home 01-07 Playwright renders; never relabel production.
        for width in (390, 1440):
            decoded[f'c05-fullpage-{width}'] = board[f'board-{width}-actual']
        for capture in manifest['captures']:
            if capture['block'] == 'fullpage':
                capture['sha256'] = digest(decoded[f"c05-fullpage-{capture['viewport']}"])
        manifest['fullpage_source'] = 'browser-rendered composed home 01-07, synthetic CMS, not production'
        manifest['fullpage_review'] = 'PENDING_HUMAN_AB_COMPARISON'
        manifest['fullpage_limitations'] = (
            'Real checked-in artwork is decoded; CMS fixtures and unfinished '
            'block 07 are not proof of production fidelity or owner approval.'
        )
        decoded['c05-owner-v2-ab-manifest'] = (
            json.dumps(manifest, indent=2, sort_keys=True).encode('utf-8'))
        validate_manifest(decoded)
    php_shell = extract_php_shell_captures(report)
    if php_shell is not None:
        # Reuse only the two existing public fullpage artifact paths. Other
        # screenshots and the owner reference are never overwritten.
        for width, name in ((1440, 'php-c05-desktop-1440'), (390, 'php-c05-mobile-390')):
            decoded[f'c05-fullpage-{width}'] = php_shell[name]
        for capture in manifest['captures']:
            if capture['block'] == 'fullpage':
                capture['sha256'] = digest(decoded[f"c05-fullpage-{capture['viewport']}"])
        manifest['fullpage_source'] = (
            'real PHP partial composition and checked-in JS/CSS, synthetic CMS, not production')
        manifest['fullpage_review'] = 'PENDING_HUMAN_AB_COMPARISON'
        manifest['fullpage_limitations'] = (
            'Actual 1440/390 local PHP screenshots; owner visual comparison is still pending.')
        decoded['c05-owner-v2-ab-manifest'] = (
            json.dumps(manifest, indent=2, sort_keys=True).encode('utf-8'))
        validate_manifest(decoded)
    if destination.exists():
        raise ValueError('ARTIFACT_DIRECTORY_ALREADY_EXISTS')
    destination.mkdir(parents=True, exist_ok=False)
    for name, filename in FILES.items():
        (destination / filename).write_bytes(decoded[name])
    attestation = {'schema': 'brvtal-concept05-public-artifact-v1',
                   'pr_head_sha': pr_head_sha, 'checkout_sha': checkout_sha,
                   'review_status': 'PENDING_HUMAN_AB_COMPARISON',
                   'synthetic_only': True,
                   'files_sha256': {filename: digest(decoded[name]) for name, filename in FILES.items()}}
    (destination / 'c05-export-attestation.json').write_text(
        json.dumps(attestation, indent=2, sort_keys=True) + '\n', encoding='utf-8')
    return 'EXPORTED'


def cli() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument('--export', required=True, help='Local Playwright JSON reporter file')
    parser.add_argument('--source', default=str(SOURCE))
    parser.add_argument('--destination', default='artifacts/concept05-positive')
    args = parser.parse_args()
    report = json.loads(Path(args.export).read_text(encoding='utf-8'))
    result = export(report, args.source, Path(args.destination),
                    os.environ.get('GITHUB_SHA', ''), os.environ.get('C05_PR_HEAD_SHA', ''))
    output = os.environ.get('GITHUB_OUTPUT')
    if not output:
        raise ValueError('GITHUB_OUTPUT_REQUIRED')
    with open(output, 'a', encoding='utf-8') as fp:
        fp.write(f'has_evidence={str(result == "EXPORTED").lower()}\n')
        fp.write(f'evidence_status={result}\n')
    print('Public Concept 05 A/B evidence:', result)


class Concept05SuccessVisualArtifactTests(unittest.TestCase):
    SHA40 = 'a' * 40

    def make_report(self, *, relative=True):
        image = lambda w: PNG + b'\x00\x00\x00\rIHDR' + struct.pack('>II', w, 12) + b'synthetic image bytes'
        content = {'owner-v2-reference': image(1200)}
        for width in (390, 1440):
            for block in ('sound', 'memories', 'fullpage'):
                content[f'c05-{block}-{width}'] = image(width if block == 'fullpage' else 375)
        manifest = {'schema': 'brvtal-c05-sound-memories-evidence-v1',
                    'source': 'synthetic fixtures (not production)',
                    'reference': 'docs/reference/home-concept05-owner-reference-v2.png',
                    'reference_sha256': digest(content['owner-v2-reference']),
                    'review_status': 'PENDING_HUMAN_AB_COMPARISON',
                    'accepted_visual_deviations': None,
                    'captures': [{'viewport': w, 'block': b, 'sha256': digest(content[f'c05-{b}-{w}'])}
                                 for w in (390, 1440) for b in ('sound', 'memories', 'fullpage')]}
        content['c05-owner-v2-ab-manifest'] = json.dumps(manifest).encode()
        att = [{'name': name, 'contentType': ('application/json' if name.endswith('manifest') else 'image/png'),
                'body': base64.b64encode(data).decode()} for name, data in content.items()]
        case = {'title': TITLE, 'file': SPEC if relative else str(SOURCE), 'ok': True,
                'tests': [{'projectName': 'chromium', 'expectedStatus': 'passed',
                           'results': [{'status': 'passed', 'attachments': att}]}]}
        return {'suites': [{'specs': [case], 'suites': []}]}

    def test_successful_chromium_publishes_only_synthetic_ab_screenshots(self):
        with tempfile.TemporaryDirectory() as temp:
            source = Path(temp) / SPEC
            source.write_text(f'test({TITLE!r})', encoding='utf-8')
            dest = Path(temp) / 'exports'
            report = self.make_report()
            report['suites'].append({'specs': [{'title': 'PRIVATE AUTH', 'file': 'admin.spec.mjs',
                                                'tests': [{'results': [{'attachments': [{'body': 'PRIVATE_SECRET'}]}]}]}]})
            self.assertEqual('EXPORTED', export(report, str(source), dest, self.SHA40, self.SHA40))
            self.assertEqual(set(FILES.values()) | {'c05-export-attestation.json'},
                             set(p.name for p in dest.iterdir()))
            self.assertNotIn('PRIVATE_SECRET', ''.join(p.read_text(errors='ignore') for p in dest.iterdir()))
            self.assertEqual(self.SHA40, json.loads((dest/'c05-export-attestation.json').read_text())['pr_head_sha'])

    def test_success_board_01_07_replaces_only_two_fullpage_images(self):
        with tempfile.TemporaryDirectory() as temp:
            source = Path(temp) / SPEC
            source.write_text(TITLE, encoding='utf-8')
            report = self.make_report()
            reference = next(item for item in
                report['suites'][0]['specs'][0]['tests'][0]['results'][0]['attachments']
                if item['name'] == 'owner-v2-reference')['body']
            def image(width):
                return PNG + b'\x00\x00\x00\rIHDR' + struct.pack('>II', width, 1900) + b'board fixture pixels'
            def attachment(name, payload, mime='image/png'):
                return {'name': name, 'contentType': mime,
                        'body': base64.b64encode(payload).decode('ascii')}
            board = {'title': BOARD_TITLE, 'file': BOARD_SPEC, 'ok': True,
                     'tests': [{'projectName': 'chromium', 'expectedStatus': 'passed',
                                'results': [{'status': 'passed', 'attachments': [
                                   {'name': 'owner-reference-v2', 'contentType': 'image/png',
                                    'body': reference},
                                   attachment('board-1440-actual', image(1440)),
                                   attachment('board-390-actual', image(390)),
                                   attachment('board-comparison-note',
                                              b'Real desktop/mobile screenshots and owner reference attached',
                                              'text/plain'),
                                ]}]}]}
            report['suites'].append({'specs': [board], 'suites': []})
            dest = Path(temp) / 'valid'
            self.assertEqual('EXPORTED', export(report, str(source), dest,
                                                 self.SHA40, self.SHA40))
            for width in (390, 1440):
                self.assertEqual(image(width), (dest / f'c05-fullpage-{width}.png').read_bytes())
            manifest = json.loads((dest/'c05-owner-v2-ab-manifest.json').read_text())
            self.assertIn('composed home 01-07', manifest['fullpage_source'])
            self.assertEqual('PENDING_HUMAN_AB_COMPARISON', manifest['fullpage_review'])
            self.assertTrue(json.loads((dest/'c05-export-attestation.json').read_text())['synthetic_only'])
            for mutation in ('missing', 'wrong_width', 'skipped'):
                with self.subTest(mutation=mutation):
                    broken = json.loads(json.dumps(report))
                    case = broken['suites'][1]['specs'][0]
                    attachments = case['tests'][0]['results'][0]['attachments']
                    if mutation == 'missing': attachments.pop()
                    if mutation == 'wrong_width':
                        attachments[2]['body'] = base64.b64encode(image(500)).decode()
                    if mutation == 'skipped': case['tests'][0]['results'][0]['status'] = 'skipped'
                    with self.assertRaises(ValueError):
                        export(broken, str(source), Path(temp) / ('invalid-'+mutation),
                               self.SHA40, self.SHA40)

    def test_success_php_shell_captures_are_persisted_with_exact_identity(self):
        with tempfile.TemporaryDirectory() as temp:
            source = Path(temp) / SPEC
            source.write_text(TITLE, encoding='utf-8')
            report = self.make_report()
            def screenshot(width):
                return PNG + b'\x00\x00\x00\rIHDR' + struct.pack('>II', width, 1200) + b'php synthetic pixels'
            def attachment(name, payload, mime='image/png'):
                return {'name': name, 'contentType': mime,
                        'body': base64.b64encode(payload).decode('ascii')}
            php_case = {'title': PHP_SHELL_TITLE, 'file': PHP_SHELL_SPEC, 'ok': True,
                        'tests': [{'projectName': 'chromium', 'expectedStatus': 'passed',
                                   'results': [{'status': 'passed', 'attachments': [
                                       attachment('php-c05-desktop-1440', screenshot(1440)),
                                       attachment('php-c05-mobile-390', screenshot(390)),
                                       attachment('php-c05-source-note', PHP_SHELL_NOTE, 'text/plain')
                                   ]}]}]}
            report['suites'].append({'specs': [php_case], 'suites': []})
            dest = Path(temp) / 'verified'
            self.assertEqual('EXPORTED', export(report, str(source), dest, self.SHA40, self.SHA40))
            for width in (390, 1440):
                self.assertEqual(screenshot(width), (dest / f'c05-fullpage-{width}.png').read_bytes())
            manifest = json.loads((dest / 'c05-owner-v2-ab-manifest.json').read_text())
            self.assertIn('real PHP partial composition', manifest['fullpage_source'])
            self.assertEqual('PENDING_HUMAN_AB_COMPARISON', manifest['fullpage_review'])
            attestation = json.loads((dest / 'c05-export-attestation.json').read_text())
            self.assertTrue(attestation['synthetic_only'])
            self.assertEqual(self.SHA40, attestation['pr_head_sha'])
            for mutation in ('missing', 'duplicate', 'wrong_width', 'wrong_mime',
                             'wrong_source', 'skipped', 'wrong_spec'):
                with self.subTest(mutation=mutation):
                    broken = json.loads(json.dumps(report))
                    case = broken['suites'][1]['specs'][0]
                    attachments = case['tests'][0]['results'][0]['attachments']
                    if mutation == 'missing':
                        attachments.pop()
                    elif mutation == 'duplicate':
                        attachments.append(attachments[0].copy())
                    elif mutation == 'wrong_width':
                        attachments[0]['body'] = base64.b64encode(screenshot(500)).decode()
                    elif mutation == 'wrong_mime':
                        attachments[0]['contentType'] = 'text/plain'
                    elif mutation == 'wrong_source':
                        attachments[2]['body'] = base64.b64encode(b'PRODUCTION_APPROVED').decode()
                    elif mutation == 'skipped':
                        case['tests'][0]['results'][0]['status'] = 'skipped'
                    elif mutation == 'wrong_spec':
                        case['file'] = 'admin-private.spec.mjs'
                    with self.assertRaises(ValueError):
                        export(broken, str(source), Path(temp) / ('invalid-' + mutation),
                               self.SHA40, self.SHA40)

    def test_no_auth_or_private_test_results_in_visual_artifact(self):
        with tempfile.TemporaryDirectory() as temp:
            source = Path(temp) / SPEC
            source.write_text(TITLE, encoding='utf-8')
            for mutation in ('extra', 'duplicate', 'bad_hash', 'wrong_width', 'body_path', 'bad_base64',
                             'skipped', 'wrong_spec', 'bad_manifest'):
                with self.subTest(mutation=mutation):
                    report = self.make_report()
                    case = report['suites'][0]['specs'][0]
                    a = case['tests'][0]['results'][0]['attachments']
                    if mutation == 'extra': a.append({'name': 'auth-cookie', 'contentType': 'text/plain', 'body': 'eA=='})
                    if mutation == 'duplicate': a.append(a[0].copy())
                    if mutation == 'bad_hash': a[-1]['body'] = base64.b64encode(b'{}').decode()
                    if mutation == 'wrong_width':
                        target = next(x for x in a if x['name'] == 'c05-fullpage-390')
                        target['body'] = base64.b64encode(PNG + b'\0\0\0\rIHDR' + struct.pack('>II', 500, 12)).decode()
                    if mutation == 'body_path': a[0]['path'] = '/tmp/private'
                    if mutation == 'bad_base64': a[0]['body'] = '!!!'
                    if mutation == 'skipped': case['tests'][0]['results'][0]['status'] = 'skipped'
                    if mutation == 'wrong_spec': case['file'] = 'admin.spec.mjs'
                    if mutation == 'bad_manifest':
                        att = next(x for x in a if x['name'].endswith('manifest'))
                        payload = json.loads(base64.b64decode(att['body']))
                        payload['review_status'] = 'APPROVED'
                        att['body'] = base64.b64encode(json.dumps(payload).encode()).decode()
                    with self.assertRaises(ValueError):
                        export(report, str(source), Path(temp) / f'out-{mutation}', self.SHA40, self.SHA40)

    def test_v7_pin_retention_failure_artifact_and_review_gate_preserved(self):
        workflow = (Path(__file__).resolve().parents[1]/'.github/workflows/update-release-metadata.yml')
        if not workflow.exists():
            self.skipTest('Workflow absent from isolated tests')
        text = workflow.read_text(encoding='utf-8')
        self.assertIn('PLAYWRIGHT_JSON_OUTPUT_FILE', text)
        self.assertIn('tests/test_concept05_success_visual_artifact.py --export', text)
        self.assertIn("if: success() && steps.concept05_evidence.outputs.has_evidence", text)
        self.assertIn('if-no-files-found: error', text)
        self.assertIn('name: concept05-pr-visual-', text)
        self.assertIn('if: failure()', text)
        self.assertIn('PENDING_HUMAN_AB_COMPARISON', Path(__file__).read_text())
        self.assertEqual(3, text.count('uses: actions/upload-artifact@'))
        self.assertIn('043fb46d1a93c77aae656e7c1c64a875d1fc6a0a # v7.0.1', text)
        self.assertIn('retention-days: 14', text)

    def test_relative_and_full_spec_paths_and_not_applicable_bootstrap(self):
        with tempfile.TemporaryDirectory() as temp:
            source = Path(temp) / SPEC
            source.write_text('test without evidence yet', encoding='utf-8')
            self.assertEqual('NOT_APPLICABLE', export({'suites': []}, str(source), Path(temp)/'none', self.SHA40, self.SHA40))
            source.write_text(TITLE, encoding='utf-8')
            with self.assertRaises(ValueError):
                export({'suites': []}, str(source), Path(temp)/'missing', self.SHA40, self.SHA40)
            for relative in (True, False):
                self.assertEqual('EXPORTED', export(self.make_report(relative=relative), str(source),
                                 Path(temp)/f'positive-{relative}', self.SHA40, self.SHA40))


if __name__ == '__main__':
    if '--export' in __import__('sys').argv:
        cli()
    else:
        unittest.main()
