#!/usr/bin/env python3
"""Contrato del catálogo read-only paginado para Bulk Actions."""
import os
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ENDPOINT = ROOT / "api/bulk-catalog.php"
LIBRARY = ROOT / "api/bulk-actions-lib.php"
MUTATION = ROOT / "api/bulk-actions.php"
class BulkCatalogApiTests(unittest.TestCase):
    def sources(self) -> tuple[str, str, str]:
        return tuple(path.read_text(encoding="utf-8") for path in (ENDPOINT, LIBRARY, MUTATION))
    def assert_has(self, source: str, *snippets: str) -> None:
        for snippet in snippets:
            self.assertIn(snippet, source)
    def test_catalog_get_is_authenticated_read_only_allowlisted_and_bounded(self) -> None:
        endpoint, library, _ = self.sources()
        for path in (ENDPOINT, LIBRARY):
            lint = subprocess.run(
                ["php", "-l", str(path)], cwd=ROOT, text=True,
                capture_output=True, timeout=30, check=False,
            )
            self.assertEqual(lint.returncode, 0, lint.stderr)
        self.assert_has(endpoint, "brvtal_admin_require();", "'Allow'=>'GET'", "'Cache-Control'=>'no-store'")
        self.assertNotIn("brvtal_admin_require_csrf", endpoint)
        self.assertNotIn("$_POST", endpoint)
        for resource in ("events", "artists", "sets", "pages", "releases", "blog"):
            self.assertIn(f"'{resource}' =>", library)
        self.assert_has(
            library, "$limit < 1 || $limit > 50", "'snapshot_complete' => true",
            "'updated' => 'updated_at'", "'where' => 'deleted_at IS NULL'",
        )
        self.assertNotIn("'media' =>", library)
    def test_search_and_cursor_cover_records_beyond_first_page_without_duplicates(self) -> None:
        _, library, _ = self.sources()
        self.assert_has(
            library, "LOCATE(LOWER(?), LOWER(COALESCE(", "WHERE id > ? AND id <= ?",
            "ORDER BY id ASC LIMIT", "$fetchLimit = $limit + 1;",
            "'snapshot_total' => $total", "'snapshot_updated_at' => $updatedAt",
            "MAX({$updatedColumn}) AS updated_at", "'next_cursor' => $nextCursor", "STALE_BULK_CURSOR",
        )
        self.assertLess(
            library.index("function brvtalBulkCatalogCursorDecode"),
            library.index("function brvtalBulkCatalogFetch"),
        )
        contract = subprocess.run(
            ["php", "tests/bulk-actions-contract.php"], cwd=ROOT, text=True,
            capture_output=True, timeout=60, check=False,
        )
        self.assertEqual(contract.returncode, 0, contract.stderr)
        if os.environ.get("BRVTAL_INTEGRATION_TESTS") == "1":
            self.assertIn("BRVTAL Bulk Catalog dynamic pagination passed.", contract.stdout)

    def test_invalid_resource_query_cursor_or_limit_fails_closed_without_mutation(self) -> None:
        endpoint, library, mutation = self.sources()
        for error in (
            "INVALID_BULK_PARAMETER", "INVALID_BULK_RESOURCE", "INVALID_BULK_QUERY",
            "INVALID_BULK_LIMIT", "INVALID_BULK_CURSOR", "STALE_BULK_CURSOR",
        ):
            self.assertIn(error, library)
        self.assert_has(
            library, "array_keys($input)", "['resource','q','cursor','limit']",
            "mb_strlen($query) > 120", "strlen($cursor) > 1024",
        )
        self.assert_has(mutation, "brvtal_admin_require_csrf();", "'POST'", "brvtal_bulk_apply($pdo, $request")
        self.assertIn("catch (InvalidArgumentException $e)", endpoint)
        self.assertNotIn("bulk-catalog.php", mutation)


if __name__ == "__main__":
    unittest.main()
