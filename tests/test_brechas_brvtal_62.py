#!/usr/bin/env python3
"""#1152: offline evidence for SPEC §40 tables (S40-L628..S40-R003).

Exercises the committed PHP route parser with synthetic requests and checks the
actual SQL/schema contracts. This is LOCAL_PARTIAL only: no database, network,
production or claims of applied migrations. An unexercised table stays UNKNOWN.
"""
from __future__ import annotations

import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
IDS = ("S40-L628", "S40-L629", "S40-L630", "S40-R002", "S40-R003")
TABLES = dict(zip(IDS, ("media", "pages", "settings", "event_artists", "analytics_events")))
FILES = {
    "matrix": "docs/matriz-cobertura.md",
    "spec": "docs/BRVTAL-SPEC.md",
    "schema": "database/schema.sql",
    "route": "api/route.php",
    "api": "api/index.php",
    "public": "api/public.php",
}
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_PARTIAL"

# The PHP file under test comes from the real checkout, not a copied/mock parser.
PHP_PROBE = r"""
require $argv[1];
$cases = [
    'media_read' => ['/api/media/9', 'GET'],
    'media_write' => ['/api/media/9', 'POST'],
    'pages_read' => ['/api/pages/8', 'GET'],
    'pages_invalid' => ['/api/pages/not-an-id', 'GET'],
    'settings_read' => ['/api/settings', 'GET'],
    'settings_invalid' => ['/api/settings/8', 'GET'],
    'lineup_read' => ['/api/events/3/lineup', 'GET'],
    'lineup_invalid' => ['/api/events/3/lineup/extra', 'GET'],
];
$out = [];
foreach ($cases as $name => [$uri, $method]) {
    $r = brvtal_api_parse_route($uri, '/api/index.php', $method);
    $out[$name] = [$r['resource'], $r['id'], $r['action']];
}
echo json_encode($out, JSON_THROW_ON_ERROR);
"""
EXPECTED_ROUTES = {
    "media_read": ["media", 9, "9"],
    "media_write": ["", None, ""],
    "pages_read": ["pages", 8, "8"],
    "pages_invalid": ["", None, ""],
    "settings_read": ["settings", None, ""],
    "settings_invalid": ["", None, ""],
    "lineup_read": ["events", 3, "lineup"],
    "lineup_invalid": ["", None, ""],
}


def rows(raw: object) -> dict[str, tuple[str, ...]]:
    """Five exact and distinct matrix rows are necessary; no silent omissions."""
    if type(raw) is not str or not raw:
        raise ValueError(UNKNOWN + ": missing matrix")
    found: dict[str, tuple[str, ...]] = {}
    for line in raw.splitlines():
        match = re.match(r"^\| (S40-(?:L628|L629|L630|R002|R003)) · ", line)
        if not match:
            continue
        key = match.group(1)
        cells = tuple(part.strip() for part in line.strip("|").split("|"))
        if (key in found or len(cells) != 5
                or cells[1].replace("`", "") != TABLES[key] + ";"
                or cells[2] != "NO_VERIFICABLE"
                or "Sin aserción contrastada" not in cells[3]
                or cells[4] != "—"):
            raise ValueError(UNKNOWN + ": ambiguous row " + key)
        found[key] = cells
    if set(found) != set(IDS):
        raise ValueError(UNKNOWN + ": incomplete S40 rows")
    return found


def sources() -> dict[str, str]:
    return {key: (ROOT / filename).read_text(encoding="utf-8")
            for key, filename in FILES.items()}


def table_ddl(schema: str, name: str) -> str | None:
    """Only one real, complete CREATE TABLE block counts as schema evidence."""
    matches = re.findall(
        rf"(?ms)^CREATE TABLE `?{re.escape(name)}`? \(\n(.*?)^\) ENGINE=InnoDB;",
        schema,
    )
    return matches[0] if len(matches) == 1 else None


def isolated_env(home: str) -> dict[str, str]:
    return {"PATH": os.defpath, "HOME": home, "LANG": "C", "APP_ENV": "testing"}


def exercise_routes(path: Path) -> dict[str, object]:
    if shutil.which("php") is None:
        raise AssertionError(UNKNOWN + ": PHP CLI absent")
    with tempfile.TemporaryDirectory(prefix="brvtal-s40-offline-") as home:
        run = subprocess.run(
            ["php", "-r", PHP_PROBE, str(path)], cwd=ROOT,
            env=isolated_env(home), capture_output=True, text=True,
            timeout=15, check=False,
        )
    if run.returncode or run.stderr:
        raise AssertionError(UNKNOWN + ": PHP route probe did not complete")
    result = json.loads(run.stdout)
    if type(result) is not dict or set(result) != set(EXPECTED_ROUTES):
        raise ValueError(UNKNOWN + ": incomplete route probe")
    return result


