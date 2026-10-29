#!/usr/bin/env python3
"""BRVTAL #1156: five offline security contrasts; never a live attestation.

Exercise the actual PHP visibility, relations, CSRF/session, prepared-query and
HTML renderer functions with synthetic inputs. The coverage matrix is read-only.
"""
from __future__ import annotations

from copy import deepcopy
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
MATRIX = "docs/matriz-cobertura.md"
SPEC = "docs/BRVTAL-SPEC.md"
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_SYNTHETIC_ONLY"
GAP = "BRECHA_DOCUMENTADA"
CLAUSES = {
    "S41-R006": (655, "Public entity delivery must filter draft/private content and private related records at the server/API layer, not merely hide them visually."),
    "S42-L662": (662, "CSRF;"),
    "S42-R001": (661, "prepared statements;"),
    "S42-R002": (663, "secure sessions;"),
    "S42-R003": (664, "output escaping;"),
}

# PHP loads real tracked source; no connection, network request or live database.
# The only generated file is an inert, isolated test configuration.
PHP_PROBE = r"""
$root = $argv[1];
$mode = $argv[2];
$configPath = $argv[3];
if ($mode === 'visibility') {
    require $root . '/config/public_visibility.php';
    require $root . '/api/public-related.php';
    $now = new DateTimeImmutable('2026-09-15 12:00:00+00:00');
    $visible = [
        'active' => brvtal_public_event_is_visible(['status'=>'published'], $now),
        'draft' => brvtal_public_event_is_visible(['status'=>'draft'], $now),
        'future' => brvtal_public_event_is_visible(
            ['status'=>'published','published_at'=>'2099-01-01 00:00:00+00:00'], $now),
        'private_page' => brvtal_public_page_is_visible(
            ['status'=>'published','locale'=>'en','private'=>true]),
        'draft_page' => brvtal_public_page_is_visible(
            ['status'=>'draft','locale'=>'en']),
    ];
    $posts = [[
        'id'=>7,
        'relations'=>[
            ['related_type'=>'event','related_id'=>1],
            ['related_type'=>'event','related_id'=>999],
            ['related_type'=>'artist','related_id'=>999],
            ['related_type'=>'not-allowed','related_id'=>1],
        ],
    ]];
    $filtered = brvtal_public_sanitize_blog_relations(
        $posts, [['id'=>1]], [], [], [], []);
    $sets = brvtal_public_sanitize_set_relations(
        [['id'=>8,'artist_id'=>999,'event_id'=>999]],
        [], [['id'=>1]], []);
    echo json_encode([
        'visibility'=>$visible,
        'relations'=>$filtered[0]['relations'],
        'set_artist'=>$sets[0]['artist_id'],
        'set_event'=>$sets[0]['event_id'],
    ], JSON_THROW_ON_ERROR);
    exit;
}
if ($mode === 'csrf-valid' || $mode === 'csrf-invalid' || $mode === 'session') {
    define('BRVTAL_SENTRY_TESTING', true);
    $GLOBALS['brvtalBootstrapConfigPath'] = $configPath;
    $_SERVER['HTTPS'] = 'on';
    session_save_path(dirname($configPath));
    require $root . '/config/admin_auth.php';
    $token = brvtal_admin_csrf_token();
    if ($mode === 'csrf-invalid') {
        brvtal_admin_require_csrf('intentionally-wrong-synthetic-token');
        echo json_encode(['allowed'=>true], JSON_THROW_ON_ERROR);
        exit;
    }
    brvtal_admin_require_csrf($token);
    echo json_encode([
        'allowed'=>true,
        'csrf_length'=>strlen($token),
        'cookie'=>session_get_cookie_params(),
        'strict'=>ini_get('session.use_strict_mode'),
        'only_cookies'=>ini_get('session.use_only_cookies'),
    ], JSON_THROW_ON_ERROR);
    exit;
}
require $root . '/config/public_page.php';
if ($mode === 'prepared') {
    class SyntheticStatement extends PDOStatement {
        public array $bound = [];
        public function execute(?array $params = null): bool {
            $this->bound = $params ?? [];
            return true;
        }
        public function fetchAll(int $mode = PDO::FETCH_DEFAULT, ...$args): array {
            return [['id'=>7]];
        }
    }
    class SyntheticPDO extends PDO {
        public string $sql = '';
        public ?SyntheticStatement $statement = null;
        public function __construct() {}
        public function prepare(string $query, array $options = []): PDOStatement|false {
            $this->sql = $query;
            $this->statement = new SyntheticStatement();
            return $this->statement;
        }
    }
    $pdo = new SyntheticPDO();
    $attack = "synthetic' OR 1=1 --";
    $rows = brvtal_page_rows($pdo, 'SELECT id FROM synthetic WHERE slug = ?', [$attack], true);
    echo json_encode(['sql'=>$pdo->sql, 'bound'=>$pdo->statement->bound,
                      'rows'=>$rows], JSON_THROW_ON_ERROR);
    exit;
}
if ($mode === 'escaping') {
    if (!function_exists('brvtal_public_seo_tags')) {
        function brvtal_public_seo_tags(array $seo): string { return ''; }
    }
    if (!function_exists('brvtal_public_absolute_url')) {
        function brvtal_public_absolute_url(string $url, string $base): string { return ''; }
    }
    if (!function_exists('brvtal_public_connected_url')) {
        function brvtal_public_connected_url(array $entity): string { return ''; }
    }
    $attack = '<script>alert("synthetic")</script>';
    $page = [
        'entity'=>['route_type'=>'sets','id'=>7,'title'=>$attack,
                   'description'=>$attack],
        'facts'=>['synthetic'=>$attack],
        'links'=>[], 'related'=>[],
        'degraded'=>false,
    ];
    $seo = ['canonical'=>'https://example.invalid/sets/test',
            'title'=>$attack, 'description'=>$attack, 'image'=>''];
    $html = brvtal_public_entity_page($page, $seo);
    echo json_encode([
        'raw_script'=>str_contains($html, $attack),
        'escaped_title'=>str_contains($html, '&lt;script&gt;'),
        'escaped_quotes'=>str_contains($html, '&quot;synthetic&quot;'),
        'escaped_description'=>str_contains($html, 'alert(&quot;synthetic&quot;)'),
    ], JSON_THROW_ON_ERROR);
    exit;
}
exit(7);
"""


