#!/usr/bin/env python3
"""BRVTAL #1120: offline S26-R005..R009 security contracts.

LOCAL_CONTRACT confirms tracked code contracts only, not migrations executed,
real credentials, production security, or coverage matrix reconciliation.
"""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
SPEC = ROOT / "docs/BRVTAL-SPEC.md"
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_CONTRACT"
IDS = tuple(f"S26-R{i:03d}" for i in range(5, 10))
SUMMARY = {
    "S26-R005": "admins.totp_secret_enc;",
    "S26-R006": "admins.totp_confirmed_at;",
    "S26-R007": "admin_recovery_codes;",
    "S26-R008": "a persistent private encryption key stored in the existing settings table",
    "S26-R009": "The TOTP encryption setting is private:",
}
PATHS = {
    "schema": "database/migration_totp_foundation.sql",
    "core": "config/totp.php",
    "login": "config/totp_auth.php",
    "enrollment": "discadmin/totp-api.php",
    "admin_api": "api/index.php",
    "contract": "tests/totp-enrollment-contract.php",
}


def matrix_rows(document: object) -> dict[str, tuple[str, ...]]:
    """Reject duplicate, missing, malformed, or promoted matrix evidence."""
    if not isinstance(document, str) or not document:
        raise ValueError("UNKNOWN: missing matrix")
    result = {}
    for line in document.splitlines():
        match = re.match(r"^\| (S26-R00[5-9]) · ", line)
        if not match:
            continue
        key = match.group(1)
        cols = tuple(x.strip() for x in line.strip().strip("|").split("|"))
        if (key in result or len(cols) != 5 or not all(cols)
                or not cols[0].startswith(key + " · [S26 L")
                or not cols[1].startswith(SUMMARY[key])
                or cols[2] != "NO_VERIFICABLE"
                or key not in cols[3]
                or "Sin aserción contrastada" not in cols[3]
                or cols[4] != "—"):
            raise ValueError("UNKNOWN: invalid row " + key)
        result[key] = cols
    if set(result) != set(IDS):
        raise ValueError("UNKNOWN: missing or ambiguous S26 clauses")
    return result


def sources() -> dict[str, str]:
    return {name: (ROOT / path).read_text(encoding="utf-8")
            for name, path in PATHS.items()}


def has(source: str, *tokens: str) -> bool:
    return all(token in source for token in tokens)


def local_evidence(key: object, rows: object, files: object) -> str:
    """Any incomplete or untrusted local source fails closed to UNKNOWN."""
    if (not isinstance(key, str) or key not in IDS
            or not isinstance(rows, dict) or key not in rows
            or not isinstance(rows[key], tuple) or len(rows[key]) != 5
            or rows[key][2] != "NO_VERIFICABLE"
            or not rows[key][1].startswith(SUMMARY[key])
            or not isinstance(files, dict) or set(files) != set(PATHS)
            or any(not isinstance(v, str) or not v.strip()
                   for v in files.values())):
        return UNKNOWN
    schema, core, login = files["schema"], files["core"], files["login"]
    enroll, admin, contract = (
        files["enrollment"], files["admin_api"], files["contract"]
    )
    begin = enroll.find("$pdo->beginTransaction();")
    lock = enroll.find("totp_admin_lock($pdo,$id)")
    assertions = {
        "S26-R005": (
            has(schema, "totp_secret_enc TEXT NULL", "totp_enabled TINYINT(1)")
            and has(enroll, "totp_encrypt($secret)", "totp_secret_enc=?",
                    "'aes-256-gcm'", "totp_assert_ready($pdo)")
            and has(login, "brvtal_totp_decryption_keys()",
                    "openssl_decrypt(", "'aes-256-gcm'", "return null;")
            and has(contract, "dedicated encryption key must remain preferred",
                    "totp_assert_ready($pdo)")
        ),
        "S26-R006": (
            has(schema, "totp_confirmed_at DATETIME NULL")
            and has(enroll, "totp_confirmed_at=NOW()", "totp_admin_lock($pdo,$id)",
                    "$pdo->beginTransaction();", "WHERE id=? AND totp_enabled=0",
                    "$enable->rowCount()!==1", "totp_confirmed_at=NULL")
            and begin >= 0 and lock > begin
        ),
        "S26-R007": (
            has(schema, "CREATE TABLE IF NOT EXISTS admin_recovery_codes",
                "code_hash VARCHAR(255)", "used_at DATETIME NULL",
                "FOREIGN KEY (admin_id) REFERENCES admins(id)")
            and has(enroll, "INSERT INTO admin_recovery_codes(admin_id,code_hash)",
                    "password_hash($plain,PASSWORD_DEFAULT)",
                    "$consume->rowCount()!==1", "'recovery_codes'=>$codes")
            and has(login, "password_verify($normalized", "used_at IS NULL",
                    "SET used_at=NOW()", "return $consume->rowCount() === 1;")
        ),
        "S26-R008": (
            has(core, "['encryption_key']", "['csrf_key']",
                "brvtal_totp_database_key(true)",
                "setting_key='security.totp_encryption_key'",
                "base64_encode(random_bytes(32))",
                "ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key)",
                "brvtal_totp_database_key(false)",
                "return hash('sha256', $databaseKey, true);")
            and has(login, "brvtal_totp_decryption_keys()")
        ),
        "S26-R009": (
            has(admin, "setting_key<>'security.totp_encryption_key'",
                "$method === 'DELETE' && $resource === 'settings'",
                "'PROTECTED_SETTING'", "'security.totp_encryption_key'")
            and admin.count("'PROTECTED_SETTING'") >= 2
            and has(contract, "admin Settings must never return",
                    "reject editing or deleting the encryption key")
        ),
    }
    return LOCAL if assertions[key] else UNKNOWN


