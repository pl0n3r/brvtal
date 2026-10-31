#!/usr/bin/env python3
"""Contrato #1358: capturas reproducibles, aprobación manual y sin publicación."""
from __future__ import annotations

import importlib.util
from pathlib import Path
import json
import struct
import zlib
import tempfile
import unittest
from unittest import mock

ROOT = Path(__file__).resolve().parents[1]
MODULE = ROOT / "scripts" / "capturas_aprobacion.py"
spec = importlib.util.spec_from_file_location("capturas_aprobacion", MODULE)
assert spec and spec.loader
capture_mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(capture_mod)


def fake_png(width: int) -> bytes:
    # PNG válido generado solo en memoria para los tests, no se publica.
    def chunk(name: bytes, data: bytes) -> bytes:
        return struct.pack(">I", len(data)) + name + data + struct.pack(">I", zlib.crc32(name + data))

    height = 8
    ihdr = struct.pack(">IIBBBBB", width, height, 8, 2, 0, 0, 0)
    raw = (b"\x00" + b"\x2a\x55\x78" * width) * height
    # Chunk ancillary benigno que evita confundirlo con un micro-placeholder.
    return (b"\x89PNG\r\n\x1a\n" + chunk(b"IHDR", ihdr)
            + chunk(b"tEXt", b"test:" + b"x" * 4096)
            + chunk(b"IDAT", zlib.compress(raw)) + chunk(b"IEND", b""))


class CapturasAprobacionTests(unittest.TestCase):
    def test_criterio_principal(self) -> None:
        self.assertTrue(MODULE.exists())
        text = MODULE.read_text("utf-8")
        for token in ("1440", "390", "America/Bogota", "aprobado", "pendiente",
                      "node", "playwright", "127.0.0.1", "csrf", "noindex", "os.replace"):
            self.assertIn(token, text)
        with tempfile.TemporaryDirectory() as temp:
            folder = Path(temp)
            specs = []

            def fake_runner(command, **kwargs):
                self.assertEqual(command[0], "node")
                self.assertTrue(kwargs["check"])
                info = json.loads(command[-1])
                specs.append(info)
                Path(info["output"]).write_bytes(fake_png(info["width"]))

            manifest = capture_mod.capture(
                "http://127.0.0.1:4173", {"home": "/index.php", "contacto": "/index.php?page=contact"},
                folder, runner=fake_runner,
            )
            self.assertEqual(manifest["status"], "pendiente")
            self.assertEqual(len(manifest["artifacts"]), 4)
            self.assertEqual({x["width"] for x in manifest["artifacts"]}, {1440, 390})
            self.assertEqual(len(specs), 4)
            self.assertEqual({x["origin"] for x in specs}, {"http://127.0.0.1:4173"})
            self.assertTrue((folder / "manifest.json").is_file())
            html = capture_mod.review_html(manifest, "pendiente", "token")
            self.assertIn("Estado: <strong>pendiente</strong>", html)
            self.assertIn("Confirmo personalmente", html)
            self.assertIn('method="post"', html)
            with self.assertRaisesRegex(ValueError, "confirmar"):
                capture_mod.save_decision(folder, "aprobado", "", confirmed=False)
            self.assertFalse((folder / "decision.json").exists())
            state = capture_mod.save_decision(folder, "comentario", "cambiar contraste", confirmed=False)
            self.assertEqual(state["status"], "pendiente")
            self.assertRegex(state["history"][0]["hora_colombia"], r"\d{2}/\d{2}/\d{4} \d{2}:\d{2}:\d{2} (AM|PM)")
            state = capture_mod.save_decision(folder, "aprobado", "visto", confirmed=True)
            self.assertEqual(state["status"], "aprobado")
            self.assertEqual(len(state["history"]), 2)
            self.assertIn("-05:00", state["history"][-1]["timestamp"])
            self.assertEqual(json.loads((folder / "decision.json").read_text("utf-8")), state)

    def test_falla_cerrado_en_hosts_externos_y_artefactos_falsos(self) -> None:
        for value in ("https://www.brvtal.com.co", "http://example.com", "http://127.0.0.1:4173/path",
                      "http://user:pass@127.0.0.1:4173", "file:///index.html"):
            with self.subTest(value=value), self.assertRaises(ValueError):
                capture_mod.safe_base(value)
        for bad in ("//evil.test", "/../private", "/a\\x", "https://example.com"):
            with self.subTest(path=bad), self.assertRaises(ValueError):
                capture_mod.safe_pages({"home": "/", "internal": bad})
        with tempfile.TemporaryDirectory() as tmp:
            def fake(_command, **_kwargs):
                pass
            with self.assertRaises((OSError, ValueError)):
                capture_mod.capture("http://localhost:4173", {"home": "/", "contacto": "/?page=contact"}, Path(tmp), runner=fake)
            self.assertFalse((Path(tmp) / "manifest.json").exists())
        html = capture_mod.review_html({"artifacts": [{"file": "home-390.png", "page": "<script>x</script>", "width": 390}]}, "pendiente", "z")
        self.assertNotIn("<script>x</script>", html)
        self.assertIn("&lt;script&gt;", html)


if __name__ == "__main__":
    unittest.main()
