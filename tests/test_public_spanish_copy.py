#!/usr/bin/env python3
"""Spanish-only authored copy regression for the Concept 05 public home.

Checks fixed public HTML and its own dynamic renderers, not CMS-authored content.
JOURNAL, proper nouns and RAVE TILL GRAVE remain unchanged.
"""
from __future__ import annotations

import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
PARTIALS = (
    "hero.html", "nav.html", "nights.html", "artists.html", "sound.html",
    "memories.html", "journal.html", "connected.html", "experience.html",
    "footer.html",
)
BANNED_VISIBLE = (
    "SCROLL TO ENTER", "NOISE / SIGNAL / MUSIC", "NAVIGATION / 00—07",
    "NO PUBLISHED NIGHTS YET.", "NO PUBLISHED ARTISTS YET.",
    "PAST NIGHTS / PERMANENT RECORD", "NO RECORDS MATCH THESE FILTERS.",
    "NO MEMORIES MATCH THESE FILTERS.", "ROSTER / PUBLISHED",
    "SOUND IS NOT BACKGROUND.", "IT IS THE", "THE NIGHT REMAINS.",
    "SEARCH MEDIA", "TITLE OR DESCRIPTION", "FIELD NOTES.",
    "TRANSMISSIONS LOADING.", "PUBLIC RELATIONAL SYSTEM / VERIFIED EDGES ONLY",
    "ONE NIGHT ONLY.", "ENTER EXPERIENCE",
    "ARCHIVE CONTINUES / MORE MEMORIES WILL BE ADDED.",
    "LAST TICKETS.", "TICKETS AVAILABLE.",
)
TEXT_NODE = re.compile(r">([^<>]+)<")
VISIBLE_ATTRIBUTE = re.compile(r"""(?:aria-label|placeholder|alt|title)=["']([^"']+)["']""")


def visible_copy(source: str) -> str:
    return chr(10).join(
        TEXT_NODE.findall(source) + VISIBLE_ATTRIBUTE.findall(source)
    ).upper()


class PublicSpanishCopyTests(unittest.TestCase):
    def test_fixed_partials_reject_english_glossary_labels(self) -> None:
        for filename in PARTIALS:
            text = (ROOT / "partials/home" / filename).read_text(encoding="utf-8")
            rendered = visible_copy(text)
            with self.subTest(partial=filename):
                for label in BANNED_VISIBLE:
                    self.assertNotIn(label, rendered, f"{filename}: {label}")

    def test_spanish_labels_and_preserved_nouns(self) -> None:
        checks = {
            "hero.html": ("DESDE 2026", "DESLIZA PARA ENTRAR", "RAVE TILL GRAVE"),
            "nav.html": ("MENÚ", "NAVEGACIÓN"),
            "nights.html": ("NOCHES PASADAS / REGISTRO PERMANENTE", "CON ARTISTAS"),
            "artists.html": ("AÚN NO HAY ARTISTAS PUBLICADOS.",),
            "sound.html": ("EL SONIDO NO ES FONDO.", "ES LA"),
            "memories.html": ("LA NOCHE PERMANECE.", "NINGUNA MEMORIA COINCIDE"),
            "journal.html": ("JOURNAL", "NOTAS DE", "CARGANDO TRANSMISIONES."),
            "connected.html": ("SISTEMA RELACIONAL PÚBLICO", "MEMORIAS"),
            "experience.html": ("PRÓXIMA EXPERIENCIA", "UNA SOLA NOCHE.", "ENTRAR"),
        }
        for filename, labels in checks.items():
            content = (ROOT / "partials/home" / filename).read_text(encoding="utf-8")
            with self.subTest(partial=filename):
                for label in labels:
                    self.assertIn(label, content)

    def test_php_js_renderers_use_spanish_fixed_copy(self) -> None:
        checks = {
            "js/public-concept05-connected.js": (
                "EVENTOS ↔ ARTISTAS", "AÚN NO HAY RELACIONES ESTRUCTURADAS"),
            "js/public-concept05-nights-artists.js": ("VER REGISTRO ↗",),
            "js/public-concept05-sound-memories.js": (
                "REPRODUCIR SET DESTACADO ↗", "CERRAR REPRODUCTOR ×",
            ),
            "js/app.js": ("ENTRADAS ↗", "EVENTO SIN TÍTULO"),
            "js/archive.js": ("TODOS LOS AÑOS", "ABRIR REGISTRO ↗", "NOCHES /"),
            "js/hero-slider.js": ("Diapositiva anterior", "Diapositiva siguiente"),
            "config/public_home.php": (
                "PRÓXIMA EXPERIENCIA / BRVTAL", "ENTRADAS DISPONIBLES.",
                "NAVEGACIÓN / ARCHIVO CULTURAL",
            ),
            "index.php": ("IR AL CONTENIDO", "ARCHIVO DE SEÑALES"),
        }
        for path, labels in checks.items():
            content = (ROOT / path).read_text(encoding="utf-8")
            with self.subTest(source=path):
                for label in labels:
                    self.assertIn(label, content)

    def test_visual_comparison_stays_fail_closed(self) -> None:
        content = (ROOT / "tests/e2e/public-concept05-fidelity-matrix.spec.mjs").read_text(
            encoding="utf-8"
        )
        self.assertIn("captureCanonicalVisualFingerprint(page)", content)
        self.assertIn("toEqual(expected)", content)
        self.assertIn("390", content)
        self.assertIn("1440", content)


if __name__ == "__main__":
    unittest.main()
