#!/usr/bin/env python3
"""#1153: S40-R004..S40-R008 offline database-contract coverage.

Read committed migrations, schema consumers and SPEC. Probe isolated
public PHP helpers with synthetic inputs; never connect a database, infer
migrations ran or change the coverage matrix. Runtime persistence = UNKNOWN.
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
IDS = tuple(f"S40-R{i:03d}" for i in range(4, 9))
LABELS = {
    "S40-R004": "admin_recovery_codes;",
    "S40-R005": "event_ticket_types;",
    "S40-R006": "artist_collective_history;",
    "S40-R007": "releases and release relation/link tables;",
    "S40-R008": "blog_posts, blog_tags, blog_post_tags, blog_post_relations;",
}
FILES = {
    "matrix": "docs/matriz-cobertura.md",
    "spec": "docs/BRVTAL-SPEC.md",
    "totp_schema": "database/migration_totp_foundation.sql",
    "totp_auth": "config/totp_auth.php",
    "content_schema": "database/migration_content_core_01.sql",
    "membership": "config/artist_collective_membership.php",
    "ticket_policy": "config/public_visibility.php",
    "release_schema": "database/migration_releases_01.sql",
    "release_api": "api/releases.php",
    "blog_schema": "database/migration_blog_01.sql",
    "blog_api": "api/blog.php",
    "blog_relations": "api/blog-relations.php",
}
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_PARTIAL"

# Only pure PHP helpers: no production bootstrap, DB, HTTP or credentials.
PHP_PROBE = r"""
require $argv[1];
require $argv[2];
require $argv[3];
$now = new DateTimeImmutable('2026-10-28 12:00:00', new DateTimeZone('UTC'));
$window = ['status'=>'active','available_from'=>'2026-10-28 12:00:00',
           'available_until'=>'2026-10-28 12:00:00'];
$tickets = [
    brvtal_public_ticket_type_is_available($window, $now),
    brvtal_public_ticket_type_is_available(['status'=>'draft'], $now),
    brvtal_public_ticket_type_is_available(['status'=>'active',
        'available_from'=>'2026-10-28 13:00:00'], $now),
    brvtal_public_ticket_type_is_available(['status'=>'active',
        'available_until'=>'invalid-date'], $now),
    brvtal_public_ticket_type_is_available(['status'=>'sold_out'], $now),
];
$valid = brvtalArtistCollectiveNormalizePayload(['is_collective_member'=>true]);
$invalid = brvtalArtistCollectiveNormalizePayload(['is_collective_member'=>'yes']);
$membership = [
    $valid['payload']['is_collective_member'],
    $invalid['error']['error'] ?? null,
    brvtalArtistCollectiveStateChanged(['is_collective_member'=>0],
        ['is_collective_member'=>1]),
    brvtalArtistCollectiveStateChanged(['is_collective_member'=>1],
        ['is_collective_member'=>1]),
    brvtalArtistCollectiveDatetime('2026-10-28'),
    brvtalArtistCollectiveDatetime('2026-02-30'),
];
$invalidIds = [];
foreach ([0, -1, true, 1.5, 'invalid-id'] as $id) {
    try { brvtal_blog_relation_id($id); $invalidIds[] = false; }
    catch (InvalidArgumentException $e) {
        $invalidIds[] = $e->getMessage() === 'INVALID_BLOG_RELATION';
    }
}
$relations = [
    brvtal_blog_relation_id('7'),
    brvtal_blog_relation_tables(),
    $invalidIds,
];
echo json_encode(['tickets'=>$tickets,'membership'=>$membership,
                  'relations'=>$relations], JSON_THROW_ON_ERROR);
