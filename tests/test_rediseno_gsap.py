"""BRVTAL #1366: contrato estático de movimiento sin red ni servicios externos."""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FILES = (
    "public-concept05-hero.js",
    "public-concept05-motion.js",
    "input-accessibility.js",
    "mobile-events.js",
    "app.js",
    "public-runtime-loader.js",
    "public-brvtal-impact.js",
)
SCENES = (
    "public-concept05-hero.js",
    "public-concept05-motion.js",
    "app.js",
    "public-brvtal-impact.js",
)
GSAP_TWEEN = re.compile(r"(?:window\.)?gsap\.(?:to|from|fromTo)\s*\(", re.I)
LAYOUT = re.compile(r"(?<![\w-])(?:width|height|top|left)\s*:", re.I)


def source(filename: str) -> str:
    return (ROOT / "js" / filename).read_text(encoding="utf-8")


class RedisenoGsapTests(unittest.TestCase):
    def test_criterio_principal(self):
        """Revisa los siete archivos: media, cleanup y solo transform/opacity."""
        for name in FILES:
            with self.subTest(file=name):
                js = source(name)
                self.assertTrue(js.strip(), "Archivo de JS vacío")
                for call in GSAP_TWEEN.finditer(js):
                    snippet = js[call.end():call.end() + 320]
                    self.assertNotRegex(snippet, LAYOUT, "GSAP no debe animar layout")
                if name in SCENES:
                    self.assertIn("prefers-reduced-motion", js)
                    self.assertIn("gsap.matchMedia()", js)
                    self.assertIn("context.revert()", js)
                    self.assertIn("pagehide", js)
                else:
                    self.assertNotRegex(js, GSAP_TWEEN)

    def test_un_timeline_por_secuencia_y_reversion(self):
        hero = source("public-concept05-hero.js")
        foundation = source("public-concept05-motion.js")
        impact = source("public-brvtal-impact.js")
        self.assertIn("sequence = gsap.timeline()", hero)
        self.assertIn("sequence = gsap.timeline(", foundation)
        self.assertIn("sequence = gsap.timeline()", impact)
        for filename in SCENES:
            self.assertIn("gsap.context(", source(filename))
            self.assertIn("context.revert()", source(filename))

    def test_sin_pintado_caro_ni_listener_scroll_filtrado(self):
        impact = source("public-brvtal-impact.js")
        self.assertNotIn("clipPath:", impact)
        self.assertNotIn("textShadow:", impact)
        self.assertNotIn("addEventListener('scroll'", impact)
        self.assertIn("clearTimeout(glitchStart)", impact)
        self.assertIn("scrub: true", impact)

    def test_scrolltrigger_refresca_y_se_limpia(self):
        for filename in ("public-concept05-motion.js", "app.js", "public-brvtal-impact.js"):
            with self.subTest(file=filename):
                js = source(filename)
                self.assertIn("ScrollTrigger", js)
                self.assertIn("refresh()", js)
                self.assertIn("pagehide", js)

    def test_scroller_movil_acepta_cambio_reduced_motion(self):
        js = source("mobile-events.js")
        self.assertIn("reducedMotionQuery.matches", js)
        self.assertIn("trigger.kill(true)", js)
        self.assertIn("removeEventListener('resize', syncViewport)", js)

    def test_progreso_cargador_transform_y_cleanup(self):
        js = source("app.js")
        self.assertIn("bar.style.transform =", js)
        self.assertIn("scaleX(", js)
        self.assertNotIn("bar.style.width = n + '%'", js)
        self.assertIn("gsap.ticker.remove(lenisTick)", js)
        self.assertIn("lenis.destroy()", js)

    def test_loader_e_input_no_definen_tweens(self):
        for filename in ("input-accessibility.js", "public-runtime-loader.js"):
            with self.subTest(file=filename):
                self.assertNotRegex(source(filename), GSAP_TWEEN)
                self.assertIn("matchMedia", source(filename))


if __name__ == "__main__":
    unittest.main()
