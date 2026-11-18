#!/usr/bin/env python3
"""BRVTAL #1112: isolated executable evidence for five §18/§19 gaps.

Evidence of a synthetic PHP run is LOCAL only. Accessibility/UX guarantees,
PR CI, deployments and production always remain UNKNOWN here. No network/DB.
"""
from __future__ import annotations

import json
import re
import shutil
import subprocess
import unittest
from functools import lru_cache
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
FILES = {
    "spec": "docs/BRVTAL-SPEC.md",
    "media": "config/media.php",
    "media_ui": "discadmin/media-library.js",
    "page_model": "config/page_content.php",
    "page_contract": "api/pages-contract.php",
    "page_ui": "discadmin/pages-publication-contract.js",
}
EXPECTED = {
    "S18-R020": "focal-only changes regenerate crop-dependent outputs",
    "S18-R021": "missing/corrupt derivatives are repaired individually",
    "S18-R025": "Administrators should not manually prepare many versions",
    "S19-R001": "Pages are managed through BRVTAL templates",
    "S19-R002": "Do not build an Elementor/Wix-style generic page builder.",
}
IDS = tuple(EXPECTED)
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_BEHAVIOR"
PARTIAL = "LOCAL_PARTIAL"
MISSING = {
    "S18-R020": "UNKNOWN: focal regression only tested on isolated generated pixels",
    "S18-R021": "UNKNOWN: repairs only tested on isolated synthetic derivatives",
    "S18-R025": "UNKNOWN: usability criterion needs measured editor session",
    "S19-R001": "UNKNOWN: easy editor and advanced HTML lack integrated user workflow",
    "S19-R002": "UNKNOWN: cannot prove absence of a generic builder across every UI",
}

