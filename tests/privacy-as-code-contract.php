<?php
declare(strict_types=1);

const BRVTAL_PRIVACY_FACTORY_SHA = '4b2be9fcf827278631caa3e3e68603b6e2a680d7';
const BRVTAL_PRIVACY_PLACEHOLDER = '[COMPLETAR POR EL DUEÑO]';

function privacy_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "PRIVACY-AS-CODE CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

function privacy_text(string $relative): string
{
    $value = file_get_contents(__DIR__ . '/../' . $relative);
    privacy_expect(is_string($value), 'missing ' . $relative);
    return $value;
}

/** @return array<string,mixed> */
function privacy_data(): array
{
    try {
        $decoded = json_decode(privacy_text('datos.yml'), true, flags: JSON_THROW_ON_ERROR);
    } catch (JsonException $exception) {
        privacy_expect(false, 'datos.yml must be canonical JSON/YAML: ' . $exception->getMessage());
    }
    privacy_expect(is_array($decoded), 'datos.yml root must be an object');
    return $decoded;
}

/** @return array<string,mixed> */
function privacy_treatment(array $data, string $id): array
{
    foreach (($data['treatments'] ?? []) as $row) {
        if (is_array($row) && ($row['id'] ?? null) === $id) return $row;
    }
    privacy_expect(false, 'missing treatment ' . $id);
}

$data = privacy_data();
privacy_expect(($data['version'] ?? null) === 1, 'data-map version must be 1');
privacy_expect(($data['project'] ?? null) === 'pl0n3r/brvtal', 'project must be pl0n3r/brvtal');
privacy_expect(($data['phase'] ?? null) === 'construccion', 'phase must remain construccion');
privacy_expect(
    ($data['controller'] ?? null) === [
        'name' => BRVTAL_PRIVACY_PLACEHOLDER,
        'identifier' => BRVTAL_PRIVACY_PLACEHOLDER,
        'address' => BRVTAL_PRIVACY_PLACEHOLDER,
        'rights_email' => BRVTAL_PRIVACY_PLACEHOLDER,
    ],
    'controller identity must remain owner placeholders during construction'
);

$expectedIds = [
    'admin_activity',
    'admin_identity',
    'admin_password',
    'admin_recovery_codes',
    'admin_session',
    'admin_totp',
    'admin_totp_pending',
    'auth_password_rate_limit',
    'auth_totp_rate_limit',
    'contact_delivery',
    'contact_rate_limit',
    'internal_analytics_events',
    'public_gtm_measurement',
];
$actualIds = array_map(static fn(array $row): string => (string)$row['id'], $data['treatments']);
sort($actualIds);
privacy_expect($actualIds === $expectedIds, 'data map must contain the scoped treatments exactly');

foreach ($data['treatments'] as $row) {
    privacy_expect(($row['basis'] ?? null) === 'review_required', $row['id'] . ' basis must stay pending legal review');
    privacy_expect(($row['consent'] ?? null) === 'review_required', $row['id'] . ' consent must stay pending legal review');
}

$identity = privacy_treatment($data, 'admin_identity');
privacy_expect($identity['fields'] === ['email','name','is_active','last_login_at','created_at','updated_at'], 'admin identity fields drifted');
$password = privacy_treatment($data, 'admin_password');
privacy_expect($password['category'] === 'authentication' && $password['fields'] === ['password_hash'], 'password treatment must describe only the hash field');
$totp = privacy_treatment($data, 'admin_totp');
privacy_expect($totp['fields'] === ['totp_enabled','totp_secret_enc','totp_confirmed_at'], 'TOTP treatment must describe encrypted-secret metadata');
$recovery = privacy_treatment($data, 'admin_recovery_codes');
privacy_expect($recovery['fields'] === ['admin_id','code_hash','used_at','created_at'], 'recovery treatment must describe hashed codes only');

