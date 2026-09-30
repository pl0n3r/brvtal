import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class AdminBackupSignalsTests(unittest.TestCase):
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
        'artifacts_bytes'=>4096,
        'components'=>['database'=>['file'=>'private.sql','sha256'=>str_repeat('a', 64)]],
    ]];
}};
$automation = function() use ($mode): array {{
    if ($mode === 'automation_failure') throw new RuntimeException('automation unavailable');
    $enabled = $mode !== 'disabled';
    $driveEnabled = in_array($mode, ['offsite_pending','offsite_failed','offsite_failed_after_success','offsite_success'], true);
    $driveStatus = $mode === 'offsite_success'
        ? 'success'
        : (in_array($mode, ['offsite_failed','offsite_failed_after_success'], true) ? 'failed' : 'unknown');
    $lastStatus = $mode === 'scheduler_failed' ? 'failed' : 'success';
    return [
        'config'=>['enabled'=>$enabled,'drive'=>['enabled'=>$driveEnabled]],
        'next_run_at'=>$enabled ? '2026-10-01T08:00:00Z' : null,
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

        self.assertEqual((missing["status"], missing["backup_state"]), ("degraded", "missing"))
        self.assertEqual((partial["status"], partial["backup_state"]), ("degraded", "partial"))
        self.assertEqual((scheduler["status"], scheduler["automation"]["last_result_status"]), ("degraded", "failed"))
        self.assertEqual((pending["status"], pending["offsite"]["status"]), ("degraded", "pending"))
        self.assertEqual((failed["status"], failed["offsite"]["status"]), ("degraded", "failed"))
        self.assertEqual(
            (failed_after_success["status"], failed_after_success["offsite"]["status"]),
            ("degraded", "failed"),
        )

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

        self.assertEqual(ready, ready_again)
        self.assertEqual(ready["source_at"], "2026-09-30T12:34:56+00:00")
        self.assertEqual((ready["backup_state"], ready["offsite"]["status"]), ("ready", "disabled"))
        self.assertFalse(disabled["automation"]["enabled"])
        self.assertEqual(success["offsite"]["status"], "success")
        self.assertEqual((source_failure["status"], source_failure["backup_state"]), ("unavailable", "unavailable"))

    def test_deploy_bound_version_is_0_1_96(self):
        version = (ROOT / "config" / "version.php").read_text(encoding="utf-8")
        package = json.loads((ROOT / "package.json").read_text(encoding="utf-8"))
        self.assertIn("BRVTAL_APP_VERSION = '0.1.96'", version)
        self.assertEqual(package["version"], "0.1.96")


if __name__ == "__main__":
    unittest.main()
