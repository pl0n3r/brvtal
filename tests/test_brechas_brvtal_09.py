#!/usr/bin/env python3
"""BRVTAL #1099: five S07 public-page gaps, offline evidence only.

Exercise the real PHP route-definition registry with synthetic, in-memory
inputs. A configured route is not a published record, a browser response,
a completed deploy or permission to promote a matrix row to CUBIERTO.
"""
from __future__ import annotations

import ast
import json
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
IDS = {
    "S07-R003": ("Media / Archive;", None, None, None),
    "S07-R005": ("individual Artist;", "artists", "artists", "MusicGroup"),
    "S07-R006": ("individual Set;", "sets", "sets_media", "MusicRecording"),
    "S07-R007": ("individual Release;", "releases", "releases", "MusicAlbum"),
    "S07-R008": ("individual Blog post;", "blog", "blog_posts", "BlogPosting"),
}
UNKNOWN = "UNKNOWN"
LOCAL_ROUTE_ONLY = "LOCAL_ROUTE_ONLY"
SECTION_ONLY = "SECTION_ONLY"
MATRIX = ROOT / "docs/matriz-cobertura.md"
SPEC = ROOT / "docs/BRVTAL-SPEC.md"
CONTRAST = ROOT / "docs/contraste-spec_resto.md"
ENTRY = ROOT / "index.php"
CONTRACT = ROOT / "tests/public-entity-pages-contract.php"
ROUTES = ROOT / "config/public_routes.php"
SEO = ROOT / "config/public_seo.php"

PHP_PROBE = r"""
require 'config/public_routes.php';
$registry = brvtal_public_content_definitions(
    static fn(string $name): ?string => null
);
echo json_encode($registry, JSON_THROW_ON_ERROR);
"""


def matrix_rows(raw: object) -> dict[str, tuple[str, ...]]:
    if type(raw) is not str:
        raise ValueError("UNKNOWN: non-text matrix")
    found = {}
    for identifier, (title, *_rest) in IDS.items():
        lines = [line for line in raw.splitlines()
                 if line.startswith("| " + identifier + " · ")]
        if len(lines) != 1:
            raise ValueError("UNKNOWN: missing or duplicated " + identifier)
        cells = tuple(part.strip() for part in lines[0].strip("|").split("|"))
        if (len(cells) != 5 or cells[1] != title
                or cells[2] != "NO_VERIFICABLE"
                or "Sin aserción contrastada" not in cells[3]
                or "[contraste](contraste-spec_resto.md)" not in cells[3]):
            raise ValueError("UNKNOWN: malformed or promoted " + identifier)
        found[identifier] = cells
    return found


def contrast_rows(raw: object) -> dict[str, tuple[str, ...]]:
    if type(raw) is not str:
        raise ValueError("UNKNOWN: non-text contrast")
    found = {}
    for identifier in IDS:
        lines = [line for line in raw.splitlines()
                 if line.startswith("| " + identifier + " |")]
        if len(lines) != 1:
            raise ValueError("UNKNOWN: missing or duplicated contrast " + identifier)
        cells = tuple(part.strip() for part in lines[0].strip("|").split("|"))
        if (len(cells) != 6 or cells[0] != identifier
                or cells[1] != "NO_VERIFICABLE"
                or "no prueba una página publicada" not in cells[5]
                or "404/noindex" not in cells[5]):
            raise ValueError("UNKNOWN: invalid contrast " + identifier)
        found[identifier] = cells
    return found


def php_registry() -> dict[str, object]:
    """Read real PHP policy without opening HTTP, a DB or a browser."""
    result = subprocess.run(
        ["php", "-r", PHP_PROBE], cwd=ROOT, text=True,
        capture_output=True, timeout=20, check=False,
    )
    if result.returncode != 0:
        raise AssertionError("UNKNOWN: PHP registry probe failed")
    try:
        value = json.loads(result.stdout)
    except (ValueError, TypeError) as exc:
        raise AssertionError("UNKNOWN: malformed PHP registry") from exc
    if type(value) is not dict:
        raise AssertionError("UNKNOWN: PHP registry is not an object")
    return value


def classify(identifier: object, documents: object, registry: object) -> str:
    """Only classify locally observed contracts, never full-page readiness."""
    if (type(identifier) is not str or identifier not in IDS
            or type(documents) is not dict or type(registry) is not dict):
        return UNKNOWN
    paths = ("matrix", "spec", "contrast", "index", "contract", "routes", "seo")
    if any(type(documents.get(path)) is not str or not documents[path]
           for path in paths):
        return UNKNOWN
    try:
        matrix_rows(documents["matrix"])
        contrast_rows(documents["contrast"])
    except ValueError:
        return UNKNOWN
    title, route, table, schema = IDS[identifier]
    if ("## 7. Initial page model" not in documents["spec"]
            or "- " + title not in documents["spec"]
            or "http_response_code(404)" not in documents["index"]
            or "X-Robots-Tag: noindex, follow" not in documents["index"]
            or "brvtalPublicEntityDocument(" not in documents["index"]):
        return UNKNOWN
    if route is None:
        if ("VISUAL ARCHIVE" not in documents["index"]
                or "public entity pages" not in documents["contract"]):
            return UNKNOWN
        # A Home archive section alone cannot certify a standalone public page.
        return SECTION_ONLY
    record = registry.get(route)
    if (type(record) is not dict
            or record.get("table") != table
            or record.get("schema_type") != schema
            or record.get("where") != "status='published'"
            or record.get("parameters") != []
            or record.get("event_visibility") is not False
            or "brvtal_public_seo_entity" not in documents["seo"]
            or "brvtal_public_not_found_seo" not in documents["seo"]
            or "brvtal_public_entity_page(" not in documents["contract"]):
        return UNKNOWN
    return LOCAL_ROUTE_ONLY


