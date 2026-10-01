from __future__ import annotations

import subprocess
import sys
import unittest
from pathlib import Path

from scripts.ci_retry import is_transient_failure


ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github" / "workflows" / "update-release-metadata.yml"


class WebKitDependencyRetryTests(unittest.TestCase):
    @staticmethod
    def webkit_block() -> str:
        text = WORKFLOW.read_text(encoding="utf-8")
        return text.split("  webkit:\n", 1)[1].split("\n  recovery:", 1)[0]

    def test_install_deps_is_bounded_and_retried_at_most_twice(self) -> None:
        block = self.webkit_block()
        self.assertIn("timeout-minutes: 15", block)
        self.assertEqual(block.count("python3 scripts/ci_retry.py"), 1)
        self.assertEqual(block.count("--attempts 2"), 1)
        self.assertEqual(
            block.count("timeout 300s npx playwright install-deps webkit"),
            1,
        )

    def test_only_timeout_is_promoted_to_verified_transient_signal(self) -> None:
        block = self.webkit_block()
        self.assertIn('if [ "$status" -eq 124 ]; then', block)
        self.assertIn(
            'echo "timeout: WebKit system dependency installation exceeded 300s"',
            block,
        )
        self.assertTrue(
            is_transient_failure(
                124,
                "timeout: WebKit system dependency installation exceeded 300s",
            )
        )
        self.assertFalse(
            is_transient_failure(100, "apt dependency resolution failed")
        )

    def test_webkit_test_itself_is_not_retried(self) -> None:
        block = self.webkit_block()
        test_command = (
            "npx playwright test tests/e2e/discadmin-totp-login.spec.mjs "
            "--project=webkit-totp"
        )
        self.assertEqual(block.count(test_command), 1)
        retry_section = block.split(
            "- name: Install WebKit system dependencies", 1
        )[1].split("- name: Install WebKit browser", 1)[0]
        self.assertNotIn("playwright test", retry_section)

    def test_ci_self_audit_accepts_the_workflow(self) -> None:
        for command in (
            [sys.executable, "scripts/ci_self_audit.py"],
            [sys.executable, "tests/ci-self-audit-contract.py"],
        ):
            result = subprocess.run(
                command,
                cwd=ROOT,
                text=True,
                stdout=subprocess.PIPE,
                stderr=subprocess.STDOUT,
                check=False,
            )
            self.assertEqual(0, result.returncode, result.stdout)


if __name__ == "__main__":
    unittest.main()
