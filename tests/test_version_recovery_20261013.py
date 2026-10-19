#!/usr/bin/env python3
"""Regressions for the isolated BRVTAL 0.1.142 -> 0.1.143 recovery."""
from __future__ import annotations

import json
import os
import re
import subprocess
import sys
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PHP_VERSION_FILE = ROOT / "config/version.php"
PACKAGE_FILE = ROOT / "package.json"
VERSION_SCRIPT = ROOT / "scripts/release-version.py"
VERSION_PATTERN = re.compile(r"const\s+BRVTAL_APP_VERSION\s*=\s*['\"](\d+)\.(\d+)\.(\d+)['\"]\s*;")


def version_from_php(text: str) -> tuple[int, int, int]:
    """Parse the explicit three-component product version in PHP metadata."""
    match = VERSION_PATTERN.search(text)
    if match is None:
        raise AssertionError("BRVTAL_APP_VERSION is missing or invalid")
    return tuple(map(int, match.groups()))


def release_fixture(version: str) -> str:
    """Build an isolated release metadata fixture for transition validation."""
    return (
        f"const BRVTAL_APP_VERSION = '{version}';\n"
        "const BRVTAL_RELEASE_DATE = '2026-10-13';\n"
    )


class VersionRecovery20261013Tests(unittest.TestCase):
    """Verify metadata consistency, consecutive release policy and PR scope."""

    @classmethod
    def setUpClass(cls) -> None:
        """Load the candidate version directly from the checked-out branch."""
        cls.php = PHP_VERSION_FILE.read_text(encoding="utf-8")
        cls.package = json.loads(PACKAGE_FILE.read_text(encoding="utf-8"))
        cls.version = version_from_php(cls.php)

    def test_patch_release_and_package_are_0143(self) -> None:
        """Require the patch bump and matching PHP/npm release metadata."""
        # The lower bound survives later legitimate consecutive releases.
        self.assertGreaterEqual(self.version, (0, 1, 143))
        self.assertEqual(
            self.package["version"], ".".join(str(part) for part in self.version)
        )
        self.assertIn("BRVTAL_APP_ENV = 'PRODUCTION'", self.php)
        self.assertTrue(VERSION_SCRIPT.is_file())

    def test_transition_0142_to_0143_passes_and_invalid_repeats_fail(self) -> None:
        """Accept only the consecutive patch and reject repeats or skipped bumps."""
        for after, allowed in (
            ("0.1.143", True),
            ("0.1.142", False),
            ("0.1.144", False),
            ("1.0.0", False),
        ):
            with self.subTest(after=after):
                env = {
                    **os.environ,
                    "BRVTAL_BASE_RELEASE": release_fixture("0.1.142"),
                    "BRVTAL_HEAD_RELEASE": release_fixture(after),
                }
                result = subprocess.run(
                    [sys.executable, str(VERSION_SCRIPT), "--compare-env"],
                    cwd=ROOT,
                    env=env,
                    text=True,
                    capture_output=True,
                    check=False,
                )
                self.assertEqual(result.returncode == 0, allowed, result.stderr)
                if allowed:
                    self.assertIn("0.1.142 -> 0.1.143", result.stdout)

        current = subprocess.run(
            [sys.executable, str(VERSION_SCRIPT), "--check-current"],
            cwd=ROOT,
            text=True,
            capture_output=True,
            check=False,
        )
        self.assertEqual(current.returncode, 0, current.stderr)

    def test_scope_is_version_metadata_only(self) -> None:
        """Compare the complete PR with its authentic merge base and file claims."""
        # Exact diff check applies to the recovery commit; do not pin later releases.
        if self.version != (0, 1, 143):
            self.skipTest("Diff assertion applies only to recovery version 0.1.143")

        def git(*args: str) -> str | None:
            """Read Git evidence without changing the worktree."""
            try:
                command = subprocess.run(
                    ["git", *args],
                    cwd=ROOT,
                    text=True,
                    capture_output=True,
                    check=False,
                )
            except OSError:
                return None
            return command.stdout if command.returncode == 0 else None

        # Compare the full PR to its authentic base, not only the last commit.
        base_sha = git("merge-base", "HEAD", "origin/main")
        head_sha = git("rev-parse", "HEAD")
        self.assertIsNotNone(base_sha, "Git base needed to verify exact PR scope")
        self.assertIsNotNone(head_sha, "Git HEAD needed to verify exact PR scope")
        base_sha = base_sha.strip()
        head_sha = head_sha.strip()
        if base_sha == head_sha:
            self.skipTest("No proposed changes to validate on main")

        previous_php = git("show", f"{base_sha}:config/version.php")
        previous_package = git("show", f"{base_sha}:package.json")
        files = git("diff", "--name-only", base_sha, "HEAD")
        self.assertIsNotNone(previous_php, "Cannot read base product version")
        self.assertIsNotNone(previous_package, "Cannot read base npm version")
        self.assertIsNotNone(files, "Cannot inspect PR changed files")
        self.assertEqual(version_from_php(previous_php), (0, 1, 142))
        self.assertEqual(
            set(files.splitlines()),
            {
                "config/version.php",
                "package.json",
                "tests/test_version_recovery_20261013.py",
            },
        )
        self.assertEqual(
            self.php,
            previous_php.replace(
                "BRVTAL_APP_VERSION = '0.1.142'",
                "BRVTAL_APP_VERSION = '0.1.143'",
                1,
            ),
        )
        old_package = json.loads(previous_package)
        old_package["version"] = "0.1.143"
        self.assertEqual(self.package, old_package)


if __name__ == "__main__":
    unittest.main()
