#!/usr/bin/env python3
"""Validate BRVTAL README dashboard facts against the exact Git diff."""

from __future__ import annotations

import argparse
import re
import subprocess
import sys
from pathlib import Path


def run(command: list[str]) -> str:
    return subprocess.check_output(command, text=True).strip()


def changed_files(base: str, head: str) -> list[str]:
    output = run(["git", "diff", "--name-only", base, head])
    return sorted(line.strip() for line in output.splitlines() if line.strip())


def diff_metrics(base: str, head: str) -> tuple[int, int]:
    output = run(["git", "diff", "--numstat", base, head])
    additions = 0
    deletions = 0
    for line in output.splitlines():
        parts = line.split("\t", 2)
        if len(parts) < 3:
            continue
        added, deleted, _ = parts
        if added.isdigit():
            additions += int(added)
        if deleted.isdigit():
            deletions += int(deleted)
    return additions, deletions


def ci_scope(files: list[str], script_path: Path) -> dict[str, str]:
    payload = "\n".join(files)
    command = [
        "bash",
        "-c",
        'source "$1"; brvtal_ci_classify_files "$2" "$3"; brvtal_ci_scope_print',
        "_",
        str(script_path),
        payload,
        "pull_request",
    ]
    output = run(command)
    values: dict[str, str] = {}
    for line in output.splitlines():
        if "=" not in line:
            continue
        key, value = line.split("=", 1)
        values[key] = value
    return values


def gate_plan(scope: dict[str, str]) -> str:
    languages: list[str] = []
    if scope.get("run_php") == "true":
        languages.append("PHP")
    if scope.get("run_js") == "true":
        languages.append("JS")
    fast = "fast[" + ("+".join(languages) if languages else "docs-only") + "]"
    selected = ["preflight", fast]
    for key, label in (
        ("run_db", "database"),
        ("run_browser", "chromium"),
        ("run_realstack", "real-stack"),
        ("run_webkit", "webkit"),
        ("run_recovery", "recovery"),
    ):
        if scope.get(key) == "true":
            selected.append(label)
    return " · ".join(selected)


def section(readme: str, heading: str) -> str:
    lines = readme.splitlines()
    collecting = False
    selected: list[str] = []
    for line in lines:
        if line.strip() == heading:
            collecting = True
            continue
        if collecting and line.startswith("## "):
            break
        if collecting:
            selected.append(line)
    return "\n".join(selected)


def fail(message: str) -> None:
    print(f"README DASHBOARD CHECK FAILED: {message}", file=sys.stderr)
    raise SystemExit(1)


def validate(base: str, head: str, readme_path: Path, scope_path: Path) -> None:
    files = changed_files(base, head)
    additions, deletions = diff_metrics(base, head)
    net = additions - deletions
    scope = ci_scope(files, scope_path)
    readme = readme_path.read_text(encoding="utf-8")

    required_headings = [
        "# BRVTAL — Último deploy",
        "## Estado del deploy",
        "## Huella del cambio",
        "## Calidad y entrega",
        "## Flujo de entrega",
        "## Qué se hizo",
        "## Archivos modificados en este deploy",
        "## Validación",
        "## Qué sigue",
        "## Panorama general pendiente",
    ]
    for heading in required_headings:
        if heading not in readme:
            fail(f"missing required heading: {heading}")

    if len(readme.encode("utf-8")) >= 8000:
        fail("README must stay below 8 KB")

    visual_markers = [
        "actions/workflows/update-release-metadata.yml/badge.svg",
        "sonarcloud.io/api/project_badges/measure",
        "actions/workflows/production-deploy-observer.yml/badge.svg",
        "mermaid",
        "PR + snapshot exacto",
        "CodeRabbit",
        "CI del SHA exacto de main",
    ]
    for marker in visual_markers:
        if marker not in readme:
            fail(f"missing dashboard visual/status marker: {marker}")

    expected_delta = (
        f"| **{len(files)}** | **+{additions}** | **−{deletions}** | "
        f"**{net:+d}** |"
    )
    delta_section = section(readme, "## Huella del cambio")
    if "<!-- brvtal:git-delta -->" not in delta_section:
        fail("missing brvtal:git-delta marker")
    if expected_delta not in delta_section:
        fail(f"Git delta is stale; expected: {expected_delta}")

    expected_plan = f"**{gate_plan(scope)}**"
    quality_section = section(readme, "## Calidad y entrega")
    if "<!-- brvtal:gate-plan -->" not in quality_section:
        fail("missing brvtal:gate-plan marker")
    if expected_plan not in quality_section:
        fail(f"gate plan is stale; expected: {expected_plan}")

    changed_section = section(readme, "## Archivos modificados en este deploy")
    tick = chr(96)
    listed = sorted(
        re.findall(
            r"^- " + re.escape(tick) + r"([^" + re.escape(tick) + r"]+)" + re.escape(tick) + r"(?:\s+—.*)?$",
            changed_section,
            flags=re.M,
        )
    )
    if files != listed:
        fail(
            "changed-file list is stale; expected "
            + ", ".join(files)
            + " but README lists "
            + ", ".join(listed)
        )

    for lane in ("**NOW**", "**NEXT**", "**LATER**", "**BLOCKED / EXTERNAL**"):
        if lane not in readme:
            fail(f"missing roadmap lane: {lane}")

    if "#517" not in readme:
        fail("product-version status must link to #517 until human-readable versioning lands")

    if "solo el deploy actual" not in readme:
        fail("README must remain explicitly deploy-scoped")

    print(
        "README dashboard matches exact diff: "
        f"{len(files)} files, +{additions}/-{deletions}, net {net:+d}; "
        f"gates={gate_plan(scope)}"
    )


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--check", action="store_true")
    parser.add_argument("--base", required=True)
    parser.add_argument("--head", required=True)
    parser.add_argument("--readme", default="README.md")
    parser.add_argument("--scope-script", default="scripts/ci-scope.sh")
    args = parser.parse_args()

    files = changed_files(args.base, args.head)
    additions, deletions = diff_metrics(args.base, args.head)
    scope = ci_scope(files, Path(args.scope_script))

    if args.check:
        validate(args.base, args.head, Path(args.readme), Path(args.scope_script))
        return

    print(f"files={len(files)}")
    print(f"insertions={additions}")
    print(f"deletions={deletions}")
    print(f"net={additions - deletions:+d}")
    print(f"gates={gate_plan(scope)}")


if __name__ == "__main__":
    main()
