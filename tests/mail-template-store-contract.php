<?php
declare(strict_types=1);
/**
 * Fast, php85-compatibility.sh auto-discovered source-only contract.
 * The integration runner invokes the same file against isolated MariaDB.
 */
require __DIR__ . '/integration/mail-template-store.php';
$source=(string)file_get_contents(__DIR__ . '/../config/mail_template_store.php');
$catalog=(string)file_get_contents(__DIR__ . '/../config/mail_template_catalog.php');
$schema=(string)file_get_contents(__DIR__ . '/../database/migration_mail_templates_01.sql');
$proof=(string)file_get_contents(__DIR__ . '/../config/migration_reconcile.php');
if (!str_contains($source, 'FOR UPDATE')
    || !str_contains($source, 'MAIL_TEMPLATE_CONFLICT')
    || !str_contains($source, 'first_published_at')
    || !str_contains($source, 'brvtal_mail_template_resolve')
    || !str_contains($source, 'brvtal_mail_template_discard_draft')
    || !str_contains($source, 'brvtal_mail_template_read_draft')
    || !str_contains($catalog, 'MAIL_TEMPLATE_VARIABLE_FORBIDDEN')
    || !str_contains($catalog, 'MAIL_TEMPLATE_MEDIA_FORBIDDEN')
    || !str_contains($schema, 'CREATE TABLE IF NOT EXISTS mail_template_registry')
    || !str_contains($schema, 'CREATE TABLE IF NOT EXISTS mail_template_versions')
    || !str_contains($proof, "migration_mail_templates_01.sql")
    || str_contains($schema, 'recipient_email')
    || str_contains($schema, 'token_hash')
) {
    throw new RuntimeException('MAIL TEMPLATE STORE CONTRACT FAILED');
}
echo "BRVTAL mail-template store PHP source contract passed.\n";