def operational_evidence(_identifier: object, _evidence: object = None) -> str:
    """No synthetic fixture can attest 404, indexing, live or actual pages."""
    return UNKNOWN


def documents() -> dict[str, str]:
    return {
        "matrix": MATRIX.read_text(encoding="utf-8"),
        "spec": SPEC.read_text(encoding="utf-8"),
        "contrast": CONTRAST.read_text(encoding="utf-8"),
        "index": ENTRY.read_text(encoding="utf-8"),
        "contract": CONTRACT.read_text(encoding="utf-8"),
        "routes": ROUTES.read_text(encoding="utf-8"),
        "seo": SEO.read_text(encoding="utf-8"),
    }


class BrechasBrvtal09Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        docs, registry = documents(), php_registry()
        self.assertEqual(set(IDS), set(matrix_rows(docs["matrix"])))
        self.assertEqual(set(IDS), set(contrast_rows(docs["contrast"])))
        for identifier in IDS:
            with self.subTest(requirement=identifier):
                expected = SECTION_ONLY if identifier == "S07-R003" else LOCAL_ROUTE_ONLY
                self.assertEqual(expected, classify(identifier, docs, registry))
                self.assertEqual(UNKNOWN, operational_evidence(identifier, registry))

    def test_falla_cerrado(self) -> None:
        docs, registry = documents(), php_registry()
        for identifier in IDS:
            with self.subTest(requirement=identifier):
                line = next(row for row in docs["matrix"].splitlines()
                            if row.startswith("| " + identifier + " · "))
                for damaged in (
                    docs["matrix"].replace(line, "", 1),
                    docs["matrix"] + "\n" + line,
                    docs["matrix"].replace(line, line.replace(
                        "NO_VERIFICABLE", "CUBIERTO", 1), 1),
                ):
                    with self.assertRaisesRegex(ValueError, UNKNOWN):
                        matrix_rows(damaged)
                    self.assertEqual(UNKNOWN, classify(
                        identifier, {**docs, "matrix": damaged}, registry))
                contrast = next(row for row in docs["contrast"].splitlines()
                                if row.startswith("| " + identifier + " |"))
                self.assertEqual(UNKNOWN, classify(
                    identifier, {**docs, "contrast":
                                 docs["contrast"].replace(contrast, "", 1)}, registry))
                self.assertEqual(UNKNOWN, classify(
                    identifier, {**docs, "index": ""}, registry))
                self.assertEqual(UNKNOWN, classify(identifier, docs, {}))
        self.assertEqual(UNKNOWN, classify("S07-R004", docs, registry))
        for route in ("artists", "sets", "releases", "blog"):
            with self.subTest(route=route):
                identifier = next(k for k, value in IDS.items() if value[1] == route)
                fake = {**registry, route: {**registry[route], "where": "status<>'draft'"}}
                self.assertEqual(UNKNOWN, classify(identifier, docs, fake))
                fake = {**registry, route: {**registry[route], "schema_type": "Unknown"}}
                self.assertEqual(UNKNOWN, classify(identifier, docs, fake))

    def test_sin_datos_reales(self) -> None:
        src = (ROOT / "tests/test_brechas_brvtal_09.py").read_text(encoding="utf-8")
        imports = set()
        for node in ast.walk(ast.parse(src)):
            if isinstance(node, ast.Import):
                imports.update(alias.name.split(".")[0] for alias in node.names)
            if isinstance(node, ast.ImportFrom) and node.module:
                imports.add(node.module.split(".")[0])
        self.assertFalse(imports & {
            "requests", "urllib", "socket", "httpx", "pymysql", "mysql",
            "playwright", "selenium",
        })
        self.assertNotIn("BEGIN " + "PRIVATE KEY", src)
        self.assertNotIn("api.github.com/repos/", src)
        self.assertEqual(set(documents()), {
            "matrix", "spec", "contrast", "index", "contract", "routes", "seo",
        })

    def test_estados_unknown_explicitos(self) -> None:
        docs, registry = documents(), php_registry()
        for identifier in IDS:
            self.assertEqual("NO_VERIFICABLE", matrix_rows(docs["matrix"])[identifier][2])
            for forged in (None, True, {}, {"ci": "success"},
                           {"http": 200, "index": True}, {"deploy": "live"},
                           {"404": True, "noindex": True}):
                with self.subTest(row=identifier, evidence=forged):
                    self.assertEqual(UNKNOWN, operational_evidence(identifier, forged))
        self.assertEqual(UNKNOWN, classify(None, docs, registry))


if __name__ == "__main__":
    unittest.main()
