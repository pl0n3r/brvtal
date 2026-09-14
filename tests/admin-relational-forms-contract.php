<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$bridge = (string) file_get_contents($root . '/discadmin/admin-relational-forms.js');
$entry = (string) file_get_contents($root . '/discadmin/index.php');

$expect = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "Admin relational forms contract failed: {$message}\n");
        exit(1);
    }
};

$expect(str_contains($entry, '/discadmin/admin-relational-forms.js'), 'DISCADMIN must load the relational form bridge');
$expect(str_contains($bridge, "replace(' ', 'T').slice(0, 16)"), 'event dates must be normalized for datetime-local inputs');
$expect(str_contains($bridge, "section !== 'sets'"), 'the relation hydration override must stay scoped to Sets');
$expect(str_contains($bridge, "req('/sets')"), 'Sets navigation must load set records');
$expect(str_contains($bridge, "req('/artists')"), 'Sets navigation must hydrate artist relationships');
$expect(str_contains($bridge, "req('/events')"), 'Sets navigation must hydrate event relationships');
$expect(str_contains($bridge, 'Promise.all(['), 'Sets, Artists and Events should hydrate concurrently');
$expect(str_contains($bridge, 'state.artists ='), 'hydrated artists must populate the state used by setForm');
$expect(str_contains($bridge, 'state.events ='), 'hydrated events must populate the state used by setForm');

echo "Admin relational forms contract passed.\n";
