#!/usr/bin/env python3
"""Fail closed when BRVTAL workflow safety invariants drift."""

from __future__ import annotations

import re
import sys
from pathlib import Path


ROOT = Path(__file__).resolve().parent.parent
WORKFLOWS = ROOT / ".github" / "workflows"
SHA = re.compile(r"^[0-9a-f]{40}$")
USES = re.compile(r"^\s*(?:-\s*)?uses:\s*([^@\s]+)@([^\s#]+)")
FACTORY_V1_WORKFLOW = re.compile(r"^pl0n3r/factory/\.github/workflows/[A-Za-z0-9._-]+\.yml$")
JOB = re.compile(r"^  ([A-Za-z0-9_-]+):\s*$")


def jobs(text: str) -> dict[str, str]:
    result: dict[str, list[str]] = {}
    current: str | None = None
    inside = False
    for line in text.splitlines():
        if line.lstrip().startswith("#"):
            continue
        if not inside:
            inside = line == "jobs:"
            continue
        if line and not line.startswith(" "):
            break
        match = JOB.match(line)
        if match:
            current = match.group(1)
            result[current] = [line]
        elif current is not None:
            result[current].append(line)
    return {name: "\n".join(block) for name, block in result.items()}


def audit_workflow(path: Path) -> list[str]:
    text = path.read_text(encoding="utf-8")
    try:
        label = str(path.relative_to(ROOT))
    except ValueError:
        label = str(path)
    active = "\n".join(line for line in text.splitlines() if not line.lstrip().startswith("#"))
    findings: list[str] = []
    for forbidden in ("pull_request_target:", "permissions: write-all", "continue-on-error: true"):
        if forbidden in active:
            findings.append(f"{label}: forbidden {forbidden}")
    for name, block in jobs(text).items():
        if "runs-on:" in block and "timeout-minutes:" not in block:
            findings.append(f"{label}: job '{name}' has no timeout-minutes")
    lines = text.splitlines()
    for index, line in enumerate(lines):
        match = USES.match(line)
        if not match:
            continue
        action, ref = match.groups()
        trusted_factory_channel = ref == "v1" and FACTORY_V1_WORKFLOW.fullmatch(action) is not None
        if not action.startswith("./") and not SHA.fullmatch(ref) and not trusted_factory_channel:
            findings.append(f"{label}: unpinned action {action}@{ref}")
        if action == "actions/checkout":
            following = "\n".join(lines[index + 1:index + 8])
            if "persist-credentials: false" not in following:
                findings.append(f"{label}: checkout lacks persist-credentials: false")
    return findings


def audit_repository(root: Path = ROOT) -> list[str]:
    workflow_dir = root / ".github" / "workflows"
    paths = sorted(workflow_dir.glob("*.yml"))
    if not paths:
        return ["No workflows found."]
    return [finding for path in paths for finding in audit_workflow(path)]


def main() -> int:
    findings = audit_repository()
    if findings:
        print("CI self-audit failed:", file=sys.stderr)
        print(*[f"- {finding}" for finding in findings], sep="\n", file=sys.stderr)
        return 1
    print("CI self-audit passed: all workflows satisfy the safety baseline.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
