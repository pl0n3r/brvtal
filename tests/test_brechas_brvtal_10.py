#!/usr/bin/env python3
"""BRVTAL #1100: five S07/S08/S09 synthetic, fail-closed contracts.

LOCAL_PARTIAL never proves publication, a visual review, deployed content,
HTTP delivery, database persistence, or production readiness.
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
MATRIX = 'docs/matriz-cobertura.md'
SPEC = 'docs/BRVTAL-SPEC.md'
FILES = (
    MATRIX, SPEC, 'api/pages-contract.php', 'config/page_content.php',
    'config/public_visibility.php', 'config/public_entity_delivery.php',
    'config/public_home.php', 'config/event_lifecycle.php', 'index.php',
    'discadmin/pages-publication-contract.js',
    'tests/public-entity-pages-contract.php',
    'tests/public-home-contract.php', 'tests/event-workflow-contract.php',
)
PHRASES = {
    'S07-R009': ('individual CMS Page.', 90),
    'S07-R010': ('The architecture must allow future pages without becoming a generic site builder.', 92),
    'S07-R011': ('Canonical published entity routes are implemented and must remain server-rendered enough to deliver correct public metadata and 404/noindex behavior.', 94),
    'S08-R001': ('Homepage composition should remain relatively stable and art-directed.', 98),
    'S09-L105': ('drafts;', 105),
}
UNKNOWN = 'UNKNOWN'
LOCAL = 'LOCAL_PARTIAL'
LOCALLY_EXERCISABLE = frozenset({'S07-R009', 'S07-R010', 'S09-L105'})


def read_sources() -> dict[str, str]:
    return {path: (ROOT / path).read_text(encoding='utf-8') for path in FILES}


def matrix_rows(raw: object) -> dict[str, tuple[str, ...]]:
    """Preserve five original matrix rows, each unique and NO_VERIFICABLE."""
    if type(raw) is not str:
        raise ValueError('UNKNOWN: matriz ausente')
    found = {}
    for key, (phrase, _) in PHRASES.items():
        rows = [line for line in raw.splitlines() if line.startswith('| ' + key + ' · ')]
        if len(rows) != 1:
            raise ValueError('UNKNOWN: fila duplicada o ausente: ' + key)
        cells = tuple(c.strip() for c in rows[0].strip('|').split('|'))
        if (len(cells) != 5 or cells[1] != phrase or cells[2] != 'NO_VERIFICABLE'
                or not cells[0].startswith(key + ' · [S')
                or 'Sin aserción contrastada' not in cells[3]
                or not cells[4]):
            raise ValueError('UNKNOWN: fila ambigua o promovida: ' + key)
        found[key] = cells
    return found


def source_guards(docs: object) -> dict[str, bool]:
    if type(docs) is not dict or set(docs) != set(FILES) or any(
        type(docs[p]) is not str or not docs[p] for p in FILES
    ):
        return dict.fromkeys(PHRASES, False)
    spec = docs[SPEC].splitlines()
    if not all(len(spec) >= line and phrase in spec[line - 1]
               for phrase, line in PHRASES.values()):
        return dict.fromkeys(PHRASES, False)
    def has(path: str, *tokens: str) -> bool:
        return all(token in docs[path] for token in tokens)
    return {
        'S07-R009': has('api/pages-contract.php', 'brvtal_page_publication_error',
                         "'published'", 'brvtal_page_content_structure_error')
                     and has('config/public_visibility.php', 'brvtal_public_page_is_visible',
                             "'published'", "'en'"),
        'S07-R010': has('config/page_content.php', "['text', 'paragraph', 'heading']",
                         'PAGE_CONTENT_STRUCTURE_UNSUPPORTED')
                     and has('discadmin/pages-publication-contract.js',
                             'text · paragraph · heading blocks'),
        'S07-R011': has('index.php', 'brvtalPublicEntityDocument(',
                         'http_response_code(404)', 'X-Robots-Tag: noindex')
                     and has('config/public_entity_delivery.php',
                             'brvtal_public_entity_page(')
                     and has('tests/public-entity-pages-contract.php',
                             'PUBLIC ENTITY PAGES CONTRACT FAILED'),
        'S08-R001': has('config/public_home.php', 'brvtal_public_select_next_experience',
                         'featured')
                     and has('tests/public-home-contract.php',
                             'Next Experience', 'Private draft'),
        'S09-L105': has('config/event_lifecycle.php', 'brvtal_event_lifecycle_patch',
                         "'draft'", 'published_at')
                     and has('tests/event-workflow-contract.php',
                             'incomplete drafts must remain allowed'),
    }


# Execute only deterministic pure PHP contracts; no HTTP, PDO or app bootstrap.
PHP_PROBE = r'''
require_once 'config/public_visibility.php';
require_once 'api/pages-contract.php';
require_once 'config/event_lifecycle.php';
$allowedPage = brvtal_page_publication_error([
  'status'=>'published', 'locale'=>'en',
  'content_json'=>'{"blocks":[{"type":"heading","text":"Synthetic headline"}]}'
]);
$draftPage = brvtal_page_publication_error([
  'status'=>'draft', 'locale'=>'en',
  'content_json'=>'{"blocks":[{"type":"embed","content":"not allowed"}]}'
]);
$badPage = brvtal_page_publication_error([
  'status'=>'published','locale'=>'en',
  'content_json'=>'{"blocks":[{"type":"embed","content":"not allowed"}]}'
]);
$badLocale = brvtal_page_publication_error([
  'status'=>'published','locale'=>'fr','content_json'=>'[]'
]);
$visible = brvtal_public_page_is_visible(['status'=>'published','locale'=>'en','slug'=>'synthetic']);
$hidden = brvtal_public_page_is_visible(['status'=>'draft','locale'=>'en','slug'=>'synthetic']);
$privateEs = brvtal_public_page_is_visible(['status'=>'published','locale'=>'es','slug'=>'synthetic','private'=>true]);
$now = new DateTimeImmutable('2026-09-15T12:00:00+00:00');
$draft = brvtal_event_lifecycle_patch([], ['status'=>'draft', 'published_at'=>'2026-01-01 00:00:00'], $now);
$active = brvtal_event_lifecycle_patch([], ['status'=>'published'], $now);
$scheduleRejected = brvtal_event_publish_at_error([], [
  'status'=>'draft','publish_at'=>'2026-09-20T12:00:00+00:00'
], $now);
echo json_encode([
 'S07-R009'=>['valid_published'=>$allowedPage===null && $visible,
               'draft_hidden'=>$draftPage===null && !$hidden && !$privateEs],
 'S07-R010'=>['unknown_builder_rejected'=>$badPage==='PAGE_CONTENT_STRUCTURE_UNSUPPORTED',
               'unsupported_locale_rejected'=>$badLocale==='PAGE_PUBLIC_LOCALE_MUST_BE_EN'],
 'S09-L105'=>['draft_no_publication'=>($draft['status']??null)==='draft'
              && !array_key_exists('published_at',$draft),
               'published_server_timestamp'=>($active['published_at']??null)==='2026-09-15 12:00:00',
               'draft_schedule_denied'=>($scheduleRejected['error']??null)==='EVENT_PUBLISH_AT_REQUIRES_PUBLIC_STATUS'],
], JSON_THROW_ON_ERROR);
'''


@lru_cache(maxsize=1)
def probe() -> dict[str, object]:
    php = shutil.which('php')
    if php is None:
        raise AssertionError('UNKNOWN: PHP CLI ausente')
    process = subprocess.run(
        [php, '-n', '-d', 'allow_url_fopen=0', '-r', PHP_PROBE],
        cwd=ROOT, capture_output=True, text=True, check=False, timeout=12,
    )
    if process.returncode != 0:
        raise AssertionError('UNKNOWN: PHP probe falló: ' + process.stderr[-900:])
    try:
        result = json.loads(process.stdout)
    except ValueError as exc:
        raise AssertionError('UNKNOWN: probe ilegible') from exc
    if type(result) is not dict or set(result) != LOCALLY_EXERCISABLE:
        raise AssertionError('UNKNOWN: resultados parciales')
    return result


def assess(docs: object, php_results: object) -> dict[str, str]:
    results = dict.fromkeys(PHRASES, UNKNOWN)
    if type(docs) is not dict or type(php_results) is not dict:
        return results
    try:
        matrix_rows(docs.get(MATRIX))
    except ValueError:
        return results
    guards = source_guards(docs)
    if not all(guards.values()) or set(php_results) != LOCALLY_EXERCISABLE:
        return results
    for key in LOCALLY_EXERCISABLE:
        states = php_results.get(key)
        if (type(states) is dict and states
                and all(type(v) is bool and v is True for v in states.values())):
            results[key] = LOCAL
    return results


class BrechasBrvtal10Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        docs, observed = read_sources(), probe()
        self.assertEqual(5, len(PHRASES))
        self.assertEqual(set(PHRASES), set(matrix_rows(docs[MATRIX])))
        self.assertTrue(all(source_guards(docs).values()))
        self.assertEqual(LOCALLY_EXERCISABLE, set(observed))
        for key, checks in observed.items():
            with self.subTest(key=key):
                self.assertTrue(checks)
                self.assertTrue(all(v is True for v in checks.values()))
        evaluated = assess(docs, observed)
        for key in PHRASES:
            with self.subTest(key=key):
                self.assertEqual(LOCAL if key in LOCALLY_EXERCISABLE else UNKNOWN,
                                 evaluated[key])

    def test_falla_cerrado(self) -> None:
        docs, observed = read_sources(), probe()
        for key in PHRASES:
            row = next(line for line in docs[MATRIX].splitlines()
                       if line.startswith('| ' + key + ' · '))
            for bad in (docs[MATRIX].replace(row, '', 1),
                        docs[MATRIX] + '\n' + row,
                        docs[MATRIX].replace(row, row.replace('NO_VERIFICABLE',
                                                            'VERIFICADO', 1), 1)):
                with self.subTest(key=key), self.assertRaisesRegex(ValueError, 'UNKNOWN'):
                    matrix_rows(bad)
                self.assertEqual({UNKNOWN}, set(assess({**docs, MATRIX: bad}, observed).values()))
        self.assertEqual({UNKNOWN}, set(assess({}, observed).values()))
        self.assertEqual({UNKNOWN}, set(assess(docs, None).values()))
        for key in LOCALLY_EXERCISABLE:
            self.assertEqual(UNKNOWN, assess(docs, {**observed, key: {}})[key])
            self.assertEqual(UNKNOWN, assess(docs, {**observed, key: {'fixture': None}})[key])
        self.assertEqual({UNKNOWN}, set(assess({**docs, SPEC: ''}, observed).values()))

    def test_sin_datos_reales(self) -> None:
        module = ast.parse(Path(__file__).read_text(encoding='utf-8'))
        imports = {name.name.split('.')[0] for node in ast.walk(module)
                   if isinstance(node, ast.Import) for name in node.names}
        imports |= {node.module.split('.')[0] for node in ast.walk(module)
                    if isinstance(node, ast.ImportFrom) and node.module}
        self.assertFalse(imports & {'requests', 'urllib', 'httpx', 'socket',
                                    'pymysql', 'mysql', 'playwright', 'selenium'})
        for token in ('PDO(', 'curl_', 'file_get_contents(', 'getenv(',
                      '$_ENV', '$_SERVER', 'api.github.com'):
            self.assertNotIn(token, PHP_PROBE)
        self.assertIn('Synthetic headline', PHP_PROBE)

    def test_estados_unknown_explicitos(self) -> None:
        docs, observed = read_sources(), probe()
        verdicts = assess(docs, observed)
        for key in ('S07-R011', 'S08-R001'):
            self.assertEqual(UNKNOWN, verdicts[key])
        self.assertEqual({UNKNOWN}, set(assess(docs, {**observed, 'production': True}).values()))
        self.assertEqual({UNKNOWN}, set(assess(docs, {**observed, 'CI': 'success'}).values()))
        self.assertEqual(UNKNOWN, assess(docs, {**observed, 'S07-R009': None})['S07-R009'])
        self.assertNotIn('VALIDATED_IN_PRODUCTION', verdicts.values())


if __name__ == '__main__':
    unittest.main()
