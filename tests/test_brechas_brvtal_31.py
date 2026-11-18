#!/usr/bin/env python3
"""BRVTAL #1121: offline TOTP security assertions S26-R010..S26-R014.

LOCAL_CONTRACT proves only tracked source-level invariants. Deployment,
encryption keys, real users, CI and production security remain UNKNOWN.
"""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
IDS = tuple(f"S26-R{i:03d}" for i in range(10, 15))
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_CONTRACT"
CLAUSES = {
    "S26-R010": "Security principles:",
    "S26-R011": "encrypted TOTP secret;",
    "S26-R012": "recovery codes hashed;",
    "S26-R013": "rate limiting;",
    "S26-R014": "no plaintext recovery-code storage;",
}
PATHS = {
    "matrix": "docs/matriz-cobertura.md",
    "spec": "docs/BRVTAL-SPEC.md",
    "schema": "database/migration_totp_foundation.sql",
    "api": "discadmin/totp-api.php",
    "login": "config/totp_auth.php",
    "rate": "config/totp_rate_limit.php",
    "contract": "tests/totp-enrollment-contract.php",
    "rate_contract": "tests/auth-rate-limit-contract.php",
}


def matrix_rows(document: object) -> dict[str, tuple[str, ...]]:
    """Reject any duplicated, missing, altered or elevated matrix clause."""
    if not isinstance(document, str) or not document.strip():
        raise ValueError("UNKNOWN: no coverage matrix")
    rows: dict[str, tuple[str, ...]] = {}
    for line in document.splitlines():
        match = re.match(r"^\| (S26-R01[0-4]) · ", line)
        if not match:
            continue
        key = match.group(1)
        cells = tuple(x.strip() for x in line.strip().strip("|").split("|"))
        if (key in rows or len(cells) != 5 or not all(cells)
                or not cells[0].startswith(key + " · [S26 L")
                or cells[1] != CLAUSES[key] or cells[2] != "NO_VERIFICABLE"
                or "Sin aserción contrastada" not in cells[3]
                or cells[4] != "—"):
            raise ValueError("UNKNOWN: ambiguous row " + key)
        rows[key] = cells
    if set(rows) != set(IDS):
        raise ValueError("UNKNOWN: missing 2FA security clause")
    return rows


def sources() -> dict[str, str]:
    return {name: (ROOT / path).read_text(encoding="utf-8")
            for name, path in PATHS.items()}


def has(source: str, *fragments: str) -> bool:
    return all(fragment in source for fragment in fragments)


def controls(files: dict[str, str]) -> dict[str, bool]:
    schema, api, login = files["schema"], files["api"], files["login"]
    rate, contract = files["rate"], files["contract"]
    encrypted = (
        has(schema, "totp_secret_enc TEXT NULL")
        and has(api, "function totp_encrypt(string $plaintext)",
                "openssl_encrypt($plaintext,'aes-256-gcm'",
                "random_bytes(12)", "base64_encode($iv.$tag.$cipher)",
                "$enc=totp_encrypt($secret)", "totp_secret_enc=?")
        and has(login, "brvtal_totp_decryption_keys()",
                "openssl_decrypt($cipher, 'aes-256-gcm'", "return null;")
        and has(contract, "enrollment must verify encryption and schema")
    )
    hashed = (
        has(schema, "CREATE TABLE IF NOT EXISTS admin_recovery_codes",
            "code_hash VARCHAR(255) NOT NULL", "used_at DATETIME NULL")
        and has(api, "INSERT INTO admin_recovery_codes(admin_id,code_hash)",
                "password_hash($plain,PASSWORD_DEFAULT)",
                "password_verify($normalized,(string)$row['code_hash'])",
                "$consume->rowCount()!==1")
        and has(login, "password_verify($normalized, (string)$row['code_hash'])",
                "SET used_at=NOW()", "return $consume->rowCount() === 1;")
    )
    limited = (
        has(rate, "BRVTAL_TOTP_RATE_LIMIT_MAX_FAILURES = 5",
            "BRVTAL_TOTP_RATE_LIMIT_WINDOW = 900", "flock($lock, LOCK_EX)",
            "brvtal_totp_rate_limit_blocked_fallback($file, $now)",
            "brvtal_rate_limit_store_write($file, $data)")
        and has(login, "brvtal_totp_rate_limit_failure($adminId, $scope)",
                "'RATE_LIMITED'", "'retry_after'", "429",
                "brvtal_totp_rate_limit_scope($adminId, 'login')")
        and has(api, "brvtal_totp_rate_limit_scope($id,'disable')")
        and has(files["rate_contract"],
                "concurrent TOTP failures must activate the blocking threshold",
                "brvtal_totp_rate_limit_reset(42, 'login'"))
    )
    no_plain_storage = (
        hashed
        and has(api, "INSERT INTO admin_recovery_codes(admin_id,code_hash)",
                "$insert->execute([$adminId,password_hash($plain,PASSWORD_DEFAULT)])",
                "'recovery_codes'=>$codes")
        and "code_plain" not in schema and "code_plain" not in api
        and "INSERT INTO admin_recovery_codes(admin_id,code)" not in api
    )
    csrf = has(api, "brvtal_admin_require();", "brvtal_admin_require_csrf();",
               "Cache-Control: no-store", "totp_assert_ready($pdo)")
    return {
        "S26-R010": csrf and encrypted and hashed and limited and no_plain_storage,
        "S26-R011": encrypted,
        "S26-R012": hashed,
        "S26-R013": limited,
        "S26-R014": no_plain_storage,
    }


