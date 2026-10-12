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
