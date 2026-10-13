#!/usr/bin/env python3
"""Contrato de inspección productiva exclusivamente de lectura (BRVTAL #991)."""
from __future__ import annotations

import json
import pathlib
import re
import runpy
import subprocess
import sys
import tempfile
import textwrap
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
        self.assertIn("fetch-depth: 0", text)
        self.assertIn("timeout-minutes: 10", text)
        self.assertIn("cancel-in-progress: false", text)
        # The guard must belong to jobs.inspect, not a sibling job, comment or step.
        top, inspect_job = text.split("\njobs:\n  inspect:\n", 1)
        self.assertIn("\npermissions:\n  contents: read\n", top)
        header, steps_block = inspect_job.split("\n    steps:\n", 1)
        self.assertIn(
            "    if: >-\n      github.actor == github.repository_owner &&\n"
            "      github.ref == 'refs/heads/main'\n",
            "\n" + header,
        )
        self.assertEqual(
            re.findall(r"(?m)^      - name: (.+)$", steps_block),
            [
                "Check out exact dispatch SHA",
                "Verify owner, main and current exact SHA",
                "Test offline inspection security contract",
                "Resolve trusted deployed identity",
                "Validate read-only SSH transport",
                "Inspect sanitized migration plan",
            ],
        )
        identity = steps_block.split(
            "      - name: Verify owner, main and current exact SHA\n", 1
        )[1].split("\n      - name: ", 1)[0]
        for guard in (
            '"$GITHUB_ACTOR" == "$GITHUB_REPOSITORY_OWNER"',
            '"$GITHUB_REF" == "refs/heads/main"',
            '"$sha" == "$GITHUB_SHA"',
            'git ls-remote --exit-code origin refs/heads/main',
            '"$current_main" == "$sha"',
        ):
            self.assertIn(guard, identity)

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
        self.assertIn("--sha \"$BRVTAL_DEPLOYED_SHA\"", self.workflow)
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
        self.assertIn("python3 -m unittest tests/test_production_health_inspect_workflow.py", self.workflow)
        self.assertLess(
            self.workflow.index("python3 -m unittest tests/test_production_health_inspect_workflow.py"),
            self.workflow.index("python3 ops/factory/transport.py validate"),
        )
        # No credentials for checkout, identity validation or offline tests.
        self.assertNotIn("DEPLOY_TOKEN:", self.workflow.split("    steps:\n", 1)[0])
        self.assertNotIn("DEPLOY_SSH_KEY:", self.workflow.split("    steps:\n", 1)[0])
        test_step = self.workflow.split("      - name: Test offline inspection security contract", 1)[1].split(
            "      - name: Validate read-only SSH transport", 1
        )[0]
        self.assertNotIn("DEPLOY_TOKEN:", test_step)
        self.assertNotIn("DEPLOY_SSH_KEY:", test_step)
        self.assertEqual(self.workflow.count("DEPLOY_TOKEN: ${{ secrets.DEPLOY_TOKEN }}"), 2)
        self.assertEqual(self.workflow.count("DEPLOY_SSH_KEY: ${{ secrets.DEPLOY_SSH_KEY }}"), 2)
        self.assertIn('git merge-base --is-ancestor "$deployed" "$BRVTAL_SOURCE_MAIN_SHA"', self.workflow)
        self.assertIn('git cat-file -t "$deployed"', self.workflow)
        self.assertIn("BRVTAL_DEPLOYED_SHA=$deployed", self.workflow)
        self.assertIn('--proto \'=https\'', self.workflow)
        self.assertIn('Source main commit:', self.workflow)
        self.assertIn('Deployed inspected commit:', self.workflow)
        self.assertIn("Main changed during SSH inspection", self.workflow)
        self.assertNotIn("BRVTAL_EXPECTED_SHA", self.workflow)
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

    def deployment_parser(self) -> str:
        opening = '''          deployed="$(python3 - "$deployment" "$BRVTAL_EXPECTED_VERSION" <<'PY'\n'''
        script = self.workflow.split(opening, 1)[1].split("\n          PY\n", 1)[0]
        return textwrap.dedent(script) + "\n"

    def test_deployed_identity_rejects_ambiguity_and_discordance(self) -> None:
        parser = self.deployment_parser()
        self.assertIn("object_pairs_hook=unique_pairs", parser)
        sha = EXPECTED_SHA
        valid = {
            "ok": True,
            "data": {"exact": True, "source": "git_checkout",
                     "version": "0.1.142", "commit": sha},
        }
        cases = [
            (json.dumps(valid), "0.1.142", True),
            (json.dumps(valid), "0.1.143", False),
            ("{", "0.1.142", False),
            ('{"ok":true,"ok":true,"data":{}}', "0.1.142", False),
            (json.dumps({"ok": False, "data": valid["data"]}), "0.1.142", False),
            (json.dumps({"ok": True, "data": {**valid["data"], "exact": False}}), "0.1.142", False),
            (json.dumps({"ok": True, "data": {**valid["data"], "source": "unknown"}}), "0.1.142", False),
            (json.dumps({"ok": True, "data": {**valid["data"], "commit": sha[:9]}}), "0.1.142", False),
            (json.dumps({"ok": True, "data": {**valid["data"], "commit": "x" * 40}}), "0.1.142", False),
            (json.dumps({"ok": True, "data": {**valid["data"], "commit": ""}}), "0.1.142", False),
        ]
        with tempfile.TemporaryDirectory() as tmp:
            path = pathlib.Path(tmp) / "deployment.json"
            for payload, version, allowed in cases:
                with self.subTest(payload=payload, version=version):
                    path.write_text(payload, encoding="utf-8")
                    completed = subprocess.run(
                        [sys.executable, "-c", parser, str(path), version],
                        capture_output=True, text=True, check=False,
                    )
                    self.assertEqual(completed.returncode == 0, allowed)
                    if allowed:
                        self.assertEqual(completed.stdout.strip(), sha)
                    else:
                        self.assertEqual(completed.stdout, "")

    def test_ancestor_proof_accepts_stale_trusted_deployment_only(self) -> None:
        self.assertIn(
            'git merge-base --is-ancestor "$deployed" "$BRVTAL_SOURCE_MAIN_SHA"',
            self.workflow,
        )
        self.assertIn(
            '"$current_main" == "$BRVTAL_SOURCE_MAIN_SHA"',
            self.workflow,
        )
        with tempfile.TemporaryDirectory() as tmp:
            def git(*args: str) -> subprocess.CompletedProcess[str]:
                return subprocess.run(
                    ["git", "-C", tmp, *args],
                    capture_output=True, text=True, check=False,
                )

            self.assertEqual(git("init", "-q").returncode, 0)
            self.assertEqual(git("config", "user.name", "CI Inspector").returncode, 0)
            self.assertEqual(git("config", "user.email", "ci-inspector@example.test").returncode, 0)
            self.assertEqual(git("commit", "--allow-empty", "-qm", "deployed").returncode, 0)
            deployed = git("rev-parse", "HEAD").stdout.strip()
            self.assertEqual(git("commit", "--allow-empty", "-qm", "main").returncode, 0)
            main = git("rev-parse", "HEAD").stdout.strip()
            # main=M, deployed=S as trusted ancestor; main=S, deployed=S both safe.
            self.assertEqual(git("merge-base", "--is-ancestor", deployed, main).returncode, 0)
            self.assertEqual(git("merge-base", "--is-ancestor", deployed, deployed).returncode, 0)
            # Future SHA and malformed or absent object cannot pass the proof.
            self.assertNotEqual(git("merge-base", "--is-ancestor", main, deployed).returncode, 0)
            self.assertNotEqual(git("cat-file", "-t", "a" * 40).returncode, 0)

    def test_missing_identity_or_transport_fails_closed(self) -> None:
        namespace = runpy.run_path(str(TRANSPORT))
        inspect = namespace["inspect_migrations"]
        error = namespace["TransportError"]
        dummy_config = object()

        # Valid empty inspection is accepted and sanitized; no SSH is performed.
        valid = (
            '{"registry_exists":true,"record_registry_migration":false,'
            '"pending":[],"baseline":[],"apply":[],"blocked":[],'
            '"actions":[],"proofs":[]}'
        )
        with mock.patch.dict(
            inspect.__globals__,
            {"_assert_identity": mock.Mock(),
             "ssh_capture": mock.Mock(return_value=valid)},
        ):
            plan = inspect(dummy_config, EXPECTED_SHA, "0.1.142", ORIGIN)
        self.assertEqual(
            plan,
            {
                "registry_exists": True,
                "record_registry_migration": False,
                "pending": [],
                "baseline": [],
                "apply": [],
                "blocked": [],
                "actions": [],
                "proofs": {},
            },
        )

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
