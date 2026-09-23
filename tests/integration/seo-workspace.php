<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/seo_workspace.php';

function seo_workspace_it_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "SEO WORKSPACE INTEGRATION FAILED: {$message}\n");
        exit(1);
    }
}

if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "BRVTAL SEO workspace integration skipped. Set BRVTAL_INTEGRATION_TESTS=1 to run.\n";
    exit(0);
}

$dbName = (string)(getenv('BRVTAL_TEST_DB_NAME') ?: '');
seo_workspace_it_expect(
    (bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/', $dbName),
    'test database name must start with brvtal_test'
);

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    (string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1'),
    (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306),
    $dbName
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

$pdo->exec('CREATE TEMPORARY TABLE settings (
    setting_key VARCHAR(120) NOT NULL PRIMARY KEY,
    setting_value LONGTEXT NOT NULL,
    is_json TINYINT(1) NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4');

$legacy = [
    'site_title'=>'Legacy Home title',
    'description'=>'Legacy Home description',
    'share_image'=>'/legacy-share.webp',
    'custom_keep'=>'preserve-me',
];
$insert = $pdo->prepare('INSERT INTO settings(setting_key,setting_value,is_json) VALUES(?,?,1)');
$insert->execute([
    BRVTAL_SEO_WORKSPACE_SETTING_KEY,
    json_encode($legacy, JSON_UNESCAPED_SLASHES),
]);

$home = brvtalSeoWorkspaceStaticValues($pdo, 'home');
seo_workspace_it_expect($home['mode'] === 'MANUAL', 'legacy flat Home values must remain authoritative before central editing');
seo_workspace_it_expect($home['seo_title'] === 'Legacy Home title', 'legacy Home title must remain readable');
seo_workspace_it_expect(
    $home['share_image'] === '/legacy-share.webp',
    'legacy root-relative Home image must remain readable before central save'
);

$clearedHome = brvtalSeoWorkspacePersistStatic($pdo, 'home', [
    'seo_title'=>'',
    'seo_description'=>'',
    'share_image'=>'',
], 'https://www.brvtal.com.co');
seo_workspace_it_expect($clearedHome['changed'] === true, 'clearing legacy Home values must persist a canonical route state');
seo_workspace_it_expect($clearedHome['state']['mode'] === 'AUTO', 'clearing Home values must restore AUTO mode');

$stored = $pdo->query(
    "SELECT setting_value FROM settings WHERE setting_key='seo' LIMIT 1"
)->fetchColumn();
$decoded = json_decode((string)$stored, true);
seo_workspace_it_expect(is_array($decoded), 'SEO setting must remain valid JSON');
seo_workspace_it_expect(($decoded['custom_keep'] ?? '') === 'preserve-me', 'unknown SEO sibling keys must be preserved');
seo_workspace_it_expect(($decoded['site_title'] ?? null) === '', 'legacy Home title mirror must be cleared');
seo_workspace_it_expect(($decoded['description'] ?? null) === '', 'legacy Home description mirror must be cleared');
seo_workspace_it_expect(($decoded['share_image'] ?? null) === '', 'legacy Home image mirror must be cleared');
seo_workspace_it_expect(($decoded['routes']['home']['title'] ?? null) === '', 'canonical Home route override must be blank');
seo_workspace_it_expect(($decoded['routes']['home']['description'] ?? null) === '', 'canonical Home description override must be blank');

$contact = brvtalSeoWorkspacePersistStatic($pdo, 'contact', [
    'seo_title'=>'Bookings & collaborations — BRVTAL',
    'seo_description'=>'Talk to BRVTAL about bookings, collaborations and media.',
    'share_image'=>'/uploads/contact.webp',
], 'https://www.brvtal.com.co');
seo_workspace_it_expect($contact['state']['mode'] === 'MANUAL', 'fully authored Contact metadata must be MANUAL');
seo_workspace_it_expect(
    $contact['state']['canonical'] === 'https://www.brvtal.com.co/contact',
    'Contact canonical must remain fixed while metadata changes'
);

$homeAfterContact = brvtalSeoWorkspaceStaticValues($pdo, 'home');
seo_workspace_it_expect($homeAfterContact['mode'] === 'AUTO', 'editing Contact must not change Home mode');

$contactAuto = brvtalSeoWorkspacePersistStatic($pdo, 'contact', [
    'seo_title'=>'  ',
    'seo_description'=>'',
    'share_image'=>'',
], 'https://www.brvtal.com.co');
seo_workspace_it_expect($contactAuto['state']['mode'] === 'AUTO', 'clearing Contact overrides must return to AUTO');
seo_workspace_it_expect(
    $contactAuto['state']['effective_title'] === 'Contact — BRVTAL',
    'Contact AUTO mode must immediately resolve the server fallback'
);

echo "BRVTAL SEO workspace MariaDB integration passed.\n";
