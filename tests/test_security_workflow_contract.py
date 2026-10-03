#!/usr/bin/env python3
from __future__ import annotations

import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github/workflows/seguridad.yml"


class SecurityWorkflowContractTests(unittest.TestCase):
    def source(self) -> str:
        return WORKFLOW.read_text(encoding="utf-8")

    def test_owner_exact_decision_commands_use_versioned_factory_security(self) -> None:
        source = self.source()
        self.assertIn("issue_comment:", source)
        self.assertIn("github.event.comment.author_association == 'OWNER'", source)
        self.assertIn("github.event.comment.user.type != 'Bot'", source)
        for option in ("A", "B", "C", "D"):
            self.assertIn(
                f"github.event.comment.body == '/decidir {option}'",
                source,
            )
        self.assertEqual(2, source.count("repository: pl0n3r/factory"))
        self.assertEqual(2, source.count("ref: v1"))
        self.assertIn("import sincronizar_puerta as gate", source)
        self.assertIn("import decision_respuesta as decision", source)

    def test_english_labels_are_bound_before_factory_security_execution(self) -> None:
        source = self.source()
        for binding in (
            'gate.BLOCKED = "status: blocked"',
            'gate.AVAILABLE = "status: available"',
            'gate.COMPLETED = "status: completed"',
            'gate.DECISION_LABEL = "decision: owner"',
            'decision.DECISION_LABEL = "decision: owner"',
            'decision.COMPLETED = "status: completed"',
            'decision.STATUS_PREFIX = "status: "',
        ):
            with self.subTest(binding=binding):
                self.assertIn(binding, source)
        self.assertIn("-f name='decision: owner'", source)
        self.assertNotIn("-f name='decisión: dueño'", source)
        self.assertNotIn('gate.BLOCKED = "estado: bloqueado"', source)

    def test_consumer_code_is_never_checked_out_or_executed(self) -> None:
        source = self.source()
        self.assertEqual(2, source.count("uses: actions/checkout@"))
        self.assertEqual(2, source.count("repository: pl0n3r/factory"))
        self.assertEqual(2, source.count("path: .factory"))
        self.assertEqual(2, source.count("persist-credentials: false"))
        self.assertNotIn("repository: pl0n3r/brvtal", source.lower())
        self.assertNotIn("repository: $" + "{{ github.repository }}", source)
        self.assertNotIn("working-directory:", source)
        self.assertNotIn("npm ", source)
        self.assertNotIn("composer ", source)

    def test_historical_or_untrusted_comments_are_not_replayed(self) -> None:
        source = self.source()
        for forbidden in (
            "workflow_run:",
            "schedule:",
            "repository_dispatch:",
            "comments?per_page=",
            "issues/comments?per_page=",
        ):
            self.assertNotIn(forbidden, source)
        self.assertIn("github.event_name == 'issue_comment'", source)
        self.assertEqual(
            1,
            source.count("github.event.comment.author_association == 'OWNER'"),
        )
        self.assertIn("OWNER|MEMBER|COLLABORATOR", source)
        self.assertNotIn("author_association == 'MEMBER'", source)
        self.assertNotIn("author_association == 'COLLABORATOR'", source)


if __name__ == "__main__":
    unittest.main()
