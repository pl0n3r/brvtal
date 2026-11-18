#!/usr/bin/env python3
"""#1129: pruebas offline sintéticas S28-R025..R028 y S29-R001.

La evidencia local no equivale a CI, despliegue, cumplimiento de seguridad
externa ni datos reales. Los enlaces sin allowlist permanecen UNKNOWN.
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
IDS = ("S28-R025", "S28-R026", "S28-R027", "S28-R028", "S29-R001")
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_CONTRACT"
SOURCES = {
    "github": "config/admin_development_signals.php",
    "sonar": "config/admin_sonar_signals.php",
    "github_api": "api/admin-development-signals.php",
    "sonar_api": "api/admin-sonar-signals.php",
    "rabbit_api": "api/admin-coderabbit-signals.php",
    "settings_ui": "discadmin/settings-v2.js",
    "settings_php": "config/public_settings.php",
    "public_api": "api/public.php",
    "settings_contract": "tests/settings-control-plane-contract.php",
}
EXPECTED = {
    "S28-R025": "optional server-side GitHub/Sonar token only when configured",
    "S28-R026": "External timeout, rate-limit, invalid/incomplete payload",
    "S28-R027": "External links are allowlisted to their canonical GitHub/Sonar hosts.",
    "S28-R028": "These adapters never mutate GitHub, Sonar, CodeRabbit, deploy state",
    "S29-R001": "Site Settings centralizes global configuration.",
}


def coverage_rows(document: str) -> dict[str, tuple[str, ...]]:
    """Detecta filas ausentes, repetidas, truncadas o reinterpretadas."""
    rows: dict[str, tuple[str, ...]] = {}
    for line in document.splitlines():
        match = re.match(r"^\|\s*(S(?:28|29)-R\d{3})(?=\W|$)", line)
        if not match or match.group(1) not in IDS:
            continue
        key = match.group(1)
        if not line.startswith(f"| {key} · ") or key in rows:
            raise ValueError("UNKNOWN: ambiguous/duplicate coverage row " + key)
        cells = tuple(part.strip() for part in line.strip().strip("|").split("|"))
        if len(cells) != 5 or not all(cells) or cells[2] != "NO_VERIFICABLE":
            raise ValueError("UNKNOWN: malformed/unreconciled row " + key)
        if EXPECTED[key] not in cells[1]:
            raise ValueError("UNKNOWN: unexpected contract for " + key)
        rows[key] = cells
    if set(rows) != set(IDS):
        raise ValueError("UNKNOWN: missing coverage rows")
    return rows


def source_files() -> dict[str, str]:
    return {key: (ROOT / path).read_text(encoding="utf-8")
            for key, path in SOURCES.items()}


# El solicitante está inyectado: ninguna ejecución de cURL, DNS, GitHub ni Sonar.
PHP_PROBE = r'''
require 'config/admin_development_signals.php';
require 'config/admin_sonar_signals.php';
$github = static function(string $url): array {
    if (str_contains($url, 'is%3Aissue')) {
        return ['status'=>200,'body'=>json_encode([
            'total_count'=>7,'incomplete_results'=>false,'items'=>[]])];
    }
    if (str_contains($url, 'is%3Apr')) {
        return ['status'=>200,'body'=>json_encode([
            'total_count'=>1,'incomplete_results'=>false,'items'=>[[
                'number'=>3,'title'=>'Synthetic PR',
                'html_url'=>'https://github.com/pl0n3r/brvtal/pull/3',
            ]]])];
    }
    if (str_contains($url, '/actions/workflows/update-release-metadata.yml/runs')) {
        return ['status'=>200,'body'=>json_encode(['workflow_runs'=>[[
            'status'=>'completed','conclusion'=>'success',
            'html_url'=>'https://github.com/pl0n3r/brvtal/actions/runs/3',
            'updated_at'=>gmdate('c'),
        ]]])];
    }
    throw new RuntimeException('UNKNOWN: unexpected synthetic GitHub route');
};
$sonar = static function(string $url): array {
    $body = match (true) {
        str_contains($url, '/qualitygates/') => ['projectStatus'=>['status'=>'OK']],
        str_contains($url, '/issues/search') => ['paging'=>['total'=>2]],
        str_contains($url, '/hotspots/search') => ['paging'=>['total'=>1]],
        str_contains($url, '/project_analyses/search') =>
            ['analyses'=>[['date'=>gmdate('c')]]],
        default => throw new RuntimeException('UNKNOWN: unexpected Sonar route'),
    };
    return ['status'=>200,'body'=>json_encode($body)];
};
$failed = static function(string $url): array {
    throw new RuntimeException('synthetic timeout/rate-limit/outage');
};
$badGithub = static function(string $url): array {
    return ['status'=>429,'body'=>'{}'];
};
$badSonar = static function(string $url): array {
    return ['status'=>200,'body'=>'{"projectStatus":{}}'];
};
$staleGithub = static function(string $url) use ($github): array {
    $response = $github($url);
    if (str_contains($url, '/actions/workflows/')) {
        $decoded = json_decode($response['body'],true);
        $decoded['workflow_runs'][0]['updated_at'] = '2000-01-01T00:00:00Z';
        $response['body'] = json_encode($decoded);
    }
    return $response;
};
$untrustedLink = null;
try {
    $untrustedLink = brvtalDevelopmentLatestPr(['items'=>[[
        'number'=>9, 'title'=>'Synthetic',
        'html_url'=>'https://outside.invalid/fake-pr',
    ]]]);
} catch (Throwable) {
    // Future hardening may reject this untrusted synthetic link.
}
echo json_encode([
    'github'=>brvtalAdminDevelopmentSignals($github),
    'sonar'=>brvtalAdminSonarSignals($sonar),
    'github_timeout'=>brvtalAdminDevelopmentSignals($failed),
    'sonar_timeout'=>brvtalAdminSonarSignals($failed),
    'github_rate_limit'=>brvtalAdminDevelopmentSignals($badGithub),
    'sonar_invalid'=>brvtalAdminSonarSignals($badSonar),
    'github_stale'=>brvtalAdminDevelopmentSignals($staleGithub),
    'untrusted_pr_url'=>$untrustedLink['url'],
], JSON_THROW_ON_ERROR);
'''


@lru_cache(maxsize=1)
def php_probe() -> dict[str, object]:
    completed = subprocess.run(
        ["php", "-r", PHP_PROBE], cwd=ROOT, capture_output=True,
        text=True, timeout=25, check=False,
    )
    if completed.returncode:
        raise AssertionError("UNKNOWN: offline PHP probe failed: " + completed.stderr[-800:])
    return json.loads(completed.stdout)


def verdict(row_id: str, rows: object, sources: object,
            observed: object) -> dict[str, str]:
    state = {"code": UNKNOWN, "ci": UNKNOWN, "production": UNKNOWN}
    if (not isinstance(rows, dict) or not isinstance(sources, dict)
            or not isinstance(observed, dict) or row_id not in IDS
            or set(rows) != set(IDS) or set(sources) != set(SOURCES)):
        return state
    if any(not isinstance(sources[key], str) or not sources[key] for key in SOURCES):
        return state
    row = rows.get(row_id)
    if (not isinstance(row, tuple) or len(row) != 5
            or row[2] != "NO_VERIFICABLE"
            or EXPECTED[row_id] not in row[1]):
        return state
    gh, sonar = observed.get("github"), observed.get("sonar")
    if not isinstance(gh, dict) or not isinstance(sonar, dict):
        return state
    github, sonar_php = sources["github"], sources["sonar"]
    good_gh = (gh.get("status") == "available"
               and gh.get("freshness") == "fresh"
               and gh.get("open_issues") == 7 and gh.get("open_prs") == 1
               and isinstance(gh.get("latest_pr"), dict)
               and gh["latest_pr"].get("url")
               == "https://github.com/pl0n3r/brvtal/pull/3"
               and gh.get("read_only") is True)
    good_sonar = (sonar.get("status") == "available"
                  and sonar.get("freshness") == "fresh"
                  and sonar.get("quality_gate") == "passed"
                  and sonar.get("new_issues") == 2
                  and sonar.get("security_hotspots") == 1
                  and sonar.get("read_only") is True)
    failures = ("github_timeout", "sonar_timeout", "github_rate_limit", "sonar_invalid")
    degraded = all(isinstance(observed.get(name), dict)
                   and observed[name].get("status") == "unavailable"
                   and observed[name].get("freshness") == "unavailable"
                   for name in failures)
    stale = observed.get("github_stale", {})
    strict_stale = (isinstance(stale, dict)
                    and stale.get("freshness") == "stale"
                    and isinstance(stale.get("latest_ci"), dict)
                    and stale["latest_ci"].get("conclusion") == "success"
                    and stale.get("source_at") == "2000-01-01T00:00:00Z")
    checks = {
        "S28-R025": (
            good_gh and good_sonar
            and "getenv('BRVTAL_GITHUB_TOKEN')" in github
            and "getenv('BRVTAL_SONAR_TOKEN')" in sonar_php
            and "CURLOPT_FOLLOWLOCATION=>false" in github
            and "CURLOPT_FOLLOWLOCATION=>false" in sonar_php
            and "BRVTAL_GITHUB_TOKEN" not in sources["github_api"]
            and "BRVTAL_SONAR_TOKEN" not in sources["sonar_api"]
            and "BRVTAL_GITHUB_TOKEN" not in sources["settings_ui"]
        ),
        "S28-R026": (
            good_gh and good_sonar and degraded and strict_stale
            and observed["github_timeout"].get("open_issues") is None
            and observed["sonar_timeout"].get("quality_gate") is None
            and observed["sonar_invalid"].get("new_issues") is None
            and observed["github_rate_limit"].get("latest_ci") is None
        ),
        # S28-R027 sigue UNKNOWN: latest PR acepta un host no canónico.
        "S28-R027": False,
        "S28-R028": (
            good_gh and good_sonar
            and "brvtal_admin_require();" in sources["github_api"]
            and "brvtal_admin_require();" in sources["sonar_api"]
            and "brvtal_admin_require();" in sources["rabbit_api"]
            and all("REQUEST_METHOD" in sources[key] and "'GET'" in sources[key]
                    for key in ("github_api", "sonar_api", "rabbit_api"))
            and "CURLOPT_POST" not in github
            and "CURLOPT_CUSTOMREQUEST" not in github
            and "CURLOPT_POST" not in sonar_php
            and "CURLOPT_CUSTOMREQUEST" not in sonar_php
        ),
        "S29-R001": (
            "function brvtal_config_setting_json" in sources["settings_php"]
            and "FROM settings WHERE setting_key=?" in sources["settings_php"]
            and "function settingsScreen()" in sources["settings_ui"]
            and 'data-settings-pane="general"' in sources["settings_ui"]
            and 'data-settings-pane="social"' in sources["settings_ui"]
            and 'data-settings-pane="seo"' in sources["settings_ui"]
            and 'data-settings-pane="analytics"' in sources["settings_ui"]
            and "await persistJson('site'" in sources["settings_ui"]
            and "await persistJson('social'" in sources["settings_ui"]
            and "brvtal_public_settings(PDO $pdo)" in sources["public_api"]
            and "SELECT setting_key, setting_value, is_json" in sources["public_api"]
            and "settings-control-plane-contract: ok" in sources["settings_contract"]
        ),
    }
    if checks[row_id]:
        state["code"] = LOCAL
    return state


class BrechasBrvtal39Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.rows = coverage_rows(MATRIX.read_text(encoding="utf-8"))
        cls.sources = source_files()
        cls.observed = php_probe()

    def test_contrato_principal(self) -> None:
        """AC-01: cada fila con aserción local o una brecha UNKNOWN explícita."""
        self.assertEqual(set(self.rows), set(IDS))
        for row_id in IDS:
            with self.subTest(row=row_id):
                self.assertIn(EXPECTED[row_id], self.rows[row_id][1])
                self.assertEqual(self.rows[row_id][2], "NO_VERIFICABLE")
                expected = UNKNOWN if row_id == "S28-R027" else LOCAL
                self.assertEqual(verdict(row_id, self.rows, self.sources,
                                         self.observed)["code"], expected)
        # A returned external host demonstrates the current gap; rejecting it
        # after a future repair must not make this test regress.
        external = self.observed["untrusted_pr_url"]
        self.assertIn(external, (None, "https://outside.invalid/fake-pr"))
        if external:
            self.assertEqual(verdict("S28-R027", self.rows, self.sources,
                                     self.observed)["code"], UNKNOWN)

    def test_falla_cerrado(self) -> None:
        """AC-02: ausencia, duplicación, alteración y evidencia parcial."""
        document = MATRIX.read_text(encoding="utf-8")
        row = next(line for line in document.splitlines()
                   if line.startswith("| S28-R025 · "))
        for bad in (document.replace(row, ""), document + "\n" + row,
                    document.replace(row, "| S28-R025 · malformed |")):
            with self.assertRaisesRegex(ValueError, "UNKNOWN"):
                coverage_rows(bad)
        for row_id in IDS:
            self.assertEqual(verdict(row_id, {}, self.sources,
                                     self.observed)["code"], UNKNOWN)
            self.assertEqual(verdict(row_id, self.rows, {},
                                     self.observed)["code"], UNKNOWN)
            self.assertEqual(verdict(row_id, self.rows, self.sources,
                                     None)["code"], UNKNOWN)
            forged = dict(self.rows)
            cells = list(forged[row_id])
            cells[2] = "CUBIERTO"
            forged[row_id] = tuple(cells)
            self.assertEqual(verdict(row_id, forged, self.sources,
                                     self.observed)["code"], UNKNOWN)
        incomplete = dict(self.observed)
        incomplete["sonar"] = {"status": "available", "quality_gate": "passed"}
        for row_id in ("S28-R025", "S28-R026", "S28-R028"):
            self.assertEqual(verdict(row_id, self.rows, self.sources,
                                     incomplete)["code"], UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        """AC-03: entrada inventada, sin APIs, cookies, tokens o DB reales."""
        own = Path(__file__).read_text(encoding="utf-8")
        for forbidden in ("import " + "requests", "import " + "socket",
                          "urllib." + "request", "curl_" + "exec(",
                          "db" + "();", "-----BEGIN " + "PRIVATE KEY-----"):
            self.assertNotIn(forbidden, own)
        self.assertEqual(self.observed["github"]["open_issues"], 7)
        self.assertEqual(self.observed["sonar"]["security_hotspots"], 1)
        self.assertNotIn("authorization", json.dumps(self.observed).lower())
        self.assertNotIn("Bearer ", json.dumps(self.observed))

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: los fixtures nunca certifican CI o producción."""
        for row_id in IDS:
            with self.subTest(row=row_id):
                result = verdict(row_id, self.rows, self.sources, self.observed)
                self.assertEqual(result["ci"], UNKNOWN)
                self.assertEqual(result["production"], UNKNOWN)
                self.assertIn(result["code"], (UNKNOWN, LOCAL))
        self.assertEqual(verdict("S28-R027", self.rows, self.sources,
                                 self.observed)["code"], UNKNOWN)
        self.assertEqual(verdict("S28-R999", self.rows, self.sources,
                                 self.observed)["code"], UNKNOWN)


if __name__ == "__main__":
    unittest.main()
