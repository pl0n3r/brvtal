#!/usr/bin/env python3
"""BRVTAL #1275: real offline behavior of Media, Pages, and public preview.

Database/browser/production-only rows and non-executable policies are classified
B or C in the Issue/PR, not deceptively marked as covered by local tests.
"""
from __future__ import annotations

import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]

# All calls below execute actual BRVTAL PHP functions with synthetic input.
# An ASCII-only fallback keeps a bare CLI without mbstring testable.
PHP_PROBE = r"""
declare(strict_types=1);
if (!function_exists('mb_substr')) {
    function mb_substr(string $s, int $start, ?int $length = null): string {
        return substr($s, $start, $length);
    }
}
require_once 'config/media.php';
require_once 'config/page_content.php';
require_once 'api/pages-contract.php';
require_once 'config/public_preview.php';

$assert = static function (bool $ok, string $why): void {
    if (!$ok) { fwrite(STDERR, "FAIL: ".$why); exit(2); }
};
$reject = static function (callable $action, string $why) use ($assert): void {
    try { $action(); } catch (InvalidArgumentException $e) { return; }
    $assert(false, $why);
};
$mode = $argv[1] ?? '';
$covered = [];
if ($mode === 'main') {
    // S18-R012/R014: independent real variant specs and context guidance.
    $q = brvtal_media_quality_guidance(1300, 1000);
    $assert($q['grade'] === 'good', 'quality grade');
    $assert(array_keys($q['contexts']) === ['square','card','hero'],
        'independent context identities');
    $assert($q['contexts']['square']['ready'] && $q['contexts']['card']['ready']
        && !$q['contexts']['hero']['ready'], 'different resolution readiness');
    $covered[] = 'S18-R014';

    $dir = brvtal_media_upload_root().'/coverage-1275-'.bin2hex(random_bytes(6));
    $assert(@mkdir($dir, 0750, true) || is_dir($dir), 'fixture directory');
    register_shutdown_function(static function () use ($dir): void {
        foreach (glob($dir.'/*') ?: [] as $file) {
            if (is_file($file) || is_link($file)) { @unlink($file); }
        }
        @rmdir($dir);
    });
    $source = $dir.'/source.png';
    $fixture = base64_decode(
        'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jpO0AAAAASUVORK5CYII=',
        true
    );
    $assert(is_string($fixture) && file_put_contents($source, $fixture) !== false,
        'synthetic source');

    // S18-R016: test the whole real delivery key set, not just one alias.
    $spec = brvtalMediaVariantSpecs($source, 'image/png', 2400, 1350);
    $assert(array_keys($spec) === [
        'square','card','hero','display','w1280','w1920'
    ], 'complete delivery keys');
    $assert($spec['square']['crop'] && $spec['card']['crop']
        && $spec['hero']['crop'] && !$spec['display']['crop']
        && !$spec['w1280']['crop'], 'focal-specific variants');
    $assert($spec['square']['path'] !== $spec['card']['path']
        && $spec['card']['path'] !== $spec['hero']['path'], 'distinct crop targets');
    $assert(($spec['w1920']['alias_of'] ?? '') === 'display'
        && $spec['w1920']['path'] === $spec['display']['path'], 'w1920 alias');
    $covered[] = 'S18-R012';
    $covered[] = 'S18-R016';

    // S18-R017: original SHA/byte-size come from real bytes in the sidecar.
    $original = brvtalMediaOriginalIdentity($source, 'image/png', 1, 1);
    $assert($original['sha256'] === hash_file('sha256', $source)
        && $original['bytes'] === strlen($fixture), 'original fingerprint');
    $assert(brvtalMediaStoreSidecar($source, [
        'version'=>3,'status'=>'original_only',
        'original'=>$original,'variants'=>[]
    ]), 'sidecar commit');
    $sidecar = brvtal_media_read_sidecar((string)$original['path']);
    $assert(($sidecar['original']['sha256'] ?? '') === $original['sha256']
        && ($sidecar['original']['bytes'] ?? -1) === strlen($fixture),
        'original hash and bytes in physical sidecar');
    file_put_contents($source, 'changed', FILE_APPEND);
    clearstatcache(true, $source);
    $new = brvtalMediaOriginalIdentity($source, 'image/png', 1, 1);
    $assert($new['sha256'] !== $original['sha256']
        && $new['bytes'] === strlen($fixture)+7, 'original change invalidates hash');
    $covered[] = 'S18-R017';

    // GD path: actual pixel decoding, WebP variants, focal changes and repair.
    // If CI lacks GD/WebP the full image regeneration remains B, not claimed A.
    if (function_exists('imagecreatetruecolor') && function_exists('imagewebp')) {
        $actual = $dir.'/render.png';
        $image = imagecreatetruecolor(320, 180);
        $red = imagecolorallocate($image, 240, 40, 35);
        $blue = imagecolorallocate($image, 30, 90, 215);
        imagefilledrectangle($image, 0, 0, 159, 179, $red);
        imagefilledrectangle($image, 160, 0, 319, 179, $blue);
        $assert(imagepng($image, $actual), 'real PNG pixels');
        imagedestroy($image);
        $hash = hash_file('sha256', $actual);
        $first = brvtalMediaGenerateVariants($actual, 'image/png');
        $assert(isset($first['variants']['square'], $first['variants']['card'],
            $first['variants']['hero'], $first['variants']['display']),
            'actual WebP generation');
        $assert(brvtalMediaStoreSidecar($actual, $first), 'WebP commit');
        $targets = brvtalMediaVariantSpecs($actual, 'image/png', 320, 180);
        $assert(brvtalMediaVariantFileValid($targets['square']), 'valid square');
        $displayHash = hash_file('sha256', $targets['display']['absolute_path']);
        $focal = brvtalMediaGenerateVariants(
            $actual, 'image/png', ['x'=>0.05,'y'=>0.9]
        );
        $assert(in_array('square', $focal['optimization']['generated'], true)
            && in_array('display', $focal['optimization']['reused'], true),
            'focal only re-generates crops');
        $assert(brvtalMediaStoreSidecar($actual, $focal), 'focal commit');
        $assert(hash_file('sha256', $targets['display']['absolute_path'])
            === $displayHash, 'preserve-aspect bytes unchanged');
        file_put_contents($targets['card']['absolute_path'], 'corrupt');
        clearstatcache(true, $targets['card']['absolute_path']);
        $assert(!brvtalMediaVariantFileValid($targets['card']), 'detect corrupt WebP');
        $repair = brvtalMediaGenerateVariants(
            $actual, 'image/png', ['x'=>0.05,'y'=>0.9]
        );
        $assert(in_array('card', $repair['optimization']['repaired'], true)
            && in_array('display', $repair['optimization']['reused'], true),
            'individual repair');
        $assert(brvtalMediaStoreSidecar($actual, $repair), 'repair commit');
        $assert(brvtalMediaVariantFileValid($targets['card']), 'repaired WebP');
        $assert(hash_file('sha256', $actual) === $hash, 'original is immutable');
        $covered = array_merge($covered, ['S18-R015','S18-R020','S18-R021']);
    }

    // S19-R001: real Pages structure, text projection and publish guard.
    $blocks = '{"blocks":[{"type":"heading","content":"BRVTAL"},'
        .'{"type":"paragraph","content":"Synthetic content"}]}';
    $assert(brvtal_page_content_structure_error($blocks) === null,
        'valid page blocks');
    $assert(brvtal_page_content_plain_text($blocks) ===
        "BRVTAL\n\nSynthetic content", 'page text projection');
    $assert(brvtal_page_publication_error([
        'status'=>'published','locale'=>'en','content_json'=>$blocks
    ]) === null, 'supported page can publish');
    $covered[] = 'S19-R001';

    // S20-R012: the six public draft types exercise the actual allowlist.
    $types = brvtalPublicPreviewTypes();
    $assert($types === ['events','artists','sets','releases','blog','pages'],
        'canonical preview types');
    foreach ($types as $type) {
        $payload = ['title'=>'Example', 'name'=>'Synthetic Artist',
            'status'=>'draft', 'untrusted_token'=>'remove-me'];
        if ($type === 'pages') { $payload['content_json'] = $blocks; }
        $snapshot = brvtalPublicPreviewSnapshot($type, $payload);
        $assert($snapshot['type'] === $type
            && ($snapshot['payload']['status'] ?? '') === 'draft'
            && !array_key_exists('untrusted_token', $snapshot['payload']),
            'bounded draft '.$type);
    }
    $covered[] = 'S20-R012';

    // S20-R013: actual session token, bounded lifetime, no public insert.
    $snapshot = brvtalPublicPreviewSnapshot('blog', [
        'title'=>'Draft', 'status'=>'draft', 'body'=>'Synthetic text'
    ]);
    $stored = brvtalPublicPreviewStore($snapshot);
    $assert(preg_match('/^[a-f0-9]{48}$/D', $stored['token']) === 1
        && $stored['url'] === '/preview/'.$stored['token'], 'opaque preview URL');
    $assert(brvtalPublicPreviewLoad($stored['token']) === $snapshot,
        'session-only payload');
    $assert(brvtalPublicPreviewLoad('wrong') === null, 'invalid token');
    $assert($stored['expires_at'] > time()
        && $stored['expires_at'] <= time() + BRVTAL_PUBLIC_PREVIEW_TTL,
        'bounded preview TTL');
    $covered[] = 'S20-R013';
    echo json_encode(['exercised'=>$covered], JSON_THROW_ON_ERROR);
} elseif ($mode === 'invalid') {
    $bad = '{"blocks":[{"type":"embed","content":"unsafe"}]}';
    $assert(brvtal_page_content_structure_error($bad) ===
        'PAGE_CONTENT_STRUCTURE_UNSUPPORTED', 'unsupported page block');
    $assert(brvtal_page_publication_error([
        'status'=>'published','locale'=>'en','content_json'=>$bad
    ]) === 'PAGE_CONTENT_STRUCTURE_UNSUPPORTED', 'publication fails closed');
    $reject(static fn() => brvtalPublicPreviewSnapshot('unknown', []),
        'unknown preview type');
    $reject(static fn() => brvtalPublicPreviewSnapshot('events', [
        'title'=>'Fixture', 'accent'=>'script-red'
    ]), 'invalid accent');
    $reject(static fn() => brvtalPublicPreviewSnapshot('pages', [
        'title'=>'Fixture','content_json'=>'{broken'
    ]), 'invalid page JSON');
    $reject(static fn() => brvtalPublicPreviewSnapshot('sets', [
        'title'=>'Fixture', 'external_url'=>'file:///private/secret'
    ]), 'invalid URL scheme');
    $assert(brvtalPublicPreviewLoad(str_repeat('a', 49)) === null, 'bad token');
    $quality = brvtal_media_quality_guidance(20,20);
    $assert($quality['grade'] === 'limited'
        && !$quality['contexts']['hero']['ready']
        && !$quality['contexts']['card']['ready']
        && !$quality['contexts']['square']['ready'], 'low quality not ready');
    echo json_encode(['invalid_rejected'=>true], JSON_THROW_ON_ERROR);
} elseif ($mode === 'synthetic') {
    $p = brvtalPublicPreviewSnapshot('blog', [
        'title'=>'Synthetic only','status'=>'draft','body'=>'Test text',
        'private_token'=>'synthetic-marker','email'=>'untrusted'
    ]);
    $assert($p['payload']['title'] === 'Synthetic only'
        && !isset($p['payload']['private_token'])
        && !isset($p['payload']['email'])
        && !isset($p['payload']['id']), 'untrusted fields never projected');
    echo json_encode(['synthetic_only'=>true], JSON_THROW_ON_ERROR);
} else { fwrite(STDERR, "Unknown probe mode"); exit(2); }
"""

