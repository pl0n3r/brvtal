#!/usr/bin/env python3
"""Regression contract for BRVTAL privacy workflow adoption."""

from __future__ import annotations

import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
FACTORY_SHA = "3341681fcc89bc46e752dd3f8f4093e831578c69"
PRIVACY = ROOT / ".github/workflows/privacidad.yml"
AUDIT = ROOT / ".github/workflows/auditoria-privacidad.yml"


class PrivacyWorkflowAdoptionTests(unittest.TestCase):
    def test_privacy_callers_pin_factory_308_fix(self) -> None:
        expected = {
            PRIVACY: "pl0n3r/factory/.github/workflows/privacidad.yml",
            AUDIT: "pl0n3r/factory/.github/workflows/auditoria-privacidad.yml",
        }
        for path, reusable in expected.items():
            text = path.read_text(encoding="utf-8")
            self.assertEqual(text.count(FACTORY_SHA), 2)
            self.assertIn(f"uses: {reusable}@{FACTORY_SHA}", text)
            self.assertIn(f"kit_ref: {FACTORY_SHA}", text)

    def test_permissions_and_secret_boundary_are_preserved(self) -> None:
        privacy = PRIVACY.read_text(encoding="utf-8")
        audit = AUDIT.read_text(encoding="utf-8")
        self.assertIn("permissions:\n  contents: read", privacy)
        self.assertNotIn("issues: write", privacy)
        self.assertIn("permissions:\n  contents: read\n  issues: write", audit)
        self.assertIn("label_language: en", audit)
        for text in (privacy, audit):
            self.assertNotIn("secrets:", text)

    def test_privacy_audit_remains_dispatchable_after_pin_upgrade(self) -> None:
        audit = AUDIT.read_text(encoding="utf-8")
        self.assertIn("workflow_dispatch:", audit)
        self.assertIn("schedule:", audit)
        self.assertIn("label_language: en", audit)
        self.assertEqual(audit.count(FACTORY_SHA), 2)

    def test_privacy_audit_supports_owner_issue_command(self) -> None:
        audit = AUDIT.read_text(encoding="utf-8")
        self.assertIn("workflow_dispatch:", audit)
        self.assertIn("issue_comment:\n    types: [created]", audit)
        self.assertIn("schedule:", audit)

    def test_privacy_audit_comment_is_owner_and_issue_scoped(self) -> None:
        audit = AUDIT.read_text(encoding="utf-8")
        for clause in (
            "github.event_name != 'issue_comment' ||",
            "github.event.issue.number == 736",
            "github.event.comment.user.login == github.repository_owner",
            "github.event.comment.author_association == 'OWNER'",
            "github.event.comment.body == '/privacy-audit'",
        ):
            self.assertIn(clause, audit)

    def test_privacy_audit_comment_fails_closed_for_other_events_and_bodies(self) -> None:
        audit = AUDIT.read_text(encoding="utf-8")
        self.assertIn("issue_comment:\n    types: [created]", audit)
        self.assertEqual(audit.count("github.event.issue.number == 736"), 1)
        self.assertEqual(audit.count("github.event.comment.body == '/privacy-audit'"), 1)
        self.assertNotIn("contains(github.event.comment.body", audit)
        self.assertNotIn("startsWith(github.event.comment.body", audit)

    def test_privacy_audit_comment_preserves_permissions_and_factory_pin(self) -> None:
        audit = AUDIT.read_text(encoding="utf-8")
        self.assertIn("permissions:\n  contents: read\n  issues: write", audit)
        self.assertEqual(audit.count(FACTORY_SHA), 2)
        self.assertIn(f"uses: pl0n3r/factory/.github/workflows/auditoria-privacidad.yml@{FACTORY_SHA}", audit)
        self.assertIn(f"kit_ref: {FACTORY_SHA}", audit)
        self.assertIn("label_language: en", audit)


if __name__ == "__main__":
    unittest.main()
