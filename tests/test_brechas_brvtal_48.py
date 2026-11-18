#!/usr/bin/env python3
"""BRVTAL #1138: fail-closed S33 backup coverage using disposable fixtures.

The coverage matrix and production evidence remain NO_VERIFICABLE. This
contract exercises real PHP backup helpers and the JS System Status renderer
without using real databases, hosts, secrets or backup artifacts.
"""
from __future__ import annotations

import json
import os
import re
import shutil
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
BACKUPS = ROOT / "config/backups.php"
ENDPOINT = ROOT / "discadmin/backups.php"
BACKUP_UI = ROOT / "discadmin/backups.js"
PRIVATE_DENY = ROOT / "storage/backups/.htaccess"

REQUIREMENTS = {
    "S33-R007": "manifest",
    "S33-R008": "protected_download",
    "S33-R009": "history",
    "S33-R010": "audit",
    "S33-R011": "private_storage",
}
UNKNOWN = "UNKNOWN"

PHP_FIXTURE = r"""
require 'config/backups.php';
$root = $argv[1];
$dir = $root . '/private';
brvtal_backup_ensure_private_dir($dir);
$id = 'brvtal-20261028T120000Z-abcdef12';
$artifact = $dir . '/' . $id . '.database.sql';
$payload = "-- SYNTHETIC FIXTURE; NO DATABASE\n";
file_put_contents($artifact, $payload);
$manifest = [
    'version'=>1, 'id'=>$id, 'status'=>'ready', 'scope'=>'database',
    'trigger'=>'manual', 'created_at'=>'2026-10-28T12:00:00+00:00',
    'deployment'=>[
        'commit'=>str_repeat('a', 40), 'short_commit'=>'aaaaaaa',
        'source'=>'synthetic', 'version'=>'0.0.test',
    ],
    'components'=>[
        'database'=>[
            'status'=>'ready', 'file'=>basename($artifact),
            'bytes'=>strlen($payload), 'size'=>brvtal_backup_format_bytes(strlen($payload)),
            'sha256'=>hash_file('sha256', $artifact),
        ],
        'media_manifest'=>['status'=>'not_requested'],
        'media_archive'=>['status'=>'not_requested'],
    ],
    'artifacts_bytes'=>strlen($payload),
    'artifacts_size'=>brvtal_backup_format_bytes(strlen($payload)),
    'restore_supported'=>false,
];
brvtal_backup_write_json($dir . '/' . $id . '.manifest.json', $manifest);
$older = $manifest;
$older['id'] = 'brvtal-20261027T120000Z-fedcba98';
$older['created_at'] = '2026-10-27T12:00:00+00:00';
$older['components']['database'] = ['status'=>'not_requested'];
brvtal_backup_write_json($dir . '/' . $older['id'] . '.manifest.json', $older);
$found = brvtal_backup_get($id, $dir);
$history = brvtal_backup_list($dir);
$bad = $manifest;
$bad['components']['database']['file'] = '../outside.sql';
$outside = $root . '/outside.sql';
file_put_contents($outside, "SYNTHETIC OUTSIDE PRIVATE DIRECTORY\n");
$linked = function_exists('symlink') && @symlink($outside, $dir . '/alias.sql');
$alias = $manifest;
$alias['components']['database']['file'] = 'alias.sql';
$deny = (string)file_get_contents($dir . '/.htaccess');
echo json_encode([
    'manifest'=>is_array($found)
        && $found['created_at'] === '2026-10-28T12:00:00+00:00'
        && strlen($found['deployment']['commit'] ?? '') === 40
        && ($found['components']['database']['sha256'] ?? '') === hash_file('sha256', $artifact)
        && ($found['artifacts_bytes'] ?? 0) === strlen($payload)
        && array_keys($found['components']) === ['database','media_manifest','media_archive'],
    'protected_download'=>brvtal_backup_resolve_component($manifest, 'database', $dir) === realpath($artifact)
        && brvtal_backup_resolve_component($bad, 'database', $dir) === null
        && brvtal_backup_resolve_component($manifest, '../database', $dir) === null
        && (! $linked || brvtal_backup_resolve_component($alias, 'database', $dir) === null)
        && brvtal_backup_get('../config.php', $dir) === null,
    'history'=>count($history) === 2
        && ($history[0]['id'] ?? '') === $id
        && ($history[1]['id'] ?? '') === $older['id']
        && brvtal_backup_get('brvtal-20261026T120000Z-00000000', $dir) === null,
    'private_storage'=>((fileperms($dir) & 0777) === 0700)
        && str_contains($deny, 'Require all denied')
        && str_contains($deny, 'Options -Indexes'),
], JSON_THROW_ON_ERROR);
"""

