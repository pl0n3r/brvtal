#!/usr/bin/env python3
"""BRVTAL #1155: S41-R001..R005; isolated public/admin API contrast.

Static source contracts and the real pure PHP route parser can be checked
offline. No probe touches a live endpoint, authentication, production data or
the database. Read-only evidence never certifies operational readiness.
"""
from __future__ import annotations

from copy import deepcopy
import ast
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_SYNTHETIC_PARTIAL"
IDS = tuple(f"S41-R{i:03d}" for i in range(1, 6))
CLAUSES = {
    "S41-R001": (645, "Public and admin APIs must remain clearly separated."),
    "S41-R002": (647, "api/public.php is read-only and allowlisted."),
    "S41-R003": (649, "api/index.php handles authenticated admin resources and may provide a backwards-compatible public route only by delegating to api/public.php."),
    "S41-R004": (651, "Never maintain two divergent public data implementations."),
    "S41-R005": (653, "Canonical event-participation route parsing must support both front-controller and compatible rewritten forms of /events/{id}/lineup."),
}
FILES = {
    "matrix": "docs/matriz-cobertura.md",
    "spec": "docs/BRVTAL-SPEC.md",
    "public": "api/public.php",
    "admin": "api/index.php",
    "route": "api/route.php",
}
# A string of status labels is not evidence of rollout, owner authority or
# network E2E; this suite deliberately does not generate any such labels.
EXTERNAL_FIELDS = ("ci", "deployment", "production", "authorization", "e2e")


def read_sources() -> dict[str, str]:
    return {key: (ROOT / filename).read_text(encoding="utf-8")
            for key, filename in FILES.items()}


def matrix_rows(raw: object) -> dict[str, tuple[str, ...]]:
    """Five well-formed scoped rows; stale, duplicated or missing is UNKNOWN."""
    if type(raw) is not str or not raw:
        raise ValueError(UNKNOWN + ": missing matrix")
    found: dict[str, tuple[str, ...]] = {}
    for line in raw.splitlines():
        match = re.match(r"^\| (S41-R00[1-5]) · ", line)
        if match is None:
            continue
        key = match.group(1)
        cells = tuple(cell.strip() for cell in line.strip("|").split("|"))
        number, expected = CLAUSES[key]
        if (key in found or len(cells) != 5 or cells[1] != expected
                or not cells[0].startswith(f"{key} · [S41 L{number}]")
                or f"/docs/BRVTAL-SPEC.md#L{number})" not in cells[0]
                or cells[2] not in {"NO_VERIFICABLE", "CUBIERTO"}
                or not cells[3] or not cells[4]):
            raise ValueError(UNKNOWN + ": malformed " + key)
        if cells[2] == "NO_VERIFICABLE":
            if ("Sin aserción contrastada" not in cells[3]
                    or cells[4] != "—"):
                raise ValueError(UNKNOWN + ": unsupported gap " + key)
        elif (("tests/test_brechas_brvtal_65.py" not in cells[3]
               and "test_brechas_brvtal_65.py" not in cells[3])
              or "/pull/" not in cells[3]):
            raise ValueError(UNKNOWN + ": unsupported promotion " + key)
        found[key] = cells
    if set(found) != set(IDS):
        raise ValueError(UNKNOWN + ": missing coverage rows")
    return found


