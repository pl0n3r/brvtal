#!/usr/bin/env python3
"""Executable contracts for Bulk Catalog Cursor V2 real-stack closure."""
import json
import re
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
E2E = ROOT / "tests" / "e2e" / "discadmin-bulk-actions-cursor.spec.mjs"
RUNNER = ROOT / "tests" / "e2e" / "run-content-core-real-stack.sh"
UI = ROOT / "discadmin" / "bulk-actions.js"
LIB = ROOT / "api" / "bulk-actions-lib.php"
SPEC = ROOT / "docs" / "BRVTAL-SPEC.md"
VERSION = ROOT / "config" / "version.php"
PACKAGE = ROOT / "package.json"


class BulkCatalogCursorE2ETests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.e2e = E2E.read_text(encoding="utf-8")
        cls.runner = RUNNER.read_text(encoding="utf-8")
        cls.ui = UI.read_text(encoding="utf-8")
        cls.lib = LIB.read_text(encoding="utf-8")
        cls.spec = SPEC.read_text(encoding="utf-8")
        cls.version = VERSION.read_text(encoding="utf-8")
        cls.package = json.loads(PACKAGE.read_text(encoding="utf-8"))

    def test_catalog_over_500_remains_searchable_and_page_complete_without_duplicates(self) -> None:
        syntax = subprocess.run(
            ["node", "--check", str(E2E)], cwd=ROOT, text=True,
            capture_output=True, timeout=30, check=False,
        )
        self.assertEqual(syntax.returncode, 0, syntax.stderr)
        shell = subprocess.run(
            ["bash", "-n", str(RUNNER)], cwd=ROOT, text=True,
            capture_output=True, timeout=30, check=False,
        )
        self.assertEqual(shell.returncode, 0, shell.stderr)
        for snippet in (
            "seq 1 605",
            "BULK CURSOR FIXTURE",
            "/api/bulk-catalog.php",
            "expect(ids).toHaveLength(605)",
            "expect(new Set(ids).size).toBe(605)",
            "expect(pageCount).toBe(13)",
            "BULK CURSOR FIXTURE 0601",
            "SNAPSHOT COMPLETE",
        ):
            self.assertIn(snippet, self.e2e + self.runner)

    def test_cross_page_selection_mutates_only_selected_ids_through_canonical_csrf_post(self) -> None:
        for snippet in (
            "100 SELECTED",
            "const selectedIds = [...pageOneIds, ...pageTwoIds]",
            "expect(new Set(selectedIds).size).toBe(100)",
            "/api/bulk-actions.php",
            "mutation.headers()['x-csrf-token']).toBe(auth.csrf)",
            "postDataJSON()",
            "toEqual(selectedIds)",
            "expect(archivedIds.sort",
            "expect(draftIds).toHaveLength(505)",
            "expect(draftIds).toContain(untouchedId)",
            "status:'archived'",
        ):
            self.assertIn(snippet, self.e2e)
        for snippet in ("FOR UPDATE", "beginTransaction()", "rollBack()"):
            self.assertIn(snippet, self.lib)
        self.assertNotIn("DELETE FROM", self.lib)

    def test_keyboard_mobile_and_incomplete_response_regressions_remain_fail_closed(self) -> None:
        for snippet in (
            "setViewportSize({width:390,height:844})",
            "toBeFocused()",
            "aria-live",
            "keyboard.press('Escape')",
            "total:500",
            "has_more:false",
            "snapshot_complete:true",
            "BULK ACTIONS UNAVAILABLE",
            "toBeDisabled()",
        ):
            self.assertIn(snippet, self.e2e)
        for snippet in (
            "INCOMPLETE_CATALOG_RESPONSE",
            "state.ready = false",
            "button.disabled = !state.ready",
        ):
            self.assertIn(snippet, self.ui)

    def test_spec_declares_server_cursor_complete_catalog_contract(self) -> None:
        self.assertIn("Bulk Catalog Cursor V2", self.spec)
        self.assertIn("/api/bulk-catalog.php", self.spec)
        self.assertIn("/api/bulk-actions.php", self.spec)
        self.assertIn("snapshot_complete", self.spec)
        self.assertNotIn("A future server-side cursor may replace the full response", self.spec)
        version_match = re.search(r"BRVTAL_APP_VERSION = '([^']+)'", self.version)
        self.assertIsNotNone(version_match)
        self.assertEqual(self.package.get("version"), version_match.group(1))
        self.assertIn("test:bulk-catalog-cursor-e2e", self.package.get("scripts", {}))
        self.assertIn("discadmin-bulk-actions-cursor.spec.mjs", self.runner)


if __name__ == "__main__":
    unittest.main()
