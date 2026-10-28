#!/usr/bin/env python3
"""#1128: regresiones offline de Development Signals S28-R020..R024.

Ejecuta los adaptadores PHP reales con efectos inyectados y fixtures sintéticos.
Una prueba local no acredita estado de producción ni evidencia externa actual.
"""
from __future__ import annotations

import json
import re
import subprocess
import unittest
from functools import lru_cache
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CLAUSES = tuple(f"S28-R{n:03d}" for n in range(20, 25))
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_TESTED"

PHP_PROBE = r"""
require_once 'config/admin_sonar_signals.php';
require_once 'config/admin_coderabbit_signals.php';
require_once 'config/admin_deploy_signals.php';
require_once 'config/seo_workspace.php';

$reply = static fn(array $payload): array => [
    'status'=>200, 'body'=>json_encode($payload, JSON_THROW_ON_ERROR)
];
$sonarUrls = [];
$sonarRequest = static function (string $url) use (&$sonarUrls, $reply): array {
    $sonarUrls[] = $url;
    if (str_contains($url, '/qualitygates/project_status?')) {
        return $reply(['projectStatus'=>['status'=>'ERROR']]);
    }
    if (str_contains($url, '/issues/search?')) return $reply(['paging'=>['total'=>3]]);
    if (str_contains($url, '/hotspots/search?')) return $reply(['paging'=>['total'=>2]]);
    if (str_contains($url, '/project_analyses/search?')) {
        return $reply(['analyses'=>[['date'=>gmdate('c')]]]);
    }
    throw new RuntimeException('unexpected sonar route');
};
$sonar = brvtalAdminSonarSignals($sonarRequest);
$sonarFail = brvtalAdminSonarSignals(static function (string $url): array {
    throw new RuntimeException('synthetic offline');
});
$sonarMalformed = brvtalAdminSonarSignals(static function (string $url) use ($reply): array {
    return $reply(['paging'=>['total'=>'0']]);
});

$head = str_repeat('a', 40);
$coderabbit = static function (string $mode) use ($reply, $head): array {
    $urls = [];
    $request = static function (string $url) use (&$urls, $reply, $mode, $head): array {
        $urls[] = $url;
        if ($mode === 'offline') throw new RuntimeException('synthetic offline');
        if (str_contains($url, '/search/issues?')) {
            return $reply(['incomplete_results'=>false, 'items'=>[[
                'number'=>1128, 'html_url'=>'https://github.com/pl0n3r/brvtal/pull/1128'
            ]]]);
        }
        if (str_ends_with($url, '/pulls/1128')) return $reply(['head'=>['sha'=>$head]]);
        if (str_contains($url, '/pulls/1128/reviews?')) {
            $bot = $mode === 'spoof' ? 'other-bot[bot]' : 'coderabbitai[bot]';
            return $reply(in_array($mode, ['missing', 'rate_limit'], true) ? [] : [[
                'user'=>['login'=>$bot, 'type'=>'Bot'],
                'body'=>'Review finished. Actionable comments posted: 0',
                'submitted_at'=>gmdate('c'),
                'commit_id'=>$mode === 'stale_head' ? str_repeat('b', 40) : $head,
                'html_url'=>'https://github.com/pl0n3r/brvtal/pull/1128#pullrequestreview-1',
            ]]);
        }
        if (str_contains($url, '/issues/1128/comments?')) {
            if ($mode === 'rate_limit') {
                return $reply([['user'=>['login'=>'coderabbitai[bot]', 'type'=>'Bot'],
                    'body'=>'Review rate limited.', 'updated_at'=>gmdate('c'),
                    'html_url'=>'https://github.com/pl0n3r/brvtal/pull/1128#issuecomment-1']]);
            }
            return $reply([]);
        }
        throw new RuntimeException('unexpected github route');
    };
    return ['data'=>brvtalAdminCodeRabbitSignals($request), 'urls'=>$urls];
};

$deployment = static fn(): array => [
    'version'=>'0.0.0-fixture', 'release_identity'=>'v0.0.0-fixture',
    'exact'=>true, 'commit'=>$head, 'source'=>'environment',
    'environment'=>'SYNTHETIC', 'release_date'=>'2000-01-01',
];
$migrations = static fn(): array => [
    'registry_exists'=>true,
    'migrations'=>[['migration'=>'fixture.sql', 'state'=>'applied',
        'checksum_sha256'=>str_repeat('b', 64)]],
    'orphaned_records'=>[],
];
$schemaFail = static function (): array { throw new RuntimeException('synthetic db offline'); };
$identityFail = static function (): array { throw new RuntimeException('synthetic identity offline'); };
$fallback = static fn(): array => [
    'version'=>'0.0.0-fixture', 'exact'=>false, 'commit'=>null,
    'source'=>'release_fallback', 'environment'=>'SYNTHETIC',
];

$seoItems = [
    ['mode'=>'AUTO', 'warnings'=>[]],
    ['mode'=>'MIXED', 'warnings'=>['MISSING_TITLE', 'DESCRIPTION_LONG']],
];
echo json_encode([
    'sonar'=>$sonar, 'sonar_urls'=>$sonarUrls,
    'sonar_fail'=>$sonarFail, 'sonar_malformed'=>$sonarMalformed,
    'coderabbit'=>$coderabbit('passed'),
    'coderabbit_missing'=>$coderabbit('missing'),
    'coderabbit_spoof'=>$coderabbit('spoof'),
    'coderabbit_stale'=>$coderabbit('stale_head'),
    'coderabbit_limited'=>$coderabbit('rate_limit'),
    'coderabbit_offline'=>$coderabbit('offline'),
    'deploy'=>brvtalAdminDeploySignals($deployment, $migrations),
    'deploy_fallback'=>brvtalAdminDeploySignals($fallback, $migrations),
    'deploy_schema_failure'=>brvtalAdminDeploySignals($deployment, $schemaFail),
    'deploy_identity_failure'=>brvtalAdminDeploySignals($identityFail, $migrations),
    'seo'=>brvtalSeoWorkspaceSummary($seoItems, []),
    'seo_truncated'=>brvtalSeoWorkspaceSummary($seoItems, ['events']),
], JSON_THROW_ON_ERROR);
"""


