#!/usr/bin/env python3
"""#1137: brechas S33-R001/R002/R003/R005/R006, solo evidencia sintética."""
from __future__ import annotations

import json
import re
import subprocess
import tempfile
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
CLAUSES = {"S33-R001", "S33-R002", "S33-R003", "S33-R005", "S33-R006"}


def php(program: str, *args: str) -> dict:
    """Solo funciones locales sin conexión; nunca se abre una base real."""
    result = subprocess.run(
        ["php", "-r", program, *map(str, args)], cwd=ROOT,
        capture_output=True, text=True, timeout=20, check=False,
    )
    if result.returncode:
        raise AssertionError(f"Escenario PHP sintético falló: {result.stderr}")
    return json.loads(result.stdout)


def coverage_rows() -> dict[str, list[str]]:
    result = {}
    for line in MATRIX.read_text(encoding="utf-8").splitlines():
        match = re.match(r"^\| (S33-R00\d) · ", line)
        if not match or match.group(1) not in CLAUSES:
            continue
        key = match.group(1)
        if key in result:
            raise AssertionError(f"Fila duplicada: {key}")
        result[key] = [v.strip() for v in line.strip().strip("|").split("|")]
    return result


class BrechasBrvtal47Tests(unittest.TestCase):
    """Cada requisito tiene contraste separado, nunca validación productiva implícita."""

    def test_contrato_principal(self):
        status = php(r'''
            require 'config/backups.php';
            require 'config/backup_automation.php';
            $c = brvtal_backup_automation_normalize_config([
                'enabled'=>true, 'scope'=>'database',
                'cadence'=>['type'=>'daily','interval'=>1,'time'=>'03:00'],
                'include_media_archive'=>true,
            ]);
            echo json_encode([
                'manual'=>brvtal_backup_normalize_scope('complete'),
                'database'=>brvtal_backup_normalize_scope('database'),
                'media'=>brvtal_backup_normalize_scope('media'),
                'schedule'=>$c,
                'next'=>brvtal_backup_automation_next_run($c, new DateTimeImmutable('2026-09-26T07:59:00Z')),
                'missing'=>brvtal_backup_inventory('tests/__missing_brvtal_backup_fixture__'),
            ], JSON_THROW_ON_ERROR);
        ''')
        with tempfile.TemporaryDirectory(prefix="brvtal-s33-synthetic-") as temp:
            root = Path(temp)
            (root / "b.txt").write_text("synthetic-b", encoding="utf-8")
            (root / "a.txt").write_text("a", encoding="utf-8")
            media = php(r'''
                require 'config/backups.php';
                $inventory = brvtal_backup_inventory($argv[1], 'uploads');
                $archive = brvtal_backup_create_media_archive(
                    $argv[1], $inventory, $argv[2], 1
                );
                echo json_encode(['inventory'=>$inventory, 'archive'=>$archive], JSON_THROW_ON_ERROR);
            ''', root, root / "out.zip")
            self.assertFalse((root / "out.zip").exists())
        paths = [entry["path"] for entry in media["inventory"]["entries"]]
        archive = media["archive"]
        endpoint = (ROOT / "discadmin/backups.php").read_text(encoding="utf-8")
        engine = (ROOT / "config/backups.php").read_text(encoding="utf-8")
        sql_export = engine.split("function brvtal_backup_export_database(", 1)[1].split(
            "\nfunction brvtal_backup_inventory(", 1
        )[0]
        spec = (ROOT / "docs/BRVTAL-SPEC.md").read_text(encoding="utf-8")
        matrix = coverage_rows()
        verdicts = {
            # La afirmación "production-validated" permanece sin evidencia live.
            "S33-R001": matrix["S33-R001"][2] == "NO_VERIFICABLE"
                and "production-validated" in spec
                and status["missing"]["files"] == 0,
            "S33-R002": status["manual"] == "full"
                and status["schedule"]["enabled"] is True
                and status["schedule"]["scope"] == "database"
                and status["schedule"]["include_media_archive"] is False
                and status["next"] == "2026-09-26T08:00:00+00:00"
                and "'manual_create' => true" in endpoint
                and "'automation' => true" in endpoint,
            "S33-R003": status["database"] == "database"
                and status["media"] == "media"
                and (ROOT / "storage/backups/.htaccess").is_file()
                and "Require all denied" in (ROOT / "storage/backups/.htaccess").read_text(encoding="utf-8")
                and "brvtal_admin_require();" in endpoint
                and "brvtal_admin_require_csrf();" in endpoint,
            # PDO/export sin SSH: contrato offline, no se ejecuta un dump real.
            "S33-R005": "PDO $pdo" in engine
                and "SHOW FULL TABLES" in sql_export
                and "SHOW CREATE TABLE" in sql_export
                and "ORDER BY {$orderSql}" in sql_export
                and "REPEATABLE READ" in sql_export
                and not any(s in sql_export for s in (
                    "shell_exec(", "proc_open(", "passthru(", "mysqldump"
                ))
                and matrix["S33-R005"][2] == "NO_VERIFICABLE",
            "S33-R006": media["inventory"]["files"] == 2
                and media["inventory"]["bytes"] == 12
                and paths == ["uploads/a.txt", "uploads/b.txt"]
                and archive["status"] == "unavailable"
                and archive["reason"] in {
                    "ZIP_EXTENSION_UNAVAILABLE", "MEDIA_SOURCE_EXCEEDS_V1_LIMIT"
                },
        }
        self.assertEqual(set(verdicts), CLAUSES)
        for clause, passed in verdicts.items():
            with self.subTest(clause=clause):
                self.assertTrue(bool(passed), clause)

    def test_falla_cerrado(self):
        result = php(r'''
            require 'config/backups.php';
            require 'config/backup_automation.php';
            $reject = [];
            foreach (['../private', 'not-a-scope'] as $value) {
                try { brvtal_backup_normalize_scope($value); $reject[] = false; }
                catch (InvalidArgumentException $e) { $reject[] = true; }
            }
            try { brvtal_backup_automation_normalize_config([
                'cadence'=>['type'=>'hours', 'interval'=>0, 'time'=>'03:00']
            ]); $reject[] = false; }
            catch (InvalidArgumentException $e) { $reject[] = true; }
            $manifest = ['id'=>'brvtal-20260926T100000Z-abcdef12',
                'components'=>['database'=>['file'=>'../private.sql']]];
            echo json_encode(['reject'=>$reject,
                'traversal'=>brvtal_backup_component_filename($manifest, 'database'),
                'unknown'=>brvtal_backup_component_filename($manifest, 'ssh'),
            ], JSON_THROW_ON_ERROR);
        ''')
        self.assertEqual(result["reject"], [True, True, True])
        self.assertIsNone(result["traversal"])
        self.assertIsNone(result["unknown"])
        for clause in CLAUSES:
            with self.subTest(clause=clause):
                self.assertEqual(coverage_rows()[clause][2], "NO_VERIFICABLE")

    def test_sin_datos_reales(self):
        """Fixtures offline, nunca PII, credenciales o conexiones de producción."""
        data = json.loads((ROOT / "datos.yml").read_text(encoding="utf-8"))
        self.assertEqual(data["phase"], "construccion")
        source = Path(__file__).read_text(encoding="utf-8")
        self.assertIn("TemporaryDirectory(", source)
        for forbidden in ("https://www.brvtal.com.co", "requests.get(",
                          "urllib.request", "mysqli_connect(", 'PDO("mysql:',
                          "BRVTAL_DB_PASSWORD"):
            self.assertNotIn(forbidden, source)

    def test_estados_unknown_explicitos(self):
        """NO_VERIFICABLE hasta pruebas de producción y recibos auténticos."""
        rows = coverage_rows()
        self.assertEqual(set(rows), CLAUSES)
        for clause, row in rows.items():
            with self.subTest(clause=clause):
                self.assertEqual(len(row), 5)
                self.assertEqual(row[2], "NO_VERIFICABLE")
                self.assertIn("Sin aserción integral confirmada", row[3])
                self.assertNotIn("VALIDATED_IN_PRODUCTION", row[3])
        self.assertIn("No certifica producción", MATRIX.read_text(encoding="utf-8"))


if __name__ == "__main__":
    unittest.main()
