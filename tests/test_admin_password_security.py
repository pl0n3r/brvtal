import pathlib
import re
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]

class AdminPasswordSecurityTests(unittest.TestCase):
    def read(self, path: str) -> str:
        return (ROOT / path).read_text(encoding="utf-8")

    def test_authenticated_change_requires_current_password_csrf_and_revokes_other_sessions(self):
        api=self.read("api/index.php")
        auth=self.read("config/admin_auth.php")
        sec=self.read("config/admin_password_security.php")
        self.assertIn("brvtal_admin_require_csrf", api)
        self.assertIn("password_verify($currentPassword", sec)
        self.assertIn("credential_epoch=credential_epoch+1", sec)
        self.assertIn("password_change", sec)
        self.assertIn("brvtalAdminPasswordRateLimit", sec)
        self.assertIn("credential_epoch", auth)
        self.assertIn("brvtal_admin_login_session($adminId)", api)

    def test_reset_token_is_hash_only_single_use_expiring_and_reissue_revokes_previous(self):
        migration=self.read("database/migration_admin_password_security_01.sql")
        sec=self.read("config/admin_password_security.php")
        self.assertIn("token_hash CHAR(64)", migration)
        self.assertIn("BRVTAL_PASSWORD_RESET_TTL_SECONDS = 3600", sec)
        self.assertIn("revoked_at=NOW()", sec)
        self.assertIn("consumed_at=NOW()", sec)
        self.assertIn("hash('sha256', $token)", sec)
        self.assertNotRegex(migration, r"\btoken\s+VARCHAR")

    def test_forgot_password_is_non_enumerating_rate_limited_and_mail_failure_revokes_token(self):
        api=self.read("api/index.php")
        sec=self.read("config/admin_password_security.php")
        self.assertIn("Si la cuenta existe", api)
        self.assertIn("brvtal_password_rate_limit_failure", sec)
        self.assertIn("PASSWORD_RECOVERY_DELIVERY_FAILED", sec)
        self.assertIn("brvtal_password_reset_revoke_token", sec)
        self.assertIn("BRVTAL_PASSWORD_RESPONSE_FLOOR_NS = 2500000000", sec)
        self.assertIn("password_forgot", sec)
        self.assertIn("__account__", sec)

    def test_password_reset_preserves_totp_and_recovery_code_requirement(self):
        sec=self.read("config/admin_password_security.php")
        self.assertIn("totp_enabled", sec)
        self.assertIn("brvtal_totp_verify", sec)
        self.assertIn("brvtal_totp_recovery_verify", sec)
        self.assertIn("SECOND_FACTOR_REQUIRED", sec)
        self.assertNotIn("totp_enabled=0", sec)

    def test_reset_page_has_no_referrer_policy_and_no_token_logging(self):
        sec=self.read("config/admin_password_security.php")
        page=self.read("discadmin/reset-password.php")
        js=self.read("discadmin/password-recovery.js")
        mailer=self.read("config/admin_mailer.php")
        self.assertIn("/discadmin/reset-password.php#token=", sec)
        self.assertNotRegex(sec, r"brvtal_log\([^\n]+\$token")
        self.assertIn("rawurlencode($issued['token'])", sec)
        self.assertIn("Referrer-Policy: no-referrer", page)
        self.assertIn('name="referrer" content="no-referrer"', page)
        self.assertIn("history.replaceState", js)
        self.assertNotIn("console.log", js)
        self.assertIn("PHPMailer", mailer)
        self.assertNotIn("mail(", mailer)

    def test_migration_is_additive_and_reconciled(self):
        migration=self.read("database/migration_admin_password_security_01.sql")
        reconcile=self.read("config/migration_reconcile.php")
        self.assertIn("ADD COLUMN credential_epoch", migration)
        self.assertIn("admin_password_reset_tokens", reconcile)
        self.assertNotRegex(migration.upper(), r"\b(DROP|TRUNCATE|DELETE)\b")
        build=self.read("ops/factory/build")
        composer=self.read("composer.json")
        self.assertIn('"phpmailer/phpmailer": "^7.1"', composer)
        self.assertIn('"license": "proprietary"', composer)
        self.assertLess(build.index('composer --working-dir="$stage_root" install'), build.index("factory_transport stage --archive"))

if __name__ == "__main__":
    unittest.main()