# A single synthetic source image, with distinct left/right halves, is fed to
# the REAL PHP media engine. It must commit sidecars for regeneration/reuse to
# work; merely calling the planner or asserting a source string is insufficient.
PHP_PROBE = r"""
require 'config/media.php';
require 'config/page_content.php';
require 'api/pages-contract.php';
$blocks = json_encode(['blocks' => [
    ['type'=>'heading', 'content'=>'Synthetic heading'],
    ['type'=>'paragraph', 'content'=>'Synthetic paragraph'],
]], JSON_THROW_ON_ERROR);
$embed = '{"blocks":[{"type":"embed","content":"<iframe>synthetic</iframe>"}]}';
$rawHtml = '{"blocks":[{"type":"html","content":"<p>synthetic</p>"}]}';
$pages = [
    'template_blocks' => brvtal_page_content_structure_error($blocks) === null
        && brvtal_page_content_plain_text($blocks) === "Synthetic heading\n\nSynthetic paragraph"
        && brvtal_page_publication_error(['status'=>'published', 'locale'=>'en', 'content_json'=>$blocks]) === null,
    'reject_generic' => brvtal_page_content_structure_error($embed) === 'PAGE_CONTENT_STRUCTURE_UNSUPPORTED'
        && brvtal_page_content_structure_error($rawHtml) === 'PAGE_CONTENT_STRUCTURE_UNSUPPORTED'
        && brvtal_page_content_plain_text($embed) === ''
        && brvtal_page_publication_error(['status'=>'published', 'locale'=>'en', 'content_json'=>$embed])
           === 'PAGE_CONTENT_STRUCTURE_UNSUPPORTED',
];
$gd = extension_loaded('gd') && function_exists('imagepng') && function_exists('imagewebp');
$media = ['focal'=>false,'corrupt'=>false,'missing'=>false,'single_source'=>false];
if ($gd) {
    $root = brvtal_media_upload_root();
    if (!is_dir($root)) { throw new RuntimeException('UNKNOWN: upload sandbox root absent'); }
    $dir = $root . '/synthetic-br1112-' . bin2hex(random_bytes(8));
    if (!mkdir($dir, 0700)) { throw new RuntimeException('UNKNOWN: fixture directory'); }
    try {
        $source = $dir . '/source.png';
        $image = imagecreatetruecolor(2000, 1125);
        if (!$image) { throw new RuntimeException('UNKNOWN: synthetic GD image'); }
        $left = imagecolorallocate($image, 235, 22, 35);
        $right = imagecolorallocate($image, 12, 44, 223);
        imagefilledrectangle($image, 0, 0, 999, 1124, $left);
        imagefilledrectangle($image, 1000, 0, 1999, 1124, $right);
        if (!imagepng($image, $source)) { throw new RuntimeException('UNKNOWN: synthetic PNG write'); }
        imagedestroy($image);
        $originalHash = hash_file('sha256', $source);
        $specs = brvtalMediaVariantSpecs($source, 'image/png', 2000, 1125);
        $first = brvtalMediaGenerateVariants($source, 'image/png', ['x'=>0.15,'y'=>0.5]);
        $okFirst = ($first['status'] ?? '') === 'ready' && brvtalMediaStoreSidecar($source, $first);
        $square = (string)($specs['square']['absolute_path'] ?? '');
        $card = (string)($specs['card']['absolute_path'] ?? '');
        $hero = (string)($specs['hero']['absolute_path'] ?? '');
        $display = (string)($specs['display']['absolute_path'] ?? '');
        $wide = (string)($specs['w1280']['absolute_path'] ?? '');
        $initialSquare = is_file($square) ? hash_file('sha256', $square) : null;
        $initialDisplay = is_file($display) ? hash_file('sha256', $display) : null;
        $initialWide = is_file($wide) ? hash_file('sha256', $wide) : null;
        $generated = (array)($first['optimization']['generated'] ?? []);
        $media['single_source'] = $okFirst && count($generated) === 5
            && ($first['optimization']['physical_variant_count'] ?? 0) === 5
            && ($first['optimization']['logical_variant_count'] ?? 0) === 6
            && count(array_filter(glob($dir . '/*.png') ?: [], 'is_file')) === 1
            && hash_file('sha256', $source) === $originalHash;

        $second = brvtalMediaGenerateVariants($source, 'image/png', ['x'=>0.85,'y'=>0.5]);
        $okSecond = ($second['status'] ?? '') === 'ready' && brvtalMediaStoreSidecar($source, $second);
        $regenerated = (array)($second['optimization']['generated'] ?? []);
        $reused = (array)($second['optimization']['reused'] ?? []);
        $media['focal'] = $okFirst && $okSecond
            && count(array_intersect(['square','card','hero'], $regenerated)) === 3
            && count(array_intersect(['display','w1280'], $reused)) === 2
            && $initialSquare !== null && is_file($square)
            && hash_file('sha256', $square) !== $initialSquare
            && $initialDisplay !== null && hash_file('sha256', $display) === $initialDisplay
            && $initialWide !== null && hash_file('sha256', $wide) === $initialWide
            && hash_file('sha256', $source) === $originalHash;

        if (!is_file($card) || file_put_contents($card, 'corrupt synthetic derivative') === false) {
            throw new RuntimeException('UNKNOWN: corruption fixture');
        }
        clearstatcache(true, $card);
        $third = brvtalMediaGenerateVariants($source, 'image/png', ['x'=>0.85,'y'=>0.5]);
        $okThird = ($third['status'] ?? '') === 'ready' && brvtalMediaStoreSidecar($source, $third);
        $media['corrupt'] = $okThird && ($third['optimization']['repaired'] ?? []) === ['card']
            && in_array('display', $third['optimization']['reused'] ?? [], true)
            && brvtalMediaVariantFileValid($specs['card'])
            && hash_file('sha256', $source) === $originalHash;

        if (!is_file($hero) || !unlink($hero)) {
            throw new RuntimeException('UNKNOWN: missing fixture');
        }
        clearstatcache(true, $hero);
        $fourth = brvtalMediaGenerateVariants($source, 'image/png', ['x'=>0.85,'y'=>0.5]);
        $okFourth = ($fourth['status'] ?? '') === 'ready' && brvtalMediaStoreSidecar($source, $fourth);
        $media['missing'] = $okFourth && ($fourth['optimization']['repaired'] ?? []) === ['hero']
            && in_array('card', $fourth['optimization']['reused'] ?? [], true)
            && brvtalMediaVariantFileValid($specs['hero'])
            && hash_file('sha256', $source) === $originalHash;
    } finally {
        foreach (glob($dir . '/*') ?: [] as $file) {
            if (is_file($file) || is_link($file)) { @unlink($file); }
        }
        @rmdir($dir);
    }
}
echo json_encode(['gd'=>$gd,'media'=>$media,'pages'=>$pages], JSON_THROW_ON_ERROR);
"""


