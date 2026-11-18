#!/usr/bin/env python3
"""BRVTAL #1122: five isolated security/admin coverage gap regressions.

LOCAL_CONTRACT documents only what tracked code and synthetic fixtures prove.
The coverage matrix remains NO_VERIFICABLE; real accounts, deployed 2FA,
production encryption and browser CI runs remain UNKNOWN without exact evidence.
"""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_CONTRACT"
IDS = ("S26-R015", "S26-R016", "S26-R017", "S27-R001", "S27-R002")
CLAUSES = {
    "S26-R015": "no automatic 2FA disabling when login verification fails.",
    "S26-R016": "AES-256-GCM has been used for the encrypted secret.",
    "S26-R017": "CI includes a dedicated browser flow for password → TOTP challenge → verified session → DISCADMIN bootstrap, including a WebKit run for the regression that affected Safari.",
    "S27-R001": "Multiple administrators are allowed.",
    "S27-R002": "For now they may share the same permissions; do not add complex RBAC prematurely.",
}
PATHS = {
    "matrix": "docs/matriz-cobertura.md",
    "spec": "docs/BRVTAL-SPEC.md",
    "api": "api/index.php",
    "enrollment": "discadmin/totp-api.php",
    "totp_auth": "config/totp_auth.php",
    "totp": "config/totp.php",
    "schema": "database/schema.sql",
    "totp_sql": "database/migration_totp_foundation.sql",
    "session": "config/admin_auth.php",
    "session_state": "config/admin_session_revalidation.php",
    "playwright": "playwright.config.mjs",
    "browser": "tests/e2e/discadmin-totp-login.spec.mjs",
    "ci": ".github/workflows/factory-ci.yml",
}


def matrix_rows(document: object) -> dict[str, tuple[str, ...]]:
    """Duplicates, missing clauses, forged status and malformed cells fail closed."""
    if not isinstance(document, str) or not document.strip():
        raise ValueError("UNKNOWN: missing coverage matrix")
    rows = {}
    for line in document.splitlines():
        found = re.match(r"^\| (S\d\d-R\d{3}) · ", line)
        if not found or found.group(1) not in IDS:
            continue
        ident = found.group(1)
        cells = tuple(cell.strip() for cell in line.strip().strip("|").split("|"))
        if (ident in rows or len(cells) != 5 or not all(cells[:4])
                or not cells[0].startswith(ident + " · [S")
                or cells[1] != CLAUSES[ident]
                or cells[2] != "NO_VERIFICABLE"
                or "Sin aserción contrastada" not in cells[3]
                or cells[4] != "—"):
            raise ValueError("UNKNOWN: ambiguous matrix row " + ident)
        rows[ident] = cells
    if set(rows) != set(IDS):
        raise ValueError("UNKNOWN: missing coverage clause")
    return rows


def read_sources() -> dict[str, str]:
    return {name: (ROOT / path).read_text(encoding="utf-8")
            for name, path in PATHS.items()}


def has(source: str, *fragments: str) -> bool:
    return all(fragment in source for fragment in fragments)


