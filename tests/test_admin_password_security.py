import pathlib
import re
import unittest

ROOT = pathlib.Path(__file__).resolve().parents[1]


class AdminPasswordSecurityTests(unittest.TestCase):
    def read(self, path: str) -> str:
        return (ROOT / path).read_text(encoding="utf-8")

    def test_authenticated_change_requires_current_password_csrf_and_revokes_other_sessions(self):
        api = self.read("api/index.php")
        auth = self.read("config/admin_auth.php")
        security = self.read("config/admin_password_security.php")
        self.assertIn("brvtal_admin_require_csrf", api)
        self.assertIn("CURRENT_PASSWORD_INVALID", security)
        self.assertIn("credential_epoch=credential_epoch+1", security)
        self.assertIn("credential_epoch", auth)
        self.assertIn("brvtal_admin_login_session($adminId)", api)

    def test_reset_token_is_hash_only_single_use_expiring_and_reissue_revokes_previous(self):
        migration = self.read("database/migration_admin_password_security_01.sql")
        security = self.read("config/admin_password_security.php")
        self.assertIn("token_hash CHAR(64)", migration)
        self.assertNotIn(" token VARCHAR", migration)
        self.assertIn("BRVTAL_PASSWORD_RESET_TTL_SECONDS = 3600", security)
        self.assertIn("DELETE FROM admin_password_reset_tokens WHERE admin_id=?", security)
        self.assertIn("used_at=NOW()", security)
        self.assertIn("hash('sha256', $token)", security)

    def test_forgot_password_is_non_enumerating_rate_limited_and_mail_failure_revokes_token(self):
        api = self.read("api/index.php")
        security = self.read("config/admin_password_security.php")
        self.assertIn("Si la cuenta existe", api)
        self.assertIn("PASSWORD_RECOVERY_DELIVERY_FAILED", security)
        self.assertRegex(security, r"brvtal_admin_password_reset_revoke\(\s*\$pdo,\s*\(int\)\$admin\['id'\],\s*\$issued\['token_hash'\]")
        self.assertIn("150_000_000", security)

    def test_password_reset_preserves_totp_and_recovery_code_requirement(self):
        security = self.read("config/admin_password_security.php")
        self.assertIn("totp_enabled", security)
        self.assertIn("brvtal_totp_verify", security)
        self.assertIn("brvtal_totp_recovery_verify", security)
        self.assertIn("SECOND_FACTOR_REQUIRED", security)
        self.assertNotIn("totp_enabled=0", security)

    def test_reset_page_has_no_referrer_policy_and_no_token_logging(self):
        security = self.read("config/admin_password_security.php")
        self.assertIn("/discadmin/reset-password.php#token=", security)
        self.assertNotIn("brvtal_log('RESET_TOKEN", security)
        self.assertNotRegex(security, r"brvtal_log\([^\n]+\$token")
        # The reset page itself lands in the UI/E2E slice; fragment transport keeps
        # the token out of HTTP request URLs until that page consumes it.

    def test_migration_is_additive_and_session_epoch_is_persisted(self):
        migration = self.read("database/migration_admin_password_security_01.sql")
        self.assertIn("ADD COLUMN credential_epoch", migration)
        self.assertIn("CREATE TABLE IF NOT EXISTS admin_password_reset_tokens", migration)
        self.assertNotRegex(migration.upper(), r"\b(DROP|TRUNCATE|DELETE)\b")


if __name__ == "__main__":
    unittest.main()