class CoberturaBrvtal06Tests(unittest.TestCase):
    @staticmethod
    def run_php(mode: str) -> dict:
        php = shutil.which("php")
        if not php:
            raise AssertionError("PHP CLI is required for behavioral assertions")
        result = subprocess.run(
            [php, "-r", PHP_PROBE, "--", mode], cwd=ROOT,
            capture_output=True, text=True, timeout=60, check=False,
        )
        if result.returncode != 0:
            raise AssertionError(
                f"PHP probe {mode} failed ({result.returncode}): "
                + result.stderr[-2200:]
            )
        return json.loads(result.stdout)

    def test_contrato_principal(self) -> None:
        exercised = set(self.run_php("main")["exercised"])
        self.assertTrue({
            "S18-R012", "S18-R014", "S18-R016", "S18-R017",
            "S19-R001", "S20-R012", "S20-R013",
        }.issubset(exercised))

    def test_falla_cerrado(self) -> None:
        self.assertTrue(self.run_php("invalid")["invalid_rejected"])

    def test_sin_datos_reales(self) -> None:
        self.assertTrue(self.run_php("synthetic")["synthetic_only"])
        self.assertNotIn("curl_exec", PHP_PROBE)
        self.assertNotIn("mysqli", PHP_PROBE.lower())

    def test_ci_contract(self) -> None:
        # The external Actions gate certifies exact-HEAD validation, not this test.
        workflow = (ROOT / ".github/workflows/factory-ci.yml").read_text(
            encoding="utf-8"
        )
        self.assertIn("name: Factory CI", workflow)
        self.assertIn("pull_request:", workflow)
        self.assertIn("stack: php", workflow)
        self.assertIn("uses: pl0n3r/factory/.github/workflows/ci.yml@v1", workflow)


if __name__ == "__main__":
    unittest.main()
