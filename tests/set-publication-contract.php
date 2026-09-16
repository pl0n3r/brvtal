<?php
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/config/set_publication.php';

$api = file_get_contents($root . '/api/index.php');
$bulk = file_get_contents($root . '/api/bulk-actions-lib.php');
$admin = file_get_contents($root . '/discadmin/set-publication-contract.js');
$entry = file_get_contents($root . '/discadmin/index.php');

$fail = static function (string $message): never {
    fwrite(STDERR, "Set publication contract failed: {$message}\n");
    exit(1);
};
$mustContain = static function (string $haystack, string $needle, string $message) use ($fail): void {
    if (!str_contains($haystack, $needle)) $fail($message . " (missing {$needle})");
};
$expect = static function (mixed $actual, mixed $expected, string $message) use ($fail): void {
    if ($actual !== $expected) $fail($message . ' (got ' . var_export($actual, true) . ')');
};

$expect(brvtal_set_publication_error(['status'=>'draft','external_url'=>'']), null, 'draft Sets may remain incomplete');
$expect(brvtal_set_publication_error(['status'=>'published','external_url'=>'']), 'SET_LISTENING_URL_REQUIRED', 'published Sets need a listening URL');
$expect(brvtal_set_publication_error(['status'=>'published','external_url'=>'ftp://example.com/set']), 'INVALID_SET_LISTENING_URL', 'published Sets only accept http(s) listening URLs');
$expect(brvtal_set_publication_error(['status'=>'published','external_url'=>'https://soundcloud.com/brvtal/example']), null, 'valid https listening URLs may publish');

$mustContain($api, "require_once __DIR__ . '/../config/set_publication.php'", 'core API must load the Set publication policy');
$mustContain($api, "brvtal_set_publication_error(array_replace(['status'=>'draft','external_url'=>''],\$p))", 'Set create must enforce publication readiness');
$mustContain($api, 'brvtal_set_publication_error(array_replace($before,$p))', 'partial Set updates must validate the resulting persisted state');
$mustContain($api, "'field'=>'external_url'", 'Set publication failures must identify the listening URL field');

$mustContain($bulk, "require_once __DIR__ . '/../config/set_publication.php'", 'Bulk Actions must use the same publication policy');
$mustContain($bulk, "\$publicationColumns = \$resource === 'sets' ? ',external_url' : ''", 'bulk Set publishing must lock and inspect listening URLs');
$mustContain($bulk, "if (\$resource === 'sets' && \$status === 'published')", 'bulk Set publishing must validate before mutation');
$mustContain($bulk, 'throw new InvalidArgumentException($publicationError)', 'bulk invalid Set publication must return a validation error');

$mustContain($entry, '/discadmin/set-publication-contract.js', 'DISCADMIN must load the Set publication guard');
$mustContain($admin, "label.textContent = required ? 'Listening URL *' : 'Listening URL'", 'editor must communicate conditional required state');
$mustContain($admin, 'input.required = required', 'editor must expose required semantics when publishing');
$mustContain($admin, "if (type === 'sets' && !validateForPublish())", 'editor must block invalid Set save before request');
$mustContain($admin, "url.protocol === 'http:' || url.protocol === 'https:'", 'editor must reject non-http(s) listening URLs');

echo "Set publication contract passed.\n";
