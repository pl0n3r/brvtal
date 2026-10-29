#!/usr/bin/env python3
"""#1289: executable offline version boundary; no false roadmap accreditation.

The 20 S51/S51.1 statements describe live GitHub roadmap governance (B)
or institutional source-ownership / merge policy (C). Neither can be promoted
to full coverage by a checkout-only test. The version CLI is an executable
sub-boundary of S51-R005, NOT proof of roadmap-version assignments.
No coverage-matrix input, network, database, production or user records.
"""
from __future__ import annotations

import os
from pathlib import Path
import re
import shutil
import subprocess
import sys
import unittest

ROOT = Path(__file__).resolve().parents[1]
IDS = frozenset((
    "S51-R002", "S51-R003", "S51-R004", "S51-R005",
    "S51-R006", "S51-R007", "S51-R008", "S51-R009",
    "S51-R010", "S51-R012", "S51-R013", "S51-R014",
    "S51-R015", "S51-R016", "S51-R017", "S51-R018",
    "S51-R019", "S51-R020", "S51-R021", "S51_1-R008",
))
B = {
    "S51-R002": "Current roadmap classification requires authenticated Issue #533 evidence",
    "S51-R003": "Tasks/milestones must be checked against live GitHub",
    "S51-R004": "Completed/pending/blocked work status is mutable GitHub state",
    "S51-R005": "Version assignments belong to Issue #533, not the local version CLI",
    "S51-R006": "Links to Issues and PRs require live GitHub relationship evidence",
    "S51-R007": "Merge and deployment evidence needs exact authenticated remote SHA",
    "S51-R008": "Blockers and their resolutions require historical issue events",
    "S51-R009": "Notes tied to real progress require authenticated issue timelines",
    "S51-R018": "Historical completion strike-through requires live roadmap history",
    "S51-R020": "User reprioritization must be observed against actual Issue #533",
    "S51_1-R008": "Global work-line cap and serial merges need coordinator/GitHub evidence",
}
C = {
    "S51-R010": "Prohibition on permanent prose in roadmap is editorial policy",
    "S51-R012": "AGENTS.md source ownership is a documentation convention",
    "S51-R013": "BRVTAL-SPEC.md source ownership is a documentation convention",
    "S51-R014": "Issue #533 as roadmap owner is institutional policy",
    "S51-R015": "Specific Issues own executable acceptance as process policy",
    "S51-R016": "PRs own implementation evidence as process policy",
    "S51-R017": "README snapshot versus roadmap is an editorial source rule",
    "S51-R019": "Relocation of normative prose is a maintenance permission",
    "S51-R021": "Do not duplicate progress in the specification is editorial policy",
}


def release_text(version: str, date: str = "2026-10-01") -> str:
    """Create deterministic non-production release metadata."""
    return (
        f"const BRVTAL_APP_VERSION = '{version}';\n"
        f"const BRVTAL_RELEASE_DATE = '{date}';\n"
    )


def release_command(
    base: str | None, head: str | None, *, check_current: bool = False
) -> subprocess.CompletedProcess[str]:
    """Execute the repository's actual release-version CLI with a clean fixture."""
    interpreter = shutil.which("python3") or sys.executable
    if not (ROOT / "scripts/release-version.py").is_file():
        raise AssertionError("UNKNOWN: real release policy implementation missing")
    env = {key: os.environ[key] for key in ("PATH", "LANG", "LC_ALL")
           if key in os.environ}
    if base is not None:
        env["BRVTAL_BASE_RELEASE"] = release_text(base)
    if head is not None:
        env["BRVTAL_HEAD_RELEASE"] = release_text(head)
    return subprocess.run(
        [interpreter, "scripts/release-version.py",
         "--check-current" if check_current else "--compare-env"],
        cwd=ROOT, env=env, capture_output=True, text=True,
        check=False, timeout=20,
    )


class CoberturaBrvtal20Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """Prove a real local version transition, never dynamic roadmap state."""
        self.assertEqual(len(IDS), 20)
        self.assertEqual(IDS, B.keys() | C.keys())
        self.assertFalse(B.keys() & C.keys())
        self.assertTrue(all(B.values()) and all(C.values()))

        patch = release_command("0.1.7", "0.1.8")
        self.assertEqual(patch.returncode, 0, patch.stderr)
        self.assertIn("0.1.7 -> 0.1.8 (patch)", patch.stdout)
        minor = release_command("0.1.99", "0.2.0")
        self.assertEqual(minor.returncode, 0, minor.stderr)
        self.assertIn("minor milestone", minor.stdout)
        current = release_command(None, None, check_current=True)
        self.assertEqual(current.returncode, 0, current.stderr)
        self.assertIn("valid pre-1.0 metadata", current.stdout)

    def test_falla_cerrado(self) -> None:
        """Real CLI must reject invalid, ambiguous and forbidden transitions."""
        for before, after in (
            ("0.1.7", "0.1.7"), ("0.1.7", "0.1.9"),
            ("0.1.8", "0.1.7"), ("0.1.7", "0.3.0"),
            ("0.1.7", "1.0.0"), ("invalid", "0.1.8"),
        ):
            with self.subTest(before=before, after=after):
                result = release_command(before, after)
                self.assertNotEqual(result.returncode, 0)
                self.assertTrue(result.stderr.strip() or result.stdout.strip())
        for base, head in ((None, "0.1.8"), ("0.1.7", None)):
            with self.subTest(base=base, head=head):
                self.assertNotEqual(release_command(base, head).returncode, 0)

    def test_sin_datos_reales(self) -> None:
        """Only synthetic CLI fixtures and local files; zero false live claims."""
        source = Path(__file__).read_text(encoding="utf-8")
        self.assertNotIn("docs/" + "matriz-cobertura", source.split("class CoberturaBrvtal20Tests")[1])
        self.assertNotRegex(source, r"(?m)^(?:import|from)\s+(?:requests|httpx|socket)\b")
        self.assertNotRegex(source, r"-----BEGIN (?:RSA |OPENSSH )?PRIVATE KEY-----")
        self.assertFalse(any("CUBIERTO" in reason for reason in (*B.values(), *C.values())))
        self.assertTrue((ROOT / "scripts/release-version.py").is_file())
        self.assertEqual(len(IDS), len(B) + len(C))
        self.assertIn("Current roadmap classification", B["S51-R002"])


if __name__ == "__main__":
    unittest.main()
