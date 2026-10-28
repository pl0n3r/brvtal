#!/usr/bin/env python3
"""BRVTAL #1087: offline GET-only observer caller contract."""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CALLER = ROOT / ".github/workflows/observador-reservas-consumidor.yml"
CI = ROOT / ".github/workflows/update-release-metadata.yml"
AUDIT = ROOT / "scripts/ci_self_audit.py"
REUSABLE = "pl0n3r/factory/.github/workflows/observador-reservas-consumidor.yml@v1"


class LeaseObserverAdoptionTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.text = CALLER.read_text(encoding="utf-8")
        cls.ci = CI.read_text(encoding="utf-8")

    def test_caller_is_local_read_only_and_scheduled(self) -> None:
        text = self.text
        self.assertRegex(text, r"(?m)^on:$")
        self.assertRegex(text, r"(?m)^  schedule:$")
        self.assertIn("cron: '*/10 * * * *'", text)
        self.assertRegex(text, r"(?m)^  workflow_dispatch:$")
        self.assertEqual(text.count("uses: " + REUSABLE), 1)
        self.assertNotIn("pl0n3r/Factory/", text)
        for forbidden in ("pull_request_target:", "issue_comment:"):
            self.assertNotIn(forbidden, text)
        for permission in ("contents", "issues", "pull-requests", "checks", "actions"):
            self.assertEqual(
                len(re.findall(r"(?m)^\s+" + re.escape(permission) + r": read$", text)),
                2, permission,
            )
        self.assertEqual(len(re.findall(r"(?m)^permissions:$", text)), 1)
        self.assertEqual(len(re.findall(r"(?m)^    permissions:$", text)), 1)

    def test_fails_closed_and_never_claims_release_authority(self) -> None:
        text = self.text
        self.assertIn("group: lease-consumer-caller-${{ github.repository_id }}", text)
        self.assertIn("cancel-in-progress: false", text)
        for forbidden in (
            "factory-coordination-", "FACTORY_LEASE_AUTO_RELEASE_ENABLED: 'true'",
            "release_authorized: true", "terminal_certified: true",
            "mutations: 1", "contents: write", "issues: write",
            "actions: write", "checks: write", "pull-requests: write",
            "secrets: inherit", "GH_TOKEN:", "github.token", "runs-on:",
            "steps:", "run: |", "workflow_call:", "repository_dispatch:",
            "curl ", "gh api ", "POST ", "PATCH ", "DELETE ",
        ):
            self.assertNotIn(forbidden, text)
        for required in (
            "release_authorized: false", "terminal_certified: false",
            "mutations: 0", "GET", "UNKNOWN",
        ):
            self.assertIn(required, text)

    def test_fast_ci_registers_observer_and_self_audit(self) -> None:
        path = "tests/test_lease_consumer_observer_adoption.py"
        self.assertIn("\n  fast:", self.ci)
        fast = self.ci.split("\n  fast:", 1)[1].split("\n  database:", 1)[0]
        self.assertEqual(fast.count(path), 2)
        self.assertIn("python3 -m py_compile", fast)
        self.assertIn("python3 -m unittest", fast)
        self.assertIn("tests/ci-self-audit-contract.py", fast)
        self.assertIn("scripts/ci_self_audit.py", fast)
        audit = AUDIT.read_text(encoding="utf-8")
        self.assertIn("FACTORY_V1_WORKFLOW", audit)
        self.assertIn("pl0n3r/factory/", audit)
        self.assertIn("tests/test_factory_observer_adoption.py", fast)


if __name__ == "__main__":
    unittest.main()