$auth = privacy_text('config/admin_auth.php');
$totpSource = privacy_text('config/totp_auth.php');
$schema = privacy_text('database/schema.sql');
$totpMigration = privacy_text('database/migration_totp_foundation.sql');
privacy_expect(str_contains($auth, 'BRVTAL_ADMIN_ABSOLUTE_TIMEOUT = 2592000'), '30-day absolute admin session timeout must remain observable');
privacy_expect(str_contains($totpSource, "time() - 600"), 'pending TOTP challenge must remain 10 minutes');
privacy_expect(str_contains($totpMigration, 'totp_secret_enc TEXT'), 'TOTP secret must remain encrypted-at-rest field');
privacy_expect(str_contains($totpMigration, 'code_hash VARCHAR(255)'), 'recovery codes must remain hashed');

$contact = privacy_treatment($data, 'contact_delivery');
privacy_expect($contact['fields'] === ['name','email','subject','message'], 'contact delivery fields drifted');
privacy_expect($contact['providers'] === [], 'mail/MTA provider must remain unknown');
privacy_expect($contact['retention'] === 'external_mailbox_review_required', 'external mailbox retention must remain pending review');
$contactApi = privacy_text('api/contact.php');
$contactConfig = privacy_text('config/public_contact.php');
privacy_expect(!str_contains($contactApi, 'db()'), 'public contact endpoint must not claim/store these fields in BRVTAL DB');
privacy_expect(str_contains($contactConfig, 'return @mail('), 'contact flow must still use host mail transport without inventing a provider');

$contactRate = privacy_treatment($data, 'contact_rate_limit');
privacy_expect($contactRate['fields'] === ['client_key_hash','timestamps'], 'contact rate limit must document hash and timestamps only');
privacy_expect(!in_array('ip', $contactRate['fields'], true), 'raw contact IP must not be declared as persisted');
privacy_expect(str_contains($contactConfig, "hash('sha256', brvtal_contact_resolve_client_ip"), 'contact rate-limit key must derive from an IP hash');
privacy_expect(str_contains($contactConfig, 'int $windowSeconds = 900'), 'contact rate-limit window must remain 15 minutes');

$passwordRate = privacy_text('config/password_rate_limit.php');
$totpRate = privacy_text('config/totp_rate_limit.php');
foreach (['auth_password_rate_limit','auth_totp_rate_limit'] as $id) {
    $row = privacy_treatment($data, $id);
    privacy_expect($row['fields'] === ['client_key_hash','attempts','blocked_until'], $id . ' must not declare raw IP/email persistence');
    privacy_expect($row['retention'] === 'window_15m', $id . ' must document observed 15-minute window');
}
privacy_expect(str_contains($passwordRate, 'BRVTAL_PASSWORD_RATE_LIMIT_WINDOW = 900'), 'password rate-limit window must remain 15 minutes');
privacy_expect(str_contains($passwordRate, "hash('sha256', $ip . '|' . strtolower(trim($email)))"), 'password rate-limit filename must remain derived hash');
privacy_expect(str_contains($totpRate, 'BRVTAL_TOTP_RATE_LIMIT_WINDOW = 900'), 'TOTP rate-limit window must remain 15 minutes');
privacy_expect(str_contains($totpRate, "hash('sha256', $prefix . $ip . '|' . $adminId)"), 'TOTP rate-limit filename must remain derived hash');

$internal = privacy_treatment($data, 'internal_analytics_events');
privacy_expect($internal['fields'] === ['event_name','page_url','referrer','locale','user_agent','created_at'], 'internal analytics map must match schema');
privacy_expect(str_contains($schema, 'CREATE TABLE analytics_events'), 'internal analytics table must remain observable in schema');