def source_contracts(sources: object) -> dict[str, bool]:
    """Source assertions are partial witnesses, not live behavioral proof."""
    if (type(sources) is not dict or set(sources) != set(FILES)
            or any(type(sources[k]) is not str or not sources[k] for k in FILES)):
        return dict.fromkeys(IDS, False)
    try:
        matrix_rows(sources["matrix"])
    except ValueError:
        return dict.fromkeys(IDS, False)
    spec = sources["spec"]
    section = re.search(r"(?ms)^## 41\. API architecture\n(.*?)(?=^## 42\. )", spec)
    if (section is None or len(re.findall(r"(?m)^## 41\. API architecture$", spec)) != 1
            or any(spec.count(clause) != 1 for _, clause in CLAUSES.values())
            or not all(clause in section.group(1) for _, clause in CLAUSES.values())):
        return dict.fromkeys(IDS, False)
    public, admin, route = sources["public"], sources["admin"], sources["route"]
    public_gate = re.search(
        r"if \(\(\$_SERVER\['REQUEST_METHOD'\] \?\? 'GET'\) !== 'GET'\)"
        r"\s*\{\s*header\('Allow: GET'\);\s*"
        r"brvtal_public_json\(\['error' => 'METHOD_NOT_ALLOWED'\], 405\);",
        public
    )
    settings_allowlist = (
        "setting_key IN ('site','social','appearance','theme.active')" in public
        and "setting_key LIKE 'theme.%'" in public
        and "foreach (['site', 'social', 'appearance'] as $key)" in public
        and "unset($themeOut['analytics'], $themeOut['customCode'])" in public
        and "brvtal_public_visible_event_statuses()" in public
        and "brvtal_public_sanitize_blog_relations" in public
    )
    bridge = re.search(
        r"if\s*\(\$resource\s*===\s*'public'\)\s*\{\s*"
        r"require __DIR__ \. '/public\.php';\s*exit;\s*\}",
        admin,
    )
    auth = admin.find("brvtal_admin_require();")
    bridge_ok = (
        bridge is not None and auth > bridge.end()
        and admin.count("require __DIR__ . '/public.php';") == 1
        and "brvtal_public_settings(" not in admin
        and "brvtal_api_parse_route(" in admin
    )
    pure_route = (
        "function brvtal_api_parse_route(" in route
        and "parse_url($requestUri, PHP_URL_PATH)" in route
        and "$segments[2] === 'lineup'" in route
        and "$legacyMediaMutation" in route
        and "return [" in route
    )
    return {
        "S41-R001": bool(public_gate and bridge_ok),
        "S41-R002": bool(public_gate and settings_allowlist),
        "S41-R003": bool(bridge_ok and auth > 0),
        "S41-R004": bool(bridge_ok and settings_allowlist),
        "S41-R005": bool(pure_route),
    }


# Uses checked-in api/route.php directly, with no bootstrap, DB or HTTP.
PHP_ROUTE_PROBE = r"""
require $argv[1];
$cases = [
    'front' => brvtal_api_parse_route('/api/index.php/events/17/lineup?synthetic=1', '/api/index.php', 'GET'),
    'rewrite' => brvtal_api_parse_route('/api/events/17/lineup', '/api/index.php', 'GET'),
    'malformed' => brvtal_api_parse_route('/api/events/invalid/lineup', '/api/index.php', 'GET'),
    'extra' => brvtal_api_parse_route('/api/events/17/lineup/surplus', '/api/index.php', 'GET'),
    'public' => brvtal_api_parse_route('/api/public', '/api/index.php', 'GET'),
    'blocked_upload' => brvtal_api_parse_route('/api/upload', '/api/index.php', 'POST'),
    'blocked_media_write' => brvtal_api_parse_route('/api/media/17', '/api/index.php', 'DELETE'),
    'media_read' => brvtal_api_parse_route('/api/media/17', '/api/index.php', 'GET'),
];
echo json_encode($cases, JSON_THROW_ON_ERROR);
"""


def php_routes() -> dict[str, dict]:
    php = shutil.which("php")
    if php is None:
        raise AssertionError(UNKNOWN + ": PHP CLI unavailable")
    with tempfile.TemporaryDirectory(prefix="brvtal-s41-route-") as home:
        env = {"PATH": os.defpath, "LANG": "C", "HOME": home,
               "APP_ENV": "testing"}
        run = subprocess.run(
            [php, "-r", PHP_ROUTE_PROBE, str(ROOT / FILES["route"])],
            cwd=ROOT, env=env, capture_output=True, text=True, timeout=15,
            check=False,
        )
    if run.returncode or run.stderr.strip():
        raise AssertionError(UNKNOWN + ": PHP route probe failed")
    try:
        data = json.loads(run.stdout)
    except ValueError as exc:
        raise AssertionError(UNKNOWN + ": invalid route observation") from exc
    expected = {"front", "rewrite", "malformed", "extra", "public",
                "blocked_upload", "blocked_media_write", "media_read"}
    if type(data) is not dict or set(data) != expected:
        raise AssertionError(UNKNOWN + ": incomplete route observation")
    return data


def route_status(data: object) -> str:
    if type(data) is not dict:
        return UNKNOWN
    try:
        front, rewrite = data["front"], data["rewrite"]
        target = {"resource": "events", "id": 17, "action": "lineup",
                  "segments": ["events", "17", "lineup"]}
        if (front != target or rewrite != target
                or data["public"]["resource"] != "public"
                or data["blocked_upload"]["resource"] != ""
                or data["blocked_media_write"]["resource"] != ""
                or data["malformed"]["resource"] != ""
                or data["extra"]["resource"] != ""
                or data["media_read"]["resource"] != "media"
                or data["media_read"]["id"] != 17):
            return UNKNOWN
    except (KeyError, IndexError, TypeError, ValueError):
        return UNKNOWN
    return LOCAL


