#!/usr/bin/env python3
"""#1286: real offline PHP contracts for BRVTAL S40-S42, no live attestation.

Proof A calls committed migration, API, visibility and security functions with
isolated synthetic fixtures. B requires a database/server observation; C is
a normative statement rather than executable behavior. No matrix state read.
"""
from __future__ import annotations

from functools import lru_cache
import json
from pathlib import Path
import shutil
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "tests"))
import test_brechas_brvtal_64 as migration
import test_brechas_brvtal_65 as api
import test_brechas_brvtal_66 as security_core
import test_brechas_brvtal_67 as security_edges
import test_cobertura_brvtal_10 as public_settings

IDS = (
    "S40-R009", "S40-R010", "S40-R011", "S40-R012", "S40-R013",
    "S41-R001", "S41-R002", "S41-R003", "S41-R004", "S41-R005",
    "S41-R006", "S42-L662", "S42-R001", "S42-R002", "S42-R003",
    "S42-R005", "S42-R006", "S42-R007", "S42-R008", "S42-R009",
)
NON_OFFLINE = {
    "S40-R009": ("B", "Production admin_activity_log table needs real schema observation"),
    "S40-R010": ("C", "Open-ended table inventory is normative documentation"),
    "S40-R011": ("B", "Committed migration scripts cannot prove production apply receipts"),
    "S40-R013": ("B", "Historical TOTP key/schema reuse needs production migration history"),
    "S41-R006": ("B", "Complete private entity and relation delivery needs HTTP/DB E2E; private-page gap remains"),
}
OFFLINE = frozenset(IDS) - NON_OFFLINE.keys()


def require_php() -> None:
    if shutil.which("php") is None:
        raise AssertionError("UNKNOWN: PHP 8.5 CLI required")


@lru_cache(maxsize=1)
def real_witnesses() -> dict[str, bool]:
    """Run actual repository PHP implementations, not Python imitations."""
    require_php()
    mig = migration.probe()  # calls config/migrations.php via PHP CLI
    routes = api.php_routes()  # calls api/route.php via PHP CLI
    projection = public_settings.settings_projection()  # real api/public.php
    valid = security_core.php_observation("csrf-valid")
    invalid = security_core.php_observation("csrf-invalid")
    prepared = security_core.php_observation("prepared")
    session = security_core.php_observation("session")
    escaping = security_core.php_observation("escaping")

    # These are structural source contracts around executed pure PHP seams.
    # The entire index/public entrypoints require DB/bootstrap; never include
    # those entrypoints in this test or claim E2E authorization from a string.
    index = (ROOT / "api/index.php").read_text(encoding="utf-8")
    public = (ROOT / "api/public.php").read_text(encoding="utf-8")
    delegate = "require __DIR__ . '/public.php';"
    delegate_pos, auth_pos = index.find(delegate), index.find("brvtal_admin_require();")
    isolated = (
        delegate_pos >= 0 and auth_pos > delegate_pos and
        index.count(delegate) == 1 and
        "brvtal_public_settings(" not in index and
        "header('Allow: GET');" in public and
        "brvtal_public_settings(" in public
    )
    public_data = projection["public"]
    restricted = (
        projection["invalid_id"] == "" and
        "analytics" not in public_data and
        "security" not in public_data and
        "SYNTH_SECRET" not in json.dumps(public_data) and
        "WHERE setting_key IN" in projection["query"]
    )
    canonical = {
        "resource": "events", "id": 17,
        "action": "lineup", "segments": ["events", "17", "lineup"],
    }
    route_safe = (
        routes["front"] == canonical and routes["rewrite"] == canonical and
        routes["public"]["resource"] == "public" and
        routes["blocked_upload"]["resource"] == "" and
        routes["blocked_media_write"]["resource"] == "" and
        routes["malformed"]["resource"] == "" and
        routes["extra"]["resource"] == ""
    )

    with tempfile.TemporaryDirectory(prefix="brvtal-1286-s42-") as folder:
        password = security_edges.password_probe(folder)
        upload = security_edges.upload_probe(folder)
        permission = security_edges.authorization_probe(folder)
        redaction, no_trace = security_edges.logger_probe(folder)

    # Coupling synthetic behavior to the real product call sites: native
    # password_hash alone or MIME sniffing alone is not product protection.
    password_source = (ROOT / "config/admin_password_security.php").read_text(
        encoding="utf-8")
    uploads_source = (ROOT / "api/media-library.php").read_text(encoding="utf-8")
    auth_source = (ROOT / "config/admin_auth.php").read_text(encoding="utf-8")
    outputs = {
        "S40-R012": (
            mig == migration.EXPECTED and
            mig["no_op_applied"] == "OK" and
            mig["missing_registry"] == "MIGRATION_REGISTRY_MISSING" and
            mig["destructive_ddl"] == "MIGRATION_NON_ADDITIVE_SQL" and
            mig["checksum_drift"] == "MIGRATION_CHECKSUM_MISMATCH"),
        "S41-R001": isolated and route_safe,
        "S41-R002": isolated and restricted,
        "S41-R003": isolated and routes["public"]["resource"] == "public",
        "S41-R004": isolated and restricted,
        "S41-R005": route_safe,
        "S42-L662": (
            valid.get("allowed") is True and valid.get("csrf_length") == 64 and
            invalid.get("ok") is False and invalid.get("error") == "CSRF"),
        "S42-R001": (
            prepared.get("sql") == "SELECT id FROM synthetic WHERE slug = ?" and
            prepared.get("bound") == ["synthetic' OR 1=1 --"] and
            prepared.get("rows") == [{"id": 7}]),
        "S42-R002": (
            session.get("allowed") is True and
            session.get("cookie", {}).get("httponly") is True and
            session.get("cookie", {}).get("secure") is True and
            session.get("cookie", {}).get("samesite") == "Strict" and
            str(session.get("strict")) == "1"),
        "S42-R003": (
            escaping.get("raw_script") is False and
            escaping.get("escaped_title") is True and
            escaping.get("escaped_quotes") is True and
            escaping.get("escaped_description") is True),
        "S42-R005": (
            password and
            "password_hash($newPassword, PASSWORD_DEFAULT)" in password_source and
            "password_verify($password, $currentHash)" in password_source),
        "S42-R006": (
            upload and "is_uploaded_file($tmp)" in uploads_source and
            "finfo(FILEINFO_MIME_TYPE)" in uploads_source),
        "S42-R007": (
            permission and
            "function brvtal_admin_require(): void" in auth_source and
            "brvtal_admin_require_csrf(" in auth_source),
        "S42-R008": redaction,
        "S42-R009": no_trace,
    }
    if set(outputs) != OFFLINE:
        raise AssertionError("UNKNOWN: incomplete real-code witness map")
    return outputs


