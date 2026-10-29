#!/usr/bin/env python3
"""BRVTAL #1098: offline contracts for five S07 initial public pages.

A route definition or synthetic fixture is not a published CMS record, HTTP
404/noindex observation, complete Legal page or deployed production evidence.
"""
from __future__ import annotations

import ast
import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
UNKNOWN = "UNKNOWN"
LOCAL_ROUTE_ONLY = "LOCAL_ROUTE_ONLY"
LOCAL_STATIC_ONLY = "LOCAL_STATIC_ONLY"

# The initial page model and matrix own these exact, still-unverified rows.
ROWS = {
    "S07-L80": ("Releases;", "releases", "releases", "MusicAlbum"),
    "S07-L82": ("Contact;", "contact", None, None),
    "S07-L83": ("Legal;", None, None, None),
    "S07-L84": ("Blog;", "blog", "blog_posts", "BlogPosting"),
    "S07-R002": ("Sets / Music;", "sets", "sets_media", "MusicRecording"),
}
FILES = {
    "matrix": "docs/matriz-cobertura.md",
    "contrast": "docs/contraste-spec_resto.md",
    "spec": "docs/BRVTAL-SPEC.md",
    "index": "index.php",
    "contract": "tests/public-entity-pages-contract.php",
    "routes": "config/public_routes.php",
    "locale": "config/public_i18n_routing.php",
}

# Execute actual pure PHP route policy in memory; never bootstrap the app/DB.
PHP_PROBE = r"""
require_once 'config/public_routes.php';
require_once 'config/public_i18n_routing.php';
$base = 'https://example.test';
$registry = brvtal_public_content_definitions(
    static fn(string $name): ?string => null
);
$routes = [];
$denied = [];
foreach (['releases', 'blog', 'sets'] as $type) {
    $routes[$type] = [
        'es' => brvtalPublicLocalizedRouteUrls($base, 'es', $type, 'synthetic-record'),
        'en' => brvtalPublicLocalizedRouteUrls($base, 'en', $type, 'synthetic-record'),
    ];
    $denied[$type] = brvtalPublicLocalizedRouteUrls(
        $base, 'es', $type, '../private'
    ) === null;
}
$routes['contact'] = [
    'es' => brvtalPublicLocalizedRouteUrls($base, 'es', '', '', 'contact'),
    'en' => brvtalPublicLocalizedRouteUrls($base, 'en', '', '', 'contact'),
];
$denied['contact'] = brvtalPublicLocalizedRouteUrls(
    $base, 'es', '', 'synthetic-record', 'contact'
) === null;
$denied['legal'] = brvtalPublicLocalizedRouteUrls(
    $base, 'es', '', '', 'legal'
) === null && !in_array('/legal', brvtal_public_static_routes(), true)
    && !array_key_exists('legal', $registry);
echo json_encode([
    'registry' => $registry,
    'static' => brvtal_public_static_routes(),
    'routes' => $routes,
    'denied' => $denied,
], JSON_THROW_ON_ERROR);
"""


def documents() -> dict[str, str]:
    return {
        name: (ROOT / path).read_text(encoding="utf-8")
        for name, path in FILES.items()
    }


def matrix_rows(raw: object) -> dict[str, tuple[str, ...]]:
    if type(raw) is not str:
        raise ValueError("UNKNOWN: unreadable matrix")
    found = {}
    for key, (label, *_rest) in ROWS.items():
        matches = [line for line in raw.splitlines()
                   if line.startswith("| " + key + " · ")]
        if len(matches) != 1:
            raise ValueError("UNKNOWN: missing or duplicated matrix row " + key)
        cells = tuple(x.strip() for x in matches[0].strip("|").split("|"))
        if (len(cells) != 5 or cells[1] != label
                or cells[2] != "NO_VERIFICABLE"
                or "Sin aserción contrastada" not in cells[3]
                or "tests/public-entity-pages-contract.php" not in cells[3]
                or "[contraste](contraste-spec_resto.md)" not in cells[3]):
            raise ValueError("UNKNOWN: invalid matrix row " + key)
        found[key] = cells
    return found


def contrast_rows(raw: object) -> dict[str, tuple[str, ...]]:
    if type(raw) is not str:
        raise ValueError("UNKNOWN: unreadable contrast")
    found = {}
    for key in ROWS:
        matches = [line for line in raw.splitlines()
                   if line.startswith("| " + key + " |")]
        if len(matches) != 1:
            raise ValueError("UNKNOWN: missing or duplicated contrast " + key)
        cells = tuple(x.strip() for x in matches[0].strip("|").split("|"))
        if (len(cells) != 6 or cells[1] != "NO_VERIFICABLE"
                or "no prueba una página publicada" not in cells[5]
                or "404/noindex" not in cells[5]):
            raise ValueError("UNKNOWN: invalid contrast " + key)
        found[key] = cells
    return found


