<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$archive = (string) file_get_contents($root . '/js/archive.js');
$release = (string) file_get_contents($root . '/scripts/update-release-metadata.py');

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$expect(!str_contains($archive, '.innerHTML'), 'Public Archive must not inject API-derived content through innerHTML');
$expect(str_contains($archive, 'replaceChildren('), 'Public Archive must replace rendered collections with DOM nodes');
$expect(str_contains($archive, '.textContent ='), 'Public Archive must render untrusted text through textContent');
$expect(str_contains($archive, 'document.createElement('), 'Public Archive must construct rendered markup with DOM APIs');
$expect(str_contains($archive, "if (!/^https?:$/i.test(url.protocol)) return '';"), 'Public Archive image URLs must reject non-HTTP(S) schemes');

$expect(str_contains($release, 'Path(__file__).resolve().parents[1]'), 'Release metadata path must be anchored to the repository root');
$expect(str_contains($release, "CONFIG_ROOT = (REPO_ROOT / 'config').resolve()"), 'Release metadata writes must be anchored to the resolved config directory');
$expect(str_contains($release, 'VERSION_FILE.relative_to(CONFIG_ROOT)'), 'Release metadata path must be verified as a config descendant');
$expect(str_contains($release, "VERSION_FILE.open('r', encoding='utf-8')"), 'Release metadata must read only the verified version file');
$expect(str_contains($release, "VERSION_FILE.open('w', encoding='utf-8')"), 'Release metadata must write only the verified version file');
$expect(!str_contains($release, "path = Path('config/version.php')"), 'Release metadata must not depend on a working-directory-relative writable path');

echo "BRVTAL Sonar security contract tests passed.\n";
