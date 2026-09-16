<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/artist_collective_lifecycle.php';
require_once __DIR__ . '/../api/content-validation.php';

/** Fail the Artist collective lifecycle contract with one precise diagnostic. */
function collective_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "ARTIST COLLECTIVE LIFECYCLE CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$partial = brvtal_artist_collective_normalize_payload(['collective_status' => 'active']);
collective_expect(($partial['error']['error'] ?? '') === 'COLLECTIVE_LIFECYCLE_FIELDS_REQUIRED', 'lifecycle edits must submit status and both dates atomically');

$invalidStatus = brvtal_artist_collective_normalize_payload([
    'collective_status' => 'resident',
    'collective_joined_at' => null,
    'collective_left_at' => null,
]);
collective_expect(($invalidStatus['error']['error'] ?? '') === 'INVALID_COLLECTIVE_STATUS', 'unknown collective status must be rejected');

$noneWithDates = brvtal_artist_collective_normalize_payload([
    'collective_status' => 'none',
    'collective_joined_at' => '2026-01-01',
    'collective_left_at' => null,
]);
collective_expect(($noneWithDates['error']['error'] ?? '') === 'COLLECTIVE_NONE_DATES_NOT_ALLOWED', 'none must not keep ambiguous membership dates');

$activeWithoutJoin = brvtal_artist_collective_normalize_payload([
    'collective_status' => 'active',
    'collective_joined_at' => null,
    'collective_left_at' => null,
]);
collective_expect(($activeWithoutJoin['error']['error'] ?? '') === 'COLLECTIVE_JOINED_AT_REQUIRED', 'active membership must have a joined date');

$activeWithLeft = brvtal_artist_collective_normalize_payload([
    'collective_status' => 'active',
    'collective_joined_at' => '2026-01-01',
    'collective_left_at' => '2026-02-01',
]);
collective_expect(($activeWithLeft['error']['error'] ?? '') === 'COLLECTIVE_ACTIVE_LEFT_AT_NOT_ALLOWED', 'active membership cannot already have a left date');

$alumniWithoutLeft = brvtal_artist_collective_normalize_payload([
    'collective_status' => 'alumni',
    'collective_joined_at' => '2026-01-01',
    'collective_left_at' => null,
]);
collective_expect(($alumniWithoutLeft['error']['error'] ?? '') === 'COLLECTIVE_LEFT_AT_REQUIRED', 'alumni membership must have a left date');

$backwards = brvtal_artist_collective_normalize_payload([
    'collective_status' => 'alumni',
    'collective_joined_at' => '2026-02-01',
    'collective_left_at' => '2026-01-01',
]);
collective_expect(($backwards['error']['error'] ?? '') === 'COLLECTIVE_DATE_ORDER_INVALID', 'left date must never precede joined date');

foreach ([
    ['collective_status'=>'none','collective_joined_at'=>null,'collective_left_at'=>null],
    ['collective_status'=>'active','collective_joined_at'=>'2026-01-01','collective_left_at'=>null],
    ['collective_status'=>'alumni','collective_joined_at'=>'2025-01-01','collective_left_at'=>'2026-01-01'],
] as $valid) {
    $normalized = brvtal_artist_collective_normalize_payload($valid);
    collective_expect($normalized['error'] === null, 'valid collective state must be accepted: ' . $valid['collective_status']);
}

$unrelated = brvtal_content_temporal_normalize('artists', ['bio' => 'Only biography changed']);
collective_expect($unrelated['error'] === null && ($unrelated['payload']['bio'] ?? '') === 'Only biography changed', 'unrelated Artist edits must not be forced through lifecycle validation');
$orderOnly = brvtal_content_temporal_normalize('artists', ['collective_order' => 4]);
collective_expect($orderOnly['error'] === null, 'collective ordering alone must remain an independent editorial edit');
$invalidDate = brvtal_content_temporal_normalize('artists', [
    'collective_status'=>'active',
    'collective_joined_at'=>'2026-02-30',
    'collective_left_at'=>null,
]);
collective_expect(($invalidDate['error']['error'] ?? '') === 'INVALID_DATE', 'invalid calendar dates must fail before lifecycle persistence');

