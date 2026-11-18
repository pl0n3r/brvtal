#!/usr/bin/env python3
"""BRVTAL #1279: executable offline coverage of 20 SPEC rows.

A = real PHP/JS with synthetic fixtures, never production attestation.
B = requires external/runtime evidence; C = policy, not a runnable feature.
No network, secrets, real accounts, migrations, deploys or matrix writes.
"""
from __future__ import annotations

from functools import lru_cache
import importlib.util
import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
IDS = tuple([f"S28-R{i:03}" for i in range(15, 29)] +
            [f"S29-R{i:03}" for i in range(1, 5)] +
            [f"S30-R{i:03}" for i in range(1, 3)])
NON_OFFLINE = {
    "S28-R015": ("B", "shared host-node filesystem size does not prove BRVTAL account quota"),
    "S28-R016": ("C", "no-autorepair is an operational policy, not one adapter's output"),
    "S28-R017": ("B", "real private log and sensitive stacktrace exposure need runtime access"),
    "S28-R025": ("B", "provider tokens and browser credential secrecy need runtime evidence"),
    "S28-R028": ("B", "absence of writes across production/external systems needs integration evidence"),
    "S30-R001": ("C", "Google Analytics is a product preference, not one function's behavior"),
    "S30-R002": ("C", "avoiding a large bespoke analytics product is architectural policy"),
}
OFFLINE = frozenset(IDS) - NON_OFFLINE.keys()

# Extract the committed function itself: public.php is an HTTP entrypoint and
# including its entire file would contact the database. This runs its real
# brvtal_public_settings implementation with a synthetic PDO seam instead.
SETTINGS_PROBE = r"""
declare(strict_types=1);
require_once 'config/public_analytics.php';
$source = file_get_contents('api/public.php');
$start = strpos($source, 'function brvtal_public_settings(PDO $pdo): array');
$end = strpos($source, "\nfunction brvtal_public_table_exists(", $start);
if ($start === false || $end === false) { throw new RuntimeException('settings source unavailable'); }
eval(substr($source, $start, $end - $start));
function brvtalPublicI18nPayload(array $site): array { return ['locale'=>'synthetic']; }
class SyntheticStatement extends PDOStatement {
    public function __construct(private array $rows) {}
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
}
class SyntheticPDO extends PDO {
    public string $sql = '';
    public function __construct(private array $rows) {}
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false {
        $this->sql = $query;
        return new SyntheticStatement($this->rows);
    }
}
$pdo = new SyntheticPDO([
    ['setting_key'=>'site', 'setting_value'=>'{"title":"Synthetic Site"}', 'is_json'=>1],
    ['setting_key'=>'analytics', 'setting_value'=>'{"token":"SYNTH_SECRET"}', 'is_json'=>1],
    ['setting_key'=>'security', 'setting_value'=>'SYNTH_SECRET', 'is_json'=>0],
    ['setting_key'=>'theme.active', 'setting_value'=>'core', 'is_json'=>0],
    ['setting_key'=>'theme.core', 'setting_value'=>json_encode([
        'name'=>'Synthetic Theme', 'colors'=>['background'=>'#000000'],
        'analytics'=>['token'=>'SYNTH_SECRET'], 'customCode'=>'SYNTH_SECRET'
    ]), 'is_json'=>1],
]);
$public = brvtal_public_settings($pdo);
$id = brvtal_public_gtm_id_value('gtm-w23phgjg');
$bad = brvtal_public_gtm_id_value('G-ABCD1234');
$markup = brvtal_public_analytics_markup($id, '0.1.101');
echo json_encode(['public'=>$public, 'query'=>$pdo->sql,
    'id'=>$id, 'invalid_id'=>$bad, 'markup'=>$markup], JSON_THROW_ON_ERROR);
"""


def actual_php(script: str) -> dict:
    php = shutil.which("php")
    if php is None:
        raise AssertionError("UNKNOWN: PHP is required")
    p = subprocess.run([php, "-d", "allow_url_fopen=0", "-r", script],
                       cwd=ROOT, capture_output=True, text=True,
                       timeout=35, check=False)
    if p.returncode:
        raise AssertionError("UNKNOWN: real PHP failed: " + p.stderr[-1100:])
    result = json.loads(p.stdout)
    if not isinstance(result, dict):
        raise AssertionError("UNKNOWN: malformed PHP result")
    return result


