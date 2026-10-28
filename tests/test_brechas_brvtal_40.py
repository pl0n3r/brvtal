#!/usr/bin/env python3
"""#1130: regresiones offline de Settings y analytics, S29-R002..S30-R002.

Se prueban helpers PHP reales con datos sintéticos; los contratos estáticos
no certifican implementación live ni ausencia global de funciones fuera
de las rutas inspeccionadas. La matriz se reconcilia por otro issue.
"""
from __future__ import annotations

import json
import re
import subprocess
import unittest
from functools import lru_cache
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
IDS = ("S29-R002", "S29-R003", "S29-R004", "S30-R001", "S30-R002")
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_CONTRACT"

PATHS = {
    "gtm": "config/public_analytics.php",
    "settings": "discadmin/settings-v2.js",
    "public": "api/public.php",
    "admin": "api/admin-analytics-signals.php",
    "analytics": "config/admin_analytics_signals.php",
    "runtime": "js/public-analytics.js",
    "index": "index.php",
    "e2e": "tests/e2e/public-analytics.spec.mjs",
}


def coverage_rows(text: str) -> dict[str, tuple[str, ...]]:
    """La fuente tiene exactamente una fila de cinco celdas por requisito."""
    result: dict[str, tuple[str, ...]] = {}
    for line in text.splitlines():
        match = re.match(r"^\| (S(?:29|30)-R\d{3}) · ", line)
        if not match or match.group(1) not in IDS:
            continue
        key = match.group(1)
        if key in result:
            raise ValueError("duplicate coverage row: " + key)
        cells = tuple(part.strip() for part in line.strip().strip("|").split("|"))
        if len(cells) != 5 or not cells[0].startswith(key + " · "):
            raise ValueError("invalid coverage row: " + key)
        result[key] = cells
    return result


def source_files() -> dict[str, str]:
    return {
        key: (ROOT / relative).read_text(encoding="utf-8")
        for key, relative in PATHS.items()
    }


@lru_cache(maxsize=1)
def php_probe() -> dict[str, object]:
    """PHP real, solo memoria; no carga DB, servicios, endpoints ni secrets."""
    fixture = r'''
        require 'config/public_analytics.php';
        $valid = brvtal_public_gtm_id_value(' gtm-w23phgjg ');
        $invalid = [];
        foreach (['', 'G-AB12CD34', 'GTM-<script>', 'GTM-A',
                  'GTM-ABCD&x=1', 'GTM-ABCDEFGHIJKLMNOPQRSTUVWXYZ'] as $bad) {
            $invalid[] = brvtal_public_gtm_id_value($bad);
        }
        echo json_encode([
            'valid' => $valid,
            'invalid' => $invalid,
            'empty_markup' => brvtal_public_analytics_markup('', 'synthetic-7'),
            'markup' => brvtal_public_analytics_markup($valid, 'synthetic-7'),
            'theme_fallback' => brvtal_public_gtm_id_from_theme([
                'analytics' => ['tag_manager' => ' gtm-ab12cd34 '],
            ]),
            'invalid_theme_fallback' => brvtal_public_gtm_id_from_theme([
                'analytics' => ['tag_manager' => 'G-AB12CD34'],
            ]),
        ], JSON_THROW_ON_ERROR);
    '''
    done = subprocess.run(
        ["php", "-r", fixture], cwd=ROOT, text=True, capture_output=True,
        timeout=20, check=False,
    )
    if done.returncode:
        raise AssertionError("offline PHP analytics probe failed: " + done.stderr)
    return json.loads(done.stdout)


