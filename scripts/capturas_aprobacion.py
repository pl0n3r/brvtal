#!/usr/bin/env python3
"""Revisión OFFLINE del home BRVTAL; nunca concede aprobación por defecto.

Captura un servidor PHP local real mediante Playwright/Chromium, guarda solo
ficheros locales y presenta el conjunto al propietario en 127.0.0.1. No
publica ni modifica contenido, selecciones o despliegues.
"""
from __future__ import annotations

import argparse
from datetime import datetime
import hashlib
from html import escape
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import os
from pathlib import Path
import re
import secrets
import subprocess
import tempfile
from urllib.parse import parse_qs, urlparse
from zoneinfo import ZoneInfo

WIDTHS = {"escritorio": (1440, 900), "movil": (390, 844)}
DEFAULT_PAGES = {"home": "/index.php", "contacto": "/index.php?page=contact"}
MAX_POST = 8192
LOCAL_HOSTS = frozenset(("127.0.0.1", "localhost", "::1"))
CHROMIUM_SCRIPT = r"""
import { chromium } from 'playwright';
const spec = JSON.parse(process.argv[1]);
const browser = await chromium.launch({headless:true});
try {
  const context = await browser.newContext({viewport:{width:spec.width,height:spec.height},
    deviceScaleFactor:1,serviceWorkers:'block'});
  await context.route('**/*', route => {
    const url = new URL(route.request().url());
    return url.origin === spec.origin ? route.continue() : route.abort();
  });
  const page = await context.newPage();
  const response = await page.goto(spec.url,{waitUntil:'domcontentloaded',timeout:30000});
  if (!response || response.status() !== 200 || new URL(page.url()).origin !== spec.origin) {
    throw Error('Página no disponible/redirect externo: '+spec.url);
  }
  await page.locator('body').waitFor({timeout:10000});
  await page.screenshot({path:spec.output,fullPage:true,animations:'disabled'});
  await context.close();
} finally {await browser.close();}
"""


def safe_base(url: str) -> str:
    p = urlparse(url)
    if p.scheme != "http" or p.hostname not in LOCAL_HOSTS or p.username or p.password or p.query or p.fragment:
        raise ValueError("Se exige servidor HTTP local sin credenciales ni parámetros")
    if p.path not in ("", "/"):
        raise ValueError("La URL base no debe contener rutas")
    return url.rstrip("/")


def safe_pages(pages: dict[str, str]) -> dict[str, str]:
    if not 2 <= len(pages) <= 12 or "home" not in pages:
        raise ValueError("Se requiere home y al menos una página interna (máximo 12)")
    for name, path in pages.items():
        if not re.fullmatch(r"[a-z0-9-]{1,42}", name):
            raise ValueError("Nombre de página inseguro")
        if not path.startswith("/") or path.startswith("//") or "\\" in path or ".." in path:
            raise ValueError("Ruta fuera del servidor local")
        p = urlparse(path)
        if p.scheme or p.netloc or p.fragment or not re.fullmatch(r"[A-Za-z0-9/_?.=&%-]+", path):
            raise ValueError("Ruta o consulta no permitida")
    return pages


def capture(base_url: str, pages: dict[str, str], output: Path, *, runner=subprocess.run) -> dict:
    base_url = safe_base(base_url)
    pages = safe_pages(pages)
    output = Path(output)
    if output.is_symlink():
        raise ValueError("Directorio de salida no debe ser un enlace")
    output = output.resolve()
    output.mkdir(parents=True, exist_ok=True)
    origin = base_url
    artifacts = []
    for name, path in pages.items():
        for size, (width, height) in WIDTHS.items():
            filename = f"{name}-{size}-{width}.png"
            target = output / filename
            if target.is_symlink():
                raise ValueError("No sobrescribir symlinks")
            spec = {"origin": origin, "url": base_url + path, "output": str(target),
                    "width": width, "height": height}
            runner(["node", "--input-type=module", "-e", CHROMIUM_SCRIPT, json.dumps(spec)],
                   check=True, timeout=90)
            data = target.read_bytes()
            if len(data) < 4096 or not data.startswith(b"\x89PNG\r\n\x1a\n"):
                raise ValueError(f"Captura vacía o no PNG: {filename}")
            actual_width = int.from_bytes(data[16:20], "big")
            if actual_width != width:
                raise ValueError(f"Viewport incorrecto: {filename}")
            artifacts.append({"page": name, "path": path, "device": size,
                              "width": width, "file": filename,
                              "sha256": hashlib.sha256(data).hexdigest()})
    manifest = {"version": 1, "status": "pendiente", "origin": base_url,
                "artifacts": artifacts, "generated_at": datetime.now(ZoneInfo("America/Bogota")).isoformat()}
    atomic_json(output / "manifest.json", manifest)
    return manifest


