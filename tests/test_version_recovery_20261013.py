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
    match = VERSION_PATTERN.search(text)
    if match is None:
        raise AssertionError("BRVTAL_APP_VERSION is missing or invalid")
    return tuple(map(int, match.groups()))


def release_fixture(version: str) -> str:
    return (
        f"const BRVTAL_APP_VERSION = '{version}';\n"
        "const BRVTAL_RELEASE_DATE = '2026-10-13';\n"
    )


class VersionRecovery20261013Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.php = PHP_VERSION_FILE.read_text(encoding="utf-8")
        cls.package = json.loads(PACKAGE_FILE.read_text(encoding="utf-8"))
        cls.version = version_from_php(cls.php)

    def test_patch_release_and_package_are_0143(self) -> None:
        # The lower bound survives later legitimate consecutive releases.
        self.assertGreaterEqual(self.version, (0, 1, 143))
        self.assertEqual(
            self.package["version"], ".".join(str(part) for part in self.version)
        )
        self.assertIn("BRVTAL_APP_ENV = 'PRODUCTION'", self.php)
        self.assertTrue(VERSION_SCRIPT.is_file())

    def test_transition_0142_to_0143_passes_and_invalid_repeats_fail(self) -> None:
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
        # Exact diff check applies to the recovery commit; do not pin later releases.
        if self.version != (0, 1, 143):
            return

        def git(*args: str) -> str | None:
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

        previous_php = git("show", "HEAD^:config/version.php")
        previous_package = git("show", "HEAD^:package.json")
        files = git("diff", "--name-only", "HEAD^", "HEAD")
        if previous_php is None or previous_package is None or files is None:
            self.skipTest("Exact parent diff unavailable in this checkout")

        if version_from_php(previous_php) != (0, 1, 142):
            return
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