def matrix_rows(document: object) -> dict[str, tuple[str, ...]]:
    if type(document) is not str or not document.strip():
        raise ValueError("UNKNOWN: missing matrix")
    rows: dict[str, tuple[str, ...]] = {}
    for line in document.splitlines():
        match = re.match(r"^\| (S41-R006|S42-L662|S42-R00[1-3]) · ", line)
        if not match:
            continue
        key = match.group(1)
        cells = tuple(cell.strip() for cell in line.strip("|").split("|"))
        anchor, clause = CLAUSES[key]
        if (key in rows or len(cells) != 5 or cells[1] != clause
                or cells[2] != "NO_VERIFICABLE"
                or not cells[0].startswith(f"{key} · [S")
                or f"/docs/BRVTAL-SPEC.md#L{anchor})" not in cells[0]
                or "Sin aserción contrastada" not in cells[3]
                or cells[4] != "—"):
            raise ValueError("UNKNOWN: ambiguous or promoted " + key)
        rows[key] = cells
    if set(rows) != set(CLAUSES):
        raise ValueError("UNKNOWN: missing/duplicate coverage rows")
    return rows


def spec_ok(source: object, key: str) -> bool:
    if type(source) is not str or key not in CLAUSES:
        return False
    heading = "41. API architecture" if key == "S41-R006" else "42. Security principles"
    marker = "## " + heading + "\n"
    if source.count(marker) != 1:
        return False
    block = source.split(marker, 1)[1].split("\n## ", 1)[0]
    return CLAUSES[key][1] in block


def environment(home: str) -> dict[str, str]:
    return {"PATH": os.defpath, "HOME": home, "LANG": "C", "LC_ALL": "C",
            "APP_ENV": "testing"}


