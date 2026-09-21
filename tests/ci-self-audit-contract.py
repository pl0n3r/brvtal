#!/usr/bin/env python3
"""Contract coverage for the CI safety auditor."""

from __future__ import annotations

import tempfile
import unittest
from pathlib import Path
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))
from scripts.ci_self_audit import audit_workflow


class CiSelfAuditTests(unittest.TestCase):
    def test_detects_unpinned_checkout_and_missing_timeout(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "bad.yml"
            path.write_text("""name: bad\non: push\njobs:\n  test:\n    runs-on: ubuntu-latest\n    steps:\n      - uses: actions/checkout@v5\n""", encoding="utf-8")
            findings = audit_workflow(path)
        self.assertTrue(any("unpinned" in finding for finding in findings))
        self.assertTrue(any("timeout" in finding for finding in findings))
        self.assertTrue(any("persist-credentials" in finding for finding in findings))

    def test_rejects_error_suppression(self) -> None:
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / "bad.yml"
            path.write_text("continue-on-error: true\n", encoding="utf-8")
            self.assertTrue(any("continue-on-error" in item for item in audit_workflow(path)))


if __name__ == "__main__":
    unittest.main()
