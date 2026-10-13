#!/usr/bin/env python3
"""Contrato de inspección productiva exclusivamente de lectura (BRVTAL #991)."""
from __future__ import annotations

import pathlib
import re
import runpy
import unittest
from unittest import mock

ROOT = pathlib.Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github/workflows/production-health-inspect.yml"
TRANSPORT = ROOT / "ops/factory/transport.py"
EXPECTED_SHA = "b6613be95c57172a46cb9ead5d04f47f14dbce2c"
ORIGIN = "https://www.brvtal.com.co"


class ProductionHealthInspectWorkflowTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.workflow = WORKFLOW.read_text(encoding="utf-8")

    def assert_manual_security_contract(self, text: str) -> None:
        self.assertIn("on:\n  workflow_dispatch:\n", text)
        self.assertEqual(
            re.findall(r"(?m)^  (?:push|schedule|pull_request|issue_comment|workflow_run):", text),
            [],
        )
        self.assertIn("permissions:\n  contents: read\n", text)
        self.assertNotRegex(text, r"(?m)^\s+(?:actions|issues|contents|deployments): write$")
        self.assertIn("github.actor == github.repository_owner", text)
        self.assertIn("github.ref == 'refs/heads/main'", text)
        self.assertIn('"$GITHUB_ACTOR" == "$GITHUB_REPOSITORY_OWNER"', text)
        self.assertIn('"$GITHUB_REF" == "refs/heads/main"', text)
        self.assertIn('"$sha" == "$GITHUB_SHA"', text)
        self.assertIn("git ls-remote --exit-code origin refs/heads/main", text)
        self.assertIn('"$current_main" == "$sha"', text)
        self.assertIn("ref: ${{ github.sha }}", text)
        self.assertIn("persist-credentials: false", text)
        self.assertIn("timeout-minutes: 10", text)
        self.assertIn("cancel-in-progress: false", text)

    def test_manual_owner_main_exact_head_only(self) -> None:
        self.assert_manual_security_contract(self.workflow)
        cases = (
            ("github.actor == github.repository_owner", "true"),
            ("github.ref == 'refs/heads/main'", "true"),
            ('"$sha" == "$GITHUB_SHA"', '"$sha" != "$GITHUB_SHA"'),
            ('"$current_main" == "$sha"', '"$current_main" != "$sha"'),
            ("persist-credentials: false", "persist-credentials: true"),
            ("  workflow_dispatch:", "  push:"),
        )
        for before, after in cases:
            with self.subTest(weakened=before):
                self.assertIn(before, self.workflow)
                bad = self.workflow.replace(before, after, 1)
                with self.assertRaises(AssertionError):
                    self.assert_manual_security_contract(bad)

    def test_only_validates_and_inspects_with_bounded_summary(self) -> None:
        commands = re.findall(
            r"python3 ops/factory/transport\.py ([a-z-]+)",
            self.workflow,
        )
        self.assertEqual(commands, ["validate", "inspect-migrations"])
        self.assertIn("--sha \"$BRVTAL_EXPECTED_SHA\"", self.workflow)
        self.assertIn("--version \"$BRVTAL_EXPECTED_VERSION\"", self.workflow)
        self.assertIn("--origin \"$BRVTAL_ORIGIN\"", self.workflow)
        self.assertIn("> /tmp/brvtal-health-inspect.json", self.workflow)
        self.assertIn("umask 077", self.workflow)
        self.assertIn("trap 'rm -f /tmp/brvtal-health-inspect.json' EXIT", self.workflow)
        self.assertIn('>> "$GITHUB_STEP_SUMMARY"', self.workflow)
        self.assertIn("DIAGNOSTIC ONLY", self.workflow)
        self.assertIn("len(plan[name])", self.workflow)
        self.assertIn("len(plan[key]) > 200", self.workflow)
        self.assertIn("Invalid sanitized inspection schema", self.workflow)
        self.assertIn("DEPLOY_TOKEN: ${{ secrets.DEPLOY_TOKEN }}", self.workflow)
        self.assertIn("DEPLOY_SSH_KEY: ${{ secrets.DEPLOY_SSH_KEY }}", self.workflow)
        for forbidden in (
            "reconcile-migrations",
            "gh workflow run",
            "ssh_script(",
            "production-migration-reconcile.yml",
            "backup_create",
            "BRVTAL_MIGRATIONS_ALLOW_WRITE",
        ):
            with self.subTest(forbidden=forbidden):
                self.assertNotIn(forbidden, self.workflow)

    def test_missing_identity_or_transport_fails_closed(self) -> None:
        namespace = runpy.run_path(str(TRANSPORT))
        inspect = namespace["inspect_migrations"]
        error = namespace["TransportError"]
        dummy_config = object()

        # A mismatch fails before contacting SSH.
        capture = mock.Mock()
        with mock.patch.dict(
            inspect.__globals__,
            {"_assert_identity": mock.Mock(side_effect=error("identity mismatch")),
             "ssh_capture": capture},
        ):
            with self.assertRaises(error):
                inspect(dummy_config, EXPECTED_SHA, "0.1.142", ORIGIN)
        capture.assert_not_called()

        # A failed SSH transport is not downgraded to a successful empty plan.
        with mock.patch.dict(
            inspect.__globals__,
            {"_assert_identity": mock.Mock(),
             "ssh_capture": mock.Mock(side_effect=error("SSH unavailable"))},
        ):
            with self.assertRaises(error):
                inspect(dummy_config, EXPECTED_SHA, "0.1.142", ORIGIN)

        # Malformed JSON or an incomplete schema remains a hard error.
        for payload in ("not-json", '{"registry_exists":true}'):
            with self.subTest(payload=payload):
                with mock.patch.dict(
                    inspect.__globals__,
                    {"_assert_identity": mock.Mock(),
                     "ssh_capture": mock.Mock(return_value=payload)},
                ):
                    with self.assertRaises(error):
                        inspect(dummy_config, EXPECTED_SHA, "0.1.142", ORIGIN)

        # Bad SHA never reaches identity/SSH routines.
        identity, capture = mock.Mock(), mock.Mock()
        with mock.patch.dict(
            inspect.__globals__, {"_assert_identity": identity, "ssh_capture": capture}
        ):
            with self.assertRaises(error):
                inspect(dummy_config, "not-a-sha", "0.1.142", ORIGIN)
        identity.assert_not_called()
        capture.assert_not_called()


if __name__ == "__main__":
    unittest.main()
