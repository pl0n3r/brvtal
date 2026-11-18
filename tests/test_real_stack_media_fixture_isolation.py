#!/usr/bin/env python3
"""Prevent shared media hashes from making the parallel real-stack suite flaky."""

from __future__ import annotations

import base64
import hashlib
import json
import re
import unittest
from pathlib import Path


class RealStackMediaFixtureIsolationTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.root = Path(__file__).resolve().parents[1]
        cls.content_core = (
            cls.root / "tests/e2e/content-core-real-stack.spec.mjs"
        ).read_text(encoding="utf-8")
        cls.memory_relations = (
            cls.root / "tests/e2e/memory-relations-real-stack.spec.mjs"
        ).read_text(encoding="utf-8")
        cls.media_api = (
            cls.root / "api/media-library.php"
        ).read_text(encoding="utf-8")

    @staticmethod
    def _fixture_bytes(source: str) -> bytes:
        match = re.search(
            r"const\s+png\s*=\s*Buffer\.from\(\s*['\"]([^'\"]+)['\"]\s*,\s*['\"]base64['\"]",
            source,
        )
        if match is None:
            raise AssertionError("PNG fixture base64 not found")
        return base64.b64decode(match.group(1), validate=True)

    def test_parallel_media_upload_fixtures_have_distinct_png_hashes(self) -> None:
        content_core_png = self._fixture_bytes(self.content_core)
        memory_png = self._fixture_bytes(self.memory_relations)

        self.assertTrue(content_core_png.startswith(b"\x89PNG\r\n\x1a\n"))
        self.assertTrue(memory_png.startswith(b"\x89PNG\r\n\x1a\n"))
        self.assertIn(b"IHDR", content_core_png)
        self.assertIn(b"IEND", content_core_png)
        self.assertIn(b"IHDR", memory_png)
        self.assertIn(b"IEND", memory_png)

        self.assertNotEqual(
            hashlib.sha256(content_core_png).hexdigest(),
            hashlib.sha256(memory_png).hexdigest(),
            "parallel workers must not upload identical media bytes",
        )

    def test_fixture_files_still_expect_fresh_upload_201_and_dedupe_runtime_stays_unchanged(
        self,
    ) -> None:
        for source in (self.content_core, self.memory_relations):
            self.assertIn("media-library.php?action=upload", source)
            self.assertIn("expect(upload.status()).toBe(201);", source)

        self.assertIn("function brvtalMediaReuseUpload", self.media_api)
        self.assertIn("'duplicate' => true", self.media_api)
        self.assertIn("'reused' => true", self.media_api)
        self.assertIn("brvtalMediaReuseUpload(", self.media_api)

    def test_release_identity_is_0_1_117_and_package_matches(self) -> None:
        release = (self.root / "config/version.php").read_text(encoding="utf-8")
        match = re.search(
            r"const\s+BRVTAL_APP_VERSION\s*=\s*['\"]([^'\"]+)['\"]",
            release,
        )
        self.assertIsNotNone(match)
        self.assertEqual(match.group(1), "0.1.117")

        package = json.loads(
            (self.root / "package.json").read_text(encoding="utf-8")
        )
        self.assertEqual(package["version"], "0.1.117")


if __name__ == "__main__":
    unittest.main()