NODE_FIXTURE = r"""
const fs = require('fs');
const vm = require('vm');
const ctx = {
  window: {},
  document: { documentElement: {} },
  MutationObserver: class { observe() {} },
  setTimeout: () => 0,
  clearTimeout: () => {},
};
vm.runInNewContext(fs.readFileSync('discadmin/backups.js', 'utf8'), ctx, {timeout: 1200});
const item = {
  id: 'brvtal-20261028T120000Z-abcdef12',
  status: 'ready',
  created_at: '2026-10-28T12:00:00Z',
  trigger: 'manual',
  scope: 'database',
  artifacts_size: '32 B',
  deployment: { short_commit: 'aaaaaaa' },
  components: { database: { available: true } },
};
const html = ctx.window.BRVTALBackupsUI.backupRows([item]);
const malicious = ctx.window.BRVTALBackupsUI.backupRows([
  { ...item, id: '<script>alert(1)</script>' },
]);
console.log(JSON.stringify({
  history: html.includes('data-status="ready"')
    && html.includes('DEPLOY aaaaaaa')
    && html.includes('DATABASE SQL')
    && html.includes('/discadmin/backups.php?action=download'),
  escaped: !malicious.includes('<script>') && malicious.includes('&lt;script&gt;'),
  empty: ctx.window.BRVTALBackupsUI.backupRows([]).includes('NO BACKUPS YET'),
}));
"""


def matrix_status(source: str, requirement: str) -> str:
    matches = [line for line in source.splitlines()
               if line.startswith("| " + requirement + " ·")]
    if len(matches) != 1:
        return UNKNOWN
    cells = [part.strip() for part in matches[0].strip("|").split("|")]
    return cells[2] if len(cells) == 5 else UNKNOWN


def evidence_state(value: object) -> str:
    return "TEST_EXECUTED" if value is True else UNKNOWN


def disposable_environment(sandbox: str) -> dict[str, str]:
    """Do not pass inherited DB passwords, tokens, app keys or network proxies."""
    return {"PATH": os.environ.get("PATH", "/usr/bin:/bin"),
            "HOME": sandbox, "LANG": "C", "APP_ENV": "testing"}


def run_fixture(binary: str, source: str) -> dict[str, object] | None:
    executable = shutil.which(binary)
    if not executable:
        return None
    with tempfile.TemporaryDirectory(prefix="brvtal-s33-test-") as temp:
        command = [executable, "-r", source, temp] if binary == "php" else [
            executable, "-e", source
        ]
        result = subprocess.run(
            command, cwd=ROOT, env=disposable_environment(temp),
            capture_output=True, text=True, check=False, timeout=45,
        )
        if result.returncode != 0:
            raise AssertionError(
                binary + " synthetic contract failed (no production data): "
                + (result.stderr or result.stdout)[-1800:]
            )
        return json.loads(result.stdout)


def audit_source_contracted(source: str) -> bool:
    """Verify the authenticated create/download paths audit before returning."""
    create_key = "if ($method === 'POST' && $action === 'create') {"
    download_key = "if ($method === 'GET' && $action === 'download') {"
    if source.count(create_key) != 1 or source.count(download_key) != 1:
        return False
    if "brvtal_admin_require();" not in source.split(create_key, 1)[0]:
        return False
    create = source.split(create_key, 1)[1].split(download_key, 1)[0]
    download = source.split(download_key, 1)[1].split("if ($action === 'restore')", 1)[0]
    return (
        "brvtal_admin_require_csrf();" in create
        and "brvtal_activity_record(" in create
        and "brvtal_activity_record(" in download
        and "BACKUP_AUDIT_REQUIRED" in download
        and "fpassthru($stream)" in download
        and download.index("brvtal_activity_record(") < download.index("fpassthru($stream)")
    )


