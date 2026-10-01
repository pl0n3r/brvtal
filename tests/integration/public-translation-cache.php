<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/public_translation.php';

function translation_cache_it_expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException("PUBLIC TRANSLATION CACHE INTEGRATION FAILED: {$message}");
    }
}

if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "BRVTAL public translation cache integration skipped. Set BRVTAL_INTEGRATION_TESTS=1 to run.\n";
    exit(0);
}

$baseDb = (string)(getenv('BRVTAL_TEST_DB_NAME') ?: '');
translation_cache_it_expect(
    (bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/', $baseDb),
    'test database name must start with brvtal_test'
);

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    (string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1'),
    (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306),
    $baseDb
);
$pdo = new PDO(
    $dsn,
    (string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root'),
    (string)(getenv('BRVTAL_TEST_DB_PASS') ?: ''),
    [
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES=>false,
    ]
);

$pdo->exec('DROP TABLE IF EXISTS public_translation_cache');
$migration = (string)file_get_contents(
    __DIR__ . '/../../database/migration_public_translation_cache_01.sql'
);
translation_cache_it_expect(trim($migration) !== '', 'migration must exist');
$pdo->exec($migration);
$pdo->exec($migration);

$identity = brvtalPublicTranslationCacheIdentity(
    'Texto canónico',
    'es',
    'en',
    'adapter-v1'
);
translation_cache_it_expect(
    brvtalPublicTranslationCacheRead($pdo, $identity) === null,
    'new identity must miss before first write'
);
brvtalPublicTranslationCacheWrite($pdo, $identity, 'Canonical text');
translation_cache_it_expect(
    brvtalPublicTranslationCacheRead($pdo, $identity) === 'Canonical text',
    'cache must return the persisted translation'
);
brvtalPublicTranslationCacheWrite($pdo, $identity, 'Updated canonical text');
translation_cache_it_expect(
    brvtalPublicTranslationCacheRead($pdo, $identity) === 'Updated canonical text',
    'same identity must update instead of duplicating rows'
);
translation_cache_it_expect(
    (int)$pdo->query('SELECT COUNT(*) FROM public_translation_cache')->fetchColumn() === 1,
    'unique cache identity must keep one row'
);

$changed = brvtalPublicTranslationCacheIdentity(
    'Texto canónico',
    'es',
    'en',
    'adapter-v2'
);
brvtalPublicTranslationCacheWrite($pdo, $changed, 'Version two');
translation_cache_it_expect(
    (int)$pdo->query('SELECT COUNT(*) FROM public_translation_cache')->fetchColumn() === 2,
    'translator version change must create a distinct cache entry'
);

$pdo->exec('DROP TABLE public_translation_cache');
echo "BRVTAL public translation cache MariaDB integration passed.\n";
