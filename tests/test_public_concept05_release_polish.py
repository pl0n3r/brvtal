#!/usr/bin/env python3
from __future__ import annotations

import importlib.util
import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FIXTURE = ROOT / "tests/fixtures/public-concept05-reference-v1.json"
MATRIX = ROOT / "tests/e2e/public-concept05-fidelity-matrix.spec.mjs"
MOBILE_PERF = ROOT / "tests/e2e/public-mobile-performance.spec.mjs"
MENU = ROOT / "tests/e2e/public-menu-accessibility.spec.mjs"
SOUND_MEMORIES = ROOT / "tests/e2e/public-concept05-sound-memories.spec.mjs"
INPUT_A11Y = ROOT / "tests/e2e/public-input-accessibility.spec.mjs"
SHELL_TEST = ROOT / "tests/test_public_concept05_footer_nav_fidelity.py"
TOKENS = ROOT / "css/public-concept05-tokens.css"
SHELL = ROOT / "css/public-concept05-shell.css"
PUBLIC_ASSETS = ROOT / "config/public_assets.php"
PUBLIC_HOME = ROOT / "config/public_home.php"
PERF_PROBE = ROOT / "tests/e2e/production-performance-probe.mjs"
WORKFLOW = ROOT / ".github/workflows/production-performance.yml"
EVIDENCE = ROOT / "scripts/production-performance-evidence.py"
VERSION = ROOT / "config/version.php"
PACKAGE = ROOT / "package.json"

PERF_MODULE_SPEC = importlib.util.spec_from_file_location(
    "concept05_production_performance_evidence",
    EVIDENCE,
)
assert PERF_MODULE_SPEC is not None
assert PERF_MODULE_SPEC.loader is not None
PERF_MODULE = importlib.util.module_from_spec(PERF_MODULE_SPEC)
PERF_MODULE_SPEC.loader.exec_module(PERF_MODULE)