def local_verdict(ident: object, files: object) -> str:
    """Never promote incomplete/replaced source to execution or live evidence."""
    if (not isinstance(ident, str) or ident not in IDS
            or not isinstance(files, dict) or set(files) != set(PATHS)
            or any(not isinstance(v, str) or not v.strip()
                   for v in files.values())):
        return UNKNOWN
    try:
        row = matrix_rows(files["matrix"])[ident]
    except (ValueError, KeyError):
        return UNKNOWN
    if row[1] not in files["spec"] or row[2] != "NO_VERIFICABLE":
        return UNKNOWN

    api = files["api"]
    enroll = files["enrollment"]
    if ident == "S26-R015":
        # A failed login challenge returns 401; ONLY the authenticated,
        # separately selected disable action can write totp_enabled=0.
        marker = "if(($d['action'] ?? '') === 'totp_verify') {"
        if marker not in api or "} $email=strtolower" not in api:
            return UNKNOWN
        challenge = api.split(marker, 1)[1].split("} $email=strtolower", 1)[0]
        disable = enroll.split("if($action==='disable')", 1)[-1]
        valid = (has(challenge, "brvtal_totp_pending_admin_id()",
                     "brvtal_totp_rate_limit($pendingId)",
                     "brvtal_totp_verify($secret,$code,null,1)",
                     "if(!$valid) { brvtal_log(", "'INVALID_TOTP'],401",
                     "brvtal_totp_complete_login(db(),$a)")
                 and challenge.index("'INVALID_TOTP'],401")
                 < challenge.index("brvtal_totp_complete_login(db(),$a)")
                 and "UPDATE admins SET totp_enabled=0" not in challenge
                 and "if($action==='disable')" in enroll
                 and has(disable, "totp_disable_code_check($pdo,$admin,$code)",
                         "if(!$verification['valid'])", "'INVALID_CODE'",
                         "UPDATE admins SET totp_enabled=0,totp_secret_enc=NULL")
                 and disable.index("if(!$verification['valid'])")
                 < disable.index("UPDATE admins SET totp_enabled=0"))
    elif ident == "S26-R016":
        valid = (has(enroll, "random_bytes(12)", "'aes-256-gcm'",
                     "openssl_encrypt($plaintext,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag,'',16)",
                     "base64_encode($iv.$tag.$cipher)",
                     "openssl_decrypt($cipher,'aes-256-gcm',$key,OPENSSL_RAW_DATA,$iv,$tag)")
                 and has(files["totp_auth"], "strlen($raw) < 28",
                         "substr($raw, 0, 12)", "substr($raw, 12, 16)",
                         "openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag)",
                         "return null;")
                 and has(files["totp"], "brvtal_totp_encryption_key()",
                         "return hash('sha256', $databaseKey, true);")
                 and "totp_secret_enc TEXT NULL" in files["totp_sql"])
    elif ident == "S26-R017":
        # The browser regression exists, but the checked-in PR CI workflow
        # does not run the WebKit project. The full CI claim stays UNKNOWN.
        browser, runner, ci = files["browser"], files["playwright"], files["ci"]
        valid = (has(browser, "#password", "totp_verify", "#discadmin-shell",
                     "authenticatedBootstrap", "csrf-after-totp")
                 and has(runner, "name: 'webkit-totp'", "Desktop Safari",
                         "discadmin-totp-login")
                 and has(ci, "webkit-totp", "playwright"))
    elif ident == "S27-R001":
        valid = (has(files["schema"], "CREATE TABLE admins (",
                     "id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY",
                     "email VARCHAR(190) NOT NULL UNIQUE", "is_active TINYINT(1)")
                 and has(api, "FROM admins WHERE email=? AND is_active=1 LIMIT 1",
                         "brvtal_admin_login_session((int)$a['id'])")
                 and has(files["session"], "function brvtal_admin_login_session(int $adminId)",
                         "$_SESSION['admin_id'] = $adminId")
                 and has(files["session_state"],
                         "FROM admins WHERE id=? LIMIT 1", "return ["))
    else:  # S27-R002
        auth = files["session"]
        state = files["session_state"]
        valid = (has(auth, "function brvtal_admin_require(): void",
                     "BRVTAL_ADMIN_AUTHENTICATED", "brvtal_admin_account_session_state")
                 and "staff_role" not in auth
                 and "staff_role" not in state
                 and "staff_role" not in api.split("if($resource==='public')", 1)[0]
                 and "staff_role VARCHAR(32) NOT NULL DEFAULT 'superadmin'" in files["schema"])
    return LOCAL if valid else UNKNOWN


def deployment_verdict(_ident: object, _observed: object = None) -> str:
    """No source-only fixture can attest to CI, credentials or production."""
    return UNKNOWN


