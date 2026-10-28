#!/usr/bin/env python3
"""BRVTAL #1132: bounded, synthetic evidence for S31-R003..S32-R004.

Local checks must never be confused with legal approval, a deployed Theme
Studio, or complete Media Library/social-image integration. Coverage rows
remain NO_VERIFICABLE until the separate reconciliation has real evidence.
"""
from __future__ import annotations

import importlib.util
import json
import re
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
SPEC = ROOT / "docs/BRVTAL-SPEC.md"
LEGAL_SCRIPT = ROOT / "scripts/generar_revision_juridica.py"
STUDIO = ROOT / "discadmin/theme-studio-v2.js"
CONFIGURATION = ROOT / "discadmin/theme-studio-configuration.js"
RUNTIME = ROOT / "js/public-theme-runtime.js"
IDS = ("S31-R003", "S31-R004", "S32-R002", "S32-R003", "S32-R004")
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_CONTRACT"


def coverage_rows(source: str) -> dict[str, tuple[str, ...]]:
    """Reject duplicate or malformed target rows instead of taking the first."""
    rows: dict[str, tuple[str, ...]] = {}
    for line in source.splitlines():
        match = re.match(r"^\| (S(?:31|32)-R\d{3}) · ", line)
        if match is None or match.group(1) not in IDS:
            continue
        key = match.group(1)
        if key in rows:
            raise ValueError("duplicate coverage row " + key)
        cells = tuple(x.strip() for x in line.strip().strip("|").split("|"))
        if len(cells) != 5 or not cells[0].startswith(key + " · "):
            raise ValueError("malformed coverage row " + key)
        rows[key] = cells
    return rows


def source_text() -> dict[str, str]:
    return {
        "spec": SPEC.read_text(encoding="utf-8"),
        "legal": LEGAL_SCRIPT.read_text(encoding="utf-8"),
        "studio": STUDIO.read_text(encoding="utf-8"),
        "config": CONFIGURATION.read_text(encoding="utf-8"),
        "runtime": RUNTIME.read_text(encoding="utf-8"),
    }


def legal_fixture() -> dict[str, bool]:
    """Run the actual legal generator against *temporary, fictional* sources."""
    module_spec = importlib.util.spec_from_file_location("brvtal_legal_1132", LEGAL_SCRIPT)
    if module_spec is None or module_spec.loader is None:
        raise AssertionError("legal generator is unavailable")
    module = importlib.util.module_from_spec(module_spec)
    module_spec.loader.exec_module(module)
    with tempfile.TemporaryDirectory(prefix="brvtal-1132-synthetic-") as tmp:
        base = Path(tmp)
        private = base / "docs" / "privacidad"
        private.mkdir(parents=True)
        (base / "config").mkdir()
        (base / "config/version.php").write_text(
            "<?php const BRVTAL_APP_VERSION = '0.0.1';", encoding="utf-8"
        )
        (base / "datos.yml").write_text(json.dumps({
            "treatments": [{"providers": ["synthetic-processor"]}]
        }), encoding="utf-8")
        for filename, _ in module.NAMES:
            (private / filename).write_text(
                "Synthetic review material: " + filename + "\n", encoding="utf-8"
            )
        sha, day = "a" * 40, "2026-10-01"
        expected = module.expected(base, sha, day)
        index = expected["00-LEEME.md"].decode("utf-8")
        bundle = expected["paquete-completo.md"].decode("utf-8")
        # --check is read-only, even for absent/stale files.
        with patch.object(module, "provenance", return_value=(sha, day)):
            missing = module.synchronize(base, check=True)
            no_write = not (base / module.FOLDER).exists()
            module.synchronize(base, check=False)
            current = module.synchronize(base, check=True)
            damaged = base / module.FOLDER / module.NAMES[0][0]
            damaged.write_text("synthetic-stale", encoding="utf-8")
            stale = module.synchronize(base, check=True)
            untouched = damaged.read_text(encoding="utf-8") == "synthetic-stale"
        return {
            "unapproved": "documented_not_legally_approved" in index
                and "requiere revisión del abogado" in index
                and "sin aprobación jurídica" in bundle,
            "human_review": "Qué debe confirmar el abogado" in index
                and "La validación jurídica se documenta fuera" in index,
            "not_mutating_check": no_write and len(missing) == len(expected)
                and not current and module.NAMES[0][0] in stale and untouched,
            "exact_synthetic_sources": all(
                expected[name] == (private / name).read_bytes()
                for name, _ in module.NAMES
            ) and "synthetic-processor" in index,
        }


