#!/usr/bin/env python3
"""CI execution contract for BRVTAL coverage 21 (#1333), synthetic only."""
from __future__ import annotations

import os
import subprocess
import tempfile
import textwrap
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github/workflows/update-release-metadata.yml"
STEP = "      - name: Verify BRVTAL 21 executable coverage"
CI_TEST = "tests/test_ci_cobertura_brvtal_21.py"
COVERAGE = "tests/test_cobertura_brvtal_21.py"


def coverage_command() -> str:
    """Extract the real GitHub workflow block; fail closed if it drifts."""
    data = WORKFLOW.read_text(encoding="utf-8")
    if data.count(STEP) != 1:
        raise AssertionError("UNKNOWN: missing or duplicate CI execution step")
    section = data.split(STEP, 1)[1].split("\n      - name:", 1)[0]
    marker = "        run: |\n"
    if section.count(marker) != 1:
        raise AssertionError("UNKNOWN: CI block is not an explicit shell script")
    block = textwrap.dedent(section.split(marker, 1)[1]).strip() + "\n"
    if not block.startswith("set -euo pipefail\n"):
        raise AssertionError("UNKNOWN: shell block does not fail closed")
    return block


def synthetic_run(*, candidate: str | None, bridge_fails: bool = False):
    """Run the actual shell block with only disposable unittest fixtures."""
    with tempfile.TemporaryDirectory(prefix="brvtal-1333-ci-") as home:
        folder = Path(home) / "tests"
        folder.mkdir()
        bridge = (
            "import unittest\n"
            "class Bridge(unittest.TestCase):\n"
            "    def test_contract(self):\n"
            + ("        self.fail('synthetic bridge fail')\n" if bridge_fails
               else "        self.assertTrue(True)\n")
        )
        (folder / Path(CI_TEST).name).write_text(bridge, encoding="utf-8")
        if candidate is not None:
            (folder / Path(COVERAGE).name).write_text(
                "import unittest\n"
                "class Coverage(unittest.TestCase):\n"
                "    def test_contract(self):\n"
                + (f"        self.fail({candidate!r})\n" if candidate
                   else "        self.assertEqual(2 + 2, 4)\n"),
                encoding="utf-8",
            )
        env = {
            "PATH": os.environ.get("PATH", "/usr/bin:/bin"),
            "PYTHONPATH": "",
            "PYTHONDONTWRITEBYTECODE": "1",
        }
        return subprocess.run(
            ["bash", "-c", coverage_command()],
            cwd=home, env=env, capture_output=True,
            text=True, timeout=20, check=False,
        )


class CiCoberturaBrvtal21Tests(unittest.TestCase):
    def test_descubrimiento(self) -> None:
        script = coverage_command()
        self.assertIn("python3 -m unittest -v " + CI_TEST, script)
        self.assertIn("if [[ -f " + COVERAGE + " ]]; then", script)
        self.assertIn("python3 -m unittest -v " + COVERAGE, script)
        valid = synthetic_run(candidate="")
        self.assertEqual(valid.returncode, 0, valid.stderr)
        self.assertIn("Ran 1 test", valid.stderr)

    def test_fallo_cerrado(self) -> None:
        missing = synthetic_run(candidate=None)
        self.assertEqual(missing.returncode, 0, missing.stderr)
        self.assertIn("NO_VERIFICABLE", missing.stdout)
        self.assertNotIn("Cobertura 21 verificada", missing.stdout)
        failing = synthetic_run(candidate="synthetic failure")
        self.assertNotEqual(failing.returncode, 0)
        self.assertIn("FAIL", failing.stderr)
        bad_bridge = synthetic_run(candidate="", bridge_fails=True)
        self.assertNotEqual(bad_bridge.returncode, 0)

    def test_sin_datos_reales(self) -> None:
        self.assertEqual(synthetic_run(candidate="").returncode, 0)
        own = Path(__file__).read_text(encoding="utf-8")
        self.assertNotIn("-----BEGIN " + "PRIVATE KEY-----", own)
        self.assertNotIn("https://" + "api.github.com/", own)
        self.assertFalse(any(name in own for name in (
            "mysql_" + "connect(", "requests." + "post(",
            "urllib.request." + "urlopen("
        )))

    def test_gate_validar(self) -> None:
        content = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn("name: BRVTAL CI", content)
        self.assertIn("  pull_request:", content)
        self.assertIn("  fast:", content)
        self.assertIn("  validate:", content)
        self.assertIn("name: validate", content)
        self.assertIn("needs: [preflight, coordination, coordination-pr, fast", content)
        self.assertTrue(coverage_command().startswith("set -euo pipefail"))


if __name__ == "__main__":
    unittest.main()
