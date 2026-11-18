#!/usr/bin/env python3
"""Decide whether one automatic Production Performance run owns a source SHA."""

from __future__ import annotations

import argparse
import json
import sys
from datetime import datetime
from typing import Any


def parse_timestamp(value: Any) -> float:
    text = str(value or "").strip()
    if not text:
        return -1.0
    try:
        return datetime.fromisoformat(text.replace("Z", "+00:00")).timestamp()
    except ValueError:
        return -1.0


def run_order(run: dict[str, Any]) -> tuple[float, int]:
    updated = run.get("updated_at") or run.get("completed_at") or run.get("created_at")
    try:
        run_id = int(run.get("id") or 0)
    except (TypeError, ValueError):
        run_id = 0
    return (parse_timestamp(updated), run_id)


def decide(payload: dict[str, Any], expected_sha: str, source_run_id: int, source_updated_at: str) -> dict[str, Any]:
    runs = payload.get("workflow_runs")
    if not isinstance(runs, list):
        runs = []
    candidates = [
        run for run in runs
        if isinstance(run, dict)
        and str(run.get("head_sha") or "") == expected_sha
        and str(run.get("event") or "") == "push"
    ]
    counterpart = max(candidates, key=run_order) if candidates else None
    result: dict[str, Any] = {
        "ready": False,
        "reason": "counterpart_missing_for_sha",
        "counterpart_run_id": None,
        "counterpart_status": "missing",
        "counterpart_conclusion": "pending",
        "counterpart_updated_at": None,
    }
    if counterpart is None:
        return result
    result.update(
        counterpart_run_id=counterpart.get("id"),
        counterpart_status=str(counterpart.get("status") or "missing"),
        counterpart_conclusion=str(counterpart.get("conclusion") or "pending"),
        counterpart_updated_at=counterpart.get("updated_at") or counterpart.get("completed_at") or counterpart.get("created_at"),
    )
    if result["counterpart_status"] != "completed":
        result["reason"] = "counterpart_not_completed"
        return result
    if result["counterpart_conclusion"] != "success":
        result["reason"] = "counterpart_not_successful"
        return result
    source_key = (parse_timestamp(source_updated_at), int(source_run_id))
    if source_key <= run_order(counterpart):
        result["reason"] = "source_not_later_completion"
        return result
    result["ready"] = True
    result["reason"] = "ready"
    return result


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--expected-sha", required=True)
    parser.add_argument("--source-run-id", required=True, type=int)
    parser.add_argument("--source-updated-at", required=True)
    args = parser.parse_args()
    try:
        payload = json.load(sys.stdin)
    except json.JSONDecodeError as exc:
        print(json.dumps({"error": f"invalid_json: {exc.msg}"}))
        return 2
    result = decide(payload, args.expected_sha, args.source_run_id, args.source_updated_at)
    print(json.dumps(result, separators=(",", ":"), sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
