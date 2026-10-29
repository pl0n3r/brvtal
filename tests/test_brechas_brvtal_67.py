#!/usr/bin/env python3
"""BRVTAL #1157: offline S42-R005..R009 security contracts.

Synthetic PHP probes execute real repository source where safely isolatable.
A local pass is not deployment evidence; the coverage matrix stays untouched.
"""
from __future__ import annotations

import ast
import base64
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
MATRIX = "docs/matriz-cobertura.md"
SPEC = "docs/BRVTAL-SPEC.md"
SOURCES = {
    "password": "config/admin_password_security.php",
    "auth": "config/admin_auth.php",
    "upload": "api/media-library.php",
    "logger": "config/logger.php",
    "sentry": "config/sentry.php",
    "deployment": "config/deployment.php",
    "version": "config/version.php",
    "logs": "discadmin/logs.php",
    "log_policy": "config/admin_log.php",
    "log_test": "tests/admin-logs-cache-contract.php",
}
CLAUSES = {
    "S42-R005": "password hashing/verification;",
    "S42-R006": "validated uploads;",
    "S42-R007": "authorization;",
    "S42-R008": "private logs;",
    "S42-R009": "no public stack traces;",
}
IDS = tuple(CLAUSES)
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_SYNTHETIC_ONLY"
# Public, fixed 1x1 PNG fixture. Neither credentials nor customer content.
PNG = base64.b64decode(
    "iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aUQAAAABJRU5ErkJggg=="
)


def matrix_rows(raw: object) -> dict[str, tuple[str, ...]]:
    """All five original matrix rows must be unique, complete and unpromoted."""
    if type(raw) is not str or not raw:
        raise ValueError(UNKNOWN + ": no matrix")
    found: dict[str, tuple[str, ...]] = {}
    for line in raw.splitlines():
        cells = tuple(piece.strip() for piece in line.strip().strip("|").split("|"))
        if not cells or not re.fullmatch(r"S42-R00[5-9] · \[S42 L\d+\].*", cells[0]):
            continue
        ident = cells[0].split(" · ", 1)[0]
        if ident in found or len(cells) != 5:
            raise ValueError(UNKNOWN + ": duplicate/malformed " + ident)
        if (cells[1] != CLAUSES[ident] or cells[2] != "NO_VERIFICABLE"
                or "docs/BRVTAL-SPEC.md#L" not in cells[0]
                or "Sin aserción contrastada" not in cells[3]
                or "falta enlazar prueba de ese inciso, archivo de código y PR fusionado" not in cells[3]
                or cells[4] != "—"):
            raise ValueError(UNKNOWN + ": altered " + ident)
        found[ident] = cells
    if set(found) != set(IDS):
        raise ValueError(UNKNOWN + ": missing S42 evidence")
    return found


def sources() -> dict[str, str]:
    paths = {MATRIX, SPEC, *SOURCES.values()}
    return {name: (ROOT / name).read_text(encoding="utf-8") for name in paths}


def run_php(code: str, args: list[str], home: str) -> str:
    """No inherited secrets, network settings or writable repository state."""
    binary = shutil.which("php")
    if binary is None:
        raise RuntimeError(UNKNOWN + ": PHP CLI missing")
    proc = subprocess.run(
        [binary, "-d", "display_errors=0", "-d", "log_errors=0", "-r", code, *args],
        cwd=home, env={"PATH": os.defpath, "HOME": home, "LANG": "C",
                       "LC_ALL": "C", "APP_ENV": "testing"},
        capture_output=True, text=True, timeout=20, check=False,
    )
    if proc.returncode or proc.stderr.strip():
        raise AssertionError(UNKNOWN + ": PHP probe failed (exit="
                             + str(proc.returncode) + ")")
    return proc.stdout


def password_probe(home: str) -> bool:
    # Native PHP hash/verify against contrasting synthetic values, coupled to
    # explicit checks for the production call sites (below).
    script = r'''
$first = 'Synthetic-long-passphrase-27!';
$other = 'different-synthetic-password-29!';
$hash = password_hash($first, PASSWORD_DEFAULT);
echo json_encode([is_string($hash) && $hash !== $first,
    password_verify($first, $hash), password_verify($other, $hash)]);
'''
    return json.loads(run_php(script, [], home)) == [True, True, False]


def upload_probe(home: str) -> bool:
    with tempfile.TemporaryDirectory(prefix="brvtal-s42-upload-", dir=home) as tmp:
        good = Path(tmp) / "fixture.png"
        bad = Path(tmp) / "fake.png"
        good.write_bytes(PNG)
        bad.write_text("not an image", encoding="utf-8")
        script = r'''
$probe = static function(string $path): array {
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($path) ?: '';
    return ['mime' => $mime, 'image' => @getimagesize($path) !== false];
};
echo json_encode([$probe($argv[1]), $probe($argv[2])]);
'''
        data = json.loads(run_php(script, [str(good), str(bad)], home))
        return (data[0] == {"mime": "image/png", "image": True}
                and data[1]["image"] is False
                and data[1]["mime"] != "image/png")


