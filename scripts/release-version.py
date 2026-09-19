#!/usr/bin/env python3
"""Validate BRVTAL's explicit pre-1.0 product-version contract."""

from __future__ import annotations

import argparse
import re
import subprocess
import sys
from dataclasses import dataclass

VERSION_RE = re.compile(r"const\s+BRVTAL_APP_VERSION\s*=\s*['\"](\d+)\.(\d+)\.(\d+)['\"]\s*;")
DATE_RE = re.compile(r"const\s+BRVTAL_RELEASE_DATE\s*=\s*['\"](\d{4}-\d{2}-\d{2})['\"]\s*;")


@dataclass(frozen=True, order=True)
class Version:
    major: int
    minor: int
    patch: int

    def __str__(self) -> str:
        return f"{self.major}.{self.minor}.{self.patch}"


def parse_release(text: str, label: str) -> Version:
    match = VERSION_RE.search(text)
    if not match:
        raise SystemExit(f"{label}: BRVTAL_APP_VERSION must be semantic X.Y.Z")
    if not DATE_RE.search(text):
        raise SystemExit(f"{label}: BRVTAL_RELEASE_DATE must use YYYY-MM-DD")
    version = Version(*(int(part) for part in match.groups()))
    if version.major >= 1:
        raise SystemExit(
            f"{label}: {version} crosses the 1.0 boundary; BRVTAL 1.0 requires an explicit administrator milestone and contract update"
        )
    return version


def git_show(ref: str) -> str:
    result = subprocess.run(
        ["git", "show", f"{ref}:config/version.php"],
        check=False,
        text=True,
        capture_output=True,
    )
    if result.returncode != 0:
        raise SystemExit(f"Cannot read config/version.php at {ref}: {result.stderr.strip()}")
    return result.stdout


def validate_transition(base_ref: str, head_ref: str) -> None:
    before = parse_release(git_show(base_ref), f"base {base_ref[:12]}")
    after = parse_release(git_show(head_ref), f"head {head_ref[:12]}")

    patch_bump = (
        after.major == before.major
        and after.minor == before.minor
        and after.patch == before.patch + 1
    )
    minor_bump = (
        before.major == 0
        and after.major == 0
        and after.minor == before.minor + 1
        and after.patch == 0
    )
    if not (patch_bump or minor_bump):
        raise SystemExit(
            f"Invalid BRVTAL product-version transition {before} -> {after}. "
            "Every deploy-bound PR increments patch by exactly one; a deliberate pre-1.0 minor milestone increments minor by one and resets patch to zero."
        )
    print(f"BRVTAL product version {before} -> {after} ({'patch' if patch_bump else 'minor milestone'})")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--base")
    parser.add_argument("--head")
    parser.add_argument("--check-current", action="store_true")
    args = parser.parse_args()

    if args.check_current:
        current = parse_release(open("config/version.php", encoding="utf-8").read(), "working tree")
        print(f"BRVTAL product version {current} is valid pre-1.0 metadata")
        return 0

    if not args.base or not args.head:
        parser.error("--base and --head are required unless --check-current is used")
    validate_transition(args.base, args.head)
    return 0


if __name__ == "__main__":
    sys.exit(main())
