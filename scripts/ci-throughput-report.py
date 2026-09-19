#!/usr/bin/env python3
"""Build BRVTAL CI throughput evidence from GitHub Actions job/step timestamps."""

from __future__ import annotations

import argparse
import json
import sys
from datetime import datetime
from typing import Any

BROWSER_JOBS = {"chromium", "webkit-totp", "real-stack"}
DEPENDENCY_STEPS = {
    "Cache npm downloads",
    "Install Node test dependencies",
    "Cache Chromium",
    "Cache WebKit",
}
BROWSER_SETUP_STEPS = {
    "Install Chromium system dependencies",
    "Install Chromium browser",
    "Install WebKit system dependencies",
    "Install WebKit browser",
}
INFRASTRUCTURE_STEPS = {
    "Initialize containers",
    "Set up PHP 8.5 with database extension",
}
TEST_STEPS = {
    "Run Chromium browser tests",
    "Run targeted WebKit TOTP regression",
    "Run authenticated PHP/MariaDB browser smoke",
}
PHASES = ("dependency_cache", "browser_system_setup", "infrastructure", "test", "other")


def seconds(start: Any, end: Any) -> int | None:
    if not start or not end:
        return None
    try:
        started = datetime.fromisoformat(str(start).replace("Z", "+00:00"))
        completed = datetime.fromisoformat(str(end).replace("Z", "+00:00"))
    except ValueError:
        return None
    return max(0, round((completed - started).total_seconds()))


def step_phase(name: str) -> str:
    if name in DEPENDENCY_STEPS:
        return "dependency_cache"
    if name in BROWSER_SETUP_STEPS:
        return "browser_system_setup"
    if name in INFRASTRUCTURE_STEPS:
        return "infrastructure"
    if name in TEST_STEPS:
        return "test"
    return "other"


def flatten_jobs(pages: Any) -> list[dict[str, Any]]:
    if not isinstance(pages, list):
        return []
    jobs: list[dict[str, Any]] = []
    for page in pages:
        if not isinstance(page, dict):
            continue
        page_jobs = page.get("jobs")
        if isinstance(page_jobs, list):
            jobs.extend(job for job in page_jobs if isinstance(job, dict))
    return jobs


def timed_step(step: dict[str, Any]) -> dict[str, Any]:
    name = str(step.get("name") or "")
    duration = seconds(step.get("started_at"), step.get("completed_at"))
    return {
        "name": name,
        "conclusion": step.get("conclusion"),
        "started_at": step.get("started_at"),
        "completed_at": step.get("completed_at"),
        "duration_seconds": duration,
        "phase": step_phase(name),
    }


def timed_job(job: dict[str, Any]) -> dict[str, Any]:
    return {
        "name": str(job.get("name") or ""),
        "conclusion": job.get("conclusion"),
        "started_at": job.get("started_at"),
        "completed_at": job.get("completed_at"),
        "duration_seconds": seconds(job.get("started_at"), job.get("completed_at")),
    }


def browser_breakdown(job: dict[str, Any]) -> dict[str, Any] | None:
    name = str(job.get("name") or "")
    if name not in BROWSER_JOBS:
        return None
    job_duration = seconds(job.get("started_at"), job.get("completed_at"))
    steps = [timed_step(step) for step in job.get("steps", []) if isinstance(step, dict)]
    totals = dict.fromkeys(PHASES, 0)
    attributed = 0
    for step in steps:
        duration = step["duration_seconds"]
        if duration is None:
            continue
        totals[step["phase"]] += duration
        attributed += duration
    unattributed = max(0, (job_duration or 0) - attributed) if job_duration is not None else None
    if unattributed is not None:
        totals["other"] += unattributed
    return {
        "name": name,
        "conclusion": job.get("conclusion"),
        "duration_seconds": job_duration,
        "phase_seconds": totals,
        "unattributed_seconds": unattributed,
        "steps": steps,
    }