def php_observation() -> dict[str, object]:
    php = shutil.which("php")
    if php is None:
        raise AssertionError("UNKNOWN: PHP CLI not installed")
    process = subprocess.run(
        [php, "-n", "-d", "allow_url_fopen=0", "-r", PHP_PROBE],
        cwd=ROOT, capture_output=True, text=True, check=False, timeout=20,
    )
    if process.returncode != 0 or process.stderr.strip():
        raise AssertionError("UNKNOWN: isolated PHP probe failed: "
                             + process.stderr[-500:])
    try:
        value = json.loads(process.stdout)
    except ValueError as exc:
        raise AssertionError("UNKNOWN: malformed PHP observation") from exc
    if type(value) is not dict or set(value) != {
        "registry", "static", "routes", "denied"
    }:
        raise AssertionError("UNKNOWN: incomplete PHP observation")
    return value


def classify(key: object, sources: object, observed: object) -> str:
    """Only assert verifiable local behavior; missing/altered data is UNKNOWN."""
    if (type(key) is not str or key not in ROWS
            or type(sources) is not dict or set(sources) != set(FILES)
            or type(observed) is not dict
            or set(observed) != {"registry", "static", "routes", "denied"}
            or any(type(sources[p]) is not str or not sources[p] for p in FILES)):
        return UNKNOWN
    try:
        matrix_rows(sources["matrix"])
        contrast_rows(sources["contrast"])
    except ValueError:
        return UNKNOWN
    label, family, table, schema = ROWS[key]
    if ("## 7. Initial page model" not in sources["spec"]
            or "- " + label not in sources["spec"]
            or "brvtalPublicLocalizedRouteUrls(" not in sources["locale"]
            or "brvtal_public_content_definitions(" not in sources["routes"]
            or "http_response_code(404)" not in sources["index"]
            or "X-Robots-Tag: noindex, follow" not in sources["index"]
            or "brvtalPublicEntityDocument(" not in sources["index"]
            or "brvtal_public_entity_page(" not in sources["contract"]):
        return UNKNOWN
    registry, static, urls, denied = (
        observed["registry"], observed["static"], observed["routes"],
        observed["denied"]
    )
    if (type(registry) is not dict or type(static) is not list
            or type(urls) is not dict or type(denied) is not dict
            or set(denied) != {"releases", "blog", "sets", "contact", "legal"}
            or not all(value is True for value in denied.values())):
        return UNKNOWN
    # There is no dedicated public Legal route in this observable contract.
    # That is a recorded gap, not a negative claim about all legal content.
    if key == "S07-L83":
        return UNKNOWN
    if key == "S07-L82":
        if ("/contact" not in static
                or "if ($pageRoute === 'contact')" not in sources["index"]
                or "brvtal_public_contact_page(" not in sources["index"]):
            return UNKNOWN
    else:
        record = registry.get(family)
        if (type(record) is not dict or record.get("table") != table
                or record.get("schema_type") != schema
                or record.get("where") != "status='published'"
                or record.get("parameters") != []
                or record.get("event_visibility") is not False
                or "brvtal_public_seo_entity(db(), $type, $slug)"
                   not in sources["index"]):
            return UNKNOWN
    route = urls.get(family)
    if type(route) is not dict or set(route) != {"es", "en"}:
        return UNKNOWN
    suffix = "contact" if family == "contact" else family + "/synthetic-record"
    for locale, prefix in (("es", ""), ("en", "/en")):
        value = route.get(locale)
        if (type(value) is not dict
                or value.get("locale") != locale
                or value.get("canonical") != "https://example.test"
                   + prefix + "/" + suffix
                or type(value.get("alternates")) is not dict
                or value["alternates"].get("x-default")
                   != "https://example.test/" + suffix):
            return UNKNOWN
    return LOCAL_STATIC_ONLY if family == "contact" else LOCAL_ROUTE_ONLY


def operational_evidence(_key: object, _evidence: object = None) -> str:
    """Never infer live publication, 404/noindex or deploy from local data."""
    return UNKNOWN


