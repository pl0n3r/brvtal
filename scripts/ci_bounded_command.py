#!/usr/bin/env python3
"""Run one external command with a hard wall-clock bound.

Timeout is the only condition translated to EX_TEMPFAIL (75). All ordinary
child exits are preserved so callers can fail closed without conflating APT
or product errors with infrastructure slowness.
"""

from __future__ import annotations

import argparse
import os
import signal
import subprocess
import sys
from collections.abc import Sequence


TIMEOUT_EXIT_CODE = 75


def run_bounded(
    command: Sequence[str],
    *,
    timeout_seconds: float,
    kill_grace_seconds: float,
) -> int:
    if not command:
        raise ValueError("The command cannot be empty.")
    if timeout_seconds <= 0:
        raise ValueError("timeout-seconds must be greater than zero.")
    if kill_grace_seconds <= 0:
        raise ValueError("kill-grace-seconds must be greater than zero.")

    process = subprocess.Popen(
        list(command),
        stdout=subprocess.PIPE,
        stderr=subprocess.STDOUT,
        text=True,
        errors="replace",
        start_new_session=True,
    )
    output = ""
    try:
        output, _ = process.communicate(timeout=timeout_seconds)
    except subprocess.TimeoutExpired as error:
        partial = error.output or ""
        if isinstance(partial, bytes):
            partial = partial.decode("utf-8", errors="replace")
        output = partial
        try:
            os.killpg(process.pid, signal.SIGTERM)
        except ProcessLookupError:
            pass
        try:
            tail, _ = process.communicate(timeout=kill_grace_seconds)
            output += tail or ""
        except subprocess.TimeoutExpired as grace_error:
            grace_partial = grace_error.output or ""
            if isinstance(grace_partial, bytes):
                grace_partial = grace_partial.decode("utf-8", errors="replace")
            output += grace_partial
            try:
                os.killpg(process.pid, signal.SIGKILL)
            except ProcessLookupError:
                pass
            tail, _ = process.communicate()
            output += tail or ""
        if output:
            print(output, end="" if output.endswith("\n") else "\n")
        print(
            f"bounded-command: deadline exceeded after {timeout_seconds:g}s; "
            f"process group terminated within {kill_grace_seconds:g}s grace."
        )
        return TIMEOUT_EXIT_CODE

    if output:
        print(output, end="" if output.endswith("\n") else "\n")
    return int(process.returncode or 0)


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--timeout-seconds", type=float, required=True)
    parser.add_argument("--kill-grace-seconds", type=float, default=10.0)
    parser.add_argument("command", nargs=argparse.REMAINDER)
    args = parser.parse_args()
    command = args.command[1:] if args.command[:1] == ["--"] else args.command
    try:
        return run_bounded(
            command,
            timeout_seconds=args.timeout_seconds,
            kill_grace_seconds=args.kill_grace_seconds,
        )
    except ValueError as error:
        print(f"ci_bounded_command: {error}", file=sys.stderr)
        return 2


if __name__ == "__main__":
    raise SystemExit(main())
