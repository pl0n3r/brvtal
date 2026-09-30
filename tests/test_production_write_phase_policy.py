#!/usr/bin/env python3
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github/workflows/production-page-write-smoke.yml"
PROBE = ROOT / "tests/e2e/production-page-write-smoke.mjs"
DOCS = ROOT / "docs/TESTING.md"
MIGRATIONS_CLI = ROOT / "scripts/migrations.php"
MIGRATIONS_CONFIG = ROOT / "config/migrations.php"
TRANSPORT = ROOT / "ops/factory/transport.py"


class ProductionWritePhasePolicyTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.workflow = WORKFLOW.read_text(encoding="utf-8")
        cls.probe = PROBE.read_text(encoding="utf-8")
        cls.docs = DOCS.read_text(encoding="utf-8")

    def test_auto_write_smoke_requires_successful_observer_and_construction_phase(self) -> None:
        for expected in (
            "workflow_run:",
            'workflows: ["Production Deploy Observer"]',
            "github.event.workflow_run.conclusion == 'success'",
            "github.event.workflow_run.head_branch == 'main'",
            "github.event.workflow_run.event == 'push'",
            "vars.BRVTAL_APP_PHASE == 'construction'",
        ):
            self.assertIn(expected, self.workflow)
        self.assertNotRegex(
            self.workflow,
            r"(?m)^  (?:push|pull_request|schedule|repository_dispatch):",
        )

    def test_live_missing_or_unknown_phase_fails_closed_and_manual_confirm_remains(self) -> None:
        self.assertIn("vars.BRVTAL_APP_PHASE == 'construction'", self.workflow)
        self.assertNotIn("vars.BRVTAL_APP_PHASE != 'live'", self.workflow)
        self.assertIn("inputs.confirm == 'WRITE_AND_DELETE_TEMP_PAGE'", self.workflow)
        self.assertIn("workflow_dispatch:", self.workflow)

    def test_auto_path_is_bound_to_observer_exact_head(self) -> None:
        self.assertGreaterEqual(
            self.workflow.count("github.event.workflow_run.head_sha"),
            2,
        )
        self.assertIn("BRVTAL_EXPECTED_SHA:", self.workflow)
        self.assertIn("Check out exact observed source", self.workflow)
        self.assertIn("ref:", self.workflow)

    def test_probe_keeps_confirmation_and_exact_synthetic_cleanup(self) -> None:
        for expected in (
            "const requiredConfirmation = 'WRITE_AND_DELETE_TEMP_PAGE';",
            "BRVTAL_PROD_PAGE_WRITE_CONFIRM",
            "await deleteCreatedPage(context, csrf, createdPageId);",
            "verifyResponse.status() !== 404",
            "findPageBySlug(context, pageSlug)",
        ):
            self.assertIn(expected, self.probe)
        self.assertEqual(1, self.probe.count("context.request.delete"))
        self.assertNotRegex(
            self.probe,
            r"context\.request\.(?:put|patch)\s*\(",
        )

    def test_migration_guards_and_additive_protection_remain_intact(self) -> None:
        cli = MIGRATIONS_CLI.read_text(encoding="utf-8")
        config = MIGRATIONS_CONFIG.read_text(encoding="utf-8")
        transport = TRANSPORT.read_text(encoding="utf-8")
        self.assertIn("BRVTAL_MIGRATIONS_ALLOW_WRITE", cli)
        self.assertIn("--confirm", cli)
        self.assertIn("BRVTAL_MIGRATION_RECONCILE_BACKUP_READY", cli)
        self.assertIn("BRVTAL_MIGRATIONS_ALLOW_WRITE", transport)
        self.assertIn("--confirm", transport)
        for destructive in ("DROP", "TRUNCATE"):
            self.assertIn(destructive, config)

    def test_write_smoke_requires_ready_backup_before_mutation(self) -> None:
        backup_start = self.workflow.index("  backup:\n")
        smoke_start = self.workflow.index("  smoke:\n")
        self.assertLess(backup_start, smoke_start)
        backup = self.workflow[backup_start:smoke_start]
        smoke = self.workflow[smoke_start:]
        for expected in (
            "name: exact-target-production-backup",
            "secrets.DEPLOY_TOKEN",
            "secrets.DEPLOY_SSH_KEY",
            'GITHUB_SHA="${BRVTAL_EXPECTED_SHA}"',
            "BRVTAL_FACTORY_ADAPTER_MODE=production",
            "bash ops/factory/backup",
        ):
            self.assertIn(expected, backup)
        self.assertIn("needs: backup", smoke)
        self.assertIn("needs.backup.result == 'success'", smoke)
        self.assertIn('actual_sha="$(git rev-parse HEAD)"', backup)
        self.assertIn('[[ "$actual_sha" == "$BRVTAL_EXPECTED_SHA" ]]', backup)
        self.assertNotIn("secrets.DEPLOY_TOKEN", smoke)
        self.assertNotIn("ref: ${{ github.event.workflow_run.head_sha", self.workflow)
        self.assertEqual(2, self.workflow.count("ref: main"))
        self.assertIn("Require exact trusted main identity", smoke)
        self.assertIn('[[ "$actual_sha" == "$BRVTAL_EXPECTED_SHA" ]]', smoke)
        self.assertLess(
            self.workflow.index("bash ops/factory/backup"),
            self.workflow.index("node tests/e2e/production-page-write-smoke.mjs"),
        )

    def test_docs_define_phase_switch_and_fail_closed_behavior(self) -> None:
        for expected in (
            "BRVTAL_APP_PHASE",
            "`construction`",
            "`live`",
            "fail-closed",
            "Production Deploy Observer",
            "github.event.workflow_run.head_sha",
        ):
            self.assertIn(expected, self.docs)
        self.assertIn(
            "Controlled production Page write smoke",
            self.docs,
        )


if __name__ == "__main__":
    unittest.main()
