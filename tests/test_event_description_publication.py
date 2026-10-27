"""Executable CI contracts for Event description publication (real PHP, no mocks)."""
from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def execute_contract(filename: str) -> None:
    result = subprocess.run(
        ["php", str(ROOT / "tests" / filename)],
        cwd=ROOT,
        capture_output=True,
        text=True,
        timeout=25,
        check=False,
    )
    if result.returncode:
        raise AssertionError(
            f"{filename} failed with exit {result.returncode}\n"
            f"stdout:\n{result.stdout}\nstderr:\n{result.stderr}"
        )


class EventDescriptionPublicationTests(unittest.TestCase):
    def test_public_workflow_requires_description(self) -> None:
        # Covers atomic workflow (shared final-state validation) and real PHP.
        execute_contract("event-workflow-contract.php")

    def test_draft_and_historical_compatibility(self) -> None:
        # Covers generic Event POST/PUT, bulk selection and status compatibility.
        execute_contract("event-publication-invariant-contract.php")


if __name__ == "__main__":
    unittest.main()