$gtm = privacy_treatment($data, 'public_gtm_measurement');
privacy_expect($gtm['providers'] === ['google_analytics'], 'public GTM is the only treatment allowed to declare Google Analytics');
privacy_expect($gtm['retention'] === 'review_required', 'external GTM/GA retention must remain unknown');
$gtmBootstrap = privacy_text('js/public-analytics.js');
$measurement = privacy_text('js/public-measurement.js');
privacy_expect(str_contains($gtmBootstrap, 'https://www.googletagmanager.com/gtm.js?id='), 'public analytics must use observable GTM layer');
privacy_expect(str_contains($gtmBootstrap, "analytics_storage: 'granted'"), 'current analytics consent default must stay explicitly documented by code');
privacy_expect(str_contains($measurement, 'url.pathname + url.hash'), 'outbound measurement must keep query strings out of explicit destination payloads');
privacy_expect(!str_contains($measurement, 'formData'), 'measurement layer must not collect form values');

$expectedDocs = {
    "politica-tratamiento.md": "ba236b1cf57a76ea8b032ec9d516c4c70232d59d334d811519bad3887b7ecdda",
    "aviso-privacidad.md": "9867b8b22924821967146cc14ddde289eae0709b87d52577ba094041b29d5380",
    "terminos-condiciones.md": "3eee6df2507807f339a84214227ea20d576a5a95f55835b83315dd460b34b4ac",
    "registro-tratamientos.md": "b0cfb0ccabd6524996324fd56ed99fb4e79b2cf3d54f0f4eca312272a34ae7fb",
    "canal-derechos.md": "43ea3834c9e9914644353f8b665c153447f8c017d2aa65337c929c969b531c45",
    "retencion.md": "92d31f2c77a17f9e45351bfcb460f3b8cfa1581e85804244501a4416f5b993b9"
};
$privacyDir = __DIR__ . '/../docs/privacidad';
$actualDocs = array_map('basename', glob($privacyDir . '/*.md') ?: []);
sort($actualDocs);
$expectedNames = array_keys($expectedDocs);
sort($expectedNames);
privacy_expect($actualDocs === $expectedNames, 'privacy directory must contain exactly the six Factory documents');
foreach ($expectedDocs as $name => $sha256) {
    privacy_expect(hash_file('sha256', $privacyDir . '/' . $name) === $sha256, $name . ' must match Factory ' . BRVTAL_PRIVACY_FACTORY_SHA . ' output byte-for-byte');
}

$privacyWorkflow = privacy_text('.github/workflows/privacidad.yml');
$auditWorkflow = privacy_text('.github/workflows/auditoria-privacidad.yml');
foreach ([$privacyWorkflow,$auditWorkflow] as $workflow) {
    privacy_expect(substr_count($workflow, BRVTAL_PRIVACY_FACTORY_SHA) === 2, 'caller and kit_ref must pin the same immutable Factory SHA');
    privacy_expect(!str_contains($workflow, 'secrets:'), 'privacy workflows must not inherit or declare secrets');
}
privacy_expect(str_contains($privacyWorkflow, "permissions:\n  contents: read"), 'PR privacy caller must be read-only');
privacy_expect(str_contains($auditWorkflow, "permissions:\n  contents: read\n  issues: write"), 'audit caller must have only contents read + issues write');
privacy_expect(str_contains($auditWorkflow, 'label_language: en'), 'BRVTAL privacy auditor must create English labels');

$allArtifacts = privacy_text('datos.yml');
foreach ($expectedDocs as $name => $_) $allArtifacts .= "\n" . privacy_text('docs/privacidad/' . $name);
privacy_expect(!preg_match('/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}/i', $allArtifacts), 'privacy artifacts must not embed a real email address');
privacy_expect(str_contains($allArtifacts, BRVTAL_PRIVACY_PLACEHOLDER), 'legal/controller placeholders must remain visible');
privacy_expect(str_contains(privacy_text('docs/privacidad/politica-tratamiento.md'), 'no constituye aprobación jurídica'), 'policy must not claim legal approval');

echo "BRVTAL privacy-as-code contract tests passed.\n";