def build_report(pages: Any, metadata: dict[str, Any]) -> dict[str, Any]:
    raw_jobs = flatten_jobs(pages)
    jobs = [timed_job(job) for job in raw_jobs]
    completed = [job for job in jobs if job["duration_seconds"] is not None]
    eligible = metadata["conclusion"] == "success"
    parallel = [
        job for job in completed
        if job["conclusion"] == "success" and job["name"] not in {"preflight", "validate"}
    ]
    critical_gate = max(parallel, key=lambda item: item["duration_seconds"] or 0, default=None)
    preflight = next((job for job in completed if job["name"] == "preflight"), None)
    validate = next((job for job in completed if job["name"] == "validate"), None)
    critical_jobs = [job for job in (preflight, critical_gate, validate) if job is not None] if eligible else []
    browser = [item for job in raw_jobs if (item := browser_breakdown(job)) is not None]
    return {
        "run_id": int(metadata["run_id"]),
        "source_sha": metadata["source_sha"],
        "event": metadata["event"],
        "conclusion": metadata["conclusion"],
        "workflow_started_at": metadata["started_at"],
        "workflow_updated_at": metadata["updated_at"],
        "workflow_wall_seconds": seconds(metadata["started_at"], metadata["updated_at"]),
        "critical_path": {
            "eligible": eligible,
            "jobs": [job["name"] for job in critical_jobs],
            "duration_seconds": sum(int(job["duration_seconds"] or 0) for job in critical_jobs) if critical_jobs else None,
        },
        "jobs": jobs,
        "browser_breakdown": browser,
    }


def display_seconds(value: Any) -> str:
    return "n/a" if value is None else f"{value}s"


def markdown_summary(report: dict[str, Any]) -> str:
    critical = report["critical_path"]
    critical_jobs = " → ".join(critical["jobs"]) if critical["jobs"] else "n/a"
    lines = [
        "# BRVTAL CI throughput",
        "",
        f"- Source SHA: `{report['source_sha']}`",
        f"- Source result: **{report['conclusion']}**",
        f"- Workflow wall time: **{display_seconds(report['workflow_wall_seconds'])}**",
        f"- Critical path: **{critical_jobs}** ({display_seconds(critical['duration_seconds'])})",
        "",
        "| Job | Result | Duration |",
        "|---|---|---:|",
    ]
    for job in report["jobs"]:
        lines.append(f"| {job['name']} | {job['conclusion'] or 'n/a'} | {display_seconds(job['duration_seconds'])} |")
    browser = report["browser_breakdown"]
    if browser:
        lines.extend([
            "",
            "## Browser setup vs test",
            "",
            "| Job | Dependencies/cache | Browser/system | Infrastructure | Test | Other | Total |",
            "|---|---:|---:|---:|---:|---:|---:|",
        ])
        for item in browser:
            phases = item["phase_seconds"]
            lines.append(
                f"| {item['name']} | {display_seconds(phases['dependency_cache'])} | "
                f"{display_seconds(phases['browser_system_setup'])} | "
                f"{display_seconds(phases['infrastructure'])} | "
                f"{display_seconds(phases['test'])} | "
                f"{display_seconds(phases['other'])} | {display_seconds(item['duration_seconds'])} |"
            )
    return "\n".join(lines) + "\n"


def main() -> None:
    parser = argparse.ArgumentParser()
    subparsers = parser.add_subparsers(dest="command", required=True)

    report_parser = subparsers.add_parser("report")
    report_parser.add_argument("--run-id", required=True)
    report_parser.add_argument("--source-sha", required=True)
    report_parser.add_argument("--event", required=True)
    report_parser.add_argument("--conclusion", required=True)
    report_parser.add_argument("--started-at", required=True)
    report_parser.add_argument("--updated-at", required=True)
    subparsers.add_parser("summary")

    args = parser.parse_args()
    payload = json.load(sys.stdin)
    if args.command == "summary":
        print(markdown_summary(payload), end="")
        return

    report = build_report(payload, {
        "run_id": args.run_id,
        "source_sha": args.source_sha,
        "event": args.event,
        "conclusion": args.conclusion,
        "started_at": args.started_at,
        "updated_at": args.updated_at,
    })
    print(json.dumps(report, indent=2, sort_keys=True))


if __name__ == "__main__":
    main()
