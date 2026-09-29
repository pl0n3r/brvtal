import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class PrivacyLegacyIpTests(unittest.TestCase):
    def data(self):
        return json.loads((ROOT / "datos.yml").read_text(encoding="utf-8"))

    def treatment(self, treatment_id):
        rows = [row for row in self.data()["treatments"] if row["id"] == treatment_id]
        self.assertEqual(len(rows), 1)
        return rows[0]

    def test_legacy_ip_treatment_is_exact_and_review_required(self):
        self.assertEqual(
            self.treatment("legacy_admin_activity_ip"),
            {
                "id": "legacy_admin_activity_ip",
                "category": "usage",
                "fields": ["ip_address"],
                "purpose": "legacy_admin_activity_audit",
                "basis": "review_required",
                "retention": "review_required",
                "consent": "review_required",
                "providers": [],
            },
        )

    def test_current_rate_limits_remain_hash_only(self):
        self.assertEqual(
            self.treatment("auth_password_rate_limit")["fields"],
            ["client_key_hash", "attempts", "blocked_until"],
        )
        self.assertEqual(
            self.treatment("auth_totp_rate_limit")["fields"],
            ["client_key_hash", "attempts", "blocked_until"],
        )
        self.assertEqual(
            self.treatment("contact_rate_limit")["fields"],
            ["client_key_hash", "timestamps"],
        )
        for treatment_id in (
            "auth_password_rate_limit",
            "auth_totp_rate_limit",
            "contact_rate_limit",
        ):
            self.assertNotIn("ip_address", self.treatment(treatment_id)["fields"])

    def test_generated_privacy_docs_include_legacy_ip_treatment(self):
        policy_row = (
            "| legacy_admin_activity_ip | usage | ip_address | "
            "legacy_admin_activity_audit | review_required | review_required | "
            "ninguno_declarado | review_required |"
        )
        for name in ("politica-tratamiento.md", "aviso-privacidad.md"):
            text = (ROOT / "docs" / "privacidad" / name).read_text(encoding="utf-8")
            self.assertIn(policy_row, text)

        register = (ROOT / "docs" / "privacidad" / "registro-tratamientos.md").read_text(
            encoding="utf-8"
        )
        self.assertIn("## legacy_admin_activity_ip", register)
        self.assertIn("- Campos de software: `ip_address`", register)
        self.assertIn("- Proveedores: ninguno_declarado", register)

        retention = (ROOT / "docs" / "privacidad" / "retencion.md").read_text(
            encoding="utf-8"
        )
        self.assertIn(
            "| legacy_admin_activity_ip | usage | review_required | review_required |",
            retention,
        )


if __name__ == "__main__":
    unittest.main()