def authorization_probe(home: str) -> bool:
    with tempfile.TemporaryDirectory(prefix="brvtal-s42-auth-", dir=home) as tmp:
        logfile = str(Path(tmp) / "synthetic.log")
        script = r'''
require $argv[1];
$file = $argv[2];
file_put_contents($file, 'SYNTHETIC_LOG_UNCHANGED');
$method = brvtal_admin_log_clear_result('GET', 'expected', 'expected', $file);
$token = brvtal_admin_log_clear_result('POST', 'expected', 'wrong', $file);
$empty = brvtal_admin_log_clear_result('POST', '', '', $file);
$unchanged = file_get_contents($file) === 'SYNTHETIC_LOG_UNCHANGED';
$ok = brvtal_admin_log_clear_result('POST', 'expected', 'expected', $file);
$cleared = file_get_contents($file) === '';
echo json_encode([$method['status'], $token['status'], $empty['status'],
                  $unchanged, $ok['status'], $cleared]);
'''
        result = json.loads(run_php(script, [str(ROOT / SOURCES["log_policy"]), logfile], home))
        return result == [405, 419, 419, True, 200, True]


def logger_probe(home: str) -> tuple[bool, bool]:
    """Copy unchanged PHP logger and dependencies into disposable sandbox."""
    with tempfile.TemporaryDirectory(prefix="brvtal-s42-log-", dir=home) as tmp:
        conf = Path(tmp) / "config"
        conf.mkdir()
        for name in ("logger", "sentry", "deployment", "version"):
            shutil.copyfile(ROOT / SOURCES[name], conf / (name + ".php"))
        output = Path(tmp) / "private.log"
        script = r'''
define('BRVTAL_SENTRY_TESTING', true);
$GLOBALS['brvtalSentryTestLogFile'] = $argv[1];
require $argv[2];
brvtal_log('SYNTHETIC', 'static audit', [
    'password' => 'SYNTHETIC_PASSWORD_MARKER',
    'token' => 'SYNTHETIC_TOKEN_MARKER',
    'secret_ref' => 'SYNTHETIC_SECRET_MARKER']);
throw new RuntimeException('SYNTHETIC_EXCEPTION_MARKER');
'''
        stdout = run_php(script, [str(output), str(conf / "logger.php")], tmp)
        private = output.read_text(encoding="utf-8")
        redacted = (all(s not in private for s in (
            "SYNTHETIC_PASSWORD_MARKER", "SYNTHETIC_TOKEN_MARKER",
            "SYNTHETIC_SECRET_MARKER"))
            and private.count("[REDACTED]") >= 3
            and "SYNTHETIC_EXCEPTION_MARKER" in private)
        no_trace = ("BRVTAL ERROR" in stdout
                    and "Se produjo un error interno." in stdout
                    and "SYNTHETIC_EXCEPTION_MARKER" not in stdout
                    and "Stack trace" not in stdout
                    and "logger.php:" not in stdout
                    and str(tmp) not in stdout)
        return redacted, no_trace


def evidence(key: object, src: object, probes: object) -> str:
    """Only complete original sources plus a real synthetic witness count."""
    if (type(key) is not str or key not in IDS or type(src) is not dict
            or type(probes) is not dict or set(src) != {MATRIX, SPEC, *SOURCES.values()}):
        return UNKNOWN
    if any(type(value) is not str or not value for value in src.values()):
        return UNKNOWN
    try:
        matrix_rows(src[MATRIX])
    except ValueError:
        return UNKNOWN
    if "## 42. Security principles" not in src[SPEC] or (
        "- " + CLAUSES[key]
    ) not in src[SPEC].split("## 42. Security principles", 1)[1].split("## 43.", 1)[0]:
        return UNKNOWN
    p, u, a, log, logs = (src[SOURCES[n]] for n in
                           ("password", "upload", "auth", "logger", "logs"))
    checks = {
        "S42-R005": ("password_hash($newPassword, PASSWORD_DEFAULT)" in p
                     and "password_verify($currentPassword" in p
                     and "password_verify($password, $currentHash)" in p),
        "S42-R006": ("brvtal_admin_require_csrf();" in u
                     and "is_uploaded_file($tmp)" in u
                     and "new finfo(FILEINFO_MIME_TYPE)->file($tmp)" in u
                     and "if (!isset($allowed[$mime]))" in u
                     and "@getimagesize($tmp) === false" in u),
        "S42-R007": ("function brvtal_admin_require(): void" in a
                     and "BRVTAL_ADMIN_UNAUTHENTICATED" in a
                     and "http_response_code($unavailable ? 503 : 401)" in a
                     and "function brvtal_admin_require_csrf(" in a
                     and "brvtal_admin_require();" in u
                     and "brvtal_admin_require();" in logs),
        "S42-R008": ("$BRVTAL_ROOT . '/storage/logs'" in log
                     and "'[REDACTED]'" in log
                     and "brvtal_admin_require();" in logs
                     and "Cache-Control: no-store" in logs
                     and "X-Robots-Tag: noindex, nofollow, noarchive" in logs),
        "S42-R009": ("set_exception_handler(" in log
                     and "getTraceAsString()" in log
                     and 'echo "BRVTAL ERROR\\n\\n"' in log
                     and 'echo "Se produjo un error interno.\\n"' in log
                     and "display_errors', '0'" in logs),
    }
    return LOCAL if checks[key] and probes.get(key) is True else UNKNOWN


