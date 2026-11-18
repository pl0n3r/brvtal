#!/usr/bin/env python3
"""#1597: contrato de 50 filas de BRVTAL; evidencia offline, fail-closed.

Los tests heredados ejecutan PHP/JS reales con fixtures sintéticos.
Este módulo no accede a Hostinger ni acredita producción o cambios a la matriz.
"""
from __future__ import annotations

import json
from pathlib import Path
import subprocess
import sys
import unittest

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
SPEC = ROOT / "docs/BRVTAL-SPEC.md"
REPORT = ROOT / "docs/verificacion/verificacion-05.md"
CASES = json.loads(r'''[{"id":"S33-R011","requirement":"Backups may contain sensitive database material such as encrypted security state; backup files therefore belong in private storage and must never be directly public.","category":"A_LOCAL","source":"config/backups.php","probe":"tests/test_cobertura_brvtal_12.py","prior_pr":"1315","gap":"Contrato parcial probado offline; falta integrar PR #1315 a MERGED_EVIDENCE_PRS y acreditar el alcance integral."},{"id":"S33-R012","requirement":"DISCADMIN Development exposes a separate **Backup Health** signal derived only from the canonical local backup inventory and scheduler state.","category":"A_LOCAL","source":"config/admin_backup_signals.php","probe":"tests/test_cobertura_brvtal_13.py","prior_pr":"1329","gap":"Contrato parcial probado offline; falta integrar PR #1329 a MERGED_EVIDENCE_PRS y acreditar el alcance integral."},{"id":"S33-R013","requirement":"It is read-only and may report the latest local backup state, scheduler evidence and off-site delivery evidence.","category":"A_LOCAL","source":"config/admin_backup_signals.php","probe":"tests/test_cobertura_brvtal_13.py","prior_pr":"1329","gap":"Contrato parcial probado offline; falta integrar PR #1329 a MERGED_EVIDENCE_PRS y acreditar el alcance integral."},{"id":"S33-R014","requirement":"Missing/partial backups, failed scheduler runs or enabled off-site delivery without successful evidence must degrade explicitly; the dashboard must never infer restore readiness, 3-2-1-1-0 compliance or a healthy state from missing evidence.","category":"A_LOCAL","source":"config/admin_backup_signals.php","probe":"tests/test_cobertura_brvtal_13.py","prior_pr":"1329","gap":"Contrato parcial probado offline; falta integrar PR #1329 a MERGED_EVIDENCE_PRS y acreditar el alcance integral."},{"id":"S33-R015","requirement":"This signal must not expose private filenames, filesystem paths, checksums, remote folder identifiers or secrets.","category":"A_LOCAL","source":"config/admin_backup_signals.php","probe":"tests/test_cobertura_brvtal_13.py","prior_pr":"1329","gap":"Contrato parcial probado offline; falta integrar PR #1329 a MERGED_EVIDENCE_PRS y acreditar el alcance integral."},{"id":"S33-R016","requirement":"Do **not** implement direct one-click restore from DISCADMIN yet.","category":"A_LOCAL","source":"config/admin_backup_signals.php","probe":"tests/test_cobertura_brvtal_13.py","prior_pr":"1329","gap":"Contrato parcial probado offline; falta integrar PR #1329 a MERGED_EVIDENCE_PRS y acreditar el alcance integral."},{"id":"S33-R017","requirement":"Restore is a separate sensitive operation requiring explicit safeguards and validation.","category":"C_DECLARATIVA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_13.py","prior_pr":"1329","gap":"Política, ejemplo o declaración sin observable atómico; requiere aceptación medible o ratificación editorial."},{"id":"S34-R002","requirement":"v0.1.0 · PRODUCTION","category":"C_DECLARATIVA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_13.py","prior_pr":"1329","gap":"Política, ejemplo o declaración sin observable atómico; requiere aceptación medible o ratificación editorial."},{"id":"S34-R003","requirement":"DEPLOY abc1234","category":"C_DECLARATIVA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_13.py","prior_pr":"1329","gap":"Política, ejemplo o declaración sin observable atómico; requiere aceptación medible o ratificación editorial."},{"id":"S34-R004","requirement":"Product version and deployment source are different concepts:","category":"A_LOCAL","source":"config/deployment.php","probe":"tests/test_cobertura_brvtal_13.py","prior_pr":"1329","gap":"Contrato parcial probado offline; falta integrar PR #1329 a MERGED_EVIDENCE_PRS y acreditar el alcance integral."},{"id":"S34-R005","requirement":"BRVTAL_APP_VERSION: deliberately controlled product version;","category":"A_LOCAL","source":"config/deployment.php","probe":"tests/test_cobertura_brvtal_13.py","prior_pr":"1329","gap":"Contrato parcial probado offline; falta integrar PR #1329 a MERGED_EVIDENCE_PRS y acreditar el alcance integral."},{"id":"S34-R006","requirement":"release/build metadata in config/version.php: intentionally changed when product/release metadata should change;","category":"C_DECLARATIVA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_13.py","prior_pr":"1329","gap":"Política, ejemplo o declaración sin observable atómico; requiere aceptación medible o ratificación editorial."},{"id":"S34-R007","requirement":"runtime deployment identity: resolved from the actual deployed Git checkout/environment by config/deployment.php.","category":"A_LOCAL","source":"config/deployment.php","probe":"tests/test_cobertura_brvtal_13.py","prior_pr":"1329","gap":"Contrato parcial probado offline; falta integrar PR #1329 a MERGED_EVIDENCE_PRS y acreditar el alcance integral."},{"id":"S34-R008","requirement":"Do not increment semantic product version on every commit and do not reintroduce automatic metadata-only stamping per commit.","category":"C_DECLARATIVA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_13.py","prior_pr":"1329","gap":"Política, ejemplo o declaración sin observable atómico; requiere aceptación medible o ratificación editorial."},{"id":"S35-R001","requirement":"Repository: pl0n3r/brvtal","category":"C_DECLARATIVA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_13.py","prior_pr":"1329","gap":"Política, ejemplo o declaración sin observable atómico; requiere aceptación medible o ratificación editorial."},{"id":"S35-R002","requirement":"Canonical branch: main","category":"C_DECLARATIVA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_13.py","prior_pr":"1329","gap":"Política, ejemplo o declaración sin observable atómico; requiere aceptación medible o ratificación editorial."},{"id":"S35-R003","requirement":"GitHub is the code/source/history/review/deployment source of truth.","category":"C_DECLARATIVA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_13.py","prior_pr":"1329","gap":"Política, ejemplo o declaración sin observable atómico; requiere aceptación medible o ratificación editorial."},{"id":"S35-R004","requirement":"Production deployment path is **GitHub main → Hostinger automatic Git deployment**.","category":"B_EXTERNA","source":"config/deployment.php","probe":"tests/test_cobertura_brvtal_13.py","prior_pr":"1329","gap":"Falta recibo de despliegue Git main → Hostinger con identidad de commit y archivo servido."},{"id":"S35-R005","requirement":"Do not use FTP as the normal deployment path.","category":"C_DECLARATIVA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_13.py","prior_pr":"1329","gap":"Política, ejemplo o declaración sin observable atómico; requiere aceptación medible o ratificación editorial."},{"id":"S35-R006","requirement":"Do not perform a manual Hostinger deploy unless explicitly requested.","category":"C_DECLARATIVA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_13.py","prior_pr":"1329","gap":"Política, ejemplo o declaración sin observable atómico; requiere aceptación medible o ratificación editorial."},{"id":"S35-R007","requirement":"Normal delivery flow:","category":"B_EXTERNA","source":"config/deployment.php","probe":"tests/test_cobertura_brvtal_13.py","prior_pr":"1329","gap":"Falta traza de PR/CI/merge/deploy y verificación productiva del mismo SHA."},{"id":"S35-R008","requirement":"branch → PR → CI → squash merge → main CI → Hostinger auto-deploy → production verification.","category":"B_EXTERNA","source":"config/deployment.php","probe":"tests/test_cobertura_brvtal_14.py","prior_pr":"1317","gap":"Falta ejecución completa branch → PR → CI → merge → Hostinger → validación del mismo despliegue."},{"id":"S36-R001","requirement":"Current production environment is Hostinger Premium shared/managed LiteSpeed with PHP 8.3.x, MariaDB 11.8.x, no SSH, approximately 25 GB operational account storage and 512 MB memory.","category":"B_EXTERNA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_14.py","prior_pr":"1317","gap":"Falta inventario actual del plan Hostinger, versiones PHP/MariaDB y cuota efectiva de la cuenta."},{"id":"S36-R002","requirement":"Design for shared-hosting constraints and avoid unnecessary infrastructure complexity.","category":"C_DECLARATIVA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_14.py","prior_pr":"1317","gap":"Política, ejemplo o declaración sin observable atómico; requiere aceptación medible o ratificación editorial."},{"id":"S36-R003","requirement":"Host filesystem totals exposed by PHP may describe a shared node and must not be treated as the account quota.","category":"A_LOCAL","source":"discadmin/system-status-storage.js","probe":"tests/test_cobertura_brvtal_14.py","prior_pr":"1317","gap":"Contrato parcial probado offline; falta integrar PR #1317 a MERGED_EVIDENCE_PRS y acreditar el alcance integral."},{"id":"S37-R001","requirement":"Production is live Hostinger.","category":"B_EXTERNA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_14.py","prior_pr":"1317","gap":"Falta observación externa vigente de la instancia Hostinger, no una constante del repo."},{"id":"S37-R002","requirement":"Do not run destructive migrations, mass deletes, resets, destructive seeds or experimental production changes without evaluating consequences.","category":"C_DECLARATIVA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_14.py","prior_pr":"1317","gap":"Política, ejemplo o declaración sin observable atómico; requiere aceptación medible o ratificación editorial."},{"id":"S37-R003","requirement":"Staging may be added later.","category":"C_DECLARATIVA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_14.py","prior_pr":"1317","gap":"Política, ejemplo o declaración sin observable atómico; requiere aceptación medible o ratificación editorial."},{"id":"S38-R001","requirement":"Before a change is considered ready for production, validate as applicable:","category":"C_DECLARATIVA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_14.py","prior_pr":"1317","gap":"Política, ejemplo o declaración sin observable atómico; requiere aceptación medible o ratificación editorial."},{"id":"S38-R002","requirement":"JavaScript syntax;","category":"A_LOCAL","source":"discadmin/system-status-storage.js","probe":"tests/test_cobertura_brvtal_14.py","prior_pr":"1317","gap":"Contrato parcial probado offline; falta integrar PR #1317 a MERGED_EVIDENCE_PRS y acreditar el alcance integral."},{"id":"S38-R003","requirement":"automated contracts/tests;","category":"A_LOCAL","source":"tests/factory-ci-contract.php","probe":"tests/test_cobertura_brvtal_14.py","prior_pr":"1317","gap":"Contrato parcial probado offline; falta integrar PR #1317 a MERGED_EVIDENCE_PRS y acreditar el alcance integral."},{"id":"S38-R004","requirement":"DB/migrations;","category":"B_EXTERNA","source":"database/schema.sql","probe":"tests/test_cobertura_brvtal_14.py","prior_pr":"1317","gap":"Falta ejecución de migraciones y consultas en base de datos aislada real, con recibo de esquema."},{"id":"S38-R005","requirement":"critical files;","category":"C_DECLARATIVA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_14.py","prior_pr":"1317","gap":"Política, ejemplo o declaración sin observable atómico; requiere aceptación medible o ratificación editorial."},{"id":"S38-R006","requirement":"browser behavior for critical DISCADMIN/public flows.","category":"B_EXTERNA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_14.py","prior_pr":"1317","gap":"Falta E2E en navegador de flujos críticos DISCADMIN/públicos con sesión y servidor reales."},{"id":"S38-R007","requirement":"On update failure, stop.","category":"A_LOCAL","source":"tests/e2e/production-release-observer.mjs","probe":"tests/test_cobertura_brvtal_14.py","prior_pr":"1317","gap":"Contrato parcial probado offline; falta integrar PR #1317 a MERGED_EVIDENCE_PRS y acreditar el alcance integral."},{"id":"S38-R008","requirement":"Do not blindly continue dependent changes.","category":"A_LOCAL","source":"tests/e2e/production-release-observer.mjs","probe":"tests/test_cobertura_brvtal_14.py","prior_pr":"1317","gap":"Contrato parcial probado offline; falta integrar PR #1317 a MERGED_EVIDENCE_PRS y acreditar el alcance integral."},{"id":"S38-R009","requirement":"Automatic rollback is not currently required.","category":"C_DECLARATIVA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_14.py","prior_pr":"1317","gap":"Política, ejemplo o declaración sin observable atómico; requiere aceptación medible o ratificación editorial."},{"id":"S39-R001","requirement":".github/workflows/update-release-metadata.yml is historically named but currently runs **BRVTAL CI**.","category":"C_DECLARATIVA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_15.py","prior_pr":"1316","gap":"Política, ejemplo o declaración sin observable atómico; requiere aceptación medible o ratificación editorial."},{"id":"S39-R002","requirement":"It validates PHP/JavaScript, contracts, MariaDB integrations, migration idempotency and browser behavior.","category":"B_EXTERNA","source":".github/workflows/update-release-metadata.yml","probe":"tests/test_cobertura_brvtal_15.py","prior_pr":"1316","gap":"Falta pipeline completo CI exact-SHA con PHP/JavaScript, MariaDB, migraciones y navegador registrados."},{"id":"S39-R003","requirement":"It does **not** rewrite config/version.php on every commit.","category":"A_LOCAL","source":"config/version.php","probe":"tests/test_cobertura_brvtal_15.py","prior_pr":"1316","gap":"Contrato parcial probado offline; falta integrar PR #1316 a MERGED_EVIDENCE_PRS y acreditar el alcance integral."},{"id":"S39-R004","requirement":"config/version.php conceptually contains intentional product/release metadata such as:","category":"C_DECLARATIVA","source":"docs/BRVTAL-SPEC.md","probe":"tests/test_cobertura_brvtal_15.py","prior_pr":"1316","gap":"Política, ejemplo o declaración sin observable atómico; requiere aceptación medible o ratificación editorial."},{"id":"S39-R005","requirement":"BRVTAL_APP_VERSION;","category":"A_LOCAL","source":"config/version.php","probe":"tests/test_cobertura_brvtal_15.py","prior_pr":"1316","gap":"Contrato parcial probado offline; falta integrar PR #1316 a MERGED_EVIDENCE_PRS y acreditar el alcance integral."},{"id":"S39-R006","requirement":"BRVTAL_APP_BUILD;","category":"A_LOCAL","source":"config/version.php","probe":"tests/test_cobertura_brvtal_15.py","prior_pr":"1316","gap":"Contrato parcial probado offline; falta integrar PR #1316 a MERGED_EVIDENCE_PRS y acreditar el alcance integral."},{"id":"S39-R007","requirement":"BRVTAL_APP_ENV;","category":"A_LOCAL","source":"config/version.php","probe":"tests/test_cobertura_brvtal_15.py","prior_pr":"1316","gap":"Contrato parcial probado offline; falta integrar PR #1316 a MERGED_EVIDENCE_PRS y acreditar el alcance integral."},{"id":"S39-R008","requirement":"BRVTAL_RELEASE_DATE.","category":"A_LOCAL","source":"config/version.php","probe":"tests/test_cobertura_brvtal_15.py","prior_pr":"1316","gap":"Contrato parcial probado offline; falta integrar PR #1316 a MERGED_EVIDENCE_PRS y acreditar el alcance integral."},{"id":"S39-R009","requirement":"The code actually serving production is traced separately at runtime through config/deployment.php, which resolves the deployed source commit.","category":"B_EXTERNA","source":"config/deployment.php","probe":"tests/test_cobertura_brvtal_15.py","prior_pr":"1316","gap":"Falta SHA del árbol servido observado en Hostinger y contrastado contra el resolver PHP."},{"id":"S39-R010","requirement":"Metadata-only changes are not themselves product features or new semantic versions.","category":"A_LOCAL","source":"config/version.php","probe":"tests/test_cobertura_brvtal_15.py","prior_pr":"1316","gap":"Contrato parcial probado offline; falta integrar PR #1316 a MERGED_EVIDENCE_PRS y acreditar el alcance integral."},{"id":"S40-R002","requirement":"event_artists;","category":"B_EXTERNA","source":"database/schema.sql","probe":"tests/test_cobertura_brvtal_16.py","prior_pr":"1320","gap":"Falta presencia de «event_artists;» en esquema MariaDB efectivamente migrado (DDL versionado y PDO sintético no acreditan despliegue)."},{"id":"S40-R003","requirement":"analytics_events;","category":"B_EXTERNA","source":"database/schema.sql","probe":"tests/test_cobertura_brvtal_16.py","prior_pr":"1320","gap":"Falta presencia de «analytics_events;» en esquema MariaDB efectivamente migrado (DDL versionado y PDO sintético no acreditan despliegue)."},{"id":"S40-R004","requirement":"admin_recovery_codes;","category":"B_EXTERNA","source":"database/schema.sql","probe":"tests/test_cobertura_brvtal_16.py","prior_pr":"1320","gap":"Falta presencia de «admin_recovery_codes;» en esquema MariaDB efectivamente migrado (DDL versionado y PDO sintético no acreditan despliegue)."}]''')
EXPECTED = {case["id"] for case in CASES}
CLASSES = {"A_LOCAL": 20, "B_EXTERNA": 12, "C_DECLARATIVA": 18}


