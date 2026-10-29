#!/usr/bin/env python3
"""BRVTAL #1111: contratos sintéticos y fail-closed de Media Engine §18.

No acredita CI, despliegue, navegador ni operación de medios en producción.
Solo ejecuta funciones PHP versionadas sobre una imagen temporal aislada.
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
SPEC = ROOT / "docs/BRVTAL-SPEC.md"
CODE = ROOT / "config/media.php"
UI = ROOT / "discadmin/media-library.js"
IDS = ("S18-R012", "S18-R014", "S18-R015", "S18-R016", "S18-R017")
EXPECTED = {
    "S18-R012": "square, card and hero crop previews;",
    "S18-R014": "per-context quality/resolution guidance;",
    "S18-R015": "safe regeneration that preserves originals and removes superseded derived files.",
    "S18-R016": "Media Engine v3 keeps the same public delivery keys",
    "S18-R017": "source originals remain authoritative and receive a stable SHA-256/byte-size identity",
}
KEYS = {"square", "card", "hero", "display", "w1280", "w1920"}
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_CONTRACT"

PHP_PROBE = r"""
require 'config/media.php';
$root = brvtal_media_upload_root();
$dir = $root . '/synthetic-br1111-' . bin2hex(random_bytes(6));
if (!is_dir($root) || !mkdir($dir, 0700)) {
    throw new RuntimeException('UNKNOWN: isolated fixture unavailable');
}
try {
    $original = $dir . '/source.png';
    $gd = extension_loaded('gd') && function_exists('imagepng')
        && function_exists('imagewebp');
    if ($gd) {
        $image = imagecreatetruecolor(2000, 1125);
        $red = imagecolorallocate($image, 220, 25, 45);
        $blue = imagecolorallocate($image, 15, 25, 220);
        imagefilledrectangle($image, 0, 0, 999, 1124, $red);
        imagefilledrectangle($image, 1000, 0, 1999, 1124, $blue);
        imagepng($image, $original);
        imagedestroy($image);
    } else {
        // Valid, synthetic one-pixel PNG for metadata-only fail-closed probes.
        file_put_contents($original, base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII='
        ));
    }
    $dims = getimagesize($original);
    if (!is_array($dims)) { throw new RuntimeException('UNKNOWN: image'); }
    $width = (int)$dims[0];
    $height = (int)$dims[1];
    $specs = brvtalMediaVariantSpecs($original, 'image/png', 2000, 1125);
    $quality = brvtal_media_quality_guidance(2000, 1125);
    $dimensions = array_map(static fn($variant) => [
        'width' => $variant['width'], 'height' => $variant['height']
    ], $specs);
    $limited = brvtal_media_quality_guidance(640, 480);
    $before = brvtalMediaOriginalIdentity($original, 'image/png', $width, $height);
    $raw = file_get_contents($original);
    $matchesBytes = is_string($raw)
        && ($before['sha256'] ?? null) === hash('sha256', $raw)
        && ($before['bytes'] ?? -1) === strlen($raw);

    $generation = null;
    $keyParity = null;
    $sidecar = ['original' => $before];
    if ($gd) {
        $first = brvtalMediaGenerateVariants($original, 'image/png', ['x'=>0.1,'y'=>0.5]);
        $firstOk = ($first['status'] ?? '') === 'ready'
            && brvtalMediaStoreSidecar($original, $first);
        $savedFirst = brvtal_media_read_sidecar((string)$before['path']);
        $second = brvtalMediaGenerateVariants($original, 'image/png', ['x'=>0.9,'y'=>0.5]);
        $secondOk = ($second['status'] ?? '') === 'ready'
            && brvtalMediaStoreSidecar($original, $second);
        $sidecar = brvtal_media_read_sidecar((string)$before['path']) ?? [];
        // Verify cleanup of a superseded derivative without deleting the source.
        $stalePath = $dir . '/source--superseded.webp';
        file_put_contents($stalePath, 'superseded synthetic derivative');
        $oldVersion = is_array($savedFirst) ? $savedFirst : ['variants'=>[]];
        $oldVersion['variants']['obsolete'] = [
            'path' => brvtal_media_public_upload_path($stalePath),
        ];
        brvtalMediaRemoveGeneratedVariants(
            $original, array_values($sidecar['variants'] ?? []), $oldVersion
        );
        $obsoleteGone = !is_file($stalePath);
        $afterRegeneration = file_get_contents($original);
        $generation = $firstOk && $secondOk && $obsoleteGone
            && $afterRegeneration === $raw
            && ($second['optimization']['generated'] ?? []) !== []
            && in_array('square', $second['optimization']['generated'] ?? [], true)
            && in_array('display', $second['optimization']['reused'] ?? [], true)
            && ($sidecar['original']['sha256'] ?? '') === hash('sha256', $raw)
            && brvtalMediaOriginalMatches($sidecar, $before)
            && ($savedFirst['original']['sha256'] ?? '') === hash('sha256', $raw);
        $fullKeyPaths = true;
        foreach (array_keys($specs) as $key) {
            $expectedPath = $specs[$key]['path'];
            $fullKeyPaths = $fullKeyPaths
                && ($first['variants'][$key]['path'] ?? null) === $expectedPath
                && ($second['variants'][$key]['path'] ?? null) === $expectedPath;
        }
        $keyParity = $firstOk && $secondOk && $fullKeyPaths
            && array_keys($first['variants']) === array_keys($specs)
            && array_keys($second['variants']) === array_keys($specs)
            && ($second['variants']['w1920']['alias_of'] ?? '') === 'display'
            && ($second['variants']['w1920']['path'] ?? '') === ($second['variants']['display']['path'] ?? '');
    } else {
        if (!brvtalMediaStoreSidecar($original, $sidecar)) {
            throw new RuntimeException('UNKNOWN: sidecar store');
        }
        $sidecar = brvtal_media_read_sidecar((string)$before['path']) ?? [];
    }
    $sidecarMatches = ($sidecar['original']['sha256'] ?? '') === hash('sha256', $raw)
        && ($sidecar['original']['bytes'] ?? -1) === strlen($raw);
    file_put_contents($original, 'source-mutation', FILE_APPEND);
    clearstatcache(true, $original);
    $changed = brvtalMediaOriginalIdentity($original, 'image/png', $width, $height);
    $rejectsStale = !brvtalMediaOriginalMatches($sidecar, $changed)
        && ($changed['sha256'] ?? '') === hash_file('sha256', $original)
        && ($changed['bytes'] ?? -1) === strlen($raw) + strlen('source-mutation')
        && $changed['sha256'] !== $before['sha256'];

    echo json_encode([
        'gd' => $gd,
        'keys' => array_keys($specs),
        'crop' => array_map(static fn($s) => $s['crop'] ?? null, $specs),
        'dimensions' => $dimensions,
        'paths' => array_map(static fn($s) => $s['path'] ?? null, $specs),
        'alias' => $specs['w1920']['alias_of'] ?? null,
        'quality' => $quality,
        'limited' => $limited,
        'generation' => $generation,
        'key_parity' => $keyParity,
        'identity' => $matchesBytes && $sidecarMatches && $rejectsStale,
    ], JSON_THROW_ON_ERROR);
} finally {
    foreach (glob($dir . '/*') ?: [] as $path) {
        if (is_file($path) || is_link($path)) { @unlink($path); }
    }
    @rmdir($dir);
}
"""


def rows(raw: str) -> dict[str, tuple[str, ...]]:
    """La fila duplicada, malformada o ausente nunca demuestra cobertura."""
    found: dict[str, tuple[str, ...]] = {}
    for line in raw.splitlines():
        match = re.match(r"^\| (S18-R\d{3}) · ", line)
        if not match or match.group(1) not in IDS:
            continue
        identifier = match.group(1)
        if identifier in found:
            raise ValueError("UNKNOWN: duplicate " + identifier)
        cells = tuple(value.strip() for value in line.strip("|").split("|"))
        if len(cells) != 5 or not cells[0].startswith(identifier + " · "):
            raise ValueError("UNKNOWN: malformed " + identifier)
        found[identifier] = cells
    return found


@lru_cache(maxsize=1)
def probe() -> dict[str, object]:
    php = shutil.which("php")
    if php is None:
        raise AssertionError("UNKNOWN: PHP CLI unavailable")
    run = subprocess.run(
        [php, "-r", PHP_PROBE], cwd=ROOT, capture_output=True,
        text=True, timeout=50, check=False,
        env={"PATH": "/usr/local/bin:/usr/bin:/bin"},
    )
    if run.returncode != 0:
        raise AssertionError("UNKNOWN: isolated PHP probe: " + run.stderr[-500:])
    try:
        value = json.loads(run.stdout)
    except (ValueError, TypeError) as exc:
        raise AssertionError("UNKNOWN: invalid PHP probe JSON") from exc
    if not isinstance(value, dict):
        raise AssertionError("UNKNOWN: non-object PHP evidence")
    return value


def verdict(identifier: str, table: dict[str, tuple[str, ...]],
            sources: dict[str, str], evidence: object) -> dict[str, str]:
    state = {"code": UNKNOWN, "ci": UNKNOWN, "production": UNKNOWN}
    row = table.get(identifier)
    if (identifier not in IDS or row is None or len(row) != 5
            or not row[1].startswith(EXPECTED[identifier])
            or row[2] != "NO_VERIFICABLE"
            or not isinstance(evidence, dict)
            or not all(isinstance(sources.get(k), str) and sources[k]
                       for k in ("spec", "code", "ui"))):
        return state
    spec, code, ui = (sources[key] for key in ("spec", "code", "ui"))
    if ("## 18. Media Engine" not in spec
            or "brvtalMediaGenerateVariants" not in code
            or "brvtalMediaOriginalIdentity" not in code
            or "quality" not in ui):
        return state
    if identifier == "S18-R012":
        paths = evidence.get("paths")
        crops = evidence.get("crop")
        dimensions = evidence.get("dimensions")
        if (isinstance(evidence.get("keys"), list)
                and set(evidence["keys"]) == KEYS
                and isinstance(paths, dict) and isinstance(crops, dict)
                and isinstance(dimensions, dict)
                and all(dimensions.get(k) == {"width": size[0], "height": size[1]}
                        for k, size in {"square": (800, 800), "card": (1200, 900),
                                        "hero": (1920, 1080)}.items())
                and all(crops.get(k) is True and isinstance(paths.get(k), str)
                        and paths[k].endswith(".webp") for k in ("square", "card", "hero"))
                and len({paths[k] for k in ("square", "card", "hero")}) == 3):
            state["code"] = LOCAL
    elif identifier == "S18-R014":
        quality, limited = evidence.get("quality"), evidence.get("limited")
        if isinstance(quality, dict) and isinstance(limited, dict):
            a, b = quality.get("contexts"), limited.get("contexts")
            if (isinstance(a, dict) and isinstance(b, dict)
                    and set(a) == {"square", "card", "hero"}
                    and set(b) == set(a)
                    and all(isinstance(a[k], dict) and isinstance(b[k], dict)
                            and isinstance(a[k].get("width"), int)
                            and isinstance(a[k].get("height"), int)
                            and isinstance(a[k].get("label"), str)
                            and a[k].get("ready") is True
                            and b[k].get("ready") is False for k in a)
                    and quality.get("grade") == "excellent"
                    and limited.get("grade") == "limited"):
                state["code"] = LOCAL
    elif identifier == "S18-R015":
        if evidence.get("gd") is True and evidence.get("generation") is True:
            state["code"] = LOCAL
    elif identifier == "S18-R016":
        if (isinstance(evidence.get("keys"), list)
                and set(evidence["keys"]) == KEYS
                and evidence.get("alias") == "display"
                and evidence.get("gd") is True
                and evidence.get("key_parity") is True):
            state["code"] = LOCAL
    elif identifier == "S18-R017":
        if evidence.get("identity") is True:
            state["code"] = LOCAL
    return state


class BrechasBrvtal21Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.table = rows(MATRIX.read_text(encoding="utf-8"))
        cls.sources = {
            "spec": SPEC.read_text(encoding="utf-8"),
            "code": CODE.read_text(encoding="utf-8"),
            "ui": UI.read_text(encoding="utf-8"),
        }
        cls.evidence = probe()

    def test_contrato_principal(self) -> None:
        self.assertEqual(set(self.table), set(IDS))
        for identifier in IDS:
            with self.subTest(identifier=identifier):
                actual = verdict(identifier, self.table, self.sources, self.evidence)
                if identifier in ("S18-R015", "S18-R016") and not self.evidence["gd"]:
                    self.assertEqual(UNKNOWN, actual["code"], "no GD/WebP: fail closed")
                else:
                    self.assertEqual(LOCAL, actual["code"], identifier)

    def test_falla_cerrado(self) -> None:
        original = MATRIX.read_text(encoding="utf-8")
        first = next(line for line in original.splitlines()
                     if line.startswith("| S18-R012 · "))
        with self.assertRaisesRegex(ValueError, "duplicate"):
            rows(original + "\n" + first)
        with self.assertRaisesRegex(ValueError, "malformed"):
            rows("| S18-R012 · missing |")
        for identifier in IDS:
            with self.subTest(identifier=identifier):
                self.assertEqual(UNKNOWN, verdict(
                    identifier, {}, self.sources, self.evidence)["code"])
                self.assertEqual(UNKNOWN, verdict(
                    identifier, self.table, {}, self.evidence)["code"])
                self.assertEqual(UNKNOWN, verdict(
                    identifier, self.table, self.sources, None)["code"])
                self.assertEqual(UNKNOWN, verdict(
                    identifier, self.table, self.sources, {})["code"])
        malformed_keys = {**self.evidence, "keys": None}
        self.assertEqual(UNKNOWN, verdict(
            "S18-R012", self.table, self.sources, malformed_keys)["code"])
        self.assertEqual(UNKNOWN, verdict(
            "S18-R016", self.table, self.sources, malformed_keys)["code"])
        bad_contexts = {**self.evidence, "quality": {
            "grade": "excellent", "contexts": {
                "square": None, "card": {}, "hero": {}
            }}}
        self.assertEqual(UNKNOWN, verdict(
            "S18-R014", self.table, self.sources, bad_contexts)["code"])
        wrong_context = {**self.evidence, "dimensions": {
            **self.evidence["dimensions"], "hero": {"width": 1200, "height": 900}
        }}
        self.assertEqual(UNKNOWN, verdict(
            "S18-R012", self.table, self.sources, wrong_context)["code"])
        compromised = dict(self.evidence)
        compromised["identity"] = False
        self.assertEqual(UNKNOWN, verdict(
            "S18-R017", self.table, self.sources, compromised)["code"])
        for key, identifier in (("generation", "S18-R015"),
                                ("key_parity", "S18-R016")):
            compromised = {**self.evidence, "gd": True, key: False}
            self.assertEqual(UNKNOWN, verdict(
                identifier, self.table, self.sources, compromised)["code"])

    def test_sin_datos_reales(self) -> None:
        own = Path(__file__).read_text(encoding="utf-8")
        self.assertIn("synthetic-br1111-", PHP_PROBE)
        self.assertNotRegex(own, r"(?m)^(?:import|from)\s+(?:requests|httpx|socket|urllib)\b")
        self.assertNotIn("PDO(", PHP_PROBE)
        self.assertNotIn("curl_exec(", PHP_PROBE)
        self.assertNotIn("BEGIN " + "PRIVATE KEY", own)
        self.assertTrue(self.evidence["identity"])

    def test_estados_unknown_explicitos(self) -> None:
        for identifier in IDS:
            with self.subTest(identifier=identifier):
                result = verdict(identifier, self.table, self.sources, self.evidence)
                self.assertEqual(UNKNOWN, result["ci"])
                self.assertEqual(UNKNOWN, result["production"])
                self.assertEqual(UNKNOWN, verdict(
                    identifier, {}, self.sources, self.evidence)["code"])
        if not self.evidence["gd"]:
            for identifier in ("S18-R015", "S18-R016"):
                self.assertEqual(UNKNOWN, verdict(
                    identifier, self.table, self.sources, self.evidence)["code"])


if __name__ == "__main__":
    unittest.main()
