<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/artist_collective_membership.php';

function collective_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "ARTIST COLLECTIVE MEMBERSHIP CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

foreach ([
    [true, 1],
    [false, 0],
    [1, 1],
    [0, 0],
    ['1', 1],
    ['0', 0],
] as [$input, $expected]) {
    $normalized = brvtalArtistCollectiveNormalizePayload(['is_collective_member' => $input]);
    collective_expect($normalized['error'] === null, 'valid checkbox values must normalize');
    collective_expect(
        ($normalized['payload']['is_collective_member'] ?? null) === $expected,
        'checkbox must normalize to 0/1'
    );
}

$invalid = brvtalArtistCollectiveNormalizePayload(['is_collective_member' => 'active']);
collective_expect(
    ($invalid['error']['error'] ?? '') === 'INVALID_COLLECTIVE_MEMBERSHIP',
    'status codes must not be accepted as current membership input'
);
collective_expect(
    brvtalArtistCollectiveMembershipValue(['is_collective_member'=>1]),
    'canonical true membership must be recognized'
);
collective_expect(
    !brvtalArtistCollectiveMembershipValue(['is_collective_member'=>0,'collective_status'=>'active']),
    'canonical boolean must override stale legacy state'
);
collective_expect(
    brvtalArtistCollectiveMembershipValue(['collective_status'=>'active']),
    'legacy active state must bridge to member before migration'
);
collective_expect(
    !brvtalArtistCollectiveMembershipValue(['collective_status'=>'alumni']),
    'legacy alumni must map to not-current-member'
);

$api = (string)file_get_contents(__DIR__ . '/../api/index.php');
$admin = (string)file_get_contents(__DIR__ . '/../discadmin/index-core.php');
$adminModules = (string)file_get_contents(__DIR__ . '/../discadmin/admin-modules.js');
$ia = (string)file_get_contents(__DIR__ . '/../discadmin/admin-information-architecture.js');
$contentCore = (string)file_get_contents(__DIR__ . '/../discadmin/content-core.php');
$migration = (string)file_get_contents(__DIR__ . '/../database/migration_artist_collective_membership_01.sql');

collective_expect(
    str_contains($api, "'is_collective_member'"),
    'core API must expose the canonical writable membership field'
);
collective_expect(
    !str_contains($api, "'collective_status','collective_order','collective_joined_at','collective_left_at'"),
    'legacy lifecycle quartet must not remain writable'
);
collective_expect(
    str_contains($admin, 'BRVTAL artist / Member of collective'),
    'Artist editor must expose the membership checkbox'
);
collective_expect(
    str_contains($adminModules, 'is_collective_member:Boolean'),
    'Artist save payload must persist checkbox state'
);
collective_expect(
    !str_contains($ia, 'COLLECTIVE STATUS'),
    'standalone Collective Status workflow must be removed'
);
collective_expect(
    !str_contains($contentCore, 'COLLECTIVE ROSTER'),
    'Content Core must no longer expose a parallel Artist membership editor'
);
collective_expect(
    str_contains($migration, "collective_status='active'"),
    'migration must map only legacy active records to current members'
);
collective_expect(
    str_contains($migration, 'is_collective_member'),
    'migration must create the canonical boolean'
);
collective_expect(
    str_contains($migration, '@membership_added'),
    'rerunning migration SQL must not re-backfill canonical edits'
);

echo "BRVTAL Artist collective membership contract tests passed.\n";