def matrix_rows(text: str) -> dict[str, tuple[str, ...]]:
    """Una fila por ID, cinco columnas; ausencias no constituyen cobertura."""
    rows: dict[str, tuple[str, ...]] = {}
    for line in text.splitlines():
        match = re.match(r"^\\| (S28-R\\d{3}) · ", line)
        if not match or match.group(1) not in CLAUSES:
            continue
        key = match.group(1)
        cells = tuple(cell.strip() for cell in line.strip().strip("|").split("|"))
        if key in rows:
            raise ValueError(f"duplicate coverage row: {key}")
        if len(cells) != 5 or not cells[0].startswith(key + " · "):
            raise ValueError(f"invalid coverage row: {key}")
        rows[key] = cells
    return rows


@lru_cache(maxsize=1)
def probe() -> dict:
    result = subprocess.run(
        ["php", "-r", PHP_PROBE], cwd=ROOT, capture_output=True,
        text=True, timeout=30, check=False,
    )
    if result.returncode:
        raise AssertionError(f"Synthetic PHP contract failed: {result.stderr}")
    return json.loads(result.stdout)


def evidence(rows: dict, data: dict | None) -> dict[str, dict[str, str]]:
    """El fixture solo prueba código local; live/producción quedan UNKNOWN."""
    result = {}
    for key in CLAUSES:
        valid = (key in rows and rows[key][2] == "NO_VERIFICABLE"
                 and isinstance(data, dict))
        result[key] = {
            "code": LOCAL if valid else UNKNOWN,
            "live": UNKNOWN, "production": UNKNOWN, "deploy_observed": UNKNOWN,
        }
    return result


