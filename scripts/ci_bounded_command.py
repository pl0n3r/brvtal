#!/usr/bin/env python3
"""Run one external command with a hard wall-clock bound.

Timeout is the only condition translated to EX_TEMPFAIL (75). Normal child
failures stay fail-closed, and child exit 75 is remapped so it cannot
accidentally masquerade as this wrapper's verified timeout signal.
"""

from __future__ import annotations

import argparse
import os
import signal
import subprocess
import sys
from collections.abc import Sequence


TIMEOUT_EXIT_CODE = 75
RESERVED_CHILD_EXIT_REMAP = 74


def _text(value: str | bytes | None) -> str:
    if value is None:
        return ""
    if isinstance(value, bytes):
        return value.decode("utf-8", errors="replace")
    return value


def _emit(output: str) -> None:
    if output:
        print(output, end="" if output.endswith("\n") else "\n")


def _signal_group(process: subprocess.Popen[str], sig: signal.Signals) -> None:
    try:
        os.killpg(process.pid, sig)
    except ProcessLookupError:
        pass


def _stop_timed_out_process(
    process: subprocess.Popen[str],
    *,
    grace_seconds: float,
) -> str:
    _signal_group(process, signal.SIGTERM)
    try:
        tail, _ = process.communicate(timeout=grace_seconds)
        return _text(tail)
    except subprocess.TimeoutExpired as error:
        partial = _text(error.output)
    _signal_group(process, signal.SIGKILL)
    tail, _ = process.communicate()
    return partial + _text(tail)


def _normal_exit_code(returncode: int | None) -> int:
    code = int(returncode or 0)
    if code == TIMEOUT_EXIT_CODE:
        print(
            "::notice title=Reserved timeout exit::"
            "Child exit 75 remapped to 74; no retry signal emitted."
        )
        return RESERVED_CHILD_EXIT_REMAP
    return code


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
    try:
        output, _ = process.communicate(timeout=timeout_seconds)
    except subprocess.TimeoutExpired as error:
        output = _text(error.output)
        output += _stop_timed_out_process(
            process,
            grace_seconds=kill_grace_seconds,
        )
        _emit(output)
        print(
            f"bounded-command: deadline exceeded after {timeout_seconds:g}s; "
            f"process group terminated within {kill_grace_seconds:g}s grace."
        )
        return TIMEOUT_EXIT_CODE

    _emit(_text(output))
    return _normal_exit_code(process.returncode)


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
