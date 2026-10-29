#!/usr/bin/env python3
"""BRVTAL #1091: five synthetic coverage contracts, not go-live evidence.

The original logo's provenance, subjective flyer quality and real DeepL
admissibility require independent human/operational evidence. These checks
never contact a provider or turn NO_VERIFICABLE into certified compliance.
"""
from __future__ import annotations

import json
import re
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
SPEC = ROOT / "docs/BRVTAL-SPEC.md"
ROUTER = ROOT / "index.php"
VISUAL = ROOT / "css/public-visual-identity.css"
TOKENS = ROOT / "css/public-concept05-tokens.css"
E2E = ROOT / "tests/e2e/public-home-visual-identity.spec.mjs"
TRIAL = ROOT / "config/public_translation_deepl_page_trial.php"
SCENARIOS = ROOT / "tests/deepl_translation_adapter_scenarios.php"
CLAUSES = ("H212-01", "S02-R001", "S02-R002", "S02-R003", "S02-R004")
UNKNOWN = "UNKNOWN"


def matrix_rows(document: str) -> dict[str, tuple[str, ...]]:
    """Reject absent, duplicated, truncated or ambiguous canonical coverage."""
    found: dict[str, tuple[str, ...]] = {}
    for line in document.splitlines():
        match = re.match(r"^\|\s*(H212-01|S02-R00[1-4])(?=\s|[·:|])", line)
        if match is None:
            continue
        key = match.group(1)
        if not line.startswith(f"| {key} · "):
            raise ValueError("UNKNOWN: ambiguous row delimiter for " + key)
        parts = tuple(cell.strip() for cell in line.strip().strip("|").split("|"))
        if key in found or len(parts) != 5 or any(not cell for cell in parts):
            raise ValueError("UNKNOWN: duplicate/incomplete row for " + key)
        if not parts[0].startswith(key + " · "):
            raise ValueError("UNKNOWN: malformed row " + key)
        found[key] = parts
    if set(found) != set(CLAUSES):
        raise ValueError("UNKNOWN: one or more coverage rows are missing")
    return found


def unverified_external_evidence(candidate: object = None) -> str:
    """Synthetic/style evidence cannot grant legal, logo or visual approval."""
    return UNKNOWN


def fake_adapter_missing() -> dict:
    """Execute repository's PHP fixture with NO key and no network transport."""
    php = shutil.which("php")
    if php is None:
        raise AssertionError("UNKNOWN: PHP runtime unavailable")
    result = subprocess.run(
        [php, str(SCENARIOS), "missing"],
        cwd=ROOT, text=True, capture_output=True, timeout=20, check=False,
    )
    if result.returncode:
        raise AssertionError("UNKNOWN: synthetic PHP fixture failed: " + result.stderr[-500:])
    try:
        return json.loads(result.stdout)
    except json.JSONDecodeError as exc:
        raise AssertionError("UNKNOWN: unreadable synthetic adapter evidence") from exc