def coverage_rows(raw: str) -> dict[str, tuple[str, ...]]:
    result: dict[str, tuple[str, ...]] = {}
    for line in raw.splitlines():
        match = re.match(r"^\| (S(?:18|19)-R\d{3}) · ", line)
        if not match or match.group(1) not in IDS:
            continue
        key = match.group(1)
        if key in result:
            raise ValueError("UNKNOWN: duplicate " + key)
        cells = tuple(cell.strip() for cell in line.strip().strip("|").split("|"))
        if len(cells) != 5 or not cells[0].startswith(key + " · "):
            raise ValueError("UNKNOWN: malformed " + key)
        result[key] = cells
    return result


def sources() -> dict[str, str]:
    return {name: (ROOT / path).read_text(encoding="utf-8")
            for name, path in FILES.items()}


@lru_cache(maxsize=1)
def probe() -> dict[str, object]:
    php = shutil.which("php")
    if php is None:
        raise AssertionError("UNKNOWN: local PHP CLI unavailable")
    run = subprocess.run([php, "-r", PHP_PROBE], cwd=ROOT,
                         capture_output=True, text=True, timeout=60,
                         env={"PATH": "/usr/local/bin:/usr/bin:/bin"}, check=False)
    if run.returncode != 0:
        raise AssertionError("UNKNOWN: synthetic PHP probe: " + run.stderr[-500:])
    try:
        parsed = json.loads(run.stdout)
    except (ValueError, TypeError) as exc:
        raise AssertionError("UNKNOWN: invalid JSON probe evidence") from exc
    if not isinstance(parsed, dict):
        raise AssertionError("UNKNOWN: non-object probe evidence")
    return parsed


def verdict(key: str, table: dict[str, tuple[str, ...]],
            code: dict[str, str], evidence: object) -> dict[str, str]:
    value = {"candidate": UNKNOWN, "full_scope": UNKNOWN,
             "ci": UNKNOWN, "production": UNKNOWN,
             "missing": "UNKNOWN: source or behavioral evidence missing"}
    row = table.get(key)
    if (key not in IDS or row is None or len(row) != 5
            or not row[1].startswith(EXPECTED[key]) or row[2] != "NO_VERIFICABLE"
            or not isinstance(evidence, dict)
            or any(not isinstance(code.get(name), str) or not code[name]
                   for name in FILES)):
        return value
    if ("## 18. Media Engine" not in code["spec"]
            or "## 19. Pages" not in code["spec"]
            or "function brvtalMediaGenerateVariants" not in code["media"]
            or "function brvtal_page_content_structure_error" not in code["page_model"]
            or "function brvtal_page_publication_error" not in code["page_contract"]):
        return value
    media, pages = evidence.get("media"), evidence.get("pages")
    if not isinstance(media, dict) or not isinstance(pages, dict):
        return value
    if key == "S18-R020" and evidence.get("gd") is True and media.get("focal") is True:
        value["candidate"] = LOCAL
    elif key == "S18-R021" and evidence.get("gd") is True and media.get("corrupt") is True and media.get("missing") is True:
        value["candidate"] = LOCAL
    elif key == "S18-R025" and evidence.get("gd") is True and media.get("single_source") is True and "uploadFiles(files)" in code["media_ui"]:
        value["candidate"] = PARTIAL
    elif key == "S19-R001" and pages.get("template_blocks") is True and "text · paragraph · heading blocks" in code["page_ui"]:
        value["candidate"] = PARTIAL
    elif key == "S19-R002" and pages.get("reject_generic") is True and "PAGE_CONTENT_STRUCTURE_UNSUPPORTED" in code["page_model"]:
        value["candidate"] = PARTIAL
    if value["candidate"] != UNKNOWN:
        value["missing"] = MISSING[key]
    return value