@lru_cache(maxsize=1)
def settings_projection() -> dict:
    return actual_php(SETTINGS_PROBE)


@lru_cache(maxsize=5)
def helper(name: str):
    # Reuse fixture runners that invoke committed PHP/JS with injected,
    # deterministic callbacks. No Python reimplementation counts as A.
    filename, cls = {
        "development": ("test_admin_development_signals.py", "AdminDevelopmentSignalsTests"),
        "sonar": ("test_admin_sonar_signals.py", "AdminSonarSignalsTests"),
        "coderabbit": ("test_admin_coderabbit_signals.py", "AdminCodeRabbitSignalsTests"),
        "deploy": ("test_admin_deploy_signals.py", "AdminDeploySignalsTests"),
        "seo": ("test_admin_seo_signals.py", "AdminSeoSignalsTests"),
    }[name]
    spec = importlib.util.spec_from_file_location("brvtal_1279_" + name, ROOT / "tests" / filename)
    if spec is None or spec.loader is None:
        raise AssertionError("UNKNOWN: fixture module missing: " + name)
    module = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(module)
    return getattr(module, cls)()


class CoberturaBrvtal10Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        self.assertEqual(20, len(OFFLINE) + len(NON_OFFLINE))
        self.assertTrue(all(kind in ("B", "C", "D") and reason
                            for kind, reason in NON_OFFLINE.values()))
        proved: set[str] = set()

        def check(row: str, ok: bool) -> None:
            self.assertIn(row, OFFLINE)
            self.assertTrue(ok, "Actual behavior not demonstrated: " + row)
            proved.add(row)

        dev = helper("development").php_fixture()
        sonar = helper("sonar").fixture("passed")
        rabbit = helper("coderabbit").fixture("passed")
        deploy = helper("deploy").fixture("exact_ready")
        seo = helper("seo").php_summary("warnings")
        settings = settings_projection()
        seo_view = helper("seo").js_seo_view(seo)

        check("S28-R018", dev["read_only"] is True and
              sonar["read_only"] is True and rabbit["read_only"] is True)
        check("S28-R019", dev["open_issues"] == 7 and dev["open_prs"] == 2 and
              dev["latest_pr"]["number"] == 123 and
              dev["latest_ci"]["conclusion"] == "success" and
              isinstance(dev["source_at"], str))
        check("S28-R020", sonar["quality_gate"] == "passed" and
              (sonar["new_issues"], sonar["security_hotspots"]) == (2, 1) and
              sonar["freshness"] == "fresh")
        check("S28-R021", rabbit["review_state"] == "passed" and
              helper("coderabbit").fixture("findings")["review_state"] == "findings" and
              helper("coderabbit").fixture("rate_limited")["review_state"] == "rate_limited")
        check("S28-R022", deploy["exact"] is True and
              deploy["release_sha"] == "a" * 40 and
              deploy["readiness"] == "ready" and
              deploy["schema_up_to_date"] is True and deploy["read_only"] is True)
        db_failure = helper("deploy").fixture("db_failure")
        check("S28-R023", db_failure["status"] == "degraded" and
              db_failure["schema"] is None and
              db_failure["readiness"] == "degraded" and
              db_failure["schema_up_to_date"] is False)
        check("S28-R024", seo["issues"] == 2 and
              seo["warning_counts"]["MISSING_TITLE"] == 2 and
              (seo["auto"], seo["manual"]) == (1, 1) and
              seo_view["state"] != "UNAVAILABLE")

        no_github = helper("development").php_fixture(fail=True)
        no_sonar = helper("sonar").fixture("failure")
        no_rabbit = helper("coderabbit").fixture("unavailable")
        check("S28-R026", no_github["open_issues"] is None and
              no_sonar["quality_gate"] is None and
              no_rabbit["review_state"] == "unavailable" and dev["open_issues"] == 7)

        js = (ROOT / "discadmin/dashboard-v2.js").read_text(encoding="utf-8")
        start = js.index("    const safeGithubLink = value => ")
        end = js.index("    const repoUrl =", start)
        node = shutil.which("node")
        if not node:
            raise AssertionError("UNKNOWN: Node is required")
        real_js = ("'use strict';\n" + js[start:end] +
                   "\nconsole.log(JSON.stringify([" +
                   "safeGithubLink('https://github.com/pl0n3r/brvtal/pull/5')," +
                   "safeGithubLink('https://github.com/attacker/brvtal/pull/5')," +
                   "safeSonarLink('https://sonarcloud.io/project/overview?id=pl0n3r_brvtal')," +
                   "safeSonarLink('https://evil.invalid/')]))")
        p = subprocess.run([node, "-e", real_js], cwd=ROOT,
                           capture_output=True, text=True, timeout=10, check=True)
        links = json.loads(p.stdout)
        check("S28-R027", links[0].startswith("https://github.com/pl0n3r/brvtal/") and
              links[1] == "" and links[2].startswith("https://sonarcloud.io/") and
              links[3] == "")

        check("S29-R001", settings["public"]["site"]["title"] == "Synthetic Site" and
              settings["public"]["theme"]["name"] == "Synthetic Theme")
        check("S29-R002", settings["id"] == "GTM-W23PHGJG" and
              settings["invalid_id"] == "" and
              'data-gtm-id="GTM-W23PHGJG"' in settings["markup"])
        check("S29-R003", "analytics" not in settings["public"] and
              "security" not in settings["public"] and
              "WHERE setting_key IN" in settings["query"])
        check("S29-R004", "SYNTH_SECRET" not in json.dumps(settings["public"]) and
              "customCode" not in settings["public"].get("theme", {}))
        self.assertEqual(proved, OFFLINE)

    def test_falla_cerrado(self) -> None:
        dev = helper("development")
        sonar = helper("sonar")
        rabbit = helper("coderabbit")
        deploy = helper("deploy")
        seo = helper("seo")
        for case in ("count", "incomplete", "missing_items", "latest_pr"):
            with self.subTest(adapter="github", case=case):
                data = dev.php_fixture(malformed=case)
                self.assertEqual(data["status"], "unavailable")
                self.assertIsNone(data["open_issues"])
        for case in ("failure", "incomplete"):
            data = sonar.fixture(case)
            self.assertEqual((data["quality_gate"], data["new_issues"]), (None, None))
        self.assertEqual(rabbit.fixture("spoofed")["review_state"], "pending")
        self.assertEqual(rabbit.fixture("stale_commit")["review_state"], "pending")
        self.assertEqual(rabbit.fixture("pending")["review_state"], "pending")
        self.assertIsNone(deploy.fixture("identity_failure")["release_sha"])
        self.assertIsNone(deploy.fixture("fallback")["release_sha"])
        self.assertFalse(deploy.fixture("degraded_schema")["schema_up_to_date"])
        partial = seo.php_summary("truncated")
        self.assertEqual((partial["status"], partial["freshness"]), ("partial", "stale"))
        invalid = seo.js_seo_view({"status":"available","freshness":"fresh","read_only":True,
                                   "total":None,"issues":None,"auto":None,"manual":None,
                                   "truncated_resources":[]})
        self.assertEqual(invalid["state"], "UNAVAILABLE")
        self.assertEqual(settings_projection()["invalid_id"], "")

    def test_sin_datos_reales(self) -> None:
        settings = settings_projection()
        self.assertEqual(settings["public"]["site"]["title"], "Synthetic Site")
        self.assertNotIn("SYNTH_SECRET", json.dumps(settings["public"]))
        self.assertNotIn("SYNTH_SECRET", settings["markup"])
        source = Path(__file__).read_text(encoding="utf-8")
        self.assertNotIn("import requests", source)
        self.assertNotIn("import socket", source)
        self.assertNotIn("-----BEGIN PRIVATE KEY-----", source)
        self.assertIn('"allow_url_fopen=0"', source)

    def test_gate_validar_presente(self) -> None:
        workflow = (ROOT / ".github/workflows/update-release-metadata.yml").read_text(encoding="utf-8")
        self.assertRegex(workflow, r"(?m)^  validate:\s*$")
        self.assertRegex(workflow, r"(?m)^    name: validate\s*$")
        self.assertIn("pull_request:", workflow)


if __name__ == "__main__":
    unittest.main()
