#!/usr/bin/env python3
from __future__ import annotations

import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github/workflows/seguridad.yml"


class SecurityWorkflowContractTests(unittest.TestCase):
    def source(self) -> str:
        """Read the workflow under test without executing repository code."""
        return WORKFLOW.read_text(encoding="utf-8")

    def job_block(self, name: str, next_name: str) -> str:
        """Return one top-level job block using stable two-space YAML anchors."""
        source = self.source()
        start = source.index(f"  {name}:")
        end = source.index(f"\n  {next_name}:", start)
        return source[start:end]

    def test_owner_exact_decision_commands_use_versioned_factory_security(self) -> None:
        """Exact owner commands must route only through versioned Factory security."""
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
        """BRVTAL English labels must be rebound before Factory mutations run."""
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
        """The security workflow must never check out or execute BRVTAL code."""
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
        """Only the current trusted event may drive decision materialization."""
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

    def test_materialization_requires_canonical_gate_after_sync(self) -> None:
        """The decision step must follow gate sync and require canonical=true."""
        block = self.job_block("materializar-respuesta", "sincronizar-decision")
        sync_at = block.index("id: gate_sync")
        decision_name = "name: Validar comando y materializar decisión con labels BRVTAL"
        decision_at = block.index(decision_name)
        self.assertLess(sync_at, decision_at)
        decision_step = block[decision_at:]
        condition = "if: steps.gate_sync.outputs.canonical == 'true'"
        self.assertIn(condition, decision_step.split("env:", 1)[0])

    def test_cleanup_fails_closed_when_gate_classification_did_not_succeed(self) -> None:
        """Queue cleanup must not run on missing or failed gate classification."""
        source = self.source()
        self.assertIn("steps.actor.outputs.trusted == 'false'", source)
        self.assertIn("steps.gate.outcome == 'success'", source)
        self.assertIn("steps.gate.outputs.status != 'gate'", source)
        self.assertNotIn("steps.actor.outputs.trusted != 'true'", source)


if __name__ == "__main__":
    unittest.main()
