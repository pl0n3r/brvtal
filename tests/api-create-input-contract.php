<?php
declare(strict_types=1);

require_once __DIR__ . '/../api/create-input-validation.php';

function createInputExpect(bool $condition, string $why): void
{
    if (!$condition) {
        throw new RuntimeException('API CREATE INPUT CONTRACT: ' . $why);
    }
}

$valid = [
    'events' => ['title' => 'Ø Ñandú 🦉 مرحبا', 'slug' => 'nandu-live', 'status' => 'draft'],
    'artists' => ['name' => 'Ø Ñandú 🦉 مرحبا', 'slug' => 'nandu', 'status' => 'draft'],
    'sets' => ['title' => 'Ø Ñandú 🦉 مرحبا', 'external_url' => '', 'status' => 'draft'],
    'ticket_types' => ['event_id' => 1, 'name' => 'Entrada general', 'status' => 'draft'],
];
foreach ($valid as $resource => $payload) {
    createInputExpect(brvtal_api_create_input_error($resource, $payload) === null,
        $resource . ' accepts valid drafts and international text');
}
createInputExpect(brvtal_api_create_input_error('pages', ['title' => '']) === null,
    'unrelated resource unaffected');

// Exact 21 fingerprint body shapes recorded in the disposable #1868 fuzz.
$variants = [
    'unicode-emoji' => ['title' => 'Ø Ñandú 🦉', 'name' => 'Ø Ñandú 🦉'],
    'rtl' => ['title' => "שלום مرحبا \u{202e}", 'name' => 'שלום مرحبا'],
    'html-script' => ['title' => '<script>alert(1)</script>', 'name' => '<script>x</script>'],
    'sql-metacharacters' => ['title' => "'; DROP TABLE artists; --", 'name' => "' OR '1'='1"],
    'nul-byte' => ['title' => "bad\0name", 'name' => "\0"],
    'extreme-numbers' => ['id' => 1.0e100, 'sort_order' => -1.0e60, 'title' => 1.0e100],
];
$affected = [
    'artists' => ['html-script', 'rtl', 'sql-metacharacters', 'unicode-emoji'],
    'events' => ['extreme-numbers', 'html-script', 'nul-byte', 'rtl', 'sql-metacharacters', 'unicode-emoji'],
    'sets' => ['extreme-numbers', 'html-script', 'nul-byte', 'rtl', 'sql-metacharacters', 'unicode-emoji'],
    'ticket_types' => ['extreme-numbers', 'html-script', 'rtl', 'sql-metacharacters', 'unicode-emoji'],
];
$count = 0;
foreach ($affected as $resource => $names) {
    foreach ($names as $name) {
        $error = brvtal_api_create_input_error($resource, $variants[$name]);
        createInputExpect(is_array($error) && is_string($error['error'])
            && is_string($error['field']), $resource . '/' . $name . ' rejects before INSERT');
        ++$count;
    }
}
createInputExpect($count === 21, 'complete 21 fingerprint coverage');

foreach ([
    ['events', ['title' => 'Missing slug'], 'slug'],
    ['artists', ['name' => 'Missing slug'], 'slug'],
    ['sets', ['title' => 'Missing external URL'], 'external_url'],
    ['ticket_types', ['name' => 'Missing event'], 'event_id'],
    ['ticket_types', ['event_id' => 1], 'name'],
    ['ticket_types', ['event_id' => 1, 'name' => str_repeat('A', 121)], 'name'],
    ['events', ['title' => [], 'slug' => 'test'], 'title'],
    ['artists', ['name' => [], 'slug' => 'test'], 'name'],
    ['sets', ['title' => 'Valid', 'external_url' => ['https://example.test']], 'external_url'],
    ['events', ['title' => 'Valid', 'slug' => 'valid', 'sort_order' => -1.0e60], 'sort_order'],
    ['events', ['title' => 'Valid', 'slug' => 'valid', 'sort_order' => '2147483648'], 'sort_order'],
    ['events', ['title' => 'Valid', 'slug' => 'valid', 'sort_order' => '-2147483649'], 'sort_order'],
    ['ticket_types', ['event_id' => 0, 'name' => 'Entrada'], 'event_id'],
    ['ticket_types', ['event_id' => '99999999999999', 'name' => 'Entrada'], 'event_id'],
    ['ticket_types', ['event_id' => 1, 'name' => 'Entrada', 'price' => 1.0e100], 'price'],
    ['artists', ['name' => "bad\0text", 'slug' => 'bad'], 'name'],
] as [$resource, $payload, $field]) {
    $error = brvtal_api_create_input_error($resource, $payload);
    createInputExpect(($error['field'] ?? '') === $field,
        $resource . '/' . $field . ' provides a specific field error');
}

foreach ([
    ['events', ['title' => 'Evento', 'slug' => '', 'sort_order' => -1]],
    ['artists', ['name' => 'Rüna', 'slug' => 'runa', 'is_collective_member' => true]],
    ['sets', ['title' => 'Rüna Set', 'external_url' => '', 'artist_id' => null]],
    ['ticket_types', ['event_id' => '42', 'name' => 'Entrada Ñ', 'price' => '19000.00']],
] as [$resource, $payload]) {
    createInputExpect(brvtal_api_create_input_error($resource, $payload) === null,
        $resource . ' still accepts valid form fields');
}

// The actual POST handler must call the tested guard before sanitization / SQL.
$index = (string)file_get_contents(__DIR__ . '/../api/index.php');
$requireAt = strpos($index, "require_once __DIR__ . '/create-input-validation.php';");
$guardAt = strpos($index, 'brvtal_api_create_input_error($resource, $d)');
$sanitizeAt = strpos($index, '$d = sanitize_payload($resource, $d);');
$insertAt = strpos($index, 'INSERT INTO {$table}');
createInputExpect($requireAt !== false && $guardAt !== false
    && $sanitizeAt !== false && $insertAt !== false
    && $requireAt < $guardAt && $guardAt < $sanitizeAt && $sanitizeAt < $insertAt,
    'guard is wired into POST before sanitize and INSERT');
echo "BRVTAL #1900: 21 CREATE fingerprints and compatible shapes passed.\n";
