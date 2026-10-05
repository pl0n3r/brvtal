#!/usr/bin/env python3
"""Contrato del catálogo read-only paginado para Bulk Actions."""

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ENDPOINT = ROOT / "api" / "bulk-catalog.php"
LIBRARY = ROOT / "api" / "bulk-actions-lib.php"
MUTATION = ROOT / "api" / "bulk-actions.php"


class BulkCatalogApiTests(unittest.TestCase):
    def sources(self) -> tuple[str, str, str]:
        return (
            ENDPOINT.read_text(encoding="utf-8"),
            LIBRARY.read_text(encoding="utf-8"),
            MUTATION.read_text(encoding="utf-8"),
        )

    def test_catalog_get_is_authenticated_read_only_allowlisted_and_bounded(self) -> None:
        endpoint, library, _ = self.sources()
        for path in (ENDPOINT, LIBRARY):
            lint = subprocess.run(
                ["php", "-l", str(path)], cwd=ROOT, text=True,
                capture_output=True, timeout=30, check=False,
            )
            self.assertEqual(lint.returncode, 0, lint.stderr)
        for snippet in (
            "require_once __DIR__ . '/../config/admin_auth.php';",
            "brvtal_admin_require();", "'Allow'=>'GET'",
            "'Cache-Control'=>'no-store'",
        ):
            self.assertIn(snippet, endpoint)
        self.assertNotIn("brvtal_admin_require_csrf", endpoint)
        self.assertNotIn("$_POST", endpoint)
        for resource in ("events", "artists", "sets", "pages", "releases", "blog"):
            self.assertIn(f"'{resource}' =>", library)
        for snippet in (
            "$limit < 1 || $limit > 50",
            "'snapshot_complete' => true",
            "'updated' => 'updated_at'",
            "'where' => 'deleted_at IS NULL'",
        ):
            self.assertIn(snippet, library)
        self.assertNotIn("'media' =>", library)

    def test_search_and_cursor_cover_records_beyond_first_page_without_duplicates(self) -> None:
        _, library, _ = self.sources()
        for snippet in (
            "LOCATE(LOWER(?), LOWER(COALESCE(",
            "WHERE id > ? AND id <= ?",
            "ORDER BY id ASC LIMIT",
            "$fetchLimit = $limit + 1;",
            "'last_id' => $lastReturnedId",
            "'snapshot_max_id' => $snapshotMaxId",
            "'snapshot_total' => $total",
            "'snapshot_updated_at' => $updatedAt",
            "MAX({$updatedColumn}) AS updated_at",
            "'next_cursor' => $nextCursor",
            "STALE_BULK_CURSOR",
        ):
            self.assertIn(snippet, library)
        self.assertLess(
            library.index("function brvtalBulkCatalogCursorDecode"),
            library.index("function brvtalBulkCatalogFetch"),
        )

    def test_invalid_resource_query_cursor_or_limit_fails_closed_without_mutation(self) -> None:
        endpoint, library, mutation = self.sources()
        for error in (
            "INVALID_BULK_PARAMETER", "INVALID_BULK_RESOURCE",
            "INVALID_BULK_QUERY", "INVALID_BULK_LIMIT",
            "INVALID_BULK_CURSOR", "STALE_BULK_CURSOR",
        ):
            self.assertIn(error, library)
        for snippet in (
            "array_keys($input)", "['resource','q','cursor','limit']",
            "mb_strlen($query) > 120", "strlen($cursor) > 1024",
            "$payload['resource'] !== $resource", "$payload['q'] !== $query",
        ):
            self.assertIn(snippet, library)
        self.assertIn("catch (InvalidArgumentException $e)", endpoint)
        self.assertIn("'BULK_CATALOG_UNAVAILABLE'", endpoint)
        for snippet in ("brvtal_admin_require_csrf();", "'POST'", "brvtal_bulk_apply($pdo, $request"):
            self.assertIn(snippet, mutation)
        self.assertNotIn("bulk-catalog.php", mutation)


if __name__ == "__main__":
    unittest.main()