def atomic_json(path: Path, payload: dict) -> None:
    path = Path(path)
    if path.is_symlink() or path.parent.is_symlink():
        raise ValueError("No escribir siguiendo symlinks")
    data = json.dumps(payload, indent=2, ensure_ascii=False).encode("utf-8")
    fd, temp = tempfile.mkstemp(prefix=".revision-", dir=path.parent)
    try:
        with os.fdopen(fd, "wb") as f:
            f.write(data)
            f.flush()
            os.fsync(f.fileno())
        os.chmod(temp, 0o600)
        os.replace(temp, path)
    finally:
        if os.path.exists(temp):
            os.unlink(temp)


def save_decision(output: Path, action: str, comment: str, confirmed: bool) -> dict:
    if action not in ("comentario", "aprobado") or len(comment) > 2000:
        raise ValueError("Decisión inválida")
    if action == "aprobado" and not confirmed:
        raise ValueError("El dueño debe confirmar explícitamente el aprobado")
    state_file = output / "decision.json"
    previous = json.loads(state_file.read_text("utf-8")) if state_file.exists() else {"history": []}
    if not isinstance(previous.get("history"), list) or len(previous["history"]) > 100:
        raise ValueError("Historial inválido")
    now = datetime.now(ZoneInfo("America/Bogota"))
    record = {"decision": action, "comentario": comment.strip(),
              "hora_colombia": now.strftime("%d/%m/%Y %I:%M:%S %p"),
              "timestamp": now.isoformat(), "declaracion_local": bool(confirmed)}
    previous["history"] = (previous["history"] + [record])[-100:]
    previous["status"] = "aprobado" if action == "aprobado" else previous.get("status", "pendiente")
    atomic_json(state_file, previous)
    return previous


def review_html(manifest: dict, status: str, csrf: str) -> str:
    slides = []
    for a in manifest.get("artifacts", []):
        file = a["file"]
        if not re.fullmatch(r"[a-z0-9-]+\.png", file):
            raise ValueError("Archivo de captura inesperado")
        slides.append(f'<figure><img src="/captures/{escape(file)}" alt="Captura {escape(a["page"])} en {a["width"]} píxeles" loading="lazy"><figcaption>{escape(a["page"])} · {a["width"]} px</figcaption></figure>')
    return ("<!doctype html><html lang='es'><head><meta charset='utf-8'>"
            "<meta name='viewport' content='width=device-width,initial-scale=1'>"
            "<meta name='robots' content='noindex,nofollow'><title>BRVTAL · Visto bueno</title>"
            "<style>body{background:#111;color:#fff;font:16px system-ui;margin:2rem}"
            "main{max-width:1440px;margin:auto}figure{border:1px solid #777;padding:1rem;margin:2rem 0}"
            "img{display:block;width:100%;height:auto}textarea{display:block;width:100%;min-height:8rem}"
            "button{min-height:44px;padding:.75rem}label{display:block;margin:1rem 0}</style></head><body>"
            "<main><h1>Capturas de revisión · BRVTAL</h1>"
            f"<p>Estado: <strong>{escape(status)}</strong>. Sin confirmación expresa no hay aprobación."
            " La identidad del revisor es una declaración local, no autenticación jurídica.</p>"
            + "".join(slides) +
            '<form action="/decision" method="post"><h2>Decisión del dueño</h2>'
            f'<input type="hidden" name="csrf" value="{escape(csrf)}">'
            '<label>Resultado<select name="decision"><option value="comentario">Dejar comentario</option>'
            '<option value="aprobado">Aprobado</option></select></label>'
            '<label>Comentarios<textarea name="comentario" maxlength="2000"></textarea></label>'
            '<label><input type="checkbox" name="confirmado" value="si"> Confirmo personalmente esta decisión</label>'
            '<button type="submit">Guardar decisión local</button></form></main></body></html>')