class BrechasBrvtal32Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.files = read_sources()
        cls.rows = matrix_rows(cls.files["matrix"])

    def test_contrato_principal(self) -> None:
        """AC-01: explicit evidence or explicit UNKNOWN for all five rows."""
        self.assertEqual(set(self.rows), set(IDS))
        self.assertIn("## 26. Security / 2FA", self.files["spec"])
        self.assertIn("## 27. Administrators and activity", self.files["spec"])
        expected = {"S26-R015": LOCAL, "S26-R016": LOCAL,
                    "S26-R017": UNKNOWN, "S27-R001": LOCAL,
                    "S27-R002": LOCAL}
        for ident in IDS:
            with self.subTest(clause=ident):
                self.assertIn(CLAUSES[ident], self.files["spec"])
                self.assertEqual(local_verdict(ident, self.files), expected[ident])
                self.assertEqual(deployment_verdict(ident, LOCAL), UNKNOWN)
        # S26-R017: an executable browser test exists, but no WebKit invocation
        # is established in this PR's declared CI; do not invent that coverage.
        self.assertIn("name: 'webkit-totp'", self.files["playwright"])
        self.assertIn("totp_verify", self.files["browser"])
        self.assertNotIn("webkit-totp", self.files["ci"])

    def test_falla_cerrado(self) -> None:
        """AC-02: no false positives for removed/altered/duplicated evidence."""
        row = next(x for x in self.files["matrix"].splitlines()
                   if x.startswith("| S26-R015 · "))
        for bad in (self.files["matrix"].replace(row + "\n", "", 1),
                    self.files["matrix"] + "\n" + row,
                    self.files["matrix"].replace(row, row.replace(
                        "NO_VERIFICABLE", "CUBIERTO", 1), 1)):
            with self.assertRaisesRegex(ValueError, UNKNOWN):
                matrix_rows(bad)
        for ident, source, token in (
            ("S26-R015", "api", "'INVALID_TOTP'],401"),
            ("S26-R016", "enrollment", "random_bytes(12)"),
            ("S27-R001", "schema", "email VARCHAR(190) NOT NULL UNIQUE"),
            ("S27-R002", "session", "function brvtal_admin_require(): void"),
        ):
            with self.subTest(clause=ident):
                source_copy = dict(self.files)
                self.assertIn(token, source_copy[source])
                source_copy[source] = source_copy[source].replace(token, "", 1)
                self.assertEqual(local_verdict(ident, source_copy), UNKNOWN)
        for ident in IDS:
            with self.subTest(missing=ident):
                self.assertEqual(local_verdict(ident, {}), UNKNOWN)
                self.assertEqual(local_verdict(ident, {**self.files, "extra": "source"}), UNKNOWN)
                self.assertEqual(local_verdict(ident, {**self.files, "spec": ""}), UNKNOWN)
        self.assertEqual(local_verdict("S27-R999", self.files), UNKNOWN)
        self.assertEqual(local_verdict(None, self.files), UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        """AC-03: synthetic identities and checked-in files only, no network."""
        synthetic = ("synthetic:admin:101", "synthetic:admin:202")
        self.assertEqual(len(set(synthetic)), 2)
        self.assertTrue(all(identity.startswith("synthetic:") for identity in synthetic))
        self.assertTrue(all("@" not in identity for identity in synthetic))
        for path in PATHS.values():
            p = ROOT / path
            self.assertTrue(p.is_file())
            self.assertTrue(p.resolve().is_relative_to(ROOT.resolve()))
        body = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(body, r"(?m)^(?:import|from)\s+(?:requests|httpx|socket|urllib)\b")
        self.assertNotRegex(body, r"-----BEGIN (?:RSA |OPENSSH )?PRIVATE KEY-----")

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: no premature promotion from local checks to deployment."""
        statuses = {ident: deployment_verdict(
            ident, local_verdict(ident, self.files)) for ident in IDS}
        self.assertEqual(statuses, dict.fromkeys(IDS, UNKNOWN))
        self.assertEqual(local_verdict("S26-R017", self.files), UNKNOWN)
        self.assertEqual(deployment_verdict("S26-R016", None), UNKNOWN)
        self.assertEqual(deployment_verdict("S27-R001", "unverified"), UNKNOWN)


if __name__ == "__main__":
    unittest.main()