def php_observation(mode: str) -> dict[str, object]:
    php = shutil.which("php")
    if php is None or mode not in {
        "visibility", "csrf-valid", "csrf-invalid", "session", "prepared", "escaping"
    }:
        raise RuntimeError("UNKNOWN: PHP unavailable or unknown probe")
    with tempfile.TemporaryDirectory(prefix="brvtal-s41-s42-synthetic-") as folder:
        config = Path(folder) / "synthetic-config.php"
        config.write_text(
            "<?php return ['app'=>['timezone'=>'UTC'],"
            "'security'=>['session_name'=>'BRVTAL_SYNTHETIC']];\n",
            encoding="utf-8",
        )
        run = subprocess.run(
            [php, "-d", "display_errors=0", "-r", PHP_PROBE, str(ROOT), mode, str(config)],
            cwd=ROOT, env=environment(folder), capture_output=True, text=True,
            timeout=20, check=False,
        )
    if run.returncode != 0:
        raise RuntimeError("UNKNOWN: isolated PHP probe failure: " + mode)
    try:
        result = json.loads(run.stdout)
    except json.JSONDecodeError as exc:
        raise RuntimeError("UNKNOWN: missing/invalid PHP observation: " + mode) from exc
    if type(result) is not dict:
        raise RuntimeError("UNKNOWN: invalid result shape: " + mode)
    return result


def verdict(key: object, matrix: object, spec: object, data: object) -> str:
    """Synthetic observations cannot certify production; invalid inputs never pass."""
    if type(key) is not str or key not in CLAUSES or type(data) is not dict:
        return UNKNOWN
    try:
        matrix_rows(matrix)
    except ValueError:
        return UNKNOWN
    if not spec_ok(spec, key):
        return UNKNOWN
    if key == "S41-R006":
        v = data.get("visibility")
        if type(v) is not dict or set(v) != {
            "active", "draft", "future", "private_page", "draft_page"
        }:
            return UNKNOWN
        baseline = (
            v["active"] is True and v["draft"] is False
            and v["future"] is False and v["draft_page"] is False
            and data.get("relations") == [{"related_type":"event", "related_id":1}]
            and data.get("set_artist", "NOT_NULL") is None
            and data.get("set_event", "NOT_NULL") is None
        )
        if not baseline:
            return UNKNOWN
        # English private Pages remain a documented gap if this policy exposes
        # them. No source-level assumption can turn this into a public-API pass.
        return GAP if v["private_page"] is True else (
            LOCAL if v["private_page"] is False else UNKNOWN
        )
    if key == "S42-L662":
        good = data.get("valid")
        denied = data.get("invalid")
        if type(good) is not dict or type(denied) is not dict:
            return UNKNOWN
        return LOCAL if (
            good.get("allowed") is True and good.get("csrf_length") == 64
            and denied.get("ok") is False and denied.get("error") == "CSRF"
            and "allowed" not in denied
        ) else UNKNOWN
    if key == "S42-R001":
        return LOCAL if (
            data.get("sql") == "SELECT id FROM synthetic WHERE slug = ?"
            and data.get("bound") == ["synthetic' OR 1=1 --"]
            and data.get("rows") == [{"id": 7}]
        ) else UNKNOWN
    if key == "S42-R002":
        cookie = data.get("cookie")
        if type(cookie) is not dict:
            return UNKNOWN
        return LOCAL if (
            data.get("allowed") is True and data.get("csrf_length") == 64
            and cookie.get("httponly") is True and cookie.get("secure") is True
            and cookie.get("samesite") == "Strict"
            and str(data.get("strict")) == "1"
            and str(data.get("only_cookies")) == "1"
        ) else UNKNOWN
    return LOCAL if (
        data.get("raw_script") is False
        and data.get("escaped_title") is True
        and data.get("escaped_quotes") is True
        and data.get("escaped_description") is True
    ) else UNKNOWN


def operational_claim(_key: object, _claim: object = None) -> str:
    """Never upgrade synthetic or CI metadata to deploy/production evidence."""
    return UNKNOWN


