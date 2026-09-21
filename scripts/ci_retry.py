#!/usr/bin/env python3
"""Retry only observable transient failures from external CI dependencies."""

from __future__ import annotations

import argparse
import re
import subprocess
import sys
import time
from collections.abc import Sequence


MAX_ATTEMPTS = 5
MAX_DELAY_SECONDS = 30.0
TRANSIENT_EXIT_CODES = {75}
TRANSIENT_PATTERNS = (
    re.compile(r"\btimed?\s*out\b", re.IGNORECASE),
    re.compile(r"\btimeout\b", re.IGNORECASE),
    re.compile(r"connection reset(?: by peer)?", re.IGNORECASE),
    re.compile(r"\beconnreset\b", re.IGNORECASE),
    re.compile(r"\betimedout\b", re.IGNORECASE),
    re.compile(r"\beconnrefused\b", re.IGNORECASE),
    re.compile(r"\bHTTP(?:/\S+)?\s+(?:429|502|503|504)\b", re.IGNORECASE),
    re.compile(r"\b(?:status|response)(?: code)?[: ]+(?:429|502|503|504)\b", re.IGNORECASE),
    re.compile(r"socket hang up", re.IGNORECASE),
)


def is_transient_failure(returncode: int, output: str) -> bool:
    return returncode in TRANSIENT_EXIT_CODES or any(
        pattern.search(output) is not None for pattern in TRANSIENT_PATTERNS
    )


def validate_options(command: Sequence[str], attempts: int, base_delay: float) -> None:
    if not command:
        raise ValueError("The command cannot be empty.")
    if not 1 <= attempts <= MAX_ATTEMPTS:
        raise ValueError(f"attempts must be between 1 and {MAX_ATTEMPTS}.")
    if not 0 <= base_delay <= MAX_DELAY_SECONDS:
        raise ValueError(f"base-delay must be between 0 and {MAX_DELAY_SECONDS} seconds.")


def execute(command: Sequence[str]) -> tuple[int, str]:
    result = subprocess.run(
        list(command), check=False, stdout=subprocess.PIPE, stderr=subprocess.STDOUT,
        text=True, errors="replace",
    )
    output = result.stdout or ""
    if output:
        print(output, end="" if output.endswith("\n") else "\n")
    return result.returncode, output


def run(command: Sequence[str], *, attempts: int = 3, base_delay: float = 2.0, label: str = "External operation") -> int:
    validate_options(command, attempts, base_delay)
    for attempt in range(1, attempts + 1):
        returncode, output = execute(command)
        if returncode == 0:
            return 0
        if not is_transient_failure(returncode, output):
            print(f"::notice title=CI not retried::{label} failed without a verified transient signal.")
            return returncode
        if attempt == attempts:
            return returncode
        delay = min(MAX_DELAY_SECONDS, base_delay * (2 ** (attempt - 1)))
        print(f"::warning title=CI retry::{label} transient failure ({attempt}/{attempts}); retrying in {delay:g}s.")
        time.sleep(delay)
    return 1


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--attempts", type=int, default=3)
    parser.add_argument("--base-delay", type=float, default=2.0)
    parser.add_argument("--label", default="External operation")
    parser.add_argument("command", nargs=argparse.REMAINDER)
    args = parser.parse_args()
    command = args.command[1:] if args.command[:1] == ["--"] else args.command
    try:
        return run(command, attempts=args.attempts, base_delay=args.base_delay, label=args.label)
    except ValueError as error:
        print(f"ci_retry: {error}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