def evidence(identifier: object, sources: object, routes: object) -> dict[str, str]:
    result = {"code": UNKNOWN, **dict.fromkeys(EXTERNAL_FIELDS, UNKNOWN)}
    if type(identifier) is not str or identifier not in IDS:
        return result
    checks = source_contracts(sources)
    if not checks.get(identifier, False):
        return result
    if identifier == "S41-R005" and route_status(routes) != LOCAL:
        return result
    result["code"] = LOCAL
    return result


def operational_attestation(_claim: object = None) -> str:
    return UNKNOWN


class BrechasBrvtal65Tests(unittest.TestCase):
    def test_contrato_principal(self):
        sources, routes = read_sources(), php_routes()
        self.assertEqual(set(matrix_rows(sources["matrix"])), set(IDS))
        self.assertEqual(set(source_contracts(sources)), set(IDS))
        for ident in IDS:
            with self.subTest(ident=ident):
                self.assertEqual(evidence(ident, sources, routes)["code"], LOCAL)

    def test_falla_cerrado(self):
        sources, routes = read_sources(), php_routes()
        for ident in IDS:
            with self.subTest(ident=ident):
                line = next(x for x in sources["matrix"].splitlines()
                            if x.startswith(f"| {ident} · "))
                for mutated in (
                    sources["matrix"].replace(line, "", 1),
                    sources["matrix"] + "\n" + line,
                    sources["matrix"].replace(line, line.replace(
                        "NO_VERIFICABLE", "NO_PROBADO"), 1),
                ):
                    bad = {**sources, "matrix": mutated}
                    with self.assertRaisesRegex(ValueError, UNKNOWN):
                        matrix_rows(bad["matrix"])
                    self.assertEqual(evidence(ident, bad, routes)["code"], UNKNOWN)
                spec_bad = {**sources, "spec": sources["spec"].replace(
                    CLAUSES[ident][1], "", 1)}
                self.assertEqual(evidence(ident, spec_bad, routes)["code"], UNKNOWN)
        for field, original, replacement in (
            ("public", "header('Allow: GET');", "header('Allow: GET, POST');"),
            ("public", "unset($themeOut['analytics'], $themeOut['customCode'])",
             "unset($themeOut['analytics'])"),
            ("admin", "require __DIR__ . '/public.php';",
             "require __DIR__ . '/another-public.php';"),
            ("route", "$segments[2] === 'lineup'", "$segments[2] === 'other'"),
        ):
            with self.subTest(field=field):
                self.assertIn(original, sources[field])
                broken = deepcopy(sources)
                broken[field] = broken[field].replace(original, replacement, 1)
                statuses = source_contracts(broken)
                self.assertTrue(any(not valid for valid in statuses.values()))
        broken_routes = deepcopy(routes)
        broken_routes["rewrite"]["id"] = 99
        self.assertEqual(route_status(broken_routes), UNKNOWN)
        self.assertEqual(evidence("S41-R005", sources, broken_routes)["code"], UNKNOWN)
        self.assertEqual(evidence("UNKNOWN", sources, routes)["code"], UNKNOWN)

    def test_sin_datos_reales(self):
        source = (ROOT / "tests/test_brechas_brvtal_65.py").read_text(
            encoding="utf-8")
        imports = set()
        for node in ast.walk(ast.parse(source)):
            if isinstance(node, ast.Import):
                imports.update(n.name.split(".")[0] for n in node.names)
            if isinstance(node, ast.ImportFrom) and node.module:
                imports.add(node.module.split(".")[0])
        self.assertLessEqual(imports, {
            "__future__", "copy", "ast", "json", "os", "pathlib",
            "re", "shutil", "subprocess", "tempfile", "unittest"
        })
        self.assertEqual(set(FILES.values()), {
            "docs/matriz-cobertura.md", "docs/BRVTAL-SPEC.md",
            "api/public.php", "api/index.php", "api/route.php",
        })
        self.assertTrue(all(operational_attestation({"live": True}) == UNKNOWN
                            for _ in IDS))

    def test_estados_unknown_explicitos(self):
        sources, routes = read_sources(), php_routes()
        for ident in IDS:
            with self.subTest(ident=ident):
                result = evidence(ident, sources, routes)
                self.assertEqual(result["code"], LOCAL)
                self.assertEqual(
                    {k: result[k] for k in EXTERNAL_FIELDS},
                    dict.fromkeys(EXTERNAL_FIELDS, UNKNOWN),
                )
                for forged in (True, {"production": "VALIDATED"},
                               {"owner": "APPROVED"}, {"CI": "success"}):
                    self.assertEqual(operational_attestation(forged), UNKNOWN)
        self.assertTrue(all(value == UNKNOWN for value in
                            evidence("OTHER", sources, routes).values()))


if __name__ == "__main__":
    unittest.main()