$activitySource = (string)file_get_contents(__DIR__ . '/../config/admin_activity.php');
collective_expect(str_contains($activitySource, "require_once __DIR__ . '/artist_collective_lifecycle.php';"), 'Admin Activity must load the collective lifecycle authority');
collective_expect(str_contains($activitySource, 'brvtal_artist_collective_sync_history('), 'Artist mutations must synchronize history in the audited transaction');
$migrationSource = (string)file_get_contents(__DIR__ . '/../database/migration_content_core_01.sql');
collective_expect(str_contains($migrationSource, 'CREATE TABLE IF NOT EXISTS artist_collective_history'), 'Content Core migration must define artist_collective_history');

if (getenv('BRVTAL_INTEGRATION_TESTS') === '1') {
    $dbName = (string)(getenv('BRVTAL_TEST_DB_NAME') ?: '');
    collective_expect((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/', $dbName), 'integration database name must start with brvtal_test');

    $dsn = sprintf(
        'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
        (string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1'),
        (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306),
        $dbName
    );
    $pdo = new PDO($dsn, (string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root'), (string)(getenv('BRVTAL_TEST_DB_PASS') ?: ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);

    $pdo->exec("CREATE TEMPORARY TABLE admins (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(190) NOT NULL,
        password_hash VARCHAR(255) NOT NULL,
        name VARCHAR(120) NOT NULL,
        is_active TINYINT(1) NOT NULL DEFAULT 1
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TEMPORARY TABLE artists (
        id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(180) NOT NULL,
        slug VARCHAR(190) NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'draft',
        collective_status VARCHAR(20) NOT NULL DEFAULT 'none',
        collective_order INT NOT NULL DEFAULT 0,
        collective_joined_at DATETIME NULL,
        collective_left_at DATETIME NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TEMPORARY TABLE artist_collective_history (
        id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        artist_id INT UNSIGNED NOT NULL,
        status VARCHAR(20) NOT NULL,
        started_at DATETIME NOT NULL,
        ended_at DATETIME NULL,
        note VARCHAR(500) NULL,
        created_by INT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_collective_artist (artist_id,started_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    collective_expect(brvtal_artist_collective_history_schema_ready($pdo), 'artist_collective_history must be queryable in the isolated integration fixture');
    $pdo->beginTransaction();
    try {
        $stamp = bin2hex(random_bytes(4));
        $adminEmail = "collective-{$stamp}@example.test";
        $adminInsert = $pdo->prepare('INSERT INTO admins(email,password_hash,name,is_active) VALUES(?,?,?,1)');
        $adminInsert->execute([$adminEmail, password_hash('integration-only', PASSWORD_DEFAULT), 'Collective Contract']);
        $adminId = (int)$pdo->lastInsertId();

        $artistInsert = $pdo->prepare("INSERT INTO artists(name,slug,status,collective_status,collective_order,collective_joined_at,collective_left_at) VALUES(?,?, 'published','none',0,NULL,NULL)");
        $artistInsert->execute(['Collective Contract Artist', 'collective-contract-' . $stamp]);
        $artistId = (int)$pdo->lastInsertId();

        $none = ['collective_status'=>'none','collective_joined_at'=>null,'collective_left_at'=>null];
        $active = ['collective_status'=>'active','collective_joined_at'=>'2025-01-10','collective_left_at'=>null];
        brvtal_artist_collective_sync_history($pdo, 'update', $artistId, $none, $active, $adminId);

        $rows = $pdo->prepare('SELECT status,started_at,ended_at,created_by FROM artist_collective_history WHERE artist_id=? ORDER BY id');
        $rows->execute([$artistId]);
        $history = $rows->fetchAll();
        collective_expect(count($history) === 1, 'none → active must create exactly one membership period');
        collective_expect($history[0]['status'] === 'active' && $history[0]['ended_at'] === null, 'active period must remain open');
        collective_expect((int)$history[0]['created_by'] === $adminId, 'new membership history must retain the acting admin');

        $correctedActive = ['collective_status'=>'active','collective_joined_at'=>'2025-01-12','collective_left_at'=>null];
        brvtal_artist_collective_sync_history($pdo, 'update', $artistId, $active, $correctedActive, $adminId);
        $rows->execute([$artistId]);
        $history = $rows->fetchAll();
        collective_expect(count($history) === 1 && str_starts_with((string)$history[0]['started_at'], '2025-01-12'), 'active date correction must update the open period instead of duplicating it');

        $alumni = ['collective_status'=>'alumni','collective_joined_at'=>'2025-01-12','collective_left_at'=>'2026-01-20'];
        brvtal_artist_collective_sync_history($pdo, 'update', $artistId, $correctedActive, $alumni, $adminId);
        $rows->execute([$artistId]);
        $history = $rows->fetchAll();
        collective_expect(count($history) === 1 && $history[0]['status'] === 'alumni', 'active → alumni must close the existing period');
        collective_expect(str_starts_with((string)$history[0]['ended_at'], '2026-01-20'), 'alumni transition must store the explicit left date');

        $rejoined = ['collective_status'=>'active','collective_joined_at'=>'2026-06-01','collective_left_at'=>null];
        brvtal_artist_collective_sync_history($pdo, 'update', $artistId, $alumni, $rejoined, $adminId);
        $rows->execute([$artistId]);
        $history = $rows->fetchAll();
        collective_expect(count($history) === 2, 'alumni → active re-entry must create a new membership period');
        collective_expect($history[1]['status'] === 'active' && $history[1]['ended_at'] === null, 're-entry period must be open and active');

        brvtal_artist_collective_sync_history(
            $pdo,
            'update',
            $artistId,
            $rejoined,
            $none,
            $adminId,
            new DateTimeImmutable('2026-09-16 12:00:00')
        );
        $rows->execute([$artistId]);
        $history = $rows->fetchAll();
        collective_expect(count($history) === 2 && $history[1]['status'] === 'alumni', 'active → none must close, not erase, the current history period');
        collective_expect((string)$history[1]['ended_at'] === '2026-09-16 12:00:00', 'active → none must close the period at the lifecycle-change time');

        $beforeBio = $none + ['bio'=>'A'];
        $afterBio = $none + ['bio'=>'B'];
        brvtal_artist_collective_sync_history($pdo, 'update', $artistId, $beforeBio, $afterBio, $adminId);
        $rows->execute([$artistId]);
        collective_expect(count($rows->fetchAll()) === 2, 'unrelated Artist edits must not create membership history');

        $artistInsert->execute(['Historical Contract Artist', 'historical-contract-' . $stamp]);
        $historicalArtistId = (int)$pdo->lastInsertId();
        $historical = ['collective_status'=>'alumni','collective_joined_at'=>'2024-02-01','collective_left_at'=>'2025-02-01'];
        brvtal_artist_collective_sync_history($pdo, 'update', $historicalArtistId, $none, $historical, $adminId);
        $historicalRows = $pdo->prepare('SELECT status,started_at,ended_at,created_by FROM artist_collective_history WHERE artist_id=?');
        $historicalRows->execute([$historicalArtistId]);
        $backfill = $historicalRows->fetchAll();
        collective_expect(count($backfill) === 1 && $backfill[0]['status'] === 'alumni', 'none → alumni must support one explicit historical backfill period');
        collective_expect((int)$backfill[0]['created_by'] === $adminId, 'historical backfill must retain the acting admin');
    } finally {
        if ($pdo->inTransaction()) $pdo->rollBack();
    }
}

echo "BRVTAL Artist Collective Lifecycle contract tests passed.\n";
