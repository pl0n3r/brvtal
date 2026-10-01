from __future__ import annotations

import importlib.util
import json
from pathlib import Path
import tempfile
import unittest
from unittest.mock import mock_open, patch

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts" / "production-performance-evidence.py"
WORKFLOW = ROOT / ".github" / "workflows" / "production-performance.yml"

spec = importlib.util.spec_from_file_location("production_performance_evidence", SCRIPT)
module = importlib.util.module_from_spec(spec)
assert spec.loader is not None
spec.loader.exec_module(module)

BASE_METRICS = {
    "firstContentfulPaint": 1200.5,
    "largestContentfulPaint": 1800.2,
    "cumulativeLayoutShift": 0.012,
    "domContentLoaded": 1400.1,
    "loadEventEnd": 2100.8,
}


class ProductionPerformanceEvidenceTests(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = tempfile.TemporaryDirectory()
        self.addCleanup(self.tmp.cleanup)
        self.root = Path(self.tmp.name)
        self.serial = 0

    def _probe(
        self,
        filename_mode: str,
        *,
        payload_mode: str | None = None,
        measured_at: str | None = None,
        metrics: dict | None = None,
    ) -> Path:
        self.serial += 1
        payload = {
            "measuredAt": measured_at or (
                "2026-10-01T10:00:00.000Z"
                if filename_mode == "mobile"
                else "2026-10-01T10:00:20.000Z"
            ),
            "mode": payload_mode or filename_mode,
            "requestedUrl": "https://www.brvtal.com.co/",
            "finalUrl": "https://www.brvtal.com.co/",
            "metrics": metrics or BASE_METRICS,
            "lcp": {},
            "page": {},
            "waterfall": {},
        }
        path = self.root / f"{filename_mode}-{self.serial}.json"
        path.write_text(json.dumps(payload), encoding="utf-8")
        return path

    def _build(self, mobile: Path | None = None, desktop: Path | None = None):
        return module.build_evidence(
            mobile or self._probe("mobile"),
            desktop or self._probe("desktop"),
            sha="c62baaabc994484d925d11f3625bb12772a8b5cb",
            release="0.1.101",
            run_id="36786118661",
        )

    def _assert_identity_error(self, code: str, **overrides: str) -> None:
        mobile, desktop = self._probe("mobile"), self._probe("desktop")
        identity = {"sha": "a" * 40, "release": "0.1.101", "run_id": "1"} | overrides
        with self.assertRaisesRegex(module.EvidenceError, f"^{code}$"):
            module.build_evidence(mobile, desktop, **identity)

    def test_valid_mobile_desktop_emit_deterministic_detector_compatible_evidence(self):
        mobile, desktop = self._probe("mobile"), self._probe("desktop")
        first, second = self._build(mobile, desktop), self._build(mobile, desktop)
        self.assertEqual(first, second)
        self.assertEqual(first["identity"], {
            "sha": "c62baaabc994484d925d11f3625bb12772a8b5cb",
            "release": "0.1.101",
        })
        self.assertEqual(first["evidence_ref"], "github:pl0n3r/brvtal/actions/runs/36786118661")
        self.assertEqual(len(first["observations"]), 10)
        required = {
            "version", "project", "surface", "metric", "value", "unit",
            "observed_at", "window_seconds", "sample_count", "severity",
            "operational_impact", "bottleneck", "evidence_ref", "sha", "release",
        }
        for row in first["observations"]:
            self.assertEqual(set(row), required)
            self.assertEqual((row["version"], row["project"]), (1, "brvtal"))
            self.assertEqual((row["sample_count"], row["window_seconds"]), (1, 1))
            self.assertEqual((row["operational_impact"], row["bottleneck"]), (False, "unknown"))
        self.assertEqual(
            {(row["metric"], row["unit"]) for row in first["observations"]},
            {
                ("fcp", "ms"), ("lcp", "ms"), ("cls", "ratio"),
                ("dom_content_loaded", "ms"), ("load_event_end", "ms"),
            },
        )

    def test_malformed_or_incoherent_evidence_fails_closed_without_echo(self):
        cases = [
            (self._probe("mobile", payload_mode="desktop"), self._probe("desktop"), "probe_mode_mismatch"),
            (
                self._probe("mobile"),
                self._probe("desktop", measured_at="2026-10-01T10:20:00Z"),
                "probe_window_mismatch",
            ),
        ]
        for bad_value in (-1, float("nan"), "1800"):
            metrics = dict(BASE_METRICS)
            metrics["largestContentfulPaint"] = bad_value
            cases.append((self._probe("mobile", metrics=metrics), self._probe("desktop"), "metric_invalid"))
        metrics = dict(BASE_METRICS)
        metrics["unexpected"] = 1
        cases.append((self._probe("mobile", metrics=metrics), self._probe("desktop"), "metrics_shape_invalid"))

        for mobile, desktop, code in cases:
            with self.subTest(code=code):
                with self.assertRaisesRegex(module.EvidenceError, f"^{code}$"):
                    self._build(mobile, desktop)

        edge_mobile = self._probe("mobile")
        self._build(edge_mobile, self._probe("desktop", measured_at="2026-10-01T10:08:00Z"))
        rejected_mobile = self._probe("mobile")
        rejected_desktop = self._probe("desktop", measured_at="2026-10-01T10:08:01Z")
        with self.assertRaisesRegex(module.EvidenceError, "^probe_window_mismatch$"):
            self._build(rejected_mobile, rejected_desktop)

        for code, overrides in (
            ("sha_invalid", {"sha": "secret-value"}),
            ("release_invalid", {"release": "bad release"}),
            ("run_id_invalid", {"run_id": "token=secret"}),
        ):
            with self.subTest(code=code):
                self._assert_identity_error(code, **overrides)

    def test_output_cannot_claim_classification_and_factory_is_authority(self):
        payload = self._build()
        self.assertIsNone(payload["classification"])
        self.assertEqual(payload["classification_authority"], "factory-performance-v1")
        for row in payload["observations"]:
            for forbidden in ("classification", "baseline", "budget"):
                self.assertNotIn(forbidden, row)

    def test_workflow_normalizes_after_both_probes_and_uploads_same_artifact(self):
        text = WORKFLOW.read_text(encoding="utf-8")
        mobile = text.index("Measure mobile production performance")
        desktop = text.index("Measure desktop production performance")
        normalize = text.index("Normalize Factory Performance v1 evidence")
        upload = text.index("Upload performance evidence")
        self.assertLess(mobile, desktop)
        self.assertLess(desktop, normalize)
        self.assertLess(normalize, upload)
        block = text[normalize:upload]
        for expected in (
            "python3 scripts/production-performance-evidence.py",
            "github.event_name == 'workflow_run'",
            "steps.prerequisites.outputs.ready == 'true'",
            'sha="$(git rev-parse HEAD)"',
            '--run-id "$GITHUB_RUN_ID"',
        ):
            self.assertIn(expected, block)
        for forbidden in ("--mobile", "--desktop", "--output", "php -r"):
            self.assertNotIn(forbidden, block)
        self.assertIn(
            "(github.event_name == 'workflow_dispatch' && github.ref == 'refs/heads/main')",
            text,
        )
        self.assertIn("path: artifacts/production-performance-*.json", text)
        self.assertIn("if: success() && steps.connectivity.outputs.reachable == 'true'", text[upload:])
        self.assertIn("if-no-files-found: error", text[upload:])
        script = SCRIPT.read_text(encoding="utf-8")
        for filename in (
            "production-performance-mobile.json",
            "production-performance-desktop.json",
            "production-performance-evidence.json",
        ):
            self.assertIn(filename, script)


    def test_cli_main_writes_only_canonical_artifact(self):
        args = type("Args", (), {"sha": "a" * 40, "release": "0.1.101", "run_id": "1"})()
        with patch.object(module, "_parser") as parser, \
             patch.object(module, "build_evidence", return_value={}), \
             patch("builtins.open", mock_open()) as output:
            parser.return_value.parse_args.return_value = args
            self.assertEqual(module.main(), 0)
        output.assert_called_once_with("artifacts/production-performance-evidence.json", "w", encoding="utf-8")


if __name__ == "__main__":
    unittest.main()
