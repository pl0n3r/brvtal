"""Privacy settings UI, server validation and activity logging contracts."""
from pathlib import Path
import json
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]


class PrivacySettingsAdminTests(unittest.TestCase):
    def setUp(self):
        self.ui = (ROOT / 'discadmin/settings-v2.js').read_text()
        self.api = (ROOT / 'api/index.php').read_text()
        self.activity = (ROOT / 'config/admin_activity.php').read_text()
        self.public = (ROOT / 'config/public_privacy_rights.php').read_text()

    def test_editable_fields_and_completion_status(self):
        for key in ('privacy.controller_name', 'privacy.controller_email', 'privacy.response_time'):
            self.assertIn("row('" + key + "')", self.ui)
            self.assertIn("['" + key + "',read(", self.ui)
            self.assertIn("'" + key + "'", self.api)
            self.assertIn("'" + key + "'", self.activity)
        for field in ('privacy_controller_name', 'privacy_controller_email', 'privacy_response_time'):
            self.assertIn("text('" + field + "'", self.ui)
        self.assertIn('data-privacy-config-status', self.ui)
        self.assertIn("privacyConfigured === 3", self.ui)
        self.assertIn('data-settings-save="privacy"', self.ui)
        self.assertIn('privacy:savePrivacy', self.ui)

    def test_client_and_server_validate_all_privacy_fields(self):
        self.assertIn('function validPrivacyText(value,limit)', self.ui)
        self.assertIn("key === 'privacy.controller_email'", self.ui)
        self.assertIn('filter_var($plain, FILTER_VALIDATE_EMAIL)', self.api)
        self.assertIn("'INVALID_PRIVACY_EMAIL'", self.api)
        self.assertIn("'INVALID_PRIVACY_VALUE'", self.api)
        self.assertIn("if ($isJson !== 0", self.api)
        self.assertIn("'field' => 'setting_value'", self.api)
        self.assertIn("in_array($key, ['privacy.controller_email','privacy.controller_address'], true)", self.api)
        self.assertIn("in_array($key, ['privacy.controller_name','privacy.response_time'], true)", self.api)
        self.assertIn('$privacyLimit = 400;', self.api)

    def test_saves_use_csrf_protected_settings_without_raw_editor(self):
        self.assertIn("await req('/settings', {", self.ui)
        self.assertIn("method:'POST'", self.ui)
        self.assertIn("setting_key:key,setting_value:value,is_json:0", self.ui)
        self.assertIn("requestAnimationFrame(() => activate(V2.tab))", self.ui)
        self.assertIn("if($method!=='GET')brvtal_admin_require_csrf();", self.api)

    def test_activity_allowlist_logs_metadata_only(self):
        self.assertIn('brvtalActivitySettingKeyAuditable($key)', self.api)
        self.assertIn("'value_changed' => true", self.api)
        self.assertIn("'settings' => ['setting_key','is_json']", self.activity)
        self.assertNotIn("'setting_value' => $value", self.api)
        self.assertNotRegex(self.activity, r"'privacy\.controller_email'\s*=>")

    def test_public_page_preserves_pending_placeholders(self):
        for field in ('controller_name','controller_email','response_time'):
            self.assertIn("'" + field + "'", self.public)
        self.assertIn('BRVTAL_PRIVACY_OWNER_PLACEHOLDER', self.public)
        self.assertIn('BRVTAL_PRIVACY_LEGAL_STATUS', self.public)
        self.assertNotIn('value="[COMPLETAR', self.ui)

    def test_two_distinct_colombian_rights_procedures(self):
        for key in ('privacy.consultation_time', 'privacy.claim_time'):
            self.assertIn("row('" + key + "')", self.ui)
            self.assertIn("['" + key + "',read(", self.ui)
            self.assertIn("'" + key + "'", self.api)
        self.assertIn("data-privacy-consultation-time", self.public)
        self.assertIn("data-privacy-claim-time", self.public)
        self.assertIn("BRVTAL_PRIVACY_LEGAL_STATUS", self.public)
        self.assertIn("if($method!=='GET')brvtal_admin_require_csrf();", self.api)

    def test_extended_privacy_activity_logs_metadata_only(self):
        for key in ('controller_domicile','controller_address',
                    'rights_contact_area','consultation_time','claim_time'):
            self.assertIn("'privacy." + key + "'", self.activity)
            self.assertIn("'privacy." + key + "'", self.api)
        self.assertIn("'value_changed' => true", self.api)
        self.assertNotIn("'setting_value' => $value", self.api)
        self.assertIn("'settings' => ['setting_key','is_json']", self.activity)

    def test_version_alignment(self):
        version_source = (ROOT/'config/version.php').read_text()
        match = re.search(r"BRVTAL_APP_VERSION\s*=\s*'([^']+)'", version_source)
        self.assertIsNotNone(match)
        self.assertEqual(json.loads((ROOT/'package.json').read_text())['version'], match.group(1))


if __name__ == '__main__':
    unittest.main()