class BrechasBrvtal66Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.matrix = (ROOT / MATRIX).read_text(encoding="utf-8")
        cls.spec = (ROOT / SPEC).read_text(encoding="utf-8")

    def test_contrato_principal(self) -> None:
        """AC-01: five distinct row-level behavioral or explicit gap contrasts."""
        self.assertEqual(set(matrix_rows(self.matrix)), set(CLAUSES))
        observations = {
            "S41-R006": php_observation("visibility"),
            "S42-L662": {
                "valid": php_observation("csrf-valid"),
                "invalid": php_observation("csrf-invalid"),
            },
            "S42-R001": php_observation("prepared"),
            "S42-R002": php_observation("session"),
            "S42-R003": php_observation("escaping"),
        }
        self.assertEqual(set(observations), set(CLAUSES))
        for key in CLAUSES:
            with self.subTest(requirement=key):
                actual = verdict(key, self.matrix, self.spec, observations[key])
                if key == "S41-R006":
                    # Partial public pool sanitation is not an end-to-end leak audit.
                    self.assertIn(actual, {LOCAL, GAP})
                else:
                    self.assertEqual(LOCAL, actual)
                self.assertEqual(UNKNOWN, operational_claim(key, observations[key]))

    def test_falla_cerrado(self) -> None:
        """AC-02: missing/duplicated/promoted rows and observations are UNKNOWN."""
        rows = matrix_rows(self.matrix)
        for key in CLAUSES:
            line = next(x for x in self.matrix.splitlines()
                        if x.startswith(f"| {key} · "))
            for altered in (
                self.matrix.replace(line, "", 1),
                self.matrix + "\n" + line,
                self.matrix.replace(line, line.replace("NO_VERIFICABLE", "CUBIERTO"), 1),
            ):
                with self.subTest(key=key):
                    with self.assertRaisesRegex(ValueError, UNKNOWN):
                        matrix_rows(altered)
                    self.assertEqual(UNKNOWN, verdict(key, altered, self.spec, {}))
            self.assertEqual("NO_VERIFICABLE", rows[key][2])
            self.assertEqual(UNKNOWN, verdict(key, self.matrix, self.spec, {}))
            self.assertEqual(UNKNOWN, verdict(key, self.matrix, "", {"allowed": True}))
        self.assertEqual(UNKNOWN, verdict("S99-R999", self.matrix, self.spec, {}))
        good = {"sql": "SELECT id FROM synthetic WHERE slug = ?",
                "bound": ["synthetic' OR 1=1 --"], "rows": [{"id": 7}]}
        self.assertEqual(LOCAL, verdict("S42-R001", self.matrix, self.spec, good))
        tampered = deepcopy(good)
        tampered["sql"] = "SELECT id FROM synthetic WHERE slug = " + tampered["bound"][0]
        self.assertEqual(UNKNOWN, verdict("S42-R001", self.matrix, self.spec, tampered))

    def test_sin_datos_reales(self) -> None:
        """AC-03: synthetic config, inert SQL and no inherited credentials."""
        env = environment("/nonexistent")
        self.assertEqual("testing", env["APP_ENV"])
        for key in ("DATABASE_URL", "DB_PASSWORD", "MYSQL_PWD", "GITHUB_TOKEN",
                    "COOKIE", "AUTHORIZATION", "HTTP_PROXY", "HTTPS_PROXY"):
            with self.subTest(key=key):
                self.assertNotIn(key, env)
        self.assertNotIn("curl", PHP_PROBE)
        self.assertNotIn("file_get_contents('http", PHP_PROBE)
        self.assertNotIn("new PDO(", PHP_PROBE)
        self.assertIn("SyntheticPDO", PHP_PROBE)

    def test_estados_unknown_explicitos(self) -> None:
        """AC-04: matrix remains unverified; no self-reported green as evidence."""
        for key, row in matrix_rows(self.matrix).items():
            with self.subTest(key=key):
                self.assertEqual("NO_VERIFICABLE", row[2])
                self.assertIn("Sin aserción contrastada", row[3])
                for claim in (None, {}, {"CI": "success"}, {"live": True},
                              {"production": "GREEN"}, {"pr": "merged"}):
                    self.assertEqual(UNKNOWN, operational_claim(key, claim))
        self.assertEqual(UNKNOWN, verdict(None, self.matrix, self.spec, None))


if __name__ == "__main__":
    unittest.main()
