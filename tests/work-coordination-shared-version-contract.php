<?php
declare(strict_types=1);

/**
 * Shared version files contract (#1811, Factory#2163).
 *
 * Both coordination calls that validate PR claims and collisions must declare
 * the release files as shared, otherwise every product leaf serializes on them.
 */
$expected = 'shared_version_paths: \'["config/version.php","package.json","revision-juridica/00-LEEME.md"]\'';
$calls = [
    '.github/workflows/update-release-metadata.yml' => 'operation: validate',
    '.github/workflows/work-coordination.yml' => 'operation: pr',
];
foreach ($calls as $file => $operation) {
    $yaml = (string)file_get_contents(__DIR__ . '/../' . $file);
    $at = strpos($yaml, $operation);
    if ($at === false) {
        fwrite(STDERR, "{$file}: missing {$operation}\n");
        exit(1);
    }
    $block = substr($yaml, $at, 600);
    if (!str_contains($block, $expected)) {
        fwrite(STDERR, "{$file}: {$operation} must declare {$expected}\n");
        exit(1);
    }
}
echo "Shared version coordination contract passed\n";
