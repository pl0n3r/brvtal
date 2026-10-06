#!/usr/bin/env python3
"""Contract for the BRVTAL actions/cache v6.1.0 adoption."""

from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
CACHE_SHA = "55cc8345863c7cc4c66a329aec7e433d2d1c52a9"
LEGACY_SHA = "0057852bfaa89a56745cba8c7296529d2fc39830"

TARGETS = {
    ".github/workflows/production-authenticated-smoke.yml": 2,
    ".github/workflows/production-page-write-smoke.yml": 2,
    ".github/workflows/production-performance.yml": 2,
    ".github/workflows/update-release-metadata.yml": 6,
}


class ActionsCacheV6AdoptionTests(unittest.TestCase):
    def source(self, relative: str) -> str:
        return (ROOT / relative).read_text(encoding="utf-8")

    def test_target_workflows_pin_actions_cache_v6_1_0(self) -> None:
        for relative, expected_count in TARGETS.items():
            with self.subTest(workflow=relative):
                source = self.source(relative)
                self.assertEqual(
                    expected_count,
                    source.count(f"uses: actions/cache@{CACHE_SHA} # v6.1.0"),
                )

    def test_cache_upgrade_preserves_existing_cache_contract_shape(self) -> None:
        contracts = {
            ".github/workflows/production-authenticated-smoke.yml": (
                "path: ~/.npm",
                "key: ${{ runner.os }}-production-auth-smoke-npm-${{ hashFiles('package.json') }}",
                "id: chromium-cache",
                "path: ~/.cache/ms-playwright",
                "key: ${{ runner.os }}-production-auth-smoke-chromium-${{ hashFiles('package.json') }}",
                "if: steps.chromium-cache.outputs.cache-hit != 'true'",
            ),
            ".github/workflows/production-page-write-smoke.yml": (
                "path: ~/.npm",
                "key: ${{ runner.os }}-production-page-write-smoke-npm-${{ hashFiles('package.json') }}",
                "id: chromium-cache",
                "path: ~/.cache/ms-playwright",
                "key: ${{ runner.os }}-production-page-write-smoke-chromium-${{ hashFiles('package.json') }}",
                "if: steps.chromium-cache.outputs.cache-hit != 'true'",
            ),
            ".github/workflows/production-performance.yml": (
                "if: steps.connectivity.outputs.reachable == 'true'",
                "path: ~/.npm",
                "key: ${{ runner.os }}-production-performance-npm-${{ hashFiles('package.json') }}",
                "id: chromium-cache",
                "path: ~/.cache/ms-playwright",
                "key: ${{ runner.os }}-production-performance-chromium-${{ hashFiles('package.json') }}",
            ),
            ".github/workflows/update-release-metadata.yml": (
                "key: ${{ runner.os }}-npm-${{ hashFiles('package.json') }}",
                "id: chromium-cache",
                "key: ${{ runner.os }}-playwright-chromium-${{ hashFiles('package.json') }}",
                "id: webkit-cache",
                "key: ${{ runner.os }}-playwright-webkit-${{ hashFiles('package.json') }}",
                "if: steps.webkit-cache.outputs.cache-hit != 'true'",
            ),
        }
        for relative, snippets in contracts.items():
            source = self.source(relative)
            for snippet in snippets:
                with self.subTest(workflow=relative, snippet=snippet):
                    self.assertIn(snippet, source)

    def test_target_workflows_reject_legacy_or_mutable_cache_refs(self) -> None:
        for relative in TARGETS:
            source = self.source(relative)
            self.assertNotIn(f"actions/cache@{LEGACY_SHA}", source)
            cache_lines = [
                line.strip()
                for line in source.splitlines()
                if "uses: actions/cache@" in line
            ]
            self.assertTrue(cache_lines)
            for line in cache_lines:
                ref = line.split("actions/cache@", 1)[1].split()[0]
                self.assertEqual(CACHE_SHA, ref)
                self.assertRegex(ref, re.compile(r"^[0-9a-f]{40}$"))


if __name__ == "__main__":
    unittest.main()