class BrechasBrvtal22Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.table = coverage_rows(MATRIX.read_text(encoding="utf-8"))
        cls.code = sources()
        cls.evidence = probe()

    def test_contrato_principal(self) -> None:
        self.assertEqual(set(self.table), set(IDS))
        for key in IDS:
            with self.subTest(row=key):
                result = verdict(key, self.table, self.code, self.evidence)
                expected = (UNKNOWN if not self.evidence["gd"] and key.startswith("S18")
                            else LOCAL if key in ("S18-R020", "S18-R021") else PARTIAL)
                self.assertEqual(expected, result["candidate"], key)
                self.assertEqual("NO_VERIFICABLE", self.table[key][2])

    def test_falla_cerrado(self) -> None:
        original = MATRIX.read_text(encoding="utf-8")
        sample = next(line for line in original.splitlines()
                      if line.startswith("| S18-R020 · "))
        with self.assertRaisesRegex(ValueError, "duplicate"):
            coverage_rows(original + "\n" + sample)
        with self.assertRaisesRegex(ValueError, "malformed"):
            coverage_rows("| S18-R020 · only one cell |")
        for key in IDS:
            with self.subTest(row=key):
                self.assertEqual(UNKNOWN, verdict(key, {}, self.code, self.evidence)["candidate"])
                self.assertEqual(UNKNOWN, verdict(key, self.table, {}, self.evidence)["candidate"])
                self.assertEqual(UNKNOWN, verdict(key, self.table, self.code, None)["candidate"])
                self.assertEqual(UNKNOWN, verdict(key, self.table, self.code, {})["candidate"])
                wrong = dict(self.table)
                row = wrong[key]
                wrong[key] = (*row[:2], "CUBIERTO", *row[3:])
                self.assertEqual(UNKNOWN, verdict(key, wrong, self.code, self.evidence)["candidate"])
                missing = {**self.code, "spec": ""}
                self.assertEqual(UNKNOWN, verdict(key, self.table, missing, self.evidence)["candidate"])
        for key, field in (("S18-R020", "focal"), ("S18-R021", "corrupt"),
                           ("S18-R021", "missing"), ("S18-R025", "single_source")):
            evidence = {**self.evidence, "media": {**self.evidence["media"], field: False}}
            self.assertEqual(UNKNOWN, verdict(key, self.table, self.code, evidence)["candidate"])
        for key, field in (("S19-R001", "template_blocks"), ("S19-R002", "reject_generic")):
            evidence = {**self.evidence, "pages": {**self.evidence["pages"], field: False}}
            self.assertEqual(UNKNOWN, verdict(key, self.table, self.code, evidence)["candidate"])

    def test_sin_datos_reales(self) -> None:
        own = Path(__file__).read_text(encoding="utf-8")
        self.assertIn("synthetic-br1112-", PHP_PROBE)
        self.assertNotRegex(own, r"(?m)^(?:import|from)\s+(?:requests|httpx|socket|urllib|pymysql)\b")
        self.assertNotIn("-----BEGIN " + "PRIVATE KEY-----", own)
        self.assertNotIn("curl_" + "exec(", own)
        self.assertNotIn("PDO" + "(", PHP_PROBE)
        self.assertTrue(all(path.startswith(("docs/", "api/", "config/", "discadmin/"))
                            for path in FILES.values()))

    def test_estados_unknown_explicitos(self) -> None:
        for key in IDS:
            with self.subTest(row=key):
                result = verdict(key, self.table, self.code, self.evidence)
                for external in ("full_scope", "ci", "production"):
                    self.assertEqual(UNKNOWN, result[external])
                self.assertTrue(result["missing"].startswith("UNKNOWN:"))
                self.assertEqual(UNKNOWN, verdict(key, {}, self.code, self.evidence)["candidate"])
        if not self.evidence["gd"]:
            for key in ("S18-R020", "S18-R021", "S18-R025"):
                self.assertEqual(UNKNOWN, verdict(key, self.table, self.code, self.evidence)["candidate"])


if __name__ == "__main__":
    unittest.main()