def contract(row_id: str, rows: dict[str, tuple[str, ...]],
             sources: dict[str, str], legal: object) -> dict[str, object]:
    """A local check is NEVER a production or legal-approval assertion."""
    state: dict[str, object] = {
        "code": UNKNOWN, "legal_approval": UNKNOWN,
        "ci": UNKNOWN, "production": UNKNOWN, "full_scope": UNKNOWN,
    }
    if row_id not in IDS or row_id not in rows or len(rows[row_id]) != 5:
        return state
    if rows[row_id][2] != "NO_VERIFICABLE" or any(
        name not in sources or not isinstance(sources[name], str)
        or not sources[name] for name in ("spec", "legal", "studio", "config", "runtime")
    ):
        return state
    if not isinstance(legal, dict) or not all(
        legal.get(key) is True for key in (
            "unapproved", "human_review", "not_mutating_check", "exact_synthetic_sources"
        )
    ):
        return state

    spec, generator = sources["spec"], sources["legal"]
    studio, config, runtime = (
        sources["studio"], sources["config"], sources["runtime"]
    )
    panel = studio.split("  function panel(theme) {", 1)
    if len(panel) != 2:
        return state
    bounded = panel[1].split("  function currentTheme()", 1)
    if len(bounded) != 2:
        return state
    controls = bounded[0]

    checks = {
        # #1029: maintenance tooling is not a lawyer's signature.
        "S31-R003": all(t in spec for t in (
            "Do not automatically claim complete legal compliance.",
            "it does not replace legal review where required.",
        )) and "documented_not_legally_approved" in generator
            and "no implica aprobación jurídica" in generator,
        "S31-R004": "Tooling assists maintenance" in spec
            and "def synchronize(root: Path, check: bool)" in generator
            and "if not check:" in generator
            and "La validación jurídica se documenta fuera" in generator,
        # Canonical iframe is the single renderer, not a local duplicate.
        "S32-R002": "not a free-form page builder" in spec
            and 'data-theme-canonical-preview' in studio
            and 'src="/?theme_studio_preview=1"' in studio
            and "window.addEventListener('message', receivePreviewTheme)" in runtime
            and "event.origin !== window.location.origin" in runtime
            and "applyTheme(previewTheme)" in runtime
            and not any(duplicate in studio for duplicate in (
                "tsv2-preview-hero", "tsv2-preview-grid",
                "tsv2-preview-browser", "tsv2-preview-nav",
            )),
        # Assert restricted editor controls AND the matching public consumers.
        "S32-R003": all(t in controls for t in (
            "colorField('bg'", "colorField('text'", "colorField('primary'",
            "fontSelectField('display'", "fontSelectField('body'",
            "selectField('menuStyle'", "toggleField('grain'",
        )) and all(t in runtime for t in (
            "applyColors(theme.colors)", "applyTypography(theme.typography)",
            "applyNavigation(theme.navigation, theme.sound)",
            "applyEffects(theme.effects)", "applyBranding(theme.branding)",
        )) and "SEO / ANALYTICS" in config
            and not any(unbounded in controls.lower() for unbounded in (
                "custom css", "custom js", "position:absolute", "z-index",
            )),
        # This row includes "default social image where supported"; the
        # current editor proves four assets, but not that fifth mapping.
        "S32-R004": all(t in controls for t in (
            "assetField('logo'", "assetField('mobileLogo'",
            "assetField('favicon'", "assetField('preloaderLogo'",
        )) and all(t in studio for t in (
            "CHOOSE FROM MEDIA", "openPicker(field, trigger)",
            "state.themeMedia", "data-media-path",
        )) and all(t in runtime for t in (
            "safeAsset(branding.logo)", "safeAsset(branding.mobileLogo)",
            "safeAsset(branding.favicon)", "safeAsset(branding.preloaderLogo)",
        )),
    }
    if checks[row_id]:
        state["code"] = LOCAL
    return state