def full_verdict(key: object, observed: object) -> str:
    """A source-only fixture cannot prove a completed production deployment."""
    return UNKNOWN


class BrechasBrvtal30Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.doc = MATRIX.read_text(encoding="utf-8")
        cls.rows = matrix_rows(cls.doc)
        cls.files = sources()

    def test_contrato_principal(self) -> None:
        """AC-01: explicit local assertions for all five TOTP clauses."""
        spec = SPEC.read_text(encoding="utf-8")
        self.assertIn("## 26. Security / 2FA", spec)
        self.assertEqual(set(IDS), set(self.rows))
        for key in IDS:
            with self.subTest(clause=key):
                self.assertIn(SUMMARY[key], spec)
                self.assertEqual(self.rows[key][2], "NO_VERIFICABLE")
                self.assertEqual(local_evidence(key, self.rows, self.files), LOCAL)
                self.assertEqual(full_verdict(key, LOCAL), UNKNOWN)

    def test_falla_cerrado(self) -> None:
        """AC-02: missing/malformed source or conflicting claims => UNKNOWN."""
        row = next(x for x in self.doc.splitlines()
                   if x.startswith("| S26-R005 · "))
        for doc in (self.doc.replace(row, "", 1),
                    self.doc + "\n" + row,
                    self.doc.replace(row, row.replace(
                        "NO_VERIFICABLE", "CUBIERTO", 1))):
            with self.assertRaisesRegex(ValueError, UNKNOWN):
                matrix_rows(doc)
        for key, src, token in (
            ("S26-R005", "schema", "totp_secret_enc TEXT NULL"),
            ("S26-R006", "enrollment", "totp_confirmed_at=NOW()"),
            ("S26-R007", "login", "SET used_at=NOW()"),
            ("S26-R008", "core",
             "ON DUPLICATE KEY UPDATE setting_key=VALUES(setting_key)"),
            ("S26-R009", "admin_api",
             "setting_key<>'security.totp_encryption_key'"),
        ):
            with self.subTest(clause=key):
                modified = dict(self.files)
                self.assertIn(token, modified[src])
                modified[src] = modified[src].replace(token, "", 1)
                self.assertEqual(local_evidence(key, self.rows, modified), UNKNOWN)
        for key in IDS:
            self.assertEqual(local_evidence(key, {}, self.files), UNKNOWN)
            self.assertEqual(local_evidence(key, self.rows, {}), UNKNOWN)
            self.assertEqual(local_evidence(
                key, self.rows, {**self.files, "untrusted": "fixture"}), UNKNOWN)
        self.assertEqual(local_evidence("S26-R999", self.rows, self.files),
                         UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        """AC-03: repository-only reads, no network, real key, or personal data."""
        for key, relative in PATHS.items():
            with self.subTest(source=key):
                file = ROOT / relative
                self.assertTrue(file.is_file())
                self.assertTrue(file.resolve().is_relative_to(ROOT.resolve()))
        synthetic = {"admin": "synthetic:admin", "tenant": "synthetic:tenant",
                     "recovery": "synthetic:recovery"}
        for item in synthetic.values():
            self.assertTrue(item.startswith("synthetic:"))
            self.assertNotIn("@", item)
        self.assertEqual(full_verdict("S26-R007", synthetic), UNKNOWN)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: source proof cannot elevate matrix or release state."""
        states = {key: full_verdict(
            key, local_evidence(key, self.rows, self.files)) for key in IDS}
        self.assertEqual(states, dict.fromkeys(IDS, UNKNOWN))
        self.assertEqual(full_verdict("S26-R005", None), UNKNOWN)
        self.assertEqual(full_verdict("S26-R009", "not-verified"), UNKNOWN)
        self.assertIn("'PROTECTED_SETTING'", self.files["admin_api"])
        self.assertNotIn("admin_recovery_codes", self.files["schema"].split(
            "CREATE TABLE IF NOT EXISTS admin_recovery_codes", 1)[0])


if __name__ == "__main__":
    unittest.main()