def normalized(value: str) -> str:
    """Ignora solo formato Markdown, no contenido semántico."""
    return " ".join(value.replace(chr(96), "").replace("**", "").split())


def matrix_rows(content: str) -> dict[str, tuple[str, str]]:
    found = {}
    for line in content.splitlines():
        if not line.startswith("| S"):
            continue
        fields = [part.strip() for part in line.strip().strip("|").split("|")]
        if len(fields) < 4:
            continue
        ident = fields[0].split(" · ", 1)[0]
        if ident not in EXPECTED:
            continue
        if ident in found:
            raise ValueError("Duplicate matrix ID " + ident)
        found[ident] = (fields[1], fields[2])
    return found


def report_rows(content: str) -> dict[str, list[str]]:
    found = {}
    for line in content.splitlines():
        if not line.startswith("| S"):
            continue
        fields = [field.strip() for field in line.strip().strip("|").split("|")]
        if not fields or fields[0] not in EXPECTED:
            continue
        if fields[0] in found or len(fields) != 8:
            raise ValueError("Duplicate or invalid report row " + fields[0])
        found[fields[0]] = fields
    return found


def verify_contract(matrix: str, spec: str, report: str, cases=CASES) -> int:
    source = matrix_rows(matrix)
    documented = report_rows(report)
    if len(cases) != 50 or len(EXPECTED) != 50 or len(documented) != 50:
        raise ValueError("The contract requires exactly 50 unique rows")
    if "NO_VERIFICABLE se conserva para las 50 filas" not in report:
        raise ValueError("The fail-closed outcome was removed")
    if "UNKNOWN" not in report or "matriz central intacta" not in report:
        raise ValueError("Operational evidence is not acknowledged")
    norm_spec = normalized(spec)
    for category, total in CLASSES.items():
        if sum(case["category"] == category for case in cases) != total:
            raise ValueError("Evidence class count drift")
    for case in cases:
        ident = case["id"]
        if source.get(ident) != (case["requirement"], "NO_VERIFICABLE"):
            raise ValueError("Source requirement/status changed: " + ident)
        if normalized(case["requirement"]) not in norm_spec:
            raise ValueError("The owning SPEC no longer states " + ident)
        for key in ("source", "probe"):
            path = case[key]
            if not path.startswith(("docs/", "config/", "discadmin/", "tests/", "database/", ".github/")):
                raise ValueError("Untrusted path " + ident)
            if not (ROOT / path).is_file():
                raise ValueError("Missing existing source/behavior test: " + path)
        row = documented.get(ident)
        if row is None:
            raise ValueError("Missing audit row " + ident)
        expected = [
            ident, case["category"], "NO_VERIFICABLE", case["requirement"],
            "`" + case["source"] + "`", "`" + case["probe"] + "`",
            "[PR #" + case["prior_pr"] + "](https://github.com/pl0n3r/brvtal/pull/"
            + case["prior_pr"] + ")", case["gap"],
        ]
        if row != expected:
            raise ValueError("Report no longer matches evidence for " + ident)
    return len(documented)


