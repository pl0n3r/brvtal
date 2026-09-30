import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class AdminBackupSignalsTests(unittest.TestCase):
    def js_backup_view(self, payload):
        js = (ROOT / "discadmin" / "dashboard-v2.js").read_text(encoding="utf-8")
        start = js.index("  function developmentBackupView(backup) {")
        end = js.index("\n\n  function developmentSources", start)
        function_source = js[start:end].strip()
        script = (
            function_source
            + "\nconsole.log(JSON.stringify(developmentBackupView(JSON.parse(process.argv[1]))));"
        )
        result = subprocess.run(
            ["node", "-e", script, json.dumps(payload)],
            cwd=ROOT,
            check=True,
            text=True,
            capture_output=True,
            timeout=30,
        )
        return json.loads(result.stdout)

    def fixture(self, mode="ready"):
        config = (ROOT / "config" / "admin_backup_signals.php").as_posix()
        script = f"""
require {json.dumps(config)};
$mode = {json.dumps(mode)};
$backups = function() use ($mode): array {{
    if ($mode === 'source_failure') throw new RuntimeException('backup unavailable');
    if ($mode === 'missing') return [];
    $status = $mode === 'partial' ? 'partial' : 'ready';
    return [[
        'id'=>'brvtal-20260930T120000Z-deadbeef',
        'status'=>$status,
        'scope'=>'full',
        'trigger'=>'scheduled',
        'created_at'=>'2026-09-30T12:00:00Z',
        'artifacts_bytes'=>$mode === 'invalid_manifest' ? '4096' : 4096,
        'components'=>['database'=>['file'=>'private.sql','sha256'=>str_repeat('a', 64)]],
    ]];
}};
$automation = function() use ($mode): array {{
    if ($mode === 'automation_failure') throw new RuntimeException('automation unavailable');
    if ($mode === 'malformed_automation') return [];
    $enabled = $mode !== 'disabled';
    $driveEnabled = in_array($mode, ['offsite_pending','offsite_failed','offsite_failed_after_success','offsite_success'], true);
    $driveStatus = $mode === 'offsite_success'
        ? 'success'
        : (in_array($mode, ['offsite_failed','offsite_failed_after_success'], true) ? 'failed' : 'unknown');
    $lastStatus = $mode === 'scheduler_failed' ? 'failed' : 'success';
    return [
        'config'=>['enabled'=>$enabled,'drive'=>['enabled'=>$driveEnabled]],
        'next_run_at'=>$enabled && $mode !== 'missing_next_run' ? '2026-10-01T08:00:00Z' : null,
        'last_success_at'=>'2026-09-30T12:00:00Z',
        'last_result'=>['status'=>$lastStatus,'drive'=>$driveStatus],
        'last_duration_ms'=>1234,
        'drive'=>[
            'last_success_at'=>in_array($mode, ['offsite_success','offsite_failed_after_success'], true)
                ? '2026-09-30T12:01:00Z'
                : null,
            'last_error'=>in_array($mode, ['offsite_failed','offsite_failed_after_success'], true)
                ? 'DRIVE_UPLOAD_FAILED'
                : null,
        ],
    ];
}};
$sourceAt = static fn(): string => '2026-09-30T12:34:56+00:00';
echo json_encode(brvtalAdminBackupSignals($backups, $automation, $sourceAt), JSON_THROW_ON_ERROR);
"""
        result = subprocess.run(
            ["php", "-r", script],
            cwd=ROOT,
            check=True,
            text=True,
            capture_output=True,
            timeout=30,
        )
        return json.loads(result.stdout)

    def test_normalizes_ready_backup_and_scheduler_state(self):
        data = self.fixture("ready")
        self.assertEqual((data["status"], data["freshness"]), ("available", "fresh"))
        self.assertEqual(data["backup_state"], "ready")
        self.assertEqual(data["latest_backup"]["trigger"], "scheduled")
        self.assertEqual(data["latest_backup"]["artifacts_bytes"], 4096)
        self.assertTrue(data["automation"]["enabled"])
        self.assertEqual(data["automation"]["last_result_status"], "success")
        self.assertTrue(data["read_only"])

    def test_missing_partial_and_failed_states_degrade_explicitly(self):
        missing = self.fixture("missing")
        partial = self.fixture("partial")
        scheduler = self.fixture("scheduler_failed")
        pending = self.fixture("offsite_pending")
        failed = self.fixture("offsite_failed")
        failed_after_success = self.fixture("offsite_failed_after_success")
        missing_next_run = self.fixture("missing_next_run")

        self.assertEqual((missing["status"], missing["backup_state"]), ("degraded", "missing"))
        self.assertEqual((partial["status"], partial["backup_state"]), ("degraded", "partial"))
        self.assertEqual((scheduler["status"], scheduler["automation"]["last_result_status"]), ("degraded", "failed"))
        self.assertEqual((pending["status"], pending["offsite"]["status"]), ("degraded", "pending"))
        self.assertEqual((failed["status"], failed["offsite"]["status"]), ("degraded", "failed"))
        self.assertEqual(
            (failed_after_success["status"], failed_after_success["offsite"]["status"]),
            ("degraded", "failed"),
        )
        self.assertEqual(missing_next_run["status"], "degraded")

    def test_reuses_canonical_backup_sources_without_mutation(self):
        config = (ROOT / "config" / "admin_backup_signals.php").read_text(encoding="utf-8")
        self.assertIn("brvtal_backup_list()", config)
        self.assertIn("brvtal_backup_automation_read()", config)
        self.assertIn("brvtal_backup_automation_public_state(", config)
        for mutation in (
            "brvtal_backup_create(",
            "brvtal_backup_automation_save_config(",
            "brvtal_backup_cleanup(",
            "brvtal_backup_automation_run(",
            "INSERT INTO",
            "UPDATE ",
            "DELETE ",
        ):
            self.assertNotIn(mutation, config)

    def test_dashboard_renders_backup_health_independently(self):
        js = (ROOT / "discadmin" / "dashboard-v2.js").read_text(encoding="utf-8")
        self.assertIn("backup:'/api/admin-backup-signals.php'", js)
        self.assertIn("LAST BACKUP", js)
        self.assertIn("BACKUP STATE", js)
        self.assertIn("AUTOMATION", js)
        self.assertIn("OFF-SITE", js)
        self.assertIn("hydrateDevelopmentSource(results,serial,11,ENDPOINTS.backup)", js)
        self.assertIn("typeof automation.enabled === 'boolean'", js)
        self.assertIn("typeof offsite.enabled === 'boolean'", js)
        self.assertIn("latest.status === backupState", js)
        self.assertIn("backupState === 'missing' && latest === null", js)

    def test_dashboard_backup_view_fails_closed_on_invalid_payloads(self):
        base = {
            "status": "available",
            "freshness": "fresh",
            "backup_state": "ready",
            "latest_backup": {
                "id": "brvtal-20260930T120000Z-deadbeef",
                "status": "ready",
                "scope": "full",
                "trigger": "scheduled",
                "created_at": "2026-09-30T12:00:00Z",
                "artifacts_bytes": 4096,
            },
            "automation": {
                "enabled": True,
                "last_result_status": "success",
            },
            "offsite": {
                "enabled": False,
                "status": "disabled",
            },
            "read_only": True,
        }
        invalid = [
            {**base, "latest_backup": None},
            {**base, "automation": {"last_result_status": "success"}},
            {**base, "offsite": {"status": "disabled"}},
            {**base, "freshness": "degraded"},
            {
                **base,
                "backup_state": "partial",
                "latest_backup": {**base["latest_backup"], "status": "partial"},
            },
            {
                **base,
                "status": "degraded",
                "freshness": "degraded",
                "backup_state": "unavailable",
                "latest_backup": None,
            },
            {**base, "offsite": {"enabled": False, "status": "success"}},
        ]
        for payload in invalid:
            with self.subTest(payload=payload):
                view = self.js_backup_view(payload)
                self.assertEqual(view["state"], "UNAVAILABLE")
                self.assertEqual(view["freshness"], "unavailable")
                self.assertEqual(view["automation"], "UNAVAILABLE")
                self.assertEqual(view["offsite"], "UNAVAILABLE")

        ready = self.js_backup_view(base)
        self.assertEqual(
            (ready["state"], ready["freshness"], ready["automation"], ready["offsite"]),
            ("READY", "fresh", "SUCCESS", "DISABLED"),
        )
        missing = self.js_backup_view(
            {
                **base,
                "status": "degraded",
                "freshness": "degraded",
                "backup_state": "missing",
                "latest_backup": None,
            }
        )
        self.assertEqual((missing["state"], missing["lastBackup"]), ("MISSING", "NONE"))

    def test_full_dashboard_rerender_preserves_backup_result(self):
        js = (ROOT / "discadmin" / "dashboard-v2.js").read_text(encoding="utf-8")
        start = js.index("  function render(results, serial) {")
        end = js.index("\n\n  async function runMount", start)
        render_source = js[start:end].strip()
        sources_start = js.index("  function developmentSources(results) {")
        sources_end = js.index("\n\n  function developmentPanel", sources_start)
        sources_source = js[sources_start:sources_end].strip()
        backup = {
            "status": "available",
            "freshness": "fresh",
            "backup_state": "ready",
            "latest_backup": {
                "id": "brvtal-20260930T120000Z-deadbeef",
                "status": "ready",
                "scope": "full",
                "trigger": "scheduled",
                "created_at": "2026-09-30T12:00:00Z",
                "artifacts_bytes": 4096,
            },
            "automation": {"enabled": True, "last_result_status": "success"},
            "offsite": {"enabled": False, "status": "disabled"},
            "read_only": True,
        }
        script = """
let captured = null;
const mountSerial = 7;
const state = {authed:true,section:'dashboard'};
const root = {innerHTML:''};
const document = {querySelector:(selector)=>selector === '.main' ? {} : null};
function ensureDashboardRoot(){ return root; }
function clearLegacyDashboard(){}
function resultValue(result){ return result?.status === 'fulfilled' ? result.value : null; }
function resultError(result){ return result?.status === 'rejected' ? String(result.reason || 'UNAVAILABLE') : ''; }
function normalizeLayout(){ return {modules:[{id:'development',visible:true}]}; }
function developmentLoadingPanel(){ return 'LOADING'; }
function developmentPanel(value){ captured = value; return 'DEVELOPMENT'; }
function nextEventPanel(){ return ''; }
function attentionPanel(){ return ''; }
function draftsPanel(){ return ''; }
function systemPanel(){ return ''; }
function activityPanel(){ return ''; }
function actionsPanel(){ return ''; }
function analyticsPanel(){ return ''; }
function moduleShell(_item,content){ return content; }
function summaryCard(){ return ''; }
function customizationPanel(){ return ''; }
function bind(){}
function setShellStatus(){}
""" + sources_source + "\n" + render_source + """
const results = Array(12).fill(null);
results[2] = {status:'fulfilled',value:{database:'connected'}};
results[6] = {status:'fulfilled',value:{freshness:'fresh'}};
results[11] = {status:'fulfilled',value:JSON.parse(process.argv[1])};
if (!render(results,7)) throw new Error('render failed');
process.stdout.write(JSON.stringify(captured));
"""
        result = subprocess.run(
            ["node", "-e", script, json.dumps(backup)],
            cwd=ROOT,
            check=True,
            text=True,
            capture_output=True,
            timeout=30,
        )
        captured = json.loads(result.stdout)
        self.assertEqual(captured["backup"], backup)
        self.assertEqual(captured["backupError"], "")

    def test_endpoint_is_authenticated_get_only_no_store_and_private_safe(self):
        api = (ROOT / "api" / "admin-backup-signals.php").read_text(encoding="utf-8")
        config = (ROOT / "config" / "admin_backup_signals.php").read_text(encoding="utf-8")
        self.assertIn("brvtal_admin_require();", api)
        self.assertIn("METHOD_NOT_ALLOWED", api)
        self.assertIn("'Allow'=>'GET'", api)
        self.assertIn("'Cache-Control'=>'no-store'", api)
        lowered = config.lower()
        for private_field in ("filename", "sha256", "folder", "remote_id", "base_dir", "password", "token"):
            self.assertNotIn(f"'{private_field}'", lowered)

    def test_state_fixtures_are_deterministic(self):
        ready = self.fixture("ready")
        ready_again = self.fixture("ready")
        disabled = self.fixture("disabled")
        success = self.fixture("offsite_success")
        source_failure = self.fixture("source_failure")
        malformed_automation = self.fixture("malformed_automation")
        invalid_manifest = self.fixture("invalid_manifest")

        self.assertEqual(ready, ready_again)
        self.assertEqual(ready["source_at"], "2026-09-30T12:34:56+00:00")
        self.assertEqual((ready["backup_state"], ready["offsite"]["status"]), ("ready", "disabled"))
        self.assertFalse(disabled["automation"]["enabled"])
        self.assertEqual(success["offsite"]["status"], "success")
        self.assertEqual((source_failure["status"], source_failure["backup_state"]), ("unavailable", "unavailable"))
        self.assertEqual((malformed_automation["status"], malformed_automation["backup_state"]), ("unavailable", "unavailable"))
        self.assertEqual((invalid_manifest["status"], invalid_manifest["backup_state"]), ("unavailable", "unavailable"))

    def test_deploy_bound_version_is_0_1_97(self):
        version = (ROOT / "config" / "version.php").read_text(encoding="utf-8")
        package = json.loads((ROOT / "package.json").read_text(encoding="utf-8"))
        self.assertIn("BRVTAL_APP_VERSION = '0.1.97'", version)
        self.assertEqual(package["version"], "0.1.97")


if __name__ == "__main__":
    unittest.main()
