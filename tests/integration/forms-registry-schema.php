<?php
declare(strict_types=1);
// @brvtal-ci database

require_once __DIR__ . '/../../config/migrations.php';
require_once __DIR__ . '/../../config/migration_reconcile.php';

function formsRegistryAssert(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException('Forms registry: ' . $message);
    }
}

function formsRegistryReject(PDO $db, string $sql, array $args = []): void
{
    try {
        $db->prepare($sql)->execute($args);
    } catch (PDOException $error) {
        formsRegistryAssert((string)$error->getCode() === '23000', 'expected integrity rejection');
        return;
    }
    throw new RuntimeException('Forms registry: invalid row accepted');
}

function formsRegistryMigration(string $file): array
{
    $sql = file_get_contents(__DIR__ . '/../../database/' . $file);
    formsRegistryAssert(is_string($sql), 'migration readable');
    brvtalMigrationAssertAdditiveSql($sql);
    $parts = preg_replace('/^\h*--[^\r\n]*$/m', '', $sql);
    formsRegistryAssert(is_string($parts), 'migration parsed');
    return array_values(array_filter(array_map(trim(...), explode(';', $parts)), static fn(string $part): bool => $part !== ''));
}

$prefix = (string)getenv('BRVTAL_TEST_DB_NAME');
formsRegistryAssert(getenv('BRVTAL_INTEGRATION_TESTS') === '1'
    && getenv('BRVTAL_TEST_DB_HOST') === '127.0.0.1'
    && preg_match('/\Abrvtal_test[A-Za-z0-9_]*\z/D', $prefix) === 1
    && strlen($prefix) <= 40, 'disposable loopback database required');
$port = filter_var(getenv('BRVTAL_TEST_DB_PORT') ?: '3306', FILTER_VALIDATE_INT,
    ['options'=>['min_range'=>1,'max_range'=>65535]]);
