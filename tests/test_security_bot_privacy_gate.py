"""Regresiones fail-closed del caller de puertas bot de privacidad BRVTAL."""

from __future__ import annotations

import contextlib
import io
import ast
import re
import subprocess
import tempfile
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


def evaluate(candidate: dict, is_trusted: bool, sync_result: bool = True) -> tuple[str, list[str]]:
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
        return sync_result

    module.gh_api = fake_api
    module._trusted_gate_candidate = trusted_gate
    module.sync_valid = sync_valid
    output = io.StringIO()
    with patch.dict(sys.modules, {"sincronizar_puerta": module}), patch.dict(
        os.environ, {"REPOSITORIO": "pl0n3r/brvtal", "ISSUE": "1671"}
    ), contextlib.redirect_stdout(output):
        exec(compile(bot_script(), "<security-workflow>", "exec"), {})
    return output.getvalue().strip(), events


def cleanup_condition(
    actor_trusted: str, bot_canonical: str, gate_outcome: str, gate_status: str
) -> bool:
    """Evaluate the actual GitHub Actions cleanup condition with synthetic outputs."""
    text = WORKFLOW.read_text(encoding="utf-8").split(NEXT_STEP, 1)[1]
    expression = text.split("        if: >-\n", 1)[1].split("        env:", 1)[0]
    observed = {
        "steps.actor.outputs.trusted": actor_trusted,
        "steps.bot_privacy_sync.outputs.canonical": bot_canonical,
        "steps.gate.outcome": gate_outcome,
        "steps.gate.outputs.status": gate_status,
    }
    comparisons = re.compile(r"(steps\.[\w.]+)\s*(==|!=)\s*'([^']*)'")

    def replace_comparison(match: re.Match[str]) -> str:
        """Substitute only known workflow outputs with tested boolean values."""
        key, operator, expected = match.groups()
        if key not in observed:
            raise AssertionError(f"Unknown workflow output: {key}")
        equal = observed[key] == expected
        return str(equal if operator == "==" else not equal)

    expression = comparisons.sub(replace_comparison, expression)
    expression = expression.replace("always()", "True").replace("&&", " and ").replace("||", " or ")
    # YAML folded scalar whitespace is significant in Python; normalize it first.
    parsed = ast.parse(" ".join(expression.split()), mode="eval")

    def boolean(node: ast.AST) -> bool:
        """Evaluate the safe subset of AST accepted for workflow predicates."""
        if isinstance(node, ast.Constant) and type(node.value) is bool:
            return node.value
        if isinstance(node, ast.BoolOp) and isinstance(node.op, ast.And):
            return all(boolean(value) for value in node.values)
        if isinstance(node, ast.BoolOp) and isinstance(node.op, ast.Or):
            return any(boolean(value) for value in node.values)
        raise AssertionError(f"Unexpected cleanup expression: {ast.dump(node)}")

    return boolean(parsed.body)


def cleanup_script() -> str:
    """Extract the actual cleanup shell step without executing the workflow."""
    fragment = WORKFLOW.read_text(encoding="utf-8").split(NEXT_STEP, 1)[1]
    return textwrap.dedent(fragment.split("        run: |\n", 1)[1])


def execute_cleanup(delete_denied: bool = False) -> tuple[int, list[str]]:
    """Execute label cleanup against a local gh stub, never the GitHub API."""
    with tempfile.TemporaryDirectory() as directory:
        path = Path(directory)
        program = path / "gh"
        log = path / "operations"
        program.write_text(
            "#!/usr/bin/env python3\n"
            "import os, sys\n"
            "args = sys.argv[1:]\n"
            "if args[:1] != ['api']: sys.exit(2)\n"
            "if '--method' in args:\n"
            "    with open(os.environ['MOCK_OPERATIONS'], 'a', encoding='utf-8') as out:\n"
            "        out.write('DELETE ' + ' '.join(args) + '\\n')\n"
            "    sys.exit(1 if os.environ.get('MOCK_DELETE_DENIED') == '1' else 0)\n"
            "if '--jq' in args:\n"
            "    print('decision: owner')\n"
            "    sys.exit(0)\n"
            "sys.exit(3)\n", encoding="utf-8",
        )
        program.chmod(0o755)
        environment = dict(os.environ)
        environment.update(
            PATH=str(path) + os.pathsep + environment.get("PATH", ""),
            MOCK_OPERATIONS=str(log),
            MOCK_DELETE_DENIED="1" if delete_denied else "0",
            REPOSITORIO="pl0n3r/brvtal",
            ISSUE="1671",
        )
        result = subprocess.run(
            ["bash", "-c", cleanup_script()],
            env=environment, capture_output=True, text=True, check=False,
        )
        operations = log.read_text(encoding="utf-8").splitlines() if log.exists() else []
        return result.returncode, operations



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


    def test_canonical_sync_failure_is_not_trusted(self) -> None:
        """A trusted candidate cannot bypass an unsuccessful canonical result."""
        output, calls = evaluate(
            {"body": "<!-- factory-privacy-material -->", "user": {"type": "Bot"}},
            True, sync_result=False,
        )
        self.assertEqual(output, "canonical=false")
        self.assertEqual(calls, ["fetch", "strict-trust", "sync"])

    def test_untrusted_and_invalid_gates_remove_the_label(self) -> None:
        """Test both the workflow predicate and real shell with mocked gh."""
        cases = (("false", "false", "skipped", ""), ("true", "", "success", "invalid-gate"))
        for case in cases:
            with self.subTest(case=case):
                self.assertTrue(cleanup_condition(*case))
                code, operations = execute_cleanup()
                self.assertEqual(code, 0)
                self.assertEqual(len(operations), 1)
                self.assertIn("decision%3A%20owner", operations[0])

    def test_canonical_gates_retain_owner_label(self) -> None:
        """A canonical bot or valid owner gate skips the deletion step."""
        self.assertFalse(cleanup_condition("false", "true", "skipped", ""))
        self.assertFalse(cleanup_condition("true", "", "success", "gate"))
        self.assertFalse(cleanup_condition("true", "", "failure", "invalid-gate"))

    def test_denied_label_removal_is_a_visible_failure(self) -> None:
        """The shell must exit nonzero if GitHub refuses label deletion."""
        status, operations = execute_cleanup(delete_denied=True)
        self.assertNotEqual(status, 0)
        self.assertEqual(len(operations), 1)

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