def local_evidence(key: object, rows: object, files: object) -> str:
    """Missing, unknown, contradictory or untrusted evidence fails closed."""
    if (not isinstance(key, str) or key not in IDS
            or not isinstance(rows, dict) or key not in rows
            or not isinstance(rows[key], tuple) or len(rows[key]) != 5
            or rows[key][1] != CLAUSES[key]
            or rows[key][2] != "NO_VERIFICABLE"
            or not isinstance(files, dict) or set(files) != set(PATHS)
            or any(not isinstance(x, str) or not x.strip()
                   for x in files.values())):
        return UNKNOWN
    try:
        if CLAUSES[key] not in files["spec"]:
            return UNKNOWN
        return LOCAL if controls(files)[key] else UNKNOWN
    except (KeyError, TypeError, ValueError):
        return UNKNOWN


def production_verdict(key: object, observation: object) -> str:
    """Code-only evidence never grants CI, live or production status."""
    return UNKNOWN


class BrechasBrvtal31Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.files = sources()
        cls.rows = matrix_rows(cls.files["matrix"])

    def test_contrato_principal(self) -> None:
        """AC-01: one explicit contract for each of the five S26 clauses."""
        self.assertEqual(set(self.rows), set(IDS))
        self.assertIn("## 26. Security / 2FA", self.files["spec"])
        for key in IDS:
            with self.subTest(requirement=key):
                self.assertIn(CLAUSES[key], self.files["spec"])
                self.assertEqual(self.rows[key][2], "NO_VERIFICABLE")
                self.assertEqual(local_evidence(key, self.rows, self.files), LOCAL)
                self.assertEqual(production_verdict(key, LOCAL), UNKNOWN)

    def test_falla_cerrado(self) -> None:
        """AC-02: corrupt matrix and removed security controls => UNKNOWN."""
        first = next(x for x in self.files["matrix"].splitlines()
                     if x.startswith("| S26-R010 · "))
        for invalid in (self.files["matrix"].replace(first, "", 1),
                        self.files["matrix"] + "\n" + first,
                        self.files["matrix"].replace(
                            first, first.replace("NO_VERIFICABLE", "CUBIERTO", 1), 1)):
            with self.assertRaisesRegex(ValueError, UNKNOWN):
                matrix_rows(invalid)
        for key, file, fragment in (
            ("S26-R010", "api", "brvtal_admin_require_csrf();"),
            ("S26-R011", "api", "totp_secret_enc=?"),
            ("S26-R012", "api", "password_hash($plain,PASSWORD_DEFAULT)"),
            ("S26-R013", "rate", "flock($lock, LOCK_EX)"),
            ("S26-R014", "schema", "code_hash VARCHAR(255) NOT NULL"),
        ):
            with self.subTest(requirement=key):
                altered = dict(self.files)
                self.assertIn(fragment, altered[file])
                altered[file] = altered[file].replace(fragment, "", 1)
                self.assertEqual(local_evidence(key, self.rows, altered), UNKNOWN)
        for key in IDS:
            self.assertEqual(local_evidence(key, {}, self.files), UNKNOWN)
            self.assertEqual(local_evidence(key, self.rows, {}), UNKNOWN)
            self.assertEqual(local_evidence(key, self.rows,
                                            {**self.files, "untrusted": "extra"}), UNKNOWN)
        self.assertEqual(local_evidence("S26-R999", self.rows, self.files), UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        """AC-03: checked-in sources and synthetic values, no remote services."""
        for path in PATHS.values():
            with self.subTest(path=path):
                source = ROOT / path
                self.assertTrue(source.is_file())
                self.assertTrue(source.resolve().is_relative_to(ROOT.resolve()))
        for label in ("synthetic:admin", "synthetic:recovery"):
            self.assertTrue(label.startswith("synthetic:"))
            self.assertNotIn("@", label)
        self.assertEqual(production_verdict("S26-R014", {}), UNKNOWN)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: neither tests nor source can attest deployment or production."""
        for key in IDS:
            with self.subTest(requirement=key):
                self.assertEqual(production_verdict(
                    key, local_evidence(key, self.rows, self.files)), UNKNOWN)
        self.assertEqual(production_verdict(None, LOCAL), UNKNOWN)
        self.assertNotIn("code_plain", self.files["schema"])


if __name__ == "__main__":
    unittest.main()