"""
EXPECTED = {
    "tickets": [True, False, False, False, True],
    "membership": [1, "INVALID_COLLECTIVE_MEMBERSHIP", True, False,
                   "2026-10-28 00:00:00", None],
    "relations": [7, {"event": "events", "artist": "artists",
                      "set": "sets_media", "release": "releases"}, [True] * 5],
}


def rows(raw: object) -> dict[str, tuple[str, ...]]:
    """Reject missing, malformed, duplicated or silently modified rows."""
    if type(raw) is not str or not raw:
        raise ValueError(UNKNOWN + ": matrix unavailable")
    found: dict[str, tuple[str, ...]] = {}
    for line in raw.splitlines():
        match = re.match(r"^\| (S40-R00[4-8]) · ", line)
        if not match:
            continue
        key = match.group(1)
        cells = tuple(cell.strip() for cell in line.strip("|").split("|"))
        if (key in found or len(cells) != 5
                or cells[1] != LABELS[key]
                or cells[2] != "NO_VERIFICABLE"
                or "Sin aserción contrastada" not in cells[3]
                or cells[4] != "—"):
            raise ValueError(UNKNOWN + ": ambiguous " + key)
        found[key] = cells
    if set(found) != set(IDS):
        raise ValueError(UNKNOWN + ": incomplete S40 contract")
    return found


def ddl(source: object, table: str) -> str | None:
    """Find one complete InnoDB CREATE TABLE in the claimed migration."""
    if type(source) is not str:
        return None
    bt = chr(96)
    blocks = re.findall(
        rf"(?ms)^CREATE TABLE IF NOT EXISTS {bt}?{re.escape(table)}{bt}? \(\n(.*?)^\) ENGINE=InnoDB\b",
        source,
    )
    return blocks[0] if len(blocks) == 1 else None


def sources() -> dict[str, str]:
    return {key: (ROOT / path).read_text(encoding="utf-8") for key, path in FILES.items()}


def isolated_env(home: str) -> dict[str, str]:
    return {"PATH": os.defpath, "HOME": home, "LANG": "C", "APP_ENV": "testing"}


def probe() -> dict[str, object]:
    if shutil.which("php") is None:
        raise AssertionError(UNKNOWN + ": PHP CLI missing")
    with tempfile.TemporaryDirectory(prefix="brvtal-s40-63-") as home:
        result = subprocess.run(
            ["php", "-r", PHP_PROBE,
             str(ROOT / FILES["ticket_policy"]),
             str(ROOT / FILES["membership"]),
             str(ROOT / FILES["blog_relations"])],
            cwd=ROOT, env=isolated_env(home), text=True,
            capture_output=True, timeout=15, check=False,
        )
    if result.returncode or result.stderr:
        raise AssertionError(UNKNOWN + ": PHP probe failed")
    observed = json.loads(result.stdout)
    if type(observed) is not dict or set(observed) != set(EXPECTED):
        raise ValueError(UNKNOWN + ": partial PHP probe")
    return observed


def schema_checks(content: dict[str, str]) -> dict[str, bool]:
    recovery = ddl(content["totp_schema"], "admin_recovery_codes") or ""
    ticket = ddl(content["content_schema"], "event_ticket_types") or ""
    history = ddl(content["content_schema"], "artist_collective_history") or ""
    releases = ddl(content["release_schema"], "releases") or ""
    release_artists = ddl(content["release_schema"], "release_artists") or ""
    blog = {name: ddl(content["blog_schema"], name) or "" for name in (
        "blog_posts", "blog_tags", "blog_post_tags", "blog_post_relations")}
    return {
        "S40-R004": ("code_hash VARCHAR(255) NOT NULL" in recovery
            and "used_at DATETIME NULL" in recovery
            and "UNIQUE KEY uq_admin_recovery_code (admin_id, code_hash)" in recovery
            and "password_verify($normalized" in content["totp_auth"]
            and "UPDATE admin_recovery_codes SET used_at=NOW() WHERE id=? AND used_at IS NULL" in content["totp_auth"]),
        "S40-R005": ("INDEX idx_ticket_event_status (event_id,status,sort_order)" in ticket
            and "ON DELETE CASCADE" in ticket
            and "available_from DATETIME NULL" in ticket
            and "available_until DATETIME NULL" in ticket
            and "function brvtal_public_ticket_type_is_available" in content["ticket_policy"]),
        "S40-R006": ("status ENUM('active','alumni') NOT NULL" in history
            and "FOREIGN KEY (artist_id) REFERENCES artists(id) ON DELETE CASCADE" in history
            and "FOREIGN KEY (created_by) REFERENCES admins(id) ON DELETE SET NULL" in history
            and "function brvtalArtistCollectiveSyncHistory" in content["membership"]),
        "S40-R007": ("UNIQUE KEY uq_releases_slug (slug)" in releases
            and "PRIMARY KEY (release_id,artist_id)" in release_artists
            and "REFERENCES releases(id) ON DELETE CASCADE" in release_artists
            and "function brvtal_release_sync_artists" in content["release_api"]),
        "S40-R008": (all(blog.values())
            and "UNIQUE KEY uq_blog_posts_slug (slug)" in blog["blog_posts"]
            and "UNIQUE KEY uq_blog_tags_slug (slug)" in blog["blog_tags"]
            and "PRIMARY KEY (post_id,tag_id)" in blog["blog_post_tags"]
            and "PRIMARY KEY (post_id,related_type,related_id)" in blog["blog_post_relations"]
            and "function brvtal_blog_lock_relation_targets" in content["blog_relations"]
            and "function brvtal_blog_sync_relations" in content["blog_api"]),
    }


def evidence(key: object, content: object, observed: object) -> str:
    """Only exercised helper behavior gives local evidence, never live proof."""
    if (type(key) is not str or key not in IDS or type(content) is not dict
            or set(content) != set(FILES) or type(observed) is not dict
            or any(type(content[k]) is not str or not content[k] for k in FILES)
            or observed != EXPECTED):
        return UNKNOWN
    try:
        rows(content["matrix"])
    except ValueError:
        return UNKNOWN
    spec = content["spec"].replace(chr(96), "")
    if ("## 40. Current database model" not in spec
            or any("- " + label not in spec for label in LABELS.values())):
        return UNKNOWN
    if not schema_checks(content)[key]:
        return UNKNOWN
    # Recovery code consumption and release-artist writes require DB fixtures;
    # their structural contracts do not certify runtime persistence.
    if key in {"S40-R004", "S40-R007"}:
        return UNKNOWN
    return LOCAL


def gap(key: object, content: object) -> str:
    if (key not in {"S40-R004", "S40-R007"} or type(content) is not dict
            or set(content) != set(FILES)
            or any(type(content[k]) is not str or not content[k] for k in FILES)):
        return UNKNOWN
    try:
        rows(content["matrix"])
    except ValueError:
        return UNKNOWN
    if not schema_checks(content)[key]:
        return UNKNOWN
    return f"{key}: UNKNOWN — SQL/source contract present; transactional runtime not exercised"


def operational(_key: object, _claim: object = None) -> str:
    """Synthetic source evidence is never a production, CI or migration claim."""
    return UNKNOWN


class BrechasBrvtal63Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.content = sources()
        cls.observed = probe()

    def test_contrato_principal(self) -> None:
        self.assertEqual(set(rows(self.content["matrix"])), set(IDS))
        self.assertEqual(set(LABELS), set(IDS))
        self.assertEqual(self.observed, EXPECTED)
        for key in IDS:
            with self.subTest(clause=key):
                self.assertEqual(rows(self.content["matrix"])[key][2], "NO_VERIFICABLE")
                self.assertTrue(schema_checks(self.content)[key], key)
                target = UNKNOWN if key in {"S40-R004", "S40-R007"} else LOCAL
                self.assertEqual(evidence(key, self.content, self.observed), target)
                if target == UNKNOWN:
                    self.assertTrue(gap(key, self.content).startswith(key + ": UNKNOWN"))

    def test_falla_cerrado(self) -> None:
        raw = self.content["matrix"]
        for key in IDS:
            original = next(x for x in raw.splitlines() if x.startswith("| " + key + " · "))
            for damaged in (raw.replace(original, "", 1), raw + "\n" + original,
                            raw.replace(original, "| " + key + " · malformed |", 1)):
                with self.subTest(key=key):
                    with self.assertRaisesRegex(ValueError, UNKNOWN):
                        rows(damaged)
                    self.assertEqual(UNKNOWN, evidence(key, {**self.content, "matrix": damaged}, self.observed))
        for source in FILES:
            reduced = dict(self.content)
            reduced.pop(source)
            self.assertTrue(all(evidence(k, reduced, self.observed) == UNKNOWN for k in IDS))
        self.assertEqual(evidence(IDS[1], self.content, {"tickets": [True]}), UNKNOWN)
        self.assertEqual(evidence("S40-R999", self.content, self.observed), UNKNOWN)
        self.assertEqual(gap("S40-R007", {**self.content, "release_schema": ""}), UNKNOWN)
        self.assertEqual(gap("S40-R004", {**self.content, "totp_schema": ""}), UNKNOWN)

    def test_sin_datos_reales(self) -> None:
        self.assertTrue(all((ROOT / p).is_file() for p in FILES.values()))
        with tempfile.TemporaryDirectory(prefix="brvtal-s40-safe-") as home:
            env = isolated_env(home)
            self.assertEqual(env["APP_ENV"], "testing")
            for secret in ("DB_PASSWORD", "DATABASE_URL", "GITHUB_TOKEN", "GH_TOKEN",
                           "MYSQL_PWD", "APP_KEY", "HTTP_PROXY", "HTTPS_PROXY"):
                self.assertNotIn(secret, env)
        for forbidden in ("PDO(", "http://", "https://", "curl", "file_get_contents("):
            self.assertNotIn(forbidden, PHP_PROBE)

    def test_estados_unknown_explicitos(self) -> None:
        self.assertEqual(set(IDS), set(LABELS))
        for key in IDS:
            with self.subTest(key=key):
                for untrusted in (None, "success", True, {"ci": "success"},
                                  {"production": "GREEN"}, {"migration": "applied"}):
                    self.assertEqual(operational(key, untrusted), UNKNOWN)
        self.assertIn("UNKNOWN", gap("S40-R004", self.content))
        self.assertIn("UNKNOWN", gap("S40-R007", self.content))
        self.assertEqual(gap("S40-R005", self.content), UNKNOWN)


if __name__ == "__main__":
    unittest.main()
