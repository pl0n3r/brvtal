#!/usr/bin/env python3
"""#1281 — real, offline Theme Studio and Backups regressions.

This executes the committed JS/PHP with disposable synthetic fixtures. It
does not read the coverage matrix or certify live/hosting/real database state.
"""
from __future__ import annotations

import ast
import json
import os
from pathlib import Path
import shutil
import subprocess
import sys
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "tests"))

import test_brechas_brvtal_45 as theme
import test_brechas_brvtal_48 as backups

A = frozenset((
    "S32-R015", "S32-R016", "S32-R017", "S32-R019", "S32-R020",
    "S32-R021", "S32-R022", "S32-R023", "S32-R024",
    "S33-R002", "S33-R006", "S33-R007", "S33-R008", "S33-R009",
    "S33-R011",
))
B = {
    "S33-R001": "production validation requires real backup/restore receipts",
    "S33-R005": "database export needs an authorized database and restore test",
    "S33-R010": "backup audit persistence needs an authorized database transaction",
}
C = {
    "S32-R018": "Do not introduce: is a parent heading, not a callable behavior",
    "S33-R003": "shared-hosting-safe primitives are an architectural preference",
}

BACKUP_OPERATIONS = r"""
require 'config/backups.php';
require 'config/backup_automation.php';
$c = brvtal_backup_automation_normalize_config([
  'enabled'=>true, 'scope'=>'database',
  'cadence'=>['type'=>'daily','interval'=>1,'time'=>'03:00'],
  'include_media_archive'=>true,
]);
$denied = [];
foreach (['../private','bad-scope'] as $scope) {
  try { brvtal_backup_normalize_scope($scope); $denied[] = false; }
  catch (InvalidArgumentException $e) { $denied[] = true; }
}
try {
  brvtal_backup_automation_normalize_config([
    'cadence'=>['type'=>'hours','interval'=>0,'time'=>'03:00']
  ]);
  $denied[] = false;
} catch (InvalidArgumentException $e) { $denied[] = true; }
echo json_encode([
  'scope'=>brvtal_backup_normalize_scope('complete'),
  'config'=>$c,
  'next'=>brvtal_backup_automation_next_run(
    $c,new DateTimeImmutable('2026-09-26T07:59:00Z')),
  'denied'=>$denied
],JSON_THROW_ON_ERROR);
"""

MEDIA_INVENTORY = r"""
require 'config/backups.php';
$inventory = brvtal_backup_inventory($argv[1], 'uploads');
$archive = brvtal_backup_create_media_archive(
  $argv[1], $inventory, $argv[2], 1
);
echo json_encode(['inventory'=>$inventory,'archive'=>$archive],JSON_THROW_ON_ERROR);
"""


def php(program: str, *args: Path) -> dict:
    binary = shutil.which("php")
    if not binary:
        raise AssertionError("PHP required; code has not been exercised")
    with tempfile.TemporaryDirectory(prefix="brvtal-1281-env-") as home:
        env = {"PATH": os.environ.get("PATH", "/usr/bin:/bin"),
               "HOME": home, "LANG": "C", "APP_ENV": "testing"}
        result = subprocess.run(
            [binary, "-d", "allow_url_fopen=0", "-r", program, *map(str, args)],
            cwd=ROOT, env=env, capture_output=True, text=True,
            check=False, timeout=35
        )
    if result.returncode:
        raise AssertionError("PHP synthetic fixture: " + result.stderr[-1100:])
    output = json.loads(result.stdout)
    if not isinstance(output, dict):
        raise AssertionError("PHP returned no JSON object")
    return output


def media_fixture() -> dict:
    with tempfile.TemporaryDirectory(prefix="brvtal-1281-media-") as temp:
        root = Path(temp) / "uploads"
        root.mkdir()
        (root / "a.txt").write_text("a", encoding="utf-8")
        (root / "b.txt").write_text("synthetic-b", encoding="utf-8")
        result = php(MEDIA_INVENTORY, root, Path(temp) / "archive.zip")
        if (Path(temp) / "archive.zip").exists():
            raise AssertionError("oversize media archive unexpectedly created")
        return result


