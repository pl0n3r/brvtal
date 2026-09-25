#!/usr/bin/env python3
"""Compare PHPStan JSON reports and fail only on findings introduced by HEAD."""

from __future__ import annotations

import argparse
from collections import Counter
import json
from pathlib import Path
import sys
from typing import Any, TextIO

Finding = tuple[str, str, str]


def read_payload(stream: TextIO) -> tuple[dict[str, Any], dict[str, Any]]:
    """Read base/head PHPStan reports from stdin and validate the envelope."""
    try:
        payload = json.load(stream)
    except json.JSONDecodeError as exc:
        raise ValueError(f"Invalid PHPStan JSON payload: {exc}") from exc
    if not isinstance(payload, dict):
        raise ValueError("PHPStan payload root must be an object")
    base = payload.get("base")
    head = payload.get("head")
    if not isinstance(base, dict) or not isinstance(head, dict):
        raise ValueError("PHPStan payload must contain object fields base and head")
    return base, head


def normalize_path(raw_path: str, root: Path) -> str:
    """Normalize report paths so base and HEAD worktrees compare deterministically."""
    path = Path(raw_path)
    if not path.is_absolute():
        return path.as_posix()
    try:
        return path.resolve().relative_to(root.resolve()).as_posix()
    except ValueError:
        return path.as_posix()


def global_findings(report: dict[str, Any]) -> Counter[Finding]:
    """Return global PHPStan errors as a multiset."""
    errors = report.get("errors", [])
    if not isinstance(errors, list):
        raise ValueError("PHPStan report errors must be a list")
    return Counter({("<global>", "", str(item)): 1 for item in errors})


def message_finding(relative: str, raw_path: str, message: Any) -> Finding:
    """Convert one PHPStan message object into a stable finding key."""
    if not isinstance(message, dict):
        raise ValueError(f"PHPStan report contains an invalid message for {raw_path}")
    text = message.get("message")
    if not isinstance(text, str) or not text:
        raise ValueError(f"PHPStan report contains an empty message for {raw_path}")
    identifier = message.get("identifier", "")
    if not isinstance(identifier, str):
        identifier = str(identifier)
    return relative, identifier, text


def file_findings(report: dict[str, Any], root: Path) -> Counter[Finding]:
    """Return per-file PHPStan findings as a multiset."""
    files = report.get("files", {})
    if not isinstance(files, dict):
        raise ValueError("PHPStan report files must be an object")
    result: Counter[Finding] = Counter()
    for raw_path, details in files.items():
        if not isinstance(raw_path, str) or not isinstance(details, dict):
            raise ValueError("PHPStan report contains an invalid file entry")
        messages = details.get("messages", [])
        if not isinstance(messages, list):
            raise ValueError(f"PHPStan report messages must be a list for {raw_path}")
        relative = normalize_path(raw_path, root)
        result.update(message_finding(relative, raw_path, message) for message in messages)
    return result


def findings(report: dict[str, Any], root: Path) -> Counter[Finding]:
    """Return all PHPStan findings in normalized multiset form."""
    return global_findings(report) + file_findings(report, root)


def introduced(
    base_report: dict[str, Any],
    head_report: dict[str, Any],
    base_root: Path,
    head_root: Path,
) -> Counter[Finding]:
    """Return only findings whose multiplicity increases in HEAD."""
    return findings(head_report, head_root) - findings(base_report, base_root)


def main() -> int:
    """Run the stdin comparison and return a CI-friendly exit code."""
    parser = argparse.ArgumentParser()
    parser.add_argument("--base-root", type=Path, required=True)
    parser.add_argument("--head-root", type=Path, required=True)
    args = parser.parse_args()

    try:
        base_report, head_report = read_payload(sys.stdin)
        new_findings = introduced(base_report, head_report, args.base_root, args.head_root)
    except ValueError as exc:
        print(f"PHPStan incremental comparison failed closed: {exc}", file=sys.stderr)
        return 2

    if not new_findings:
        print("PHPStan incremental gate: no findings introduced by HEAD.")
        return 0

    print("PHPStan incremental gate: new findings detected:", file=sys.stderr)
    for (path, identifier, message), count in sorted(new_findings.items()):
        rule = f" [{identifier}]" if identifier else ""
        suffix = f" x{count}" if count > 1 else ""
        print(f"- {path}{rule}: {message}{suffix}", file=sys.stderr)
    return 1


if __name__ == "__main__":
    raise SystemExit(main())
