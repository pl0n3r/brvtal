#!/usr/bin/env python3
"""Regression tests for the exact-SHA upgrade of workflow dependencies."""
from __future__ import annotations

import re
import unittest
from pathlib import Path

WORKFLOWS = Path(__file__).resolve().parents[1] / ".github" / "workflows"
CHECKOUT = "3d3c42e5aac5ba805825da76410c181273ba90b1"
FACTORY = "dc7a24a7ff16aa01857009aff7f9e13b0bc0ca46"
UPLOAD = "cf430e030ddbb5b0abf93d22962f4752f3646cd9"
NODE = "949feb2413d6458794dcd2491c4babbbce0c15c1"
PHP = "eb7c497e18156a6bbabfef1d3a82760b9eda3962"
EXPECTED_COUNTS = {
    "auditoria-privacidad.yml": (0, 2, 0, 0, 0),
    "ci-throughput-telemetry.yml": (0, 0, 1, 0, 0),
    "privacidad.yml": (0, 2, 0, 0, 0),
    "production-authenticated-smoke.yml": (1, 0, 1, 1, 0),
    "production-page-write-smoke.yml": (2, 0, 1, 1, 0),
    "production-performance.yml": (1, 0, 1, 1, 0),
    "update-release-metadata.yml": (9, 0, 3, 0, 4),
    "factory-hostinger-cutover.yml": (1, 0, 0, 0, 0),
    "production-health-inspect.yml": (1, 0, 0, 0, 0),
    "production-migration-reconcile.yml": (1, 0, 0, 0, 0),
}
OLD = (
    "fbc6f3992d24b796d5a048ff273f7fcc4a7b6c09",
    "fd4674c27e4bd54cce1a2d92e6432f845396fa71",
    "043fb46d1a93c77aae656e7c1c64a875d1fc6a0a",
    "820762786026740c76f36085b0efc47a31fe5020",
    "f3e473d116dcccaddc5834248c87452386958240",
)
NEW = (CHECKOUT, FACTORY, UPLOAD, NODE, PHP)


def source(name: str) -> str:
    return (WORKFLOWS / name).read_text(encoding="utf-8")


class DependencyPinsTests(unittest.TestCase):
    def test_pinned_actions_match_reviewed_updates(self) -> None:
        for name, expected in EXPECTED_COUNTS.items():
            with self.subTest(workflow=name):
                text = source(name)
                self.assertFalse(any(sha in text for sha in OLD))
                self.assertEqual(tuple(text.count(sha) for sha in NEW), expected)
                for match in re.finditer(
                    r"uses:\s+(?:actions/(checkout|upload-artifact|setup-node)|"
                    r"shivammathur/(setup-php))@(\S+)", text
                ):
                    action = match.group(1) or match.group(2)
                    pinned_sha = match.group(3)
                    self.assertRegex(pinned_sha, r"^[0-9a-f]{40}$")
                    self.assertEqual(
                        pinned_sha,
                        {
                            "checkout": CHECKOUT,
                            "upload-artifact": UPLOAD,
                            "setup-node": NODE,
                            "setup-php": PHP,
                        }[action],
                        f"{name}: unexpected pin for {action}",
                    )
        self.assertIn(" # v7.0.1", source("factory-hostinger-cutover.yml"))
        self.assertIn(" # v7.1.0", source("production-performance.yml"))

    def test_privacy_reusable_workflows_share_exact_factory_sha(self) -> None:
        for name in ("privacidad.yml", "auditoria-privacidad.yml"):
            with self.subTest(workflow=name):
                text = source(name)
                match = re.search(
                    r"uses:\s+pl0n3r/factory/.github/workflows/[\w-]+.yml@([0-9a-f]{40})",
                    text,
                )
                self.assertIsNotNone(match)
                self.assertEqual(match.group(1), FACTORY)
                self.assertRegex(text, r"kit_ref:\s+" + FACTORY)
                self.assertIn("permissions:\n  contents: read", text)
        self.assertIn(
            "github.event.comment.author_association == 'OWNER'",
            source("auditoria-privacidad.yml"),
        )

    def test_production_workflows_preserve_security_boundaries(self) -> None:
        controls = {
            "factory-hostinger-cutover.yml": (
                "workflow_dispatch:", "CUTOVER-BRVTAL",
                '[[ "$REF_NAME" == "refs/heads/$DEFAULT_BRANCH" ]]',
                "persist-credentials: false",
            ),
            "production-page-write-smoke.yml": (
                "workflow_dispatch:", "WRITE_AND_DELETE_TEMP_PAGE",
                "permissions:\n  contents: read", "persist-credentials: false",
            ),
            "production-migration-reconcile.yml": (
                "github.event.issue.number == 681",
                "permissions:\n  contents: read", "persist-credentials: false",
            ),
            "production-health-inspect.yml": (
                "github.actor == github.repository_owner", "refs/heads/main",
            ),
            "update-release-metadata.yml": (
                "branches: [main]", "permissions:\n  contents: read",
                "persist-credentials: false",
            ),
        }
        for name, guards in controls.items():
            with self.subTest(workflow=name):
                text = source(name)
                for guard in guards:
                    self.assertIn(guard, text)


if __name__ == "__main__":
    unittest.main()