class CoberturaBrvtal12Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        """AC-01: 15 real executable synthetic slices, 5 non-executable rows."""
        probe = theme.probe()  # calls actual Theme Studio JS in the Node VM
        source, panel, runtime = theme.sections()
        projection = probe["preview"]
        allowed = {"slug", "branding", "colors", "typography",
                   "navigation", "effects", "sound"}
        clean = set(projection) == allowed
        config = theme.CONFIG.read_text(encoding="utf-8")
        public = theme.API.read_text(encoding="utf-8")
        preview_ui = theme.PREVIEW_UI.read_text(encoding="utf-8")
        preview_api = theme.PREVIEW_API.read_text(encoding="utf-8")
        preview_page = theme.PREVIEW_PAGE.read_text(encoding="utf-8")
        fixture = theme.synthetic_theme()
        theme_cases = {
            "S32-R015": clean and "MOVED TO SETTINGS" in config
                and "if (seoTab) seoTab.hidden = true;" in config
                and "unset($themeOut['analytics'], $themeOut['customCode'])" in public,
            "S32-R016": clean and 'src="/?theme_studio_preview=1"' in panel
                and "theme:concept05PreviewTheme(t)" in source
                and "applyTheme(previewTheme)" in runtime
                and "event.origin !== window.location.origin" in runtime,
            "S32-R017": 'data-theme-canonical-preview' in panel
                and "BRVTALPublicPreview" in preview_ui
                and "const endpoint = '/api/public-preview.php'" in preview_ui
                and "brvtalPublicPreviewStore($snapshot)" in preview_api
                and "brvtalPublicEntityDocument(" in preview_page
                and "/api/public-preview.php" not in source,
            "S32-R019": clean and all(probe["unsupportedPresets"].values())
                and "if (kind !== 'concept05') return;" in source,
            "S32-R020": clean and 'src="/?theme_studio_preview=1"' in panel
                and "window.addEventListener('message', receivePreviewTheme)" in runtime
                and "event.source !== window.parent" in runtime,
            "S32-R021": clean and "customCode" not in projection
                and "unsafe-fixture-marker" not in json.dumps(projection)
                and "unset($themeOut['analytics'], $themeOut['customCode'])" in public,
            "S32-R022": clean and len(probe["fontOptions"]["display"]) >= 2
                and not {"pages", "breakpoints", "zIndex", "layout"} & set(projection),
            "S32-R023": clean and all(probe["unsupportedPresets"].values())
                and "RESET VISUALS TO CONCEPT 05" in panel,
            "S32-R024": clean
                and projection["colors"]["primary"] == fixture["colors"]["primary"]
                and projection["colors"]["accent"] == fixture["colors"]["accent"]
                and projection["typography"]["bodySize"] == "16px"
                and projection["navigation"]["menuStyle"] == "fullscreen"
                and projection["effects"]["motion"] == "brvtal",
        }
        plan = php(BACKUP_OPERATIONS)
        media = media_fixture()
        verified_backup = backups.run_fixture("php", backups.PHP_FIXTURE)
        verified_ui = backups.run_fixture("node", backups.NODE_FIXTURE)
        if verified_backup is None or verified_ui is None:
            raise AssertionError("PHP/Node required for backup evidence")
        endpoint = backups.ENDPOINT.read_text(encoding="utf-8")
        backups_cases = {
            "S33-R002": plan["scope"] == "full"
                and plan["config"]["enabled"] is True
                and plan["config"]["scope"] == "database"
                and plan["config"]["include_media_archive"] is False
                and plan["next"] == "2026-09-26T08:00:00+00:00",
            "S33-R006": media["inventory"]["files"] == 2
                and media["inventory"]["bytes"] == 12
                and [item["path"] for item in media["inventory"]["entries"]] ==
                    ["uploads/a.txt", "uploads/b.txt"]
                and media["archive"]["status"] == "unavailable",
            "S33-R007": verified_backup["manifest"] is True,
            "S33-R008": verified_backup["protected_download"] is True
                and "brvtal_admin_require();" in endpoint.split(
                    "if ($method === 'GET' && $action === 'download')", 1)[0],
            "S33-R009": verified_backup["history"] is True
                and verified_ui["history"] is True
                and verified_ui["empty"] is True
                and "brvtal_backup_list()" in endpoint,
            "S33-R011": verified_backup["private_storage"] is True
                and verified_backup["protected_download"] is True,
        }
        verdict = {**theme_cases, **backups_cases}
        self.assertEqual(A, set(verdict))
        self.assertEqual(len(A) + len(B) + len(C), 20)
        self.assertFalse((set(A) & set(B)) | (set(A) & set(C)))
        for key, actual in verdict.items():
            with self.subTest(row=key):
                self.assertTrue(actual, key + ": real code behavior not proven")

    def test_falla_cerrado(self) -> None:
        """AC-02: invalid options, scope, traversal and unsafe editor data."""
        projected = theme.probe()["preview"]
        for key in ("customCode", "eventPreset", "pages", "layout",
                    "analytics", "seo", "builder", "skins"):
            with self.subTest(key=key):
                self.assertNotIn(key, projected)
        self.assertTrue(all(theme.probe()["unsupportedPresets"].values()))
        self.assertNotEqual(projected["typography"]["h1"],
                            theme.synthetic_theme()["typography"]["h1"])
        self.assertEqual("left", projected["navigation"]["logoPosition"])
        self.assertEqual([True, True, True], php(BACKUP_OPERATIONS)["denied"])
        secured = backups.run_fixture("php", backups.PHP_FIXTURE)
        self.assertIsNotNone(secured)
        self.assertTrue(secured["protected_download"])
        sanitized_ui = backups.run_fixture("node", backups.NODE_FIXTURE)
        self.assertIsNotNone(sanitized_ui)
        self.assertTrue(sanitized_ui["escaped"])
        self.assertTrue(backups.audit_source_contracted(
            backups.ENDPOINT.read_text(encoding="utf-8")
        ))

    def test_sin_datos_reales(self) -> None:
        """AC-03: no production state or customer records enter the fixtures."""
        data = json.loads((ROOT / "datos.yml").read_text(encoding="utf-8"))
        self.assertEqual("construccion", data["phase"])
        self.assertEqual("brvtal-core", theme.synthetic_theme()["slug"])
        serialized = json.dumps(theme.probe()["preview"])
        for field in ("unsafe-fixture-marker", "apiKey", "access_token",
                      "authorization", "Bearer "):
            self.assertNotIn(field, serialized)
        self.assertIn("SYNTHETIC FIXTURE; NO DATABASE", backups.PHP_FIXTURE)
        self.assertNotIn("new PDO(", backups.PHP_FIXTURE)
        self.assertNotIn("brvtal_backup_create(", backups.PHP_FIXTURE)
        self.assertNotIn("fetch(", backups.NODE_FIXTURE)
        own = Path(__file__).read_text(encoding="utf-8")
        for node in ast.walk(ast.parse(own)):
            if isinstance(node, ast.Import):
                self.assertFalse(
                    {item.name.split(".")[0] for item in node.names}
                    & {"requests", "urllib", "socket", "httpx", "pymysql"}
                )
        self.assertEqual((len(A), len(B), len(C)), (15, 3, 2))


if __name__ == "__main__":
    unittest.main()
