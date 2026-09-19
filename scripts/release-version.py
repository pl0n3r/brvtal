#!/usr/bin/env python3
"""Validate BRVTAL's explicit pre-1.0 product-version contract."""

from __future__ import annotations

import argparse
import os
import re
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
            f"{label}: {version} crosses the 1.0 boundary; "
            "BRVTAL 1.0 requires an explicit administrator milestone and contract update"
        )
    return version


def validate_transition(before: Version, after: Version) -> None:
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
            "Every deploy-bound PR increments patch by exactly one; a deliberate "
            "pre-1.0 minor milestone increments minor by one and resets patch to zero."
        )
    kind = "patch" if patch_bump else "minor milestone"
    print(f"BRVTAL product version {before} -> {after} ({kind})")


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--compare-env", action="store_true")
    parser.add_argument("--check-current", action="store_true")
    args = parser.parse_args()

    if args.check_current:
        with open("config/version.php", encoding="utf-8") as release_file:
            current = parse_release(release_file.read(), "working tree")
        print(f"BRVTAL product version {current} is valid pre-1.0 metadata")
        return

    if not args.compare_env:
        parser.error("--compare-env or --check-current is required")

    base_text = os.environ.get("BRVTAL_BASE_RELEASE", "")
    head_text = os.environ.get("BRVTAL_HEAD_RELEASE", "")
    if not base_text or not head_text:
        raise SystemExit("BRVTAL_BASE_RELEASE and BRVTAL_HEAD_RELEASE are required")

    before = parse_release(base_text, "base release")
    after = parse_release(head_text, "head release")
    validate_transition(before, after)


if __name__ == "__main__":
    main()