def operational(*_inputs: object) -> str:
    """No local test certifies deployed state, server headers or live auth."""
    return UNKNOWN


class BrechasBrvtal67Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.src = sources()
        cls.rows = matrix_rows(cls.src[MATRIX])
        cls.witness: dict[str, bool] = {}
        if shutil.which("php") is None:
            return
        with tempfile.TemporaryDirectory(prefix="brvtal-s42-root-") as temp:
            log_private, no_trace = logger_probe(temp)
            cls.witness = {
                "S42-R005": password_probe(temp),
                "S42-R006": upload_probe(temp),
                "S42-R007": authorization_probe(temp),
                "S42-R008": log_private,
                "S42-R009": no_trace,
            }

    def test_contrato_principal(self) -> None:
        self.assertEqual(set(IDS), set(self.rows))
        self.assertEqual(set(IDS), set(CLAUSES))
        if not self.witness:
            for key in IDS:
                with self.subTest(key=key):
                    self.assertEqual(UNKNOWN, evidence(key, self.src, {}))
            return
        for key in IDS:
            with self.subTest(key=key):
                self.assertTrue(self.witness[key], "UNKNOWN: missing synthetic proof " + key)
                self.assertEqual(LOCAL, evidence(key, self.src, self.witness))
                self.assertEqual(UNKNOWN, operational(key, self.witness))

    def test_falla_cerrado(self) -> None:
        raw = self.src[MATRIX]
        for key in IDS:
            line = next(s for s in raw.splitlines() if s.startswith(f"| {key} · "))
            for bad in (raw.replace(line, "", 1), raw + "\n" + line,
                        raw.replace(line, line.replace("NO_VERIFICABLE", "VERIFICADO", 1), 1)):
                with self.subTest(key=key), self.assertRaisesRegex(ValueError, UNKNOWN):
                    matrix_rows(bad)
                self.assertEqual(UNKNOWN, evidence(key, {**self.src, MATRIX: bad}, self.witness))
            self.assertEqual(UNKNOWN, evidence(key, {**self.src, SPEC: ""}, self.witness))
            self.assertEqual(UNKNOWN, evidence(key, self.src, {}))
            self.assertEqual(UNKNOWN, evidence(key, self.src, {key: "success"}))
        self.assertEqual(UNKNOWN, evidence(None, self.src, self.witness))

    def test_sin_datos_reales(self) -> None:
        text = Path(__file__).read_text(encoding="utf-8")
        tree = ast.parse(text)
        modules = set()
        for node in ast.walk(tree):
            if isinstance(node, ast.Import):
                modules.update(a.name.split(".")[0] for a in node.names)
            elif isinstance(node, ast.ImportFrom) and node.module:
                modules.add(node.module.split(".")[0])
        self.assertLessEqual(modules, {"__future__", "ast", "base64", "json", "os", "pathlib", "re", "shutil", "subprocess", "tempfile", "unittest"})
        self.assertNotIn("requests", modules)
        self.assertNotIn("socket", modules)
        self.assertEqual(set(SOURCES), {"password", "auth", "upload", "logger", "sentry", "deployment", "version", "logs", "log_policy", "log_test"})
        self.assertTrue(all(not Path(p).is_absolute() for p in SOURCES.values()))
        self.assertEqual(5, len(IDS))

    def test_estados_unknown_explicitos(self) -> None:
        for key in IDS:
            with self.subTest(key=key):
                self.assertEqual("NO_VERIFICABLE", self.rows[key][2])
                for claimed in (None, False, True, {}, {"live": "GREEN"}, {"CI": "success"}):
                    self.assertEqual(UNKNOWN, operational(key, claimed))
                self.assertEqual(UNKNOWN, evidence(key, self.src, {key: False}))


if __name__ == "__main__":
    unittest.main()
