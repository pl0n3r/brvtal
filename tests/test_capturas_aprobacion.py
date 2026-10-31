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
assert spec is not None
assert spec.loader is not None
capture_mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(capture_mod)


def fake_png(width: int, height: int = 8) -> bytes:
    # PNG válido generado solo en memoria para los tests, no se publica.
    def chunk(name: bytes, data: bytes) -> bytes:
        return struct.pack(">I", len(data)) + name + data + struct.pack(">I", zlib.crc32(name + data))

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
                Path(info["output"]).write_bytes(fake_png(info["width"], info["height"]))

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



    def test_recaptura_invalida_aprobacion(self) -> None:
        with tempfile.TemporaryDirectory() as temp:
            folder = Path(temp)
            def runner(command, **kwargs):
                info = json.loads(command[-1])
                known_paths = [
                    folder / f"{page}-{device}-{width}.png"
                    for page in ("home", "contacto")
                    for device, (width, _height) in capture_mod.WIDTHS.items()
                ]
                target = next((path for path in known_paths if str(path) == info["output"]), None)
                if target is None:
                    raise ValueError("Runner output fuera de fixtures enumerados")
                target.write_bytes(fake_png(info["width"], info["height"]))
            pages = {"home": "/", "contacto": "/?page=contact"}
            capture_mod.capture("http://127.0.0.1:4173", pages, folder, runner=runner)
            capture_mod.save_decision(folder, "aprobado", "ronda inicial", True)
            self.assertEqual(json.loads((folder / "decision.json").read_text())["status"], "aprobado")
            def missing(_command, **_kwargs):
                return None
            with self.assertRaises((OSError, ValueError)):
                capture_mod.capture("http://127.0.0.1:4173", pages, folder, runner=missing)
            self.assertFalse((folder / "decision.json").exists())
            self.assertFalse((folder / "manifest.json").exists())
            self.assertFalse(list(folder.glob("*.png")))
            self.assertEqual(len(list(folder.glob("decision-archived-*.json"))), 1)
            fresh = capture_mod.capture("http://127.0.0.1:4173", pages, folder, runner=runner)
            self.assertEqual(fresh["status"], "pendiente")
            self.assertFalse((folder / "decision.json").exists())

    def test_http_loopback_seguridad_y_concurrencia(self) -> None:
        import http.client
        import re
        import threading
        from concurrent.futures import ThreadPoolExecutor
        from urllib.parse import urlencode
        with tempfile.TemporaryDirectory() as temp:
            folder = Path(temp)
            def runner(command, **_kwargs):
                info = json.loads(command[-1])
                known_paths = [
                    folder / f"{page}-{device}-{width}.png"
                    for page in ("home", "contacto")
                    for device, (width, _height) in capture_mod.WIDTHS.items()
                ]
                target = next((path for path in known_paths if str(path) == info["output"]), None)
                if target is None:
                    raise ValueError("Runner output fuera de fixtures enumerados")
                target.write_bytes(fake_png(info["width"], info["height"]))
            capture_mod.capture("http://localhost:4173", {"home": "/", "contacto": "/contacto"}, folder, runner=runner)
            server = capture_mod.build_server(folder, 0)
            thread = threading.Thread(target=server.serve_forever, daemon=True)
            thread.start()
            try:
                port = server.server_port
                def request(method, path, payload=None, headers=None):
                    conn = http.client.HTTPConnection("127.0.0.1", port, timeout=5)
                    try:
                        conn.request(method, path, payload, headers or {})
                        resp = conn.getresponse()
                        return resp.status, resp.read().decode("utf-8", "replace")
                    finally:
                        conn.close()
                status, html = request("GET", "/")
                self.assertEqual(status, 200)
                csrf = re.search(r'name="csrf" value="([^"]+)"', html).group(1)
                self.assertEqual(request("GET", "/", headers={"Host": "example.test"})[0], 403)
                self.assertEqual(request("GET", "/captures/../manifest.json")[0], 404)
                image = folder / "home-movil-390.png"
                image.unlink()
                image.symlink_to(folder / "manifest.json")
                self.assertEqual(request("GET", "/captures/home-movil-390.png")[0], 404)
                def post(fields):
                    data = urlencode(fields)
                    return request("POST", "/decision", data,
                                   {"Content-Type": "application/x-www-form-urlencoded"})[0]
                self.assertEqual(post({"csrf": "ñ", "decision": "comentario"}), 400)
                self.assertEqual(post({"csrf": "wrong", "decision": "comentario"}), 400)
                self.assertEqual(request("POST", "/decision", "x" * (capture_mod.MAX_POST + 1),
                    {"Content-Type": "application/x-www-form-urlencoded"})[0], 400)
                self.assertEqual(post({"csrf": csrf, "decision": "comentario", "comentario": "ok"}), 303)
                with ThreadPoolExecutor(max_workers=8) as pool:
                    statuses = list(pool.map(lambda i: post({"csrf": csrf, "decision": "comentario",
                                                             "comentario": str(i)}), range(8)))
                self.assertEqual(statuses, [303] * 8)
                state = json.loads((folder / "decision.json").read_text())
                self.assertEqual(len(state["history"]), 9)
                self.assertEqual({str(i) for i in range(8)},
                                 {r["comentario"] for r in state["history"][1:]})
            finally:
                server.shutdown()
                server.server_close()
                thread.join(timeout=5)

    def test_png_corrupto_y_symlink_fallan_cerrado(self) -> None:
        self.assertFalse(capture_mod.valid_png(b"not png", 390, 844))
        valid = fake_png(390, 844)
        broken = bytearray(valid)
        broken[30] ^= 1
        self.assertFalse(capture_mod.valid_png(bytes(broken), 390, 844))
        self.assertFalse(capture_mod.valid_png(valid, 1440, 900))
        with tempfile.TemporaryDirectory() as temp:
            folder = Path(temp)
            (folder / "manifest.json").symlink_to(folder / "missing.json")
            with self.assertRaises(ValueError):
                capture_mod.capture("http://localhost:4173", {"home": "/", "contacto": "/contacto"},
                                    folder, runner=lambda *_a, **_kw: None)

if __name__ == "__main__":
    unittest.main()