def verdict(row_id: str, rows: dict[str, tuple[str, ...]],
            files: dict[str, str], observed: object) -> dict[str, str]:
    """Test local positivo no transforma evidencia live/CI en éxito."""
    state = {"code": UNKNOWN, "ci": UNKNOWN, "production": UNKNOWN}
    row = rows.get(row_id)
    if (row_id not in IDS or row is None or len(row) != 5
            or row[2] != "NO_VERIFICABLE"
            or not all(key in files and isinstance(files[key], str)
                       and files[key] for key in PATHS)
            or not isinstance(observed, dict)):
        return state

    gt, settings, public, admin = (
        files["gtm"], files["settings"], files["public"], files["admin"]
    )
    analytics, runtime, index, e2e = (
        files["analytics"], files["runtime"], files["index"], files["e2e"]
    )
    clean_probe = (
        observed.get("valid") == "GTM-W23PHGJG"
        and observed.get("invalid") == [""] * 6
        and observed.get("empty_markup") == ""
        and observed.get("theme_fallback") == "GTM-AB12CD34"
        and observed.get("invalid_theme_fallback") == ""
        and isinstance(observed.get("markup"), str)
        and 'data-gtm-id="GTM-W23PHGJG"' in observed["markup"]
        and "/js/public-analytics.js?v=synthetic-7" in observed["markup"]
        and "/js/public-measurement.js?v=synthetic-7" in observed["markup"]
    )
    # Pruebas de límites: GTM allowlisted en lugar de código JS arbitrario.
    checks = {
        "S29-R002": (
            clean_probe
            and "function brvtal_public_gtm_id_value" in gt
            and "htmlspecialchars($id, ENT_QUOTES, 'UTF-8')" in gt
            and "read('gtm_id').toUpperCase()" in settings
            and "Google Tag Manager Container ID" in settings
            and "if (!/^GTM-[A-Z0-9]{4,20}$/.test(id)) return;" in runtime
            and "googletagmanager.com/gtm.js?id=" in runtime
            and "headCode" not in index
            and "brvtal_public_analytics_markup(" in index
        ),
        "S29-R003": (
            "function brvtal_public_settings(PDO $pdo): array" in public
            and "WHERE setting_key IN ('site','social','appearance','theme.active')" in public
            and "foreach (['site', 'social', 'appearance'] as $key)" in public
            and "setting_key LIKE 'theme.%'" in public
            and "brvtal_admin_require();" in admin
            and "brvtalAdminAnalyticsSignals()" in admin
            and "setting_key IN ('site','social','appearance','analytics'" not in public
        ),
        "S29-R004": (
            clean_probe
            and "$allowedThemeKeys = [" in public
            and "if (array_key_exists($key, $theme))" in public
            and "unset($themeOut['analytics'], $themeOut['customCode']);" in public
            and "BRVTAL_GA4_PRIVATE_KEY" not in public
            and "BRVTAL_GA4_CLIENT_EMAIL" not in public
            and "headCode" not in index
            and "credentials" not in json.dumps(observed, sort_keys=True)
        ),
        "S30-R001": (
            clean_probe
            and "Google Tag Manager is the single public tag-delivery layer" in gt
            and "Google Tag Manager is the single public tag-delivery layer" in settings
            and "googletagmanager.com/gtm.js?id=" in runtime
            and "googletagmanager.com/gtag/js" not in runtime
            and "BRVTAL_GA4_SCOPE" in analytics
            and "analytics.readonly" in analytics
            and "brvtal_admin_require();" in admin
            and "GTM-W23PHGJG" in e2e
        ),
        "S30-R002": (
            "function brvtalAdminAnalyticsSignals(" in analytics
            and "'read_only'=>true" in analytics
            and "function brvtalAnalyticsTopPages(" in analytics
            and "function brvtalAnalyticsDevices(" in analytics
            and "brvtal_admin_require();" in admin
            and "'Cache-Control'=>'no-store'" in admin
            and "function brvtal_public_settings(PDO $pdo): array" in public
            and "brvtal_public_gtm_id(db())" in index
            and "brvtal.analytics.choice.v1" in e2e
        ),
    }
    if checks[row_id]:
        state["code"] = LOCAL
    return state


class BrechasBrvtal40Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        rows = coverage_rows(MATRIX.read_text(encoding="utf-8"))
        self.assertEqual(set(rows), set(IDS))
        files = source_files()
        observed = php_probe()
        for row_id in IDS:
            with self.subTest(row=row_id):
                self.assertEqual(verdict(row_id, rows, files, observed)["code"], LOCAL)

    def test_falla_cerrado(self) -> None:
        rows = coverage_rows(MATRIX.read_text(encoding="utf-8"))
        files = source_files()
        observed = php_probe()
        self.assertEqual(verdict(IDS[0], {}, files, observed)["code"], UNKNOWN)
        self.assertEqual(verdict(IDS[0], rows, {}, observed)["code"], UNKNOWN)
        self.assertEqual(verdict(IDS[0], rows, files, None)["code"], UNKNOWN)
        self.assertEqual(verdict("S29-R999", rows, files, observed)["code"], UNKNOWN)
        for source in PATHS:
            with self.subTest(missing=source):
                altered = dict(files)
                altered.pop(source)
                self.assertEqual(verdict(IDS[0], rows, altered, observed)["code"], UNKNOWN)
        incomplete = dict(observed)
        incomplete["invalid"] = [""]
        for row_id in ("S29-R002", "S29-R004", "S30-R001"):
            self.assertEqual(verdict(row_id, rows, files, incomplete)["code"], UNKNOWN)
        a_row = next(line for line in MATRIX.read_text(encoding="utf-8").splitlines()
                     if line.startswith("| S29-R002 · "))
        with self.assertRaisesRegex(ValueError, "duplicate"):
            coverage_rows(a_row + "\n" + a_row)

    def test_sin_datos_reales(self) -> None:
        # El fixture no usa credenciales, ni sesión de admin, ni transacciones.
        observed = php_probe()
        self.assertEqual(observed["valid"], "GTM-W23PHGJG")
        self.assertEqual(observed["invalid"], [""] * 6)
        own = Path(__file__).read_text(encoding="utf-8")
        self.assertNotIn("-----BEGIN " + "PRIVATE KEY-----", own)
        self.assertNotRegex(own, r"(?m)^(?:import|from)\s+(?:requests|urllib|httpx|socket)\b")
        self.assertNotIn("https://" + "api.", own)
        self.assertNotIn("db" + "();", own)
        self.assertNotIn("curl_" + "exec(", own)

    def test_estados_unknown_explicitos(self) -> None:
        rows = coverage_rows(MATRIX.read_text(encoding="utf-8"))
        files = source_files()
        observed = php_probe()
        for row_id in IDS:
            with self.subTest(row=row_id):
                self.assertEqual(rows[row_id][2], "NO_VERIFICABLE")
                state = verdict(row_id, rows, files, observed)
                self.assertEqual(state["code"], LOCAL)
                self.assertEqual(state["ci"], UNKNOWN)
                self.assertEqual(state["production"], UNKNOWN)
                forged = dict(rows)
                bad = list(rows[row_id])
                bad[2] = "CUBIERTO"
                forged[row_id] = tuple(bad)
                self.assertEqual(verdict(row_id, forged, files, observed)["code"], UNKNOWN)


if __name__ == "__main__":
    unittest.main()