class BrechasBrvtal48Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """Exactly five requirements, with real synthetic PHP/JS contracts."""
        self.assertEqual(5, len(REQUIREMENTS))
        php = run_fixture("php", PHP_FIXTURE)
        node = run_fixture("node", NODE_FIXTURE)
        endpoint = ENDPOINT.read_text(encoding="utf-8")
        ui_source = BACKUP_UI.read_text(encoding="utf-8")

        for requirement, behavior in REQUIREMENTS.items():
            with self.subTest(requirement=requirement):
                if requirement in {"S33-R007", "S33-R008", "S33-R011"}:
                    self.assertTrue(BACKUPS.is_file())
                    self.assertEqual("TEST_EXECUTED" if php else UNKNOWN,
                                     evidence_state(None if php is None else php.get(behavior)))
                elif requirement == "S33-R009":
                    self.assertIn("system-status-v2", ui_source)
                    self.assertIn("brvtal_backup_list()", endpoint)
                    self.assertEqual("TEST_EXECUTED" if php else UNKNOWN,
                                     evidence_state(None if php is None else php.get("history")))
                    self.assertEqual("TEST_EXECUTED" if node else UNKNOWN,
                                     evidence_state(None if node is None else node.get("history")))
                    self.assertEqual("TEST_EXECUTED" if node else UNKNOWN,
                                     evidence_state(None if node is None else node.get("empty")))
                    self.assertEqual("TEST_EXECUTED" if node else UNKNOWN,
                                     evidence_state(None if node is None else node.get("escaped")))
                else:  # S33-R010: integration DB audit cannot be proven offline.
                    self.assertTrue(audit_source_contracted(endpoint))
                    self.assertEqual(UNKNOWN, evidence_state(None))

        self.assertIn("Require all denied", PRIVATE_DENY.read_text(encoding="utf-8"))
        ignore = (ROOT / ".gitignore").read_text(encoding="utf-8")
        self.assertIn("/storage/backups/*", ignore)
        self.assertIn("!/storage/backups/.htaccess", ignore)

    def test_falla_cerrado(self) -> None:
        """Missing, ambiguous or unexecuted evidence is never success."""
        for requirement in REQUIREMENTS:
            with self.subTest(requirement=requirement):
                self.assertEqual(UNKNOWN, evidence_state(None))
                self.assertEqual(UNKNOWN, evidence_state(False))
                self.assertEqual(UNKNOWN, evidence_state({}))
                self.assertEqual(UNKNOWN, evidence_state("ready"))
        self.assertFalse(audit_source_contracted(""))
        self.assertFalse(audit_source_contracted(
            "if ($method === 'POST' && $action === 'create') {}"
            "if ($method === 'GET' && $action === 'download') {}"
        ))
        self.assertEqual(UNKNOWN, matrix_status("", "S33-R007"))
        self.assertEqual(UNKNOWN, matrix_status(
            "| S33-R007 · source | clause | NO_VERIFICABLE | reason | evidence |\n"
            "| S33-R007 · repeated | clause | CUBIERTO | reason | evidence |",
            "S33-R007",
        ))

    def test_sin_datos_reales(self) -> None:
        """All runtime fixtures are disposable; no production or external I/O."""
        self.assertIn("tempfile.TemporaryDirectory(", Path(__file__).read_text(encoding="utf-8"))
        env = disposable_environment("/tmp/test-synthetic")
        for key in ("DB_HOST", "DB_DATABASE", "DB_PASSWORD", "DATABASE_URL",
                    "MYSQL_PWD", "GITHUB_TOKEN", "APP_KEY", "HTTP_PROXY"):
            with self.subTest(key=key):
                self.assertNotIn(key, env)
        self.assertEqual("testing", env["APP_ENV"])
        self.assertIn("SYNTHETIC FIXTURE", PHP_FIXTURE)
        self.assertIn("brvtal_backup_ensure_private_dir($dir)", PHP_FIXTURE)
        self.assertNotIn("brvtal_backup_create(", PHP_FIXTURE)
        self.assertNotIn("new PDO(", PHP_FIXTURE)
        self.assertNotIn("fetch(", NODE_FIXTURE)
        self.assertIn("env=disposable_environment(temp)", Path(__file__).read_text(encoding="utf-8"))

    def test_estados_unknown_explicitos(self) -> None:
        """Never promote NO_VERIFICABLE or claim production verification."""
        matrix = MATRIX.read_text(encoding="utf-8")
        for requirement in REQUIREMENTS:
            with self.subTest(requirement=requirement):
                self.assertEqual("NO_VERIFICABLE", matrix_status(matrix, requirement))
                self.assertEqual(UNKNOWN, matrix_status("", requirement))
                self.assertEqual(UNKNOWN, evidence_state(None))


if __name__ == "__main__":
    unittest.main()