formsRegistryAssert($port !== false, 'valid fixture port');
$dsn = "mysql:host=127.0.0.1;port=$port;charset=utf8mb4";
$user = (string)getenv('BRVTAL_TEST_DB_USER');
$pass = (string)getenv('BRVTAL_TEST_DB_PASS');
$options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false];
$server = new PDO($dsn, $user, $pass, $options);
$name = $prefix . '_registry_' . bin2hex(random_bytes(4));
$server->exec('CREATE DATABASE ' . $name . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
try {
    $db = new PDO($dsn . ';dbname=' . $name, $user, $pass, $options);
    foreach (formsRegistryMigration('migration_forms_entries_01.sql') as $statement) {
        $db->exec($statement);
    }
    $now = '2026-11-01 00:00:00.000000';
    $v1 = '{"form_key":"contact","version_number":1,"fields":[]}';
    $v2 = '{"form_key":"contact","version_number":2,"fields":[]}';
    $other = '{"form_key":"feedback","version_number":1,"fields":[]}';
    $digest = hash('sha256', $v1, true);
    $digest2 = hash('sha256', $v2, true);
    $digestOther = hash('sha256', $other, true);
    $version = $db->prepare('INSERT INTO form_definition_versions VALUES (?,?,?,?,?)');
    $version->execute(['contact',1,$digest,$v1,$now]);
    $version->execute(['feedback',1,$digestOther,$other,$now]);
    $entryId = random_bytes(16);
    $db->prepare('INSERT INTO form_entries (entry_id,form_key,version_number,definition_sha256,idempotency_digest,
        payload_json,created_at_utc,purpose_key,notice_version,retention_policy_key,retention_until_utc,state)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$entryId,'contact',1,$digest,hash('sha256','synthetic-entry',true),
            '{"message":"synthetic"}',$now,'contact_capture','fixture-v1','fixture-only','2026-12-01 00:00:00','received']);
    $db->prepare('INSERT INTO form_notification_intents VALUES (?,?,?,?)')
        ->execute([$entryId,'contact_notify','pending',$now]);
    $snapshot = static fn(): array => [
        $db->query('SELECT * FROM form_definition_versions ORDER BY form_key,version_number')->fetchAll(PDO::FETCH_ASSOC),
        $db->query('SELECT * FROM form_entries ORDER BY entry_id')->fetchAll(PDO::FETCH_ASSOC),
        $db->query('SELECT * FROM form_notification_intents ORDER BY entry_id,action_key')->fetchAll(PDO::FETCH_ASSOC),
    ];
    $before = $snapshot();
    $migration = 'migration_forms_registry_01.sql';
    $statements = formsRegistryMigration($migration);
    $disposition = static fn(): string => brvtalMigrationProofDisposition($migration, brvtalMigrationSchemaProof($db, $migration));
    formsRegistryAssert($disposition() === 'apply', 'proof absent');
    formsRegistryAssert(count($statements) === 2, 'two additive tables');
    $db->exec($statements[0]);
    formsRegistryAssert($disposition() === 'blocked', 'partial schema fails closed');
    foreach ($statements as $statement) {
        $db->exec($statement);
    }
    formsRegistryAssert($disposition() === 'baseline', 'complete structural proof');
    formsRegistryAssert($snapshot() === $before, 'preserved legacy versions, entries, intents');
    formsRegistryAssert((int)$db->query('SELECT COUNT(*) FROM form_publication_policies')->fetchColumn() === 0
        && (int)$db->query('SELECT COUNT(*) FROM form_definition_registry')->fetchColumn() === 0, 'no backfill or activation');

    $policySql = 'INSERT INTO form_publication_policies VALUES (?,?,?,?,?,?,?)';
    $policy = $db->prepare($policySql);
    $policy->execute(['contact',1,$digest,'fixture-v1','{"purpose":"contact"}','{"actions":[]}',$now]);
    $policy->execute(['feedback',1,$digestOther,'fixture-v1','{"purpose":"feedback"}','{"actions":[]}',$now]);
    formsRegistryReject($db, $policySql, ['contact',1,$digest,'fixture-v2','{}','{}',$now]);
    formsRegistryReject($db, $policySql, ['contact',2,$digest,'fixture-v2','{}','{}',$now]);

    $registrySql = 'INSERT INTO form_definition_registry (form_key,public_id,slug,draft_json,created_at_utc,updated_at_utc)
        VALUES (?,?,?,?,?,?)';
    $registry = $db->prepare($registrySql);
    $publicId = random_bytes(16);
    $registry->execute(['contact',$publicId,'contact-form','{}',$now,$now]);
    $registry->execute(['feedback',random_bytes(16),'feedback-form','{}',$now,$now]);
    formsRegistryReject($db, $registrySql, ['new_form',random_bytes(16),'contact-form','{}',$now,$now]);
    formsRegistryReject($db, $registrySql, ['new_form',$publicId,'other-slug','{}',$now,$now]);
    formsRegistryReject($db, $registrySql, ['new_form',random_bytes(16),'invalid_slug','{}',$now,$now]);
    formsRegistryReject($db, $registrySql, ['new_form',random_bytes(16),'new-form','[]',$now,$now]);
    formsRegistryReject($db, "UPDATE form_definition_registry SET revision=0 WHERE form_key='contact'");
    formsRegistryReject($db, "UPDATE form_definition_registry SET state='active' WHERE form_key='contact'");
    formsRegistryReject($db, "UPDATE form_definition_registry SET state='active',published_version_number=2 WHERE form_key='contact'");
    $db->exec("UPDATE form_definition_registry SET state='active',published_version_number=1,revision=revision+1 WHERE form_key='contact'");

    $snapshotV1 = $db->query("SELECT * FROM form_publication_policies WHERE form_key='contact' AND version_number=1")->fetch(PDO::FETCH_ASSOC);
    $save = $db->prepare("UPDATE form_definition_registry SET draft_json=?,slug=?,revision=revision+1 WHERE form_key='contact' AND revision=?");
    $save->execute(['{"title":"Synthetic revision"}','renamed-contact',2]);
    formsRegistryAssert($save->rowCount() === 1, 'optimistic revision advances');
    $save->execute(['{}','stale-edit',2]);
    formsRegistryAssert($save->rowCount() === 0, 'stale revision rejected by CAS');
    formsRegistryAssert((int)$db->query("SELECT published_version_number FROM form_definition_registry WHERE form_key='contact'")->fetchColumn() === 1, 'draft cannot move published version');

    $version->execute(['contact',2,$digest2,$v2,$now]);
    formsRegistryReject($db, $policySql, ['contact',2,$digest,'fixture-v2','{}','{}',$now]);
    formsRegistryReject($db, $policySql, ['contact',2,$digest2,'','{}','{}',$now]);
    formsRegistryReject($db, $policySql, ['contact',2,$digest2,'fixture-v2','[]','{}',$now]);
    formsRegistryReject($db, $policySql, ['contact',2,$digest2,'fixture-v2','{}','null',$now]);
    formsRegistryReject($db, "UPDATE form_definition_registry SET published_version_number=2 WHERE form_key='contact'");
    $policy->execute(['contact',2,$digest2,'fixture-v2','{"purpose":"contact"}','{"actions":[]}',$now]);
    formsRegistryReject($db, "UPDATE form_definition_registry SET state='active',published_version_number=2 WHERE form_key='feedback'");
    $db->exec("UPDATE form_definition_registry SET published_version_number=2,revision=revision+1 WHERE form_key='contact'");
    formsRegistryReject($db, "DELETE FROM form_publication_policies WHERE form_key='contact' AND version_number=2");
    formsRegistryReject($db, "DELETE FROM form_definition_versions WHERE form_key='contact' AND version_number=1");
    $db->exec("UPDATE form_definition_registry SET state='disabled',revision=revision+1 WHERE form_key='contact'");
    $row = $db->query("SELECT * FROM form_definition_registry WHERE form_key='contact'")->fetch(PDO::FETCH_ASSOC);
    formsRegistryAssert($row['public_id'] === $publicId && $row['slug'] === 'renamed-contact'
        && $row['state'] === 'disabled' && (int)$row['published_version_number'] === 2
        && (int)$row['revision'] === 5, 'stable identity and preserved published pointer');
    $beforeRepeat = $snapshot();
    $registryBefore = $db->query('SELECT * FROM form_definition_registry ORDER BY form_key')->fetchAll(PDO::FETCH_ASSOC);
    $policiesBefore = $db->query('SELECT * FROM form_publication_policies ORDER BY form_key,version_number')->fetchAll(PDO::FETCH_ASSOC);
    foreach ($statements as $statement) {
        $db->exec($statement);
    }
    formsRegistryAssert($disposition() === 'baseline' && $snapshot() === $beforeRepeat
        && $db->query('SELECT * FROM form_definition_registry ORDER BY form_key')->fetchAll(PDO::FETCH_ASSOC) === $registryBefore
        && $db->query('SELECT * FROM form_publication_policies ORDER BY form_key,version_number')->fetchAll(PDO::FETCH_ASSOC) === $policiesBefore,
        'repeat migration preserves every row');
    formsRegistryAssert($db->query("SELECT * FROM form_publication_policies WHERE form_key='contact' AND version_number=1")->fetch(PDO::FETCH_ASSOC) === $snapshotV1
        && $db->query("SELECT * FROM form_definition_versions WHERE form_key='contact' AND version_number=1")->fetch(PDO::FETCH_ASSOC) === $before[0][0]
        && $beforeRepeat[1] === $before[1] && $beforeRepeat[2] === $before[2], 'history unchanged across versions and slug change');
} finally {
    $server->exec('DROP DATABASE ' . $name);
}
echo "BRVTAL_MARKED_DATABASE_PASS: forms-registry-schema.php\n";