class Concept05ReleasePolishTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.reference = json.loads(FIXTURE.read_text(encoding="utf-8"))
        cls.matrix = MATRIX.read_text(encoding="utf-8")
        cls.mobile_perf = MOBILE_PERF.read_text(encoding="utf-8")
        cls.menu = MENU.read_text(encoding="utf-8")
        cls.sound_memories = SOUND_MEMORIES.read_text(encoding="utf-8")
        cls.input_a11y = INPUT_A11Y.read_text(encoding="utf-8")
        cls.shell_test = SHELL_TEST.read_text(encoding="utf-8")
        cls.tokens = TOKENS.read_text(encoding="utf-8")
        cls.shell = SHELL.read_text(encoding="utf-8")
        cls.public_assets = PUBLIC_ASSETS.read_text(encoding="utf-8")
        cls.public_home = PUBLIC_HOME.read_text(encoding="utf-8")
        cls.perf_probe = PERF_PROBE.read_text(encoding="utf-8")
        cls.workflow = WORKFLOW.read_text(encoding="utf-8")
        cls.evidence = EVIDENCE.read_text(encoding="utf-8")
        cls.version = VERSION.read_text(encoding="utf-8")
        cls.package = json.loads(PACKAGE.read_text(encoding="utf-8"))

    def test_responsive_matrix_pins_supported_widths_and_no_overflow(self) -> None:
        release = self.reference["release_polish"]
        self.assertEqual([390, 430, 768, 1024, 1280, 1440, 1728], release["supported_widths"])
        self.assertEqual(1728, release["extended_width_min_px"])
        self.assertEqual([390, 430, 768, 1024, 1280, 1440, 1728, 1920], release["responsive_widths"])
        for width in release["responsive_widths"]:
            with self.subTest(width=width):
                self.assertRegex(self.matrix, rf"width:\s*{width}\b")
        for marker in (
            "geometry.scrollWidth",
            "geometry.viewport + 1",
            "starts outside viewport",
            "escapes viewport",
            "header collision between",
            "title escapes its owner",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, self.matrix)

    def test_visual_regression_is_deterministic_across_canonical_regions(self) -> None:
        release = self.reference["release_polish"]
        self.assertEqual([390, 1440], release["canonical_visual_widths"])
        self.assertEqual([390, 1440], release["canonical_snapshot_widths"])
        self.assertEqual("c5-visual-test", release["deterministic_visual_class"])
        self.assertEqual(
            ["hero", "next-experience", "nights", "artists", "sound", "memories", "journal", "connected", "footer"],
            release["canonical_regions"],
        )
        self.assertEqual("c5-visual-test", release["determinism"]["html_class"])
        for marker in (
            "const canonicalVisualRegions = [",
            "canonicalScreenshotBaselines",
            "captureCanonicalVisualFingerprint",
            "animations:'disabled'",
            "caret:'hide'",
            "PENDING_CALIBRATION",
            "Never auto-update these values in CI.",
        ):
            with self.subTest(marker=marker):
                self.assertIn(marker, self.matrix)
        for selector in (
            ".home-phase-a-hero", "#genesis", "#events", "#artists", "#sets",
            "#media", "#transmissions", "#connected", ".c5-footer",
        ):
            self.assertIn(f"'{selector}'", self.matrix)

    def test_accessibility_contract_preserves_focus_touch_keyboard_and_semantics(self) -> None:
        release = self.reference["release_polish"]
        invariants = set(release["accessibility_invariants"])
        accessibility = release["accessibility"]
        self.assertEqual(44, accessibility["touch_target_min_px"])
        self.assertTrue(accessibility["visible_focus"])
        self.assertTrue(accessibility["keyboard"])
        self.assertTrue(accessibility["safe_area"])
        self.assertTrue(accessibility["reduced_motion"])
        self.assertTrue(accessibility["coarse_pointer_lite_motion"])
        self.assertTrue({
            "visible-keyboard-focus",
            "touch-targets-at-least-44px",
            "menu-focus-trap-and-escape",
            "audio-failure-remains-operable",
            "mobile-safe-area",
            "reduced-motion-preserves-content",
            "no-essential-hover-only-actions",
        }.issubset(invariants))

        for marker in ("toBeGreaterThanOrEqual(44)", "outlineStyle", "outlineWidth", "reduced-motion"):
            self.assertIn(marker, self.matrix)
        for marker in ("traps focus", "aria-modal", "Escape", "toBeFocused()", "inert"):
            self.assertIn(marker, self.menu)
        for marker in ("failed audio Memory", "reduced-motion", "toBeGreaterThanOrEqual(44)"):
            self.assertIn(marker, self.sound_memories)
        for marker in ("visible keyboard focus", "keyboard skip navigation", "minimum touch target height"):
            self.assertIn(marker, self.input_a11y)
        for marker in ("safe_area", "aria-current", "bottomNav.inert = locked;"):
            self.assertIn(marker, self.shell_test)

        self.assertIn('alt="" aria-hidden="true"', self.public_assets)
        self.assertIn('data-c5-hero-documentary-image src="" alt=""', self.public_home)
        self.assertIn("$safeCover . '\" alt=\"' . $safeTitle", self.public_home)

        self.assertIn("--c5-mobile-safe-bottom:env(safe-area-inset-bottom,0px);", self.tokens)
        self.assertIn("--c5-bottom-nav-h:calc(56px + var(--c5-mobile-safe-bottom));", self.tokens)
        self.assertIn("padding-bottom:var(--c5-mobile-safe-bottom);", self.shell)
        self.assertIn("min-height:48px", self.shell)
        hover_block = self.shell.split("@media(hover:hover) and (pointer:fine){", 1)[1].split("}", 1)[0]
        self.assertNotIn("display:none", hover_block)
        self.assertNotIn("visibility:hidden", hover_block)

    def test_performance_contract_protects_lcp_media_lazy_load_and_reduced_work(self) -> None:
        release = self.reference["release_polish"]
        invariants = set(release["performance_invariants"])
        performance = release["performance"]
        self.assertEqual(["lcp", "cls", "fcp", "dom_content_loaded", "load_event_end"], performance["metrics"])
        self.assertTrue(performance["lazy_noncritical_media"])
        self.assertTrue(performance["async_media_decode"])
        self.assertTrue(performance["exact_main_evidence"])
        self.assertEqual("0.1.137", performance["minimum_release"])
        self.assertTrue({
            "hero-lcp-preloaded-and-high-priority",
            "noncritical-media-lazy",
            "local-images-receive-intrinsic-dimensions",
            "coarse-pointer-skips-nonessential-motion",
            "reduced-motion-skips-nonessential-motion",
            "production-performance-requires-post-redesign-release",
        }.issubset(invariants))

        for marker in (
            'data-brvtal-lcp-preload="desktop"',
            'data-brvtal-lcp-preload="mobile"',
            'fetchpriority="high"',
            'loading="lazy"',
            "brvtal_public_local_image_dimensions",
        ):
            self.assertIn(marker, self.public_assets)
        self.assertIn('loading="lazy"', self.public_home)
        for marker in ("touch runtime skips desktop motion downloads", "reduced-motion runtime skips desktop motion downloads"):
            self.assertIn(marker, self.mobile_perf)

        self.assertIn("largest-contentful-paint", self.perf_probe)
        self.assertIn("layout-shift", self.perf_probe)
        self.assertIn("hadRecentInput", self.perf_probe)
        self.assertIn("cumulativeLayoutShift", self.perf_probe)
        self.assertIn('--minimum-release "0.1.137"', self.workflow)
        self.assertIn("steps.prerequisites.outputs.ready == 'true'", self.workflow)
        self.assertIn("steps.source_identity.outputs.ready == 'true'", self.workflow)
        self.assertIn('"largestContentfulPaint": ("lcp", "ms")', self.evidence)
        self.assertIn('"cumulativeLayoutShift": ("cls", "ratio")', self.evidence)
        self.assertIn("release_before_minimum", self.evidence)
        self.assertIn('payload["release_contract"]', self.evidence)
        self.assertEqual((0, 1, 137), PERF_MODULE._semver_tuple("0.1.137"))
        self.assertGreater(PERF_MODULE._semver_tuple("0.1.138"), PERF_MODULE._semver_tuple("0.1.137"))
        with self.assertRaisesRegex(PERF_MODULE.EvidenceError, "^minimum_release_invalid$"):
            PERF_MODULE._semver_tuple("0.1")

        self.assertRegex(self.version, r"BRVTAL_APP_VERSION = '0\.1\.137'")
        self.assertEqual("0.1.137", self.package["version"])
        self.assertIn(
            "npm run test:concept05-release-polish",
            self.package["scripts"]["test:integration"],
        )


if __name__ == "__main__":
    unittest.main()
