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
        self.assertIn("function brvtal_activity_setting_key_auditable", activity)
        self.assertIn("'settings' => ['setting_key','is_json']", activity)
        self.assertIn("brvtal_activity_setting_key_auditable($key)", api)
        self.assertNotIn("'settings' => ['setting_key','setting_value'", activity)

    def test_theme_settings_flow_is_audited(self):
        api = (ROOT / "api/index.php").read_text()
        self.assertIn("$themeMutation = str_starts_with($key, 'theme.');", api)
        self.assertIn("'setting_update'", api)
        self.assertIn("'source' => $themeMutation ? 'theme_studio' : 'settings_api'", api)
        self.assertIn("'setting_key' => $key", api)

    def test_existing_activity_resources_remain_covered(self):
        activity = (ROOT / "config/admin_activity.php").read_text()
        for resource in ("events", "artists", "sets", "pages", "ticket_types", "releases", "blog", "event_lineup"):
            self.assertIn(f"'{resource}' =>", activity)


if __name__ == "__main__":
    unittest.main()