class BrechasBrvtal38Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.rows = matrix_rows((ROOT / "docs/matriz-cobertura.md").read_text(encoding="utf-8"))

    def test_contrato_principal(self):
        """Cada ID ejercita un adaptador real con datos y efectos inyectados."""
        self.assertEqual(set(self.rows), set(CLAUSES))
        d = probe()

        # S28-R020: proyecto Sonar público, Quality Gate, new code y freshness.
        sonar = d["sonar"]
        self.assertEqual((sonar["quality_gate"], sonar["new_issues"], sonar["security_hotspots"]),
                         ("failed", 3, 2))
        self.assertEqual((sonar["status"], sonar["freshness"]), ("available", "fresh"))
        self.assertTrue(sonar["source_at"] and sonar["read_only"])
        self.assertEqual(len(d["sonar_urls"]), 4)
        self.assertTrue(all(url.startswith("https://sonarcloud.io/api/") and
                            "pl0n3r_brvtal" in url for url in d["sonar_urls"]))

        # S28-R021: último PR, bot auténtico y revisión de HEAD coincidente.
        cr = d["coderabbit"]
        self.assertEqual((cr["data"]["pr_number"], cr["data"]["review_state"]),
                         (1128, "passed"))
        self.assertTrue(cr["data"]["read_only"])
        self.assertEqual(len(cr["urls"]), 4)
        self.assertTrue(all(url.startswith("https://api.github.com/") for url in cr["urls"]))
        self.assertTrue(any("/pulls/1128/reviews?" in url for url in cr["urls"]))

        # S28-R022: identidad local exacta, helpers canónicos de migración.
        deploy = d["deploy"]
        self.assertEqual((deploy["status"], deploy["source"], deploy["readiness"]),
                         ("available", "environment", "ready"))
        self.assertTrue(deploy["exact"] and deploy["schema_up_to_date"])
        self.assertEqual(deploy["release_sha"], "a" * 40)
        self.assertEqual(deploy["schema"]["applied"], 1)
        self.assertTrue(deploy["read_only"])

        # S28-R023: un fallo DB no inventa readiness ni esquema saludable.
        self.assertEqual(d["deploy_schema_failure"]["status"], "degraded")
        self.assertIsNone(d["deploy_schema_failure"]["schema"])
        self.assertFalse(d["deploy_schema_failure"]["schema_up_to_date"])

        # S28-R024: inventario, avisos y modos del SEO Workspace canónico.
        seo = d["seo"]
        self.assertEqual((seo["total"], seo["issues"], seo["healthy"]), (2, 1, 1))
        self.assertEqual((seo["auto"], seo["manual"]), (1, 1))
        self.assertEqual(seo["warning_counts"], {"DESCRIPTION_LONG": 1, "MISSING_TITLE": 1})
        self.assertTrue(seo["read_only"])
        api = (ROOT / "api/seo-workspace.php").read_text(encoding="utf-8")
        js = (ROOT / "discadmin/dashboard-v2.js").read_text(encoding="utf-8")
        self.assertIn("($_GET['summary'] ?? '') === '1'", api)
        self.assertIn("brvtalSeoWorkspaceSummary($items, $truncatedResources)", api)
        self.assertIn("seo:'/api/seo-workspace.php?summary=1'", js)

    def test_falla_cerrado(self):
        d = probe()
        for key in ("sonar_fail", "sonar_malformed"):
            with self.subTest(case=key):
                self.assertEqual(d[key]["status"], "unavailable")
                self.assertIsNone(d[key]["quality_gate"])
                self.assertIsNone(d[key]["new_issues"])
                self.assertIsNone(d[key]["source_at"])
        for key in ("coderabbit_missing", "coderabbit_spoof", "coderabbit_stale"):
            with self.subTest(case=key):
                self.assertEqual(d[key]["data"]["review_state"], "pending")
        self.assertEqual(d["coderabbit_offline"]["data"]["review_state"], "unavailable")
        self.assertEqual(d["coderabbit_limited"]["data"]["review_state"], "rate_limited")
        fallback = d["deploy_fallback"]
        self.assertFalse(fallback["exact"])
        self.assertIsNone(fallback["release_sha"])
        self.assertEqual(fallback["readiness"], "degraded")
        failed = d["deploy_identity_failure"]
        self.assertEqual(failed["status"], "unavailable")
        self.assertIsNone(failed["release_sha"])
        self.assertIsNone(failed["schema"])
        degraded = d["deploy_schema_failure"]
        self.assertEqual(degraded["readiness"], "degraded")
        self.assertIsNone(degraded["schema"])
        seo = d["seo_truncated"]
        self.assertEqual((seo["status"], seo["freshness"]), ("partial", "stale"))
        self.assertEqual(seo["truncated_resources"], ["events"])
        self.assertEqual(matrix_rows(""), {})
        sample = next(x for x in (ROOT / "docs/matriz-cobertura.md").read_text().splitlines()
                      if x.startswith("| S28-R020 · "))
        with self.assertRaisesRegex(ValueError, "duplicate"):
            matrix_rows(sample + "\n" + sample)
        with self.assertRaisesRegex(ValueError, "invalid"):
            matrix_rows("| S28-R020 · invalid | missing |")

    def test_sin_datos_reales(self):
        """Todos los efectos son sintéticos; sin red, DB ni credenciales."""
        for path in ("config/admin_sonar_signals.php",
                     "config/admin_coderabbit_signals.php",
                     "config/admin_deploy_signals.php", "config/seo_workspace.php"):
            self.assertTrue((ROOT / path).is_file())
        self.assertNotRegex(PHP_PROBE, r"(?:curl_exec|file_get_contents|PDO\\(|getenv\\(|exec\\(|shell_exec\\()")
        self.assertNotRegex(PHP_PROBE, r"(?:BEGIN (?:RSA |OPENSSH )?PRIVATE KEY|Authorization:\\s*Bearer)")
        self.assertEqual(probe()["deploy"]["environment"], "SYNTHETIC")
        self.assertEqual(probe()["deploy"]["version"], "0.0.0-fixture")

    def test_estados_unknown_explicitos(self):
        states = evidence(self.rows, probe())
        self.assertEqual(set(states), set(CLAUSES))
        for key, value in states.items():
            with self.subTest(row=key):
                self.assertEqual(value["code"], LOCAL)
                self.assertEqual((value["live"], value["production"], value["deploy_observed"]),
                                 (UNKNOWN, UNKNOWN, UNKNOWN))
                self.assertEqual(self.rows[key][2], "NO_VERIFICABLE")
        self.assertEqual({x["code"] for x in evidence({}, probe()).values()}, {UNKNOWN})
        self.assertEqual({x["code"] for x in evidence(self.rows, None).values()}, {UNKNOWN})
        altered = dict(self.rows)
        row = list(altered["S28-R020"])
        row[2] = "CUBIERTO"
        altered["S28-R020"] = tuple(row)
        self.assertEqual(evidence(altered, probe())["S28-R020"]["code"], UNKNOWN)


if __name__ == "__main__":
    unittest.main()
