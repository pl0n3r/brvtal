import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class LegacyPrivacyInventoryTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.data = json.loads((ROOT / "datos.yml").read_text(encoding="utf-8"))
        cls.treatments = {row["id"]: row for row in cls.data["treatments"]}

    def test_legacy_ip_is_declared_separately(self):
        row = self.treatments["admin_activity_legacy_ip"]
        self.assertEqual(row["category"], "location")
        self.assertEqual(row["fields"], ["ip_address"])
        self.assertEqual(row["purpose"], "admin_activity_audit")
        self.assertEqual(row["basis"], "review_required")
        self.assertEqual(row["retention"], "review_required")
        self.assertEqual(row["consent"], "review_required")
        self.assertEqual(row["providers"], [])

    def test_modern_admin_activity_does_not_claim_ip(self):
        modern = self.treatments["admin_activity"]
        self.assertNotIn("ip_address", modern["fields"])
        self.assertEqual(modern["purpose"], "admin_activity_audit")

    def test_generated_docs_include_legacy_ip_treatment(self):
        for name in (
            "politica-tratamiento.md",
            "aviso-privacidad.md",
            "registro-tratamientos.md",
            "retencion.md",
        ):
            text = (ROOT / "docs" / "privacidad" / name).read_text(encoding="utf-8")
            self.assertIn("admin_activity_legacy_ip", text)
            if name != "retencion.md":
                self.assertIn("ip_address", text)

    def test_legacy_migration_signal_remains_inventory_covered(self):
        migration = (ROOT / "database" / "v4-cms-migration.sql").read_text(encoding="utf-8")
        self.assertIn("CREATE TABLE IF NOT EXISTS activity_log", migration)
        self.assertIn("ip_address VARCHAR(45) NULL", migration)
        declared = {
            field
            for treatment in self.data["treatments"]
            for field in treatment["fields"]
        }
        self.assertIn("ip_address", declared)

    def test_privacy_workflow_still_supports_fresh_owner_audit(self):
        workflow = (ROOT / ".github" / "workflows" / "auditoria-privacidad.yml").read_text(encoding="utf-8")
        self.assertIn("issue_comment:", workflow)
        self.assertIn("github.event.issue.pull_request == null", workflow)
        self.assertIn("github.event.issue.number == 736", workflow)
        self.assertIn("github.event.comment.user.login == github.repository_owner", workflow)
        self.assertIn("github.event.comment.author_association == 'OWNER'", workflow)
        self.assertIn("github.event.comment.body == '/privacy-audit'", workflow)


if __name__ == "__main__":
    unittest.main()