def serve_review(output: Path, port: int = 0) -> None:
    output = output.resolve()
    manifest = json.loads((output / "manifest.json").read_text("utf-8"))
    allowed = {a["file"] for a in manifest["artifacts"]}
    if any(not re.fullmatch(r"[a-z0-9-]+\.png", file) for file in allowed):
        raise ValueError("Manifest contiene rutas no permitidas")
    csrf = secrets.token_urlsafe(32)

    class Handler(BaseHTTPRequestHandler):
        def _host_valid(self) -> bool:
            expected = f"127.0.0.1:{self.server.server_port}"
            return self.headers.get("Host") == expected

        def do_GET(self) -> None:
            if not self._host_valid():
                self.send_error(403)
                return
            if self.path == "/":
                state_file = output / "decision.json"
                state = json.loads(state_file.read_text("utf-8")) if state_file.is_file() else {}
                content = review_html(manifest, state.get("status", "pendiente"), csrf).encode()
                kind = "text/html; charset=utf-8"
            elif self.path.startswith("/captures/") and self.path[len("/captures/"):] in allowed:
                filename = self.path[len("/captures/"):]
                target = output / filename
                if target.is_symlink():
                    self.send_error(404)
                    return
                content = target.read_bytes()
                kind = "image/png"
            else:
                self.send_error(404)
                return
            self.send_response(200)
            self.send_header("Content-Type", kind)
            self.send_header("Cache-Control", "no-store")
            self.send_header("X-Content-Type-Options", "nosniff")
            self.send_header("Content-Security-Policy", "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'")
            self.send_header("Content-Length", str(len(content)))
            self.end_headers()
            self.wfile.write(content)

        def do_POST(self) -> None:
            if not self._host_valid():
                self.send_error(403)
                return
            if self.path != "/decision" or self.headers.get("Content-Type") != "application/x-www-form-urlencoded":
                self.send_error(404)
                return
            try:
                length = int(self.headers.get("Content-Length", "0"))
                if not 0 < length <= MAX_POST:
                    raise ValueError("Tamaño inválido")
                form = parse_qs(self.rfile.read(length).decode("utf-8"), strict_parsing=True)
                if any(len(values) != 1 for values in form.values()) or not secrets.compare_digest(form.get("csrf", [""])[0], csrf):
                    raise ValueError("CSRF inválido")
                save_decision(output, form.get("decision", [""])[0], form.get("comentario", [""])[0], form.get("confirmado") == ["si"])
            except (ValueError, UnicodeError, OSError, KeyError, json.JSONDecodeError):
                self.send_error(400)
                return
            self.send_response(303)
            self.send_header("Location", "/")
            self.send_header("Cache-Control", "no-store")
            self.end_headers()

    server = ThreadingHTTPServer(("127.0.0.1", port), Handler)
    try:
        print(f"Revisión privada: http://127.0.0.1:{server.server_port}/", flush=True)
        server.serve_forever()
    finally:
        server.server_close()


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    subs = parser.add_subparsers(dest="action", required=True)
    cap = subs.add_parser("capture")
    cap.add_argument("--base-url", required=True, help="URL del servidor PHP LOCAL (no producción)")
    cap.add_argument("--output", type=Path, required=True)
    cap.add_argument("--page", action="append", default=[], help="nombre=/ruta, repetible")
    rev = subs.add_parser("review")
    rev.add_argument("--output", type=Path, required=True)
    rev.add_argument("--port", type=int, default=0)
    args = parser.parse_args()
    if args.action == "capture":
        pages = dict(DEFAULT_PAGES)
        for spec in args.page:
            name, sep, path = spec.partition("=")
            if not sep or name in pages:
                parser.error("Página malformada o duplicada")
            pages[name] = path
        result = capture(args.base_url, pages, args.output)
        print(f"Capturas reales guardadas: {len(result['artifacts'])}; estado pendiente")
    else:
        serve_review(args.output, args.port)


if __name__ == "__main__":
    main()