class BrechasBrvtal01Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.document = MATRIX.read_text(encoding="utf-8")
        cls.rows = matrix_rows(cls.document)
        cls.spec = SPEC.read_text(encoding="utf-8")
        cls.visual = VISUAL.read_text(encoding="utf-8")
        cls.tokens = TOKENS.read_text(encoding="utf-8")
        cls.router = ROUTER.read_text(encoding="utf-8")
        cls.e2e = E2E.read_text(encoding="utf-8")
        cls.trial = TRIAL.read_text(encoding="utf-8")
        cls.fake = fake_adapter_missing()

    def test_contrato_principal(self):
        """AC-01: each clause has an independent, bounded offline assertion."""
        for key in CLAUSES:
            with self.subTest(clause=key):
                self.assertIn(key, self.rows)
                self.assertIn(self.rows[key][2], {"NO_VERIFICABLE", "CUBIERTO"})
                self.assertEqual(unverified_external_evidence(self.rows[key]), UNKNOWN)

        # H212-01: fake/provider-neutral architecture is testable, real
        # provider/legal authorization is NOT implied by these fixtures.
        self.assertIn("Traducción ES/EN: arquitectura provider-neutral", self.rows["H212-01"][1])
        self.assertEqual(self.fake["reason"], "provider_not_configured")
        self.assertEqual(self.fake["transport_calls"], 0)
        self.assertEqual(self.fake["result"]["locale"], "es")
        self.assertFalse(self.fake["result"]["translated"])
        self.assertIn("BRVTAL_DEEPL_TRIAL_OPERATIONAL_PERMIT", self.trial)
        self.assertIn("BRVTAL_DEEPL_ENABLED", self.trial)
        self.assertIn("status", self.trial)

        # S02-R001: canonical brand text and real public visual stylesheet.
        # Neither source code nor a screenshot proves logo ownership.
        self.assertIn("BRVTAL already has an official logo", self.spec)
        self.assertIn("css/public-visual-identity.css", self.router)
        self.assertIn("public router loads the Home visual layer", self.e2e)

        # S02-R002: palette, editorial/monospace typography and grain tokens.
        self.assertIn("--brvtal-signal-red:#9e0d12", self.visual)
        for token in ("--c5-deep-black", "--c5-paper-dirty", "--c5-signal-red",
                      "--c5-type-mono", ".c5-tx-grain", ".c5-registration"):
            with self.subTest(token=token):
                self.assertIn(token, self.tokens)

        # S02-R003: event editorial hierarchy and real E2E layout assertions.
        self.assertIn("Flyers should not be treated as conventional generic cards", self.spec)
        self.assertIn(".events .event-info{", self.visual)
        self.assertIn("grid-template-areas:", self.visual)
        self.assertIn("desktop Events keeps roughly two to three cards", self.e2e)

        # S02-R004: existing overlay/glitch and reduced-motion fallback.
        self.assertIn("Prefer masks, crops, zoom, glitch", self.spec)
        self.assertIn(".events .event-img::before", self.visual)
        self.assertIn("mix-blend-mode:screen", self.visual)
        self.assertIn("@keyframes brvtalHeroInterference", self.visual)
        self.assertIn("@media(prefers-reduced-motion:reduce)", self.visual)
        self.assertIn("reduced motion disables decorative", self.e2e)

    def test_falla_cerrado(self):
        """AC-02: corrupt, duplicated or absent evidence cannot pass silently."""
        row = next(x for x in self.document.splitlines() if x.startswith("| H212-01 · "))
        for corrupted in (
            self.document.replace(row, ""),
            self.document + "\n" + row,
            self.document.replace(row, "| H212-01 · truncated |"),
            self.document + "\n| H212-01: noncanonical delimiter |",
        ):
            with self.subTest(corruption=corrupted.count("H212-01")):
                with self.assertRaises(ValueError):
                    matrix_rows(corrupted)
        self.assertEqual(unverified_external_evidence({"ci": "success"}), UNKNOWN)
        self.assertEqual(unverified_external_evidence({"logo_owner": True}), UNKNOWN)
        self.assertEqual(unverified_external_evidence(None), UNKNOWN)

    def test_sin_datos_reales(self):
        """AC-03: purely synthetic fixtures, no personal data or real provider."""
        self.assertEqual(self.fake["transport_calls"], 0)
        self.assertEqual(self.fake["result"]["text"], "Hola")
        self.assertNotIn("api_key", json.dumps(self.fake).lower())
        self.assertNotIn("password", json.dumps(self.fake).lower())
        self.assertNotIn("authorization", json.dumps(self.fake).lower())
        self.assertEqual(unverified_external_evidence({"provider": "DeepL"}), UNKNOWN)

    def test_estados_unknown_explicitos(self):
        """AC-04: do not label visual review or external provider as proven."""
        self.assertEqual(set(self.rows), set(CLAUSES))
        for key in CLAUSES:
            with self.subTest(clause=key):
                row = self.rows[key]
                self.assertTrue(row[3])
                for alleged in (None, {}, {"visual_review": "approved"},
                                {"logo_license": "yes"}, {"provider_live": True},
                                {"ci": "success"}):
                    self.assertEqual(unverified_external_evidence(alleged), UNKNOWN)
        self.assertEqual(self.rows["H212-01"][2], "NO_VERIFICABLE")


if __name__ == "__main__":
    unittest.main()
