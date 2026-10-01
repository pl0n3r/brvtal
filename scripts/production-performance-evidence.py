#!/usr/bin/env python3
"""Normalize BRVTAL production-performance probes into Factory Performance v1 observations."""

from __future__ import annotations

import argparse
from datetime import datetime, timezone
import json
import math
from pathlib import Path
import re
from typing import Any

MAX_INPUT_BYTES = 1_000_000
ROOT = Path(__file__).resolve().parents[1]
ARTIFACTS_DIR = ROOT / "artifacts"
MOBILE_PATH = ARTIFACTS_DIR / "production-performance-mobile.json"
DESKTOP_PATH = ARTIFACTS_DIR / "production-performance-desktop.json"
MAX_PAIR_SKEW_SECONDS = 8 * 60  # bounded by Production Performance workflow timeout
METRICS = {
    "firstContentfulPaint": ("fcp", "ms"),
    "largestContentfulPaint": ("lcp", "ms"),
    "cumulativeLayoutShift": ("cls", "ratio"),
    "domContentLoaded": ("dom_content_loaded", "ms"),
    "loadEventEnd": ("load_event_end", "ms"),
}
_SHA = re.compile(r"^[0-9a-f]{40}$")
_RELEASE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]{0,79}$")
_RUN_ID = re.compile(r"^[1-9]\d{0,19}$", re.ASCII)


class EvidenceError(ValueError):
    """Production performance evidence is malformed or incoherent."""


def _timestamp(value: Any) -> datetime:
    if not isinstance(value, str):
        raise EvidenceError("measured_at_invalid")
    normalized = value[:-1] + "+00:00" if value.endswith("Z") else value
    try:
        parsed = datetime.fromisoformat(normalized)
    except ValueError as exc:
        raise EvidenceError("measured_at_invalid") from exc
    if parsed.tzinfo is None:
        raise EvidenceError("measured_at_invalid")
    return parsed.astimezone(timezone.utc)


def _iso(value: datetime) -> str:
    return value.isoformat(timespec="seconds").replace("+00:00", "Z")


def _number(value: Any) -> int | float | None:
    if value is None:
        return None
    if isinstance(value, bool) or not isinstance(value, (int, float)):
        raise EvidenceError("metric_invalid")
    if isinstance(value, float) and not math.isfinite(value):
        raise EvidenceError("metric_invalid")
    if value < 0 or abs(value) > 1e15:
        raise EvidenceError("metric_invalid")
    return value


def _load_probe(path: Path, expected_mode: str) -> tuple[datetime, dict[str, int | float | None]]:
    try:
        if path.stat().st_size > MAX_INPUT_BYTES:
            raise EvidenceError("probe_too_large")
        raw = json.loads(path.read_text(encoding="utf-8"))
    except EvidenceError:
        raise
    except (OSError, UnicodeError, json.JSONDecodeError) as exc:
        raise EvidenceError("probe_unreadable") from exc
    if not isinstance(raw, dict):
        raise EvidenceError("probe_shape_invalid")
    for required in ("measuredAt", "mode", "metrics"):
        if required not in raw:
            raise EvidenceError("probe_shape_invalid")
    if raw["mode"] != expected_mode:
        raise EvidenceError("probe_mode_mismatch")
    metrics = raw["metrics"]
    if not isinstance(metrics, dict) or set(metrics) != set(METRICS):
        raise EvidenceError("metrics_shape_invalid")
    values = {name: _number(metrics[name]) for name in METRICS}
    if values["largestContentfulPaint"] is None:
        raise EvidenceError("lcp_missing")
    return _timestamp(raw["measuredAt"]), values


def build_evidence(
    mobile_path: Path,
    desktop_path: Path,
    *,
    sha: str,
    release: str,
    run_id: str,
) -> dict[str, Any]:
    """Build a deterministic, classification-free evidence envelope."""
    if _SHA.fullmatch(sha) is None:
        raise EvidenceError("sha_invalid")
    if _RELEASE.fullmatch(release) is None:
        raise EvidenceError("release_invalid")
    if _RUN_ID.fullmatch(run_id) is None:
        raise EvidenceError("run_id_invalid")

    mobile_at, mobile = _load_probe(mobile_path, "mobile")
    desktop_at, desktop = _load_probe(desktop_path, "desktop")
    if abs((desktop_at - mobile_at).total_seconds()) > MAX_PAIR_SKEW_SECONDS:
        raise EvidenceError("probe_window_mismatch")

    evidence_ref = f"github:pl0n3r/brvtal/actions/runs/{run_id}"
    observations: list[dict[str, Any]] = []
    for mode, observed_at, values in (
        ("mobile", mobile_at, mobile),
        ("desktop", desktop_at, desktop),
    ):
        for source_name, (metric_id, unit) in METRICS.items():
            value = values[source_name]
            if value is None:
                continue
            observations.append({
                "version": 1,
                "project": "brvtal",
                "surface": f"public.home.{mode}",
                "metric": metric_id,
                "value": value,
                "unit": unit,
                "observed_at": _iso(observed_at),
                "window_seconds": 1,
                "sample_count": 1,
                "severity": "info",
                "operational_impact": False,
                "bottleneck": "unknown",
                "evidence_ref": evidence_ref,
                "sha": sha,
                "release": release,
            })

    observations.sort(key=lambda item: (item["surface"], item["metric"]))
    return {
        "version": 1,
        "project": "brvtal",
        "classification_authority": "factory-performance-v1",
        "classification": None,
        "identity": {"sha": sha, "release": release},
        "evidence_ref": evidence_ref,
        "observed_at": _iso(max(mobile_at, desktop_at)),
        "observations": observations,
    }


def _parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser()
    parser.add_argument("--sha", required=True)
    parser.add_argument("--release", required=True)
    parser.add_argument("--run-id", required=True)
    return parser


def main() -> int:
    args = _parser().parse_args()
    try:
        evidence = build_evidence(
            MOBILE_PATH,
            DESKTOP_PATH,
            sha=args.sha,
            release=args.release,
            run_id=args.run_id,
        )
    except EvidenceError as exc:
        print(f"Invalid production performance evidence: {exc}")
        return 2
    with open(
        "artifacts/production-performance-evidence.json",
        "w",
        encoding="utf-8",
    ) as output:
        output.write(
            json.dumps(evidence, ensure_ascii=False, indent=2, sort_keys=True) + "\n"
        )
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
