#!/usr/bin/env python3
"""Contracts for the remote cursor Bulk Actions modal."""
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
UI = ROOT / "discadmin" / "bulk-actions.js"


class BulkActionsCursorUiTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.source = UI.read_text(encoding="utf-8")

    def assert_has(self, *snippets: str) -> None:
        for snippet in snippets:
            self.assertIn(snippet, self.source)

    def test_ui_uses_server_query_cursor_and_never_downloads_full_module_catalog(self) -> None:
        syntax = subprocess.run(
            ["node", "--check", str(UI)], cwd=ROOT, text=True,
            capture_output=True, timeout=30, check=False,
        )
        self.assertEqual(syntax.returncode, 0, syntax.stderr)
        self.assert_has(
            "const CATALOG_ENDPOINT = '/api/bulk-catalog.php';",
            "new URLSearchParams({resource:module,q:query,limit:String(PAGE_SIZE)})",
            "if (cursor) params.set('cursor', cursor);",
            "SEARCH_DEBOUNCE_MS = 250",
            "state.loadController?.abort()",
            "state.searchTimer = setTimeout(() => loadPage('', 0), SEARCH_DEBOUNCE_MS)",
        )
        for legacy in ("/api/index.php/events", "/api/index.php/artists", "/api/releases.php", "/api/blog.php"):
            self.assertNotIn(legacy, self.source)
        self.assertNotIn("visibleRows()", self.source)

    def test_selection_persists_across_pages_with_global_limit_100_and_visible_page_toggle_only(self) -> None:
        self.assert_has(
            "const MAX_SELECTED = 100;",
            "selected:new Set()",
            "state.cursorStack[index] = state.nextCursor;",
            "void loadPage(state.nextCursor, index);",
            "const ids = recordIds();",
            "ids.forEach(id => state.selected.delete(id));",
            "const available = MAX_SELECTED - state.selected.size;",
            "state.query = String(event.target.value || '').trim().slice(0, 120);",
            "resetNavigation();",
        )
        search_handler = self.source.index("state.query = String(event.target.value")
        next_clear = self.source.find("state.selected.clear()", search_handler, search_handler + 600)
        self.assertEqual(next_clear, -1, "changing query must preserve cross-page selection")

    def test_stale_error_or_incomplete_catalog_response_fails_closed_and_apply_keeps_canonical_csrf_post(self) -> None:
        self.assert_has(
            "throw new Error('INCOMPLETE_CATALOG_RESPONSE')",
            "setUnavailable(timedOut ? 'REQUEST_TIMEOUT'",
            "state.ready = false; state.unavailable = true",
            "button.disabled = !state.ready || !status || count < 1 || count > MAX_SELECTED",
            "const MUTATION_ENDPOINT = '/api/bulk-actions.php';",
            "method:'POST'",
            "'X-CSRF-Token':token",
            "body:JSON.stringify({action:'set_status',resource:module,status,ids})",
            "window.confirm(",
        )


if __name__ == "__main__":
    unittest.main()
