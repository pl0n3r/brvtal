from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]


class AdminActivityAuditCoverageTests(unittest.TestCase):
    def test_media_mutations_are_audited_without_sensitive_payloads(self):
        activity = (ROOT / "config/admin_activity.php").read_text()
        media = (ROOT / "api/media-library.php").read_text()
        self.assertIn("'media' => ['id','type','title','file_path','mime_type','file_size','alt_text','status']", activity)
        for action in ("upload", "register", "update", "transform", "delete"):
            self.assertIn(f"'{action}',\n", media)
        self.assertNotIn("'content_hash' =>", activity.split("'media' =>", 1)[1].split("],", 1)[0])

    def test_settings_audit_uses_explicit_safe_allowlist(self):
        activity = (ROOT / "config/admin_activity.php").read_text()
        api = (ROOT / "api/index.php").read_text()
        self.assertIn("function brvtalActivitySettingKeyAuditable", activity)
        self.assertIn("'settings' => ['setting_key','is_json']", activity)
        self.assertIn("brvtalActivitySettingKeyAuditable($key)", api)
        self.assertNotIn("'settings' => ['setting_key','setting_value'", activity)

    def test_theme_settings_flow_is_audited(self):
        api = (ROOT / "api/index.php").read_text()
        self.assertIn("$themeMutation = str_starts_with($key, 'theme.');", api)
        self.assertIn("'setting_update'", api)
        self.assertIn("'source' => $themeMutation ? 'theme_studio' : 'settings_api'", api)
        self.assertIn("'setting_key' => $key", api)

    def test_audit_is_committed_atomically_with_settings_and_media(self):
        core = (ROOT / "api/index.php").read_text()
        media = (ROOT / "api/media-library.php").read_text()

        settings_update = core.index("'setting_update'")
        settings_update_commit = core.index("$pdo->commit();", settings_update)
        settings_update_indexnow = core.index("brvtalIndexNowNotifySetting($pdo, $key);", settings_update_commit)
        self.assertLess(settings_update, settings_update_commit)
        self.assertLess(settings_update_commit, settings_update_indexnow)

        settings_delete = core.index("'setting_delete'")
        settings_delete_commit = core.index("$pdo->commit();", settings_delete)
        self.assertLess(settings_delete, settings_delete_commit)

        upload_preflight = media.index("if (!brvtal_activity_schema_ready($pdo))")
        upload_move = media.index("move_uploaded_file($tmp, $absolute)")
        self.assertLess(upload_preflight, upload_move)

        register = media.index("if ($method === 'POST' && $action === 'register')")
        register_tx = media.index("$pdo->beginTransaction();", register)
        register_insert = media.index("INSERT INTO media", register)
        register_audit = media.index("'register'", register_insert)
        register_commit = media.index("$pdo->commit();", register_audit)
        self.assertLess(register_tx, register_insert)
        self.assertLess(register_insert, register_audit)
        self.assertLess(register_audit, register_commit)

        transform = media.index("if ($method === 'POST' && $action === 'transform')")
        transform_audit = media.index("'transform'", transform)
        transform_commit = media.index("$pdo->commit();", transform_audit)
        self.assertLess(transform_audit, transform_commit)

    def test_existing_activity_resources_remain_covered(self):
        activity = (ROOT / "config/admin_activity.php").read_text()
        for resource in ("events", "artists", "sets", "pages", "ticket_types", "releases", "blog", "event_lineup"):
            self.assertIn(f"'{resource}' =>", activity)


if __name__ == "__main__":
    unittest.main()
