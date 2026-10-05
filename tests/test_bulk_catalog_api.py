#!/usr/bin/env python3
"""Contrato del catálogo read-only paginado para Bulk Actions."""

from __future__ import annotations

import subprocess
import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
ENDPOINT = ROOT / "api" / "bulk-catalog.php"
LIBRARY = ROOT / "api" / "bulk-actions-lib.php"
MUTATION = ROOT / "api" / "bulk-actions.php"


class BulkCatalogApiTests(unittest.TestCase):
    def endpoint(self) -> str:
        return ENDPOINT.read_text(encoding="utf-8")

    def library(self) -> str:
        return LIBRARY.read_text(encoding="utf-8")

    def test_catalog_get_is_authenticated_read_only_allowlisted_and_bounded(self) -> None:
        endpoint = self.endpoint()
        library = self.library()

        for path in (ENDPOINT, LIBRARY):
            lint = subprocess.run(
                ["php", "-l", str(path)],
                cwd=ROOT,
                check=False,
                text=True,
                capture_output=True,
                timeout=30,
            )
            self.assertEqual(lint.returncode, 0, lint.stderr)

        self.assertIn("require_once __DIR__ . '/../config/admin_auth.php';", endpoint)
        self.assertIn("brvtal_admin_require();", endpoint)
        self.assertIn("'GET'", endpoint)
        self.assertIn("'Allow'=>'GET'", endpoint)
        self.assertIn("'Cache-Control'=>'no-store'", endpoint)
        self.assertNotIn("brvtal_admin_require_csrf", endpoint)
        self.assertNotIn("$_POST", endpoint)

        for resource in ("events", "artists", "sets", "pages", "releases", "blog"):
            self.assertIn(f"'{resource}' =>", library)
        self.assertNotIn("'media' =>", library)
        self.assertIn("$limit < 1 || $limit > 50", library)
        self.assertIn("'snapshot_complete' => true", library)
        self.assertIn("'total' => $total", library)

        upper_endpoint = endpoint.upper()
        for mutation in ("INSERT ", "UPDATE ", "DELETE ", "REPLACE ", " FOR UPDATE"):
            self.assertNotIn(mutation, upper_endpoint)

    def test_search_and_cursor_cover_records_beyond_first_page_without_duplicates(self) -> None:
        library = self.library()

        self.assertIn("LOCATE(LOWER(?), LOWER(COALESCE(", library)
        self.assertIn("$labelColumn", library)
        self.assertIn("$slugColumn", library)
        self.assertIn("WHERE id > ? AND id <= ?", library)
        self.assertIn("ORDER BY id ASC LIMIT", library)
        self.assertIn("$fetchLimit = $limit + 1;", library)
        self.assertIn("'last_id' => $lastReturnedId", library)
        self.assertIn("'snapshot_max_id' => $snapshotMaxId", library)
        self.assertIn("'has_more' => $hasMore", library)
        self.assertIn("'next_cursor' => $nextCursor", library)

        encode = library.index("function brvtal_bulk_catalog_cursor_encode")
        decode = library.index("function brvtal_bulk_catalog_cursor_decode")
        normalize = library.index("function brvtal_bulk_catalog_normalize_query")
        fetch = library.index("function brvtal_bulk_catalog_fetch")
        self.assertLess(encode, decode)
        self.assertLess(decode, normalize)
        self.assertLess(normalize, fetch)

    def test_invalid_resource_query_cursor_or_limit_fails_closed_without_mutation(self) -> None:
        endpoint = self.endpoint()
        library = self.library()
        mutation = MUTATION.read_text(encoding="utf-8")

        for error in (
            "INVALID_BULK_PARAMETER",
            "INVALID_BULK_RESOURCE",
            "INVALID_BULK_QUERY",
            "INVALID_BULK_LIMIT",
            "INVALID_BULK_CURSOR",
        ):
            self.assertIn(error, library)

        self.assertIn("array_keys($input)", library)
        self.assertIn("['resource','q','cursor','limit']", library)
        self.assertIn("mb_strlen($query) > 120", library)
        self.assertIn("strlen($cursor) > 1024", library)
        self.assertIn("$payload['resource'] !== $resource", library)
        self.assertIn("$payload['q'] !== $query", library)
        self.assertIn("catch (InvalidArgumentException $e)", endpoint)
        self.assertIn("'BULK_CATALOG_UNAVAILABLE'", endpoint)

        self.assertIn("brvtal_admin_require_csrf();", mutation)
        self.assertIn("'POST'", mutation)
        self.assertIn("brvtal_bulk_apply($pdo, $request", mutation)
        self.assertNotIn("bulk-catalog.php", mutation)


if __name__ == "__main__":
    unittest.main()