class CoberturaBrvtal17Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: 15 executable slices; 4 B and 1 C are not falsely passed."""
        self.assertEqual(len(IDS), 20)
        self.assertEqual(OFFLINE | NON_OFFLINE.keys(), set(IDS))
        self.assertFalse(OFFLINE & NON_OFFLINE.keys())
        self.assertTrue(all(kind in ("B", "C", "D") and len(reason) > 25
                            for kind, reason in NON_OFFLINE.values()))
        observed = real_witnesses()
        self.assertEqual(set(observed), OFFLINE)
        for key, proof in observed.items():
            with self.subTest(requirement=key):
                self.assertIs(proof, True, "UNKNOWN: real PHP regression " + key)

    def test_falla_cerrado(self) -> None:
        """AC-02: malformed routes, forged CSRF, SQL and auth are rejected."""
        observed = real_witnesses()
        self.assertTrue(all(observed.values()))
        routes = api.php_routes()
        for key in ("malformed", "extra", "blocked_upload", "blocked_media_write"):
            with self.subTest(route=key):
                self.assertEqual("", routes[key]["resource"])
        self.assertEqual(routes["front"], routes["rewrite"])
        forged = security_core.php_observation("csrf-invalid")
        self.assertFalse(forged.get("ok", True))
        self.assertEqual(forged.get("error"), "CSRF")
        sql = security_core.php_observation("prepared")
        self.assertNotIn(sql["bound"][0], sql["sql"])
        self.assertEqual(sql["bound"], ["synthetic' OR 1=1 --"])
        session = security_core.php_observation("session")
        self.assertTrue(session.get("cookie", {}).get("httponly"))
        html = security_core.php_observation("escaping")
        self.assertFalse(html.get("raw_script"))
        with tempfile.TemporaryDirectory(prefix="brvtal-1286-negative-") as home:
            self.assertTrue(security_edges.authorization_probe(home))
            self.assertTrue(security_edges.upload_probe(home))
            self.assertTrue(security_edges.password_probe(home))

    def test_sin_datos_reales(self) -> None:
        """AC-03: only disposable fake records and isolated local PHP probes."""
        self.assertEqual(real_witnesses()["S42-R009"], True)
        with tempfile.TemporaryDirectory(prefix="brvtal-1286-env-") as home:
            env = security_core.environment(home)
            self.assertEqual("testing", env["APP_ENV"])
            for key in ("DATABASE_URL", "DB_PASSWORD", "MYSQL_PWD",
                        "GITHUB_TOKEN", "GH_TOKEN", "APP_KEY",
                        "HTTP_PROXY", "HTTPS_PROXY"):
                self.assertNotIn(key, env)
        import ast
        source = Path(__file__).read_text(encoding="utf-8")
        parsed = ast.parse(source)
        imports = set()
        for node in ast.walk(parsed):
            if isinstance(node, ast.Import):
                imports.update(alias.name.split(".")[0] for alias in node.names)
            elif isinstance(node, ast.ImportFrom) and node.module:
                imports.add(node.module.split(".")[0])
        self.assertFalse(imports & {"requests", "socket"})
        self.assertNotIn("-----BEGIN " + "PRIVATE KEY-----", source)
        self.assertNotIn("https://" + "api.github.com/", source)

    def test_validar_exigido(self) -> None:
        """AC-04: check wired; only GitHub terminal status certifies success."""
        workflow = (ROOT / ".github/workflows/update-release-metadata.yml").read_text(
            encoding="utf-8")
        self.assertIn("name: BRVTAL CI", workflow)
        self.assertIn("  pull_request:", workflow)
        self.assertIn("  validate:", workflow)
        self.assertIn("name: validate", workflow)


if __name__ == "__main__":
    unittest.main()