class VerificacionBrvtal05Tests(unittest.TestCase):
    def test_cincuenta_filas_exacto_sin_acreditacion_falsa(self):
        self.assertEqual(50, verify_contract(
            MATRIX.read_text(encoding="utf-8"),
            SPEC.read_text(encoding="utf-8"),
            REPORT.read_text(encoding="utf-8"),
        ))

    def test_mutaciones_negativas_fallan(self):
        matrix = MATRIX.read_text(encoding="utf-8")
        spec = SPEC.read_text(encoding="utf-8")
        report = REPORT.read_text(encoding="utf-8")
        first = CASES[0]
        scenarios = (
            (matrix.replace(first["requirement"] + " | NO_VERIFICABLE |",
                            first["requirement"] + " | CUBIERTO |", 1), spec, report),
            (matrix, spec.replace(first["requirement"], "INVALID_SOURCE", 1), report),
            (matrix, spec.replace("BRVTAL_APP_VERSION`: deliberately controlled product version;",
                                  "BRVTALAPPVERSION`: deliberately controlled product version;", 1), report),
            (matrix, spec, report.replace("| " + first["id"] + " |", "| S33-R999 |", 1)),
            (matrix, spec, report.replace("| A_LOCAL |", "| CUBIERTO |", 1)),
            (matrix, spec, report.replace("`" + first["source"] + "`",
                                         "`config/absent-test-source.php`", 1)),
        )
        for i, values in enumerate(scenarios):
            with self.subTest(mutation=i), self.assertRaises(ValueError):
                verify_contract(*values)

    def test_fixtures_sinteticos_y_comportamiento_heredado(self):
        """Run actual preexisting PHP/JS contracts, never a production request."""
        for target in (
            "tests/test_cobertura_brvtal_12.py",
            "tests/test_cobertura_brvtal_13.py",
            "tests.test_cobertura_brvtal_14.CoberturaBrvtal14Tests.test_falla_cerrado",
            "tests.test_cobertura_brvtal_14.CoberturaBrvtal14Tests.test_sin_datos_reales",
            "tests/test_cobertura_brvtal_15.py",
            "tests/test_cobertura_brvtal_16.py",
        ):
            with self.subTest(probe=target):
                result = subprocess.run(
                    [sys.executable, "-m", "unittest", target],
                    cwd=ROOT, capture_output=True, text=True, timeout=180, check=False,
                )
                self.assertEqual(0, result.returncode,
                                 target + " failed\n" + result.stderr[-1200:])

    def test_storage_y_observador_con_js_real(self):
        """Positive runtime witness replaces an obsolete *phrase* in older test14."""
        from tests import test_cobertura_brvtal_14 as old

        snapshot = old.observe()  # Executes real JS/observer in a Node VM.
        storage = snapshot["storage"]
        visible = storage["visible"]
        self.assertTrue(storage["valid"])
        self.assertEqual(visible["scope"], "brvtal-managed-data")
        self.assertEqual(visible["primary"], "6 MB / 24 MB")
        self.assertIn("configured hosting quota", visible["title"])
        self.assertNotIn("987 TB", json.dumps(visible))
        self.assertEqual(visible["head"], "25.0% USED · BRVTAL DATA")

        for program, args, contents in (
            ("php", ("-l",), (ROOT / "config/version.php").read_text(encoding="utf-8")),
            ("node", ("--check",), (ROOT / "discadmin/system-status-storage.js").read_text(encoding="utf-8")),
        ):
            result = old.run(program, *args, input_text=contents)
            self.assertEqual(0, result.returncode, result.stderr[-500:])
        contract = old.run("php", "tests/factory-ci-contract.php")
        self.assertEqual(0, contract.returncode, contract.stderr[-500:])
        self.assertIn("Factory CI caller contract passed.", contract.stdout)

        release = snapshot["release"]
        self.assertTrue(release["mismatched"])
        self.assertEqual(release["order"], ["observe", "authenticate"])


if __name__ == "__main__":
    unittest.main()