def evidence(key: object, content: object, observed: object) -> str:
    """No schema name alone or self-asserted success becomes implementation proof."""
    if (key not in IDS or type(content) is not dict or type(observed) is not dict
            or set(content) != set(FILES)
            or any(type(content[k]) is not str or not content[k] for k in FILES)
            or observed != EXPECTED_ROUTES):
        return UNKNOWN
    try:
        rows(content["matrix"])
    except ValueError:
        return UNKNOWN
    schema = content["schema"]
    api = content["api"]
    public = content["public"]
    spec = content["spec"]
    name = TABLES[key]
    ddl = table_ddl(schema, name)
    if not ddl or f"- `{name}`;" not in spec or "## 40. Current database model" not in spec:
        return UNKNOWN
    checks = {
        "S40-L628": (
            "id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY" in ddl
            and "UNIQUE KEY uq_media_content_hash (content_hash)" in ddl
            and "status ENUM('draft','published')" in ddl
            and "'media' => ['type','title'" in api
        ),
        "S40-L629": (
            "slug VARCHAR(190) NOT NULL UNIQUE" in ddl
            and "content_json LONGTEXT NULL" in ddl
            and "status ENUM('draft','published')" in ddl
            and "brvtal_page_content_json_error" in api
        ),
        "S40-L630": (
            "setting_key VARCHAR(120) PRIMARY KEY" in ddl
            and "is_json TINYINT(1)" in ddl
            and "WHERE setting_key IN ('site','social','appearance','theme.active')" in public
            and "SELECT setting_key,setting_value,is_json FROM settings ORDER BY setting_key" not in public
        ),
        "S40-R002": (
            "PRIMARY KEY (event_id, artist_id)" in ddl
            and ddl.count("ON DELETE CASCADE") == 2
            and "JOIN artists a ON a.id=ea.artist_id" in api
            and "INSERT INTO event_artists(event_id,artist_id,lineup_order,role)" in api
        ),
    }
    # S40-R003 has only schema + an aggregate SQL expression, not a synthetic
    # execution of analytics persistence: document the gap, never mark it pass.
    return LOCAL if checks.get(key) is True else UNKNOWN


def gap(key: object, content: object) -> str:
    """Unexercised analytics persistence is an explicit UNKNOWN, not success."""
    if key != "S40-R003" or type(content) is not dict:
        return UNKNOWN
    schema = content.get("schema")
    api = content.get("api")
    ddl = table_ddl(schema, "analytics_events") if type(schema) is str else None
    if (ddl and type(api) is str
            and "event_name VARCHAR(120) NOT NULL" in ddl
            and "INDEX idx_analytics_date (created_at)" in ddl
            and "INDEX idx_analytics_name (event_name)" in ddl
            and "FROM analytics_events WHERE created_at>=DATE_SUB(NOW(),INTERVAL 30 DAY)" in api):
        return "S40-R003: BRECHA_DOCUMENTADA — SQL/schema estáticos; escritura y consulta reales no ejercitadas (UNKNOWN)"
    return UNKNOWN


def operational(_key: object, _claimed: object = None) -> str:
    return UNKNOWN


class BrechasBrvtal62Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.content = sources()
        cls.observed = exercise_routes(ROOT / FILES["route"])

    def test_contrato_principal(self) -> None:
        self.assertEqual(set(rows(self.content["matrix"])), set(IDS))
        for key in IDS:
            with self.subTest(row=key):
                self.assertEqual(rows(self.content["matrix"])[key][2], "NO_VERIFICABLE")
                self.assertEqual(evidence(key, self.content, self.observed),
                                 UNKNOWN if key == "S40-R003" else LOCAL)
                self.assertEqual(operational(key, self.observed), UNKNOWN)
        self.assertIn("S40-R003: BRECHA_DOCUMENTADA", gap("S40-R003", self.content))

    def test_falla_cerrado(self) -> None:
        raw = self.content["matrix"]
        for key in IDS:
            original = next(line for line in raw.splitlines() if line.startswith(f"| {key} · "))
            for corrupted in (raw.replace(original, "", 1), raw + "\n" + original,
                              raw.replace(original, f"| {key} · malformed |", 1)):
                with self.subTest(row=key, corrupt_len=len(corrupted)):
                    with self.assertRaisesRegex(ValueError, UNKNOWN):
                        rows(corrupted)
                    self.assertEqual(evidence(key, {**self.content, "matrix": corrupted},
                                              self.observed), UNKNOWN)
        for source in FILES:
            missing = dict(self.content)
            missing.pop(source)
            self.assertTrue(all(evidence(key, missing, self.observed) == UNKNOWN for key in IDS))
        self.assertEqual(evidence("S40-R999", self.content, self.observed), UNKNOWN)
        self.assertEqual(evidence(IDS[0], self.content, {"media_read": ["media", 9, "9"]}), UNKNOWN)
        damaged = dict(self.content)
        damaged["schema"] = damaged["schema"].replace("CREATE TABLE event_artists", "CREATE TABLE missing_event_artists")
        self.assertEqual(evidence("S40-R002", damaged, self.observed), UNKNOWN)
        self.assertEqual(gap("S40-R003", {"schema": "", "api": ""}), UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        self.assertEqual(set(FILES), {"matrix", "spec", "schema", "route", "api", "public"})
        self.assertTrue(all((ROOT / path).is_file() for path in FILES.values()))
        with tempfile.TemporaryDirectory(prefix="brvtal-s40-env-") as home:
            env = isolated_env(home)
            self.assertEqual(env["APP_ENV"], "testing")
            for secret in ("DB_PASSWORD", "DATABASE_URL", "MYSQL_PWD", "GITHUB_TOKEN",
                           "GH_TOKEN", "APP_KEY", "HTTP_PROXY", "HTTPS_PROXY"):
                self.assertNotIn(secret, env)
        self.assertNotIn("PDO(", PHP_PROBE)
        self.assertNotIn("http://", PHP_PROBE)
        self.assertNotIn("https://", PHP_PROBE)
        self.assertNotIn("file_get_contents(", PHP_PROBE)

    def test_estados_unknown_explicitos(self) -> None:
        self.assertEqual(set(TABLES), set(IDS))
        for key in IDS:
            with self.subTest(row=key):
                for untrusted in (None, True, "success", {"ci": "success"},
                                  {"production": "GREEN"}, {"migration": "applied"}):
                    self.assertEqual(operational(key, untrusted), UNKNOWN)
        self.assertEqual(evidence("S40-R003", self.content, self.observed), UNKNOWN)
        self.assertIn("UNKNOWN", gap("S40-R003", self.content))
        self.assertEqual(gap(None, self.content), UNKNOWN)


if __name__ == "__main__":
    unittest.main()