class BrechasBrvtal42Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: one executable assertion for every scoped SPEC clause."""
        rows = coverage_rows(MATRIX.read_text(encoding="utf-8"))
        sources, legal = source_text(), legal_fixture()
        self.assertEqual(set(rows), set(IDS))
        self.assertTrue(all(value is True for value in legal.values()))
        for row_id in IDS:
            with self.subTest(row=row_id):
                result = contract(row_id, rows, sources, legal)
                self.assertEqual(result["code"], LOCAL)
                self.assertEqual(result["production"], UNKNOWN)

    def test_falla_cerrado(self) -> None:
        """AC-02: missing, malformed or forged status cannot become success."""
        rows = coverage_rows(MATRIX.read_text(encoding="utf-8"))
        sources, legal = source_text(), legal_fixture()
        for row_id in IDS:
            with self.subTest(row=row_id):
                self.assertEqual(contract(row_id, {}, sources, legal)["code"], UNKNOWN)
                self.assertEqual(contract(row_id, rows, {}, legal)["code"], UNKNOWN)
                self.assertEqual(contract(row_id, rows, sources, None)["code"], UNKNOWN)
                bad = {**legal, "unapproved": "true"}
                self.assertEqual(contract(row_id, rows, sources, bad)["code"], UNKNOWN)
                tampered = dict(rows)
                altered = list(rows[row_id])
                altered[2] = "CUBIERTO"
                tampered[row_id] = tuple(altered)
                self.assertEqual(contract(row_id, tampered, sources, legal)["code"], UNKNOWN)
        target = next(line for line in MATRIX.read_text(encoding="utf-8").splitlines()
                      if line.startswith("| S31-R003 · "))
        with self.assertRaisesRegex(ValueError, "duplicate"):
            coverage_rows(target + "\n" + target)
        with self.assertRaisesRegex(ValueError, "malformed"):
            coverage_rows("| S32-R004 · partial | CUBIERTO |")
        self.assertEqual(contract("S31-R999", rows, sources, legal)["code"], UNKNOWN)
        missing_asset = {**sources,
                         "studio": sources["studio"].replace(
                             "assetField('favicon'", "disabledField('favicon'")}
        self.assertEqual(contract("S32-R004", rows, missing_asset, legal)["code"], UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        """AC-03: ephemeral legal records and checked-in JavaScript only."""
        index = legal_fixture()
        self.assertTrue(index["exact_synthetic_sources"])
        own = Path(__file__).read_text(encoding="utf-8")
        self.assertNotIn("-----BEGIN " + "PRIVATE KEY-----", own)
        self.assertNotRegex(own, r"(?m)^(?:import|from)\s+(?:requests|httpx|socket|urllib)\b")
        self.assertNotIn("curl_" + "exec(", own)
        self.assertNotIn("db" + "();", own)
        self.assertNotIn("GH_" + "TOKEN", own)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: code evidence does not promote legal, CI or live status."""
        rows = coverage_rows(MATRIX.read_text(encoding="utf-8"))
        sources, legal = source_text(), legal_fixture()
        for row_id in IDS:
            with self.subTest(row=row_id):
                self.assertEqual(rows[row_id][2], "NO_VERIFICABLE")
                result = contract(row_id, rows, sources, legal)
                self.assertEqual(result["code"], LOCAL)
                self.assertEqual(result["legal_approval"], UNKNOWN)
                self.assertEqual(result["ci"], UNKNOWN)
                self.assertEqual(result["production"], UNKNOWN)
                self.assertEqual(result["full_scope"], UNKNOWN)
        # No proof of a default social-image editor control: the specification
        # says "where supported" and this test may not invent that support.
        self.assertIn("default social image where supported", sources["spec"])
        panel = sources["studio"].split("  function panel(theme) {", 1)[1].split(
            "  function currentTheme()", 1
        )[0]
        self.assertNotIn("assetField('defaultSocialImage'", panel)
        self.assertIn("documented_not_legally_approved", sources["legal"])


if __name__ == "__main__":
    unittest.main()
