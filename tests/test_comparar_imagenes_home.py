#!/usr/bin/env python3
"""#1357: contract and offline PHP behavior tests for the private image picker."""
from __future__ import annotations

import pathlib
import shutil
import subprocess
import tempfile
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]
PAGE = ROOT / "discadmin" / "comparar-imagenes-home.php"


def run_php(script: str, *args: str) -> subprocess.CompletedProcess[str]:
    if shutil.which("php") is None:
        raise RuntimeError("PHP CLI required for the image picker acceptance test")
    return subprocess.run(
        ["php", "-d", "display_errors=1", "-r", script, str(PAGE), *args],
        cwd=ROOT,
        capture_output=True,
        text=True,
        timeout=30,
        check=False,
    )


class CompararImagenesHomeTests(unittest.TestCase):
    def test_criterio_principal(self) -> None:
        self.assertTrue(PAGE.is_file())
        source = PAGE.read_text(encoding="utf-8")
        for required in (
            "brvtal_admin_require();", "brvtal_admin_require_csrf();",
            "c05_picker_candidates", "c05_picker_validate_choice", "c05_picker_save",
            "America/Bogota", "1440", "390", "rights_evidence",
            "noindex,nofollow,noarchive", "draft-not-published",
        ):
            with self.subTest(required=required):
                self.assertIn(required, source)
        self.assertNotIn("UPDATE settings SET", source)
        self.assertNotIn("INSERT INTO settings", source)
        self.assertNotIn("assets/placeholders/concept05' . $name", source)
        syntax = subprocess.run(["php", "-l", str(PAGE)], capture_output=True, text=True)
        self.assertEqual(syntax.returncode, 0, syntax.stderr + syntax.stdout)
        with tempfile.TemporaryDirectory() as temporary:
            path = pathlib.Path(temporary) / "selection.json"
            php = r'''
define('BRVTAL_PICKER_LIBRARY_TEST', true);
require $argv[1];
$path = $argv[2];
$source = c05_picker_sources();
if (count($source) !== 5) throw new Exception('expected five source images');
if (c05_picker_read($path)['choices'] !== []) throw new Exception('initial choice changed');
$one = c05_picker_validate_choice('hero_face', 'source:hero-face', $source, '');
if ($one['status'] !== 'draft-not-published'
    || !str_contains($one['saved_at_colombia_12h'], 'Colombia')
    || !str_contains($one['saved_at'], '-05:00')) throw new Exception('time or draft');
c05_picker_save($path, $one);
$two = c05_picker_validate_choice('crowd', 'source:crowd', $source, '');
c05_picker_save($path, $two);
$data = c05_picker_read($path);
if (count($data['choices']) !== 2 || count($data['history']) !== 2
    || $data['choices']['hero_face']['candidate'] !== 'source:hero-face')
    throw new Exception('choices overwritten');
if (c05_picker_media_path('//evil.example/image.webp') !== null
    || c05_picker_media_path('/uploads/media/../secret.png') !== null)
    throw new Exception('unsafe media URL accepted');
echo "PICKER_ACCEPTANCE_OK";
'''
            result = run_php(php, str(path))
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertIn("PICKER_ACCEPTANCE_OK", result.stdout)
            self.assertTrue(path.exists())

    def test_fail_closed_invalid_media_and_rights(self) -> None:
        with tempfile.TemporaryDirectory() as temporary:
            path = pathlib.Path(temporary) / "selection.json"
            php = r'''
define('BRVTAL_PICKER_LIBRARY_TEST', true);
require $argv[1];
$path = $argv[2];
$items = c05_picker_sources();
$items['cms:88'] = ['url'=>'/uploads/media/2026/10/photo.webp',
 'origin'=>'cms', 'title'=>'Media', 'rights'=>'unknown'];
foreach ([['wrong','source:hero-face',''],['hero_face','cms:88',''],
 ['hero_face','cms:88','short'],['hero_face','cms:999','']] as $case) {
  try {c05_picker_validate_choice($case[0],$case[1],$items,$case[2]);}
  catch (InvalidArgumentException $e) {continue;}
  throw new Exception('invalid choice accepted');
}
$choice = c05_picker_validate_choice('hero_face','cms:88',$items,'Contrato firmado, folio 123');
c05_picker_save($path,$choice);
if (c05_picker_read($path)['choices']['hero_face']['rights_evidence']
 !== 'Contrato firmado, folio 123') throw new Exception('rights not recorded');
file_put_contents($path, '{bad json');
try {c05_picker_read($path);} catch (Throwable $e) {echo "FAIL_CLOSED_OK";exit(0);}
throw new Exception('corruption treated as empty');
'''
            result = run_php(php, str(path))
            self.assertEqual(result.returncode, 0, result.stderr)
            self.assertIn("FAIL_CLOSED_OK", result.stdout)


if __name__ == "__main__":
    unittest.main()