class BrechasBrvtal08Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.sources = documents()
        cls.observed = php_observation()

    def test_contrato_principal(self) -> None:
        self.assertEqual(5, len(ROWS))
        self.assertEqual(set(ROWS), set(matrix_rows(self.sources["matrix"])))
        self.assertEqual(set(ROWS), set(contrast_rows(self.sources["contrast"])))
        expected = {
            "S07-L80": LOCAL_ROUTE_ONLY,
            "S07-L82": LOCAL_STATIC_ONLY,
            "S07-L83": UNKNOWN,
            "S07-L84": LOCAL_ROUTE_ONLY,
            "S07-R002": LOCAL_ROUTE_ONLY,
        }
        for key in ROWS:
            with self.subTest(row=key):
                self.assertEqual(expected[key], classify(key, self.sources, self.observed))
                self.assertEqual(UNKNOWN, operational_evidence(key, self.observed))

    def test_falla_cerrado(self) -> None:
        for key in ROWS:
            with self.subTest(row=key):
                row = next(s for s in self.sources["matrix"].splitlines()
                           if s.startswith("| " + key + " · "))
                for corrupted in (
                    self.sources["matrix"].replace(row, "", 1),
                    self.sources["matrix"] + "\n" + row,
                    self.sources["matrix"].replace(
                        row, row.replace("NO_VERIFICABLE", "CUBIERTO", 1), 1
                    ),
                ):
                    with self.assertRaisesRegex(ValueError, UNKNOWN):
                        matrix_rows(corrupted)
                    self.assertEqual(UNKNOWN, classify(
                        key, {**self.sources, "matrix": corrupted}, self.observed))
                match = next(s for s in self.sources["contrast"].splitlines()
                             if s.startswith("| " + key + " |"))
                self.assertEqual(UNKNOWN, classify(
                    key, {**self.sources, "contrast":
                          self.sources["contrast"].replace(match, "", 1)},
                    self.observed))
                self.assertEqual(UNKNOWN, classify(key, {}, self.observed))
                self.assertEqual(UNKNOWN, classify(key, self.sources, {}))
        self.assertEqual(UNKNOWN, classify("S07-R999", self.sources, self.observed))
        for key in ("S07-L80", "S07-L84", "S07-R002"):
            route = ROWS[key][1]
            registry = {**self.observed["registry"],
                        route: {**self.observed["registry"][route],
                                "where": "status<>'draft'"}}
            self.assertEqual(UNKNOWN, classify(
                key, self.sources, {**self.observed, "registry": registry}))
        self.assertEqual(UNKNOWN, classify(
            "S07-L80", self.sources,
            {**self.observed, "routes": {
                **self.observed["routes"],
                "releases": {
                    **self.observed["routes"]["releases"],
                    "es": {
                        **self.observed["routes"]["releases"]["es"],
                        "alternates": None,
                    },
                },
            }}))
        self.assertEqual(UNKNOWN, classify(
            "S07-L82", self.sources, {**self.observed, "static": ["/"]}))
        self.assertEqual(UNKNOWN, classify(
            "S07-R002", self.sources,
            {**self.observed, "denied": {**self.observed["denied"], "sets": False}}))

    def test_sin_datos_reales(self) -> None:
        module = ast.parse(Path(__file__).read_text(encoding="utf-8"))
        imports = {a.name.split(".")[0] for node in ast.walk(module)
                   if isinstance(node, ast.Import) for a in node.names}
        imports |= {node.module.split(".")[0] for node in ast.walk(module)
                    if isinstance(node, ast.ImportFrom) and node.module}
        self.assertFalse(imports & {
            "requests", "urllib", "socket", "httpx", "pymysql", "mysql",
            "playwright", "selenium"
        })
        for token in ("new PDO", "curl_", "getenv(", "$_SERVER", "$_ENV",
                      "file_get_contents(", "api.github.com", "sk_live_"):
            self.assertNotIn(token, PHP_PROBE)
        self.assertIn("synthetic-record", PHP_PROBE)
        self.assertIn("https://example.test", PHP_PROBE)

    def test_estados_unknown_explicitos(self) -> None:
        for key in ROWS:
            with self.subTest(row=key):
                self.assertEqual("NO_VERIFICABLE",
                                 matrix_rows(self.sources["matrix"])[key][2])
                for fake in (None, True, {"ci": "success"}, {"http": 200},
                             {"published": True}, {"404": True, "noindex": True},
                             {"deployment": "VALIDATED_IN_PRODUCTION"}):
                    self.assertEqual(UNKNOWN, operational_evidence(key, fake))
        self.assertEqual(UNKNOWN, classify("S07-L83", self.sources, self.observed))
        self.assertNotEqual("CUBIERTO", classify(
            "S07-L80", self.sources, self.observed))


if __name__ == "__main__":
    unittest.main()
