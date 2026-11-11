"""Regresiones fail-closed del caller de puertas bot de privacidad BRVTAL."""

from __future__ import annotations

import contextlib
import io
import os
from pathlib import Path
import sys
import textwrap
import types
import unittest
from unittest.mock import patch

WORKFLOW = Path(__file__).resolve().parents[1] / ".github/workflows/seguridad.yml"
STEP = "      - name: Reconciliar privacidad automática autenticada"
NEXT_STEP = "      - name: Retirar de la cola si no es puerta válida y confiable"


def bot_script() -> str:
    workflow = WORKFLOW.read_text(encoding="utf-8")
    body = workflow.split(STEP, 1)[1].split(NEXT_STEP, 1)[0]
    inline = body.split("python3 - <<'PY' >> \"$GITHUB_OUTPUT\"\n", 1)[1]
    return textwrap.dedent(inline.split("\n          PY", 1)[0])


def evaluate(candidate: dict, is_trusted: bool) -> tuple[str, list[str]]:
    """Test the actual workflow inline Python with a controlled Factory API."""
    events: list[str] = []
    module = types.ModuleType("sincronizar_puerta")
    module.PRIVACY_MATERIAL_MARKER = "<!-- factory-privacy-material -->"

    def fake_api(method: str, path: str) -> dict:
        events.append("fetch")
        if method != "GET" or path != "repos/pl0n3r/brvtal/issues/1671":
            raise AssertionError("unexpected API call")
        return candidate

    def trusted_gate(issue: dict, repo: str) -> bool:
        events.append("strict-trust")
        if repo != "pl0n3r/brvtal":
            raise AssertionError("unexpected repo")
        return is_trusted

    def sync_valid(api, repo: str, number: int) -> bool:
        events.append("sync")
        if number != 1671 or repo != "pl0n3r/brvtal":
            raise AssertionError("unexpected target")
        return True

    module.gh_api = fake_api
    module._trusted_gate_candidate = trusted_gate
    module.sync_valid = sync_valid
    output = io.StringIO()
    with patch.dict(sys.modules, {"sincronizar_puerta": module}), patch.dict(
        os.environ, {"REPOSITORIO": "pl0n3r/brvtal", "ISSUE": "1671"}
    ), contextlib.redirect_stdout(output):
        exec(compile(bot_script(), "<security-workflow>", "exec"), {})
    return output.getvalue().strip(), events


class BotPrivacyGateWorkflowTests(unittest.TestCase):
    def test_valid_bot_privacy_gate_reconciles_with_factory_validator(self) -> None:
        output, calls = evaluate(
            {"body": "<!-- factory-privacy-material -->", "user": {"type": "Bot"}},
            True,
        )
        self.assertEqual(output, "canonical=true")
        self.assertEqual(calls, ["fetch", "strict-trust", "sync"])

    def test_untrusted_bot_privacy_gate_fails_closed(self) -> None:
        output, calls = evaluate(
            {"body": "<!-- factory-privacy-material -->", "user": {"type": "Bot"}},
            False,
        )
        self.assertEqual(output, "canonical=false")
        self.assertEqual(calls, ["fetch", "strict-trust"])

    def test_no_privacy_marker_cannot_reconcile_even_with_mocked_trust(self) -> None:
        output, calls = evaluate({"body": "<!-- fake-other-gate -->"}, True)
        self.assertEqual(output, "canonical=false")
        self.assertEqual(calls, ["fetch"])

    def test_invalid_payload_fails_closed(self) -> None:
        for candidate in ({}, {"body": None}, {"body": 42}):
            with self.subTest(candidate=candidate):
                output, calls = evaluate(candidate, True)
                self.assertEqual(output, "canonical=false")
                self.assertEqual(calls, ["fetch"])

    def test_human_gates_and_untrusted_cleanup_remain_independent(self) -> None:
        workflow = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("steps.actor.outputs.trusted == 'true' && steps.gate.outputs.status == 'gate'", workflow)
        self.assertIn("steps.bot_privacy_sync.outputs.canonical != 'true'", workflow)
        self.assertIn("github.event.comment.author_association == 'OWNER'", workflow)
        self.assertIn("decision.DECISION_LABEL = \"decision: owner\"", workflow)
        self.assertIn("gate._trusted_gate_candidate(candidate, repo)", bot_script())
        self.assertIn("gate.PRIVACY_MATERIAL_MARKER in candidate[\"body\"]", bot_script())
        self.assertNotIn("decision_respuesta", bot_script())


if __name__ == "__main__":
    unittest.main()
