#!/usr/bin/env python3
"""BRVTAL #1163: five SPEC §46–48 requirements; offline, fail-closed.

A synthetic deployment SHA does not prove production cache contents.
Architectural policy and incomplete public records remain UNKNOWN.
"""
from __future__ import annotations

import ast
import json
import os
from pathlib import Path
import re
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_SYNTHETIC_ONLY"
GAP = "BRECHA_DOCUMENTADA"
CLAUSES = {
    "S46-R004": ("S46", 718, "Admin enhancement assets use deployment-SHA cache busting so browsers receive the code corresponding to the deployed checkout without requiring manual hard-refresh behavior."),
    "S47-R001": ("S47", 722, "Incomplete content must degrade gracefully."),
    "S47-R002": ("S47", 722, "No broken images or broken components for artists/events/releases lacking optional media or metadata."),
    "S48-R001": ("S48", 726, "Before creating a new table, endpoint, service, editor, media picker or settings system, check whether an existing implementation can be extended safely."),
    "S48-R002": ("S48", 728, "Do not rebuild existing Media Engine, Search, Activity, SEO, Archive or Related Content foundations under new parallel names."),
}
FILES = {
    "matrix": "docs/matriz-cobertura.md",
    "spec": "docs/BRVTAL-SPEC.md",
    "deployment": "config/deployment.php",
    "version": "config/version.php",
    "admin": "discadmin/index.php",
    "admin_core": "discadmin/index-core.php",
    "app": "js/app.js",
    "roster": "js/public-roster.js",
    "fallback_e2e": "tests/e2e/public-runtime-fallback.spec.mjs",
    "public_media": "js/public-media.js",
    "media_core": "config/media.php",
    "global_search": "discadmin/global-search.js",
    "admin_activity": "discadmin/admin-activity.js",
    "seo": "discadmin/seo-workspace.js",
    "archive": "js/archive.js",
    "related": "tests/related-content-contract.php",
}
EXPECTED = {
    "S46-R004": LOCAL,
    "S47-R001": GAP,
    "S47-R002": GAP,
    "S48-R001": GAP,
    "S48-R002": GAP,
}
GAPS = {
    "S47-R001": "Overall graceful degradation with every optional field and a real browser/session is UNKNOWN.",
    "S47-R002": "Image failures across artists, events and releases need full independent browser evidence; UNKNOWN.",
    "S48-R001": "A local inventory cannot prove every future change reused existing tables/endpoints/editors; UNKNOWN.",
    "S48-R002": "Existing foundations cannot prove the absence of every parallel service; UNKNOWN.",
}


def sources() -> dict[str, str]:
    return {key: (ROOT / path).read_text(encoding="utf-8")
            for key, path in FILES.items()}


def matrix_rows(raw: object) -> dict[str, tuple[str, ...]]:
    if type(raw) is not str or not raw.strip():
        raise ValueError(UNKNOWN + ": matrix absent")
    found = {}
    for line in raw.splitlines():
        m = re.match(r"^\| (S(?:46-R004|47-R00[12]|48-R00[12])) · ", line)
        if not m:
            continue
        key = m.group(1)
        cells = tuple(x.strip() for x in line.strip("|").split("|"))
        section, lineno, phrase = CLAUSES[key]
        if (key in found or len(cells) != 5 or cells[1] != phrase
                or cells[2] != "NO_VERIFICABLE"
                or f"[{section} L{lineno}]" not in cells[0]
                or f"BRVTAL-SPEC.md#L{lineno})" not in cells[0]
                or "Sin aserción contrastada" not in cells[3]
                or "falta enlazar prueba de ese inciso, archivo de código y PR fusionado" not in cells[3]
                or cells[4] != "—"):
            raise ValueError(UNKNOWN + ": invalid, duplicated or promoted " + key)
        found[key] = cells
    if set(found) != set(CLAUSES):
        raise ValueError(UNKNOWN + ": missing rows")
    return found


def spec_clause(raw: object, key: object) -> bool:
    if type(raw) is not str or key not in CLAUSES:
        return False
    section, _, phrase = CLAUSES[key]
    marker = {"S46": "## 46. Accessibility and performance\n",
              "S47": "## 47. Content fallbacks\n",
              "S48": "## 48. Avoid duplication\n"}[section]
    if raw.count(marker) != 1:
        return False
    return raw.split(marker, 1)[1].split("\n## ", 1)[0].count(phrase) == 1


def sanitized_env(home: str, commit: str) -> dict[str, str]:
    return {"HOME": home, "PATH": os.defpath, "LANG": "C",
            "APP_ENV": "testing", "BRVTAL_DEPLOY_COMMIT": commit}


def cache_probe(commit: str) -> dict:
    """Execute the checked-in PHP identity resolver with a synthetic SHA."""
    if not re.fullmatch(r"[a-f0-9]{40}", commit):
        raise ValueError(UNKNOWN + ": malformed synthetic SHA")
    php = ("require 'config/deployment.php';"
           "echo json_encode(brvtalDeploymentPublicData(),JSON_THROW_ON_ERROR);")
    with tempfile.TemporaryDirectory(prefix="brvtal-1163-") as home:
        proc = subprocess.run(
            ["php", "-r", php], cwd=ROOT, input="", text=True,
            capture_output=True, timeout=15, check=False,
            env=sanitized_env(home, commit),
        )
    if proc.returncode or proc.stderr.strip():
        raise AssertionError(UNKNOWN + ": PHP source contract unavailable")
    try:
        data = json.loads(proc.stdout)
    except (TypeError, ValueError) as exc:
        raise AssertionError(UNKNOWN + ": identity not JSON") from exc
    if type(data) is not dict or set(data) != {
        "commit", "short_commit", "source", "exact", "version",
        "release_identity", "cache_key", "environment", "release_date",
    }:
        raise AssertionError(UNKNOWN + ": identity incomplete")
    return data


def observe_cache() -> dict[str, bool]:
    first, second = "a" * 40, "b" * 40
    a, b = cache_probe(first), cache_probe(second)
    return {
        "exactSha": all(row["source"] == "environment"
                        and row["exact"] is True
                        and row["commit"] == commit
                        and row["short_commit"] == commit[:7]
                        and row["cache_key"] == commit[:7]
                        for row, commit in ((a, first), (b, second))),
        "changesWithSha": a["cache_key"] != b["cache_key"],
        "notVersion": all(row["cache_key"] != row["release_identity"]
                          for row in (a, b)),
    }


def evidence(key: object, data: object, probe: object) -> str:
    expected_probe = {"exactSha": True, "changesWithSha": True,
                      "notVersion": True}
    if (type(key) is not str or key not in CLAUSES
            or type(data) is not dict or set(data) != set(FILES)
            or any(type(v) is not str or not v for v in data.values())
            or type(probe) is not dict or probe != expected_probe
            or not spec_clause(data["spec"], key)):
        return UNKNOWN
    try:
        matrix_rows(data["matrix"])
    except ValueError:
        return UNKNOWN
    if key == "S46-R004":
        admin, core, dep = data["admin"], data["admin_core"], data["deployment"]
        valid = ("BRVTAL_DEPLOY_COMMIT" in dep
                 and "brvtalDeploymentIsExact()" in dep
                 and "brvtalDeploymentCacheKey()" in dep
                 and "rawurlencode(brvtalDeploymentCacheKey())" in admin
                 and "'?v=' . $assetVersion" in admin
                 and admin.count(" . $suffix") >= 8
                 and core.count("rawurlencode(brvtalDeploymentCacheKey())") >= 6)
        return LOCAL if valid else UNKNOWN
    if key == "S47-R001":
        valid = ("const normalize = (payload) =>" in data["app"]
                 and "return []" in data["app"]
                 and "STATIC / API OFFLINE" in data["app"]
                 and "updateStatus(false" in data["app"]
                 and "public navigation and CMS work without" in data["fallback_e2e"])
        return GAP if valid else UNKNOWN
    if key == "S47-R002":
        valid = ("const image = imgUrl(pick(event," in data["app"]
                 and "if (image)" in data["app"]
                 and "const name = String(artist.name ?? artist.title ?? 'UNKNOWN')" in data["roster"]
                 and "const photo = imageUrl(" in data["roster"]
                 and "data-image" in data["roster"]
                 and "bindImageFallback(image)" in data["public_media"]
                 and "image.hidden = true" in data["public_media"])
        return GAP if valid else UNKNOWN
    foundation_names = ("media_core", "global_search", "admin_activity",
                        "seo", "archive", "related")
    if not all(data[name].strip() for name in foundation_names):
        return UNKNOWN
    if key == "S48-R001":
        return GAP
    return GAP if all(phrase in data["spec"] for phrase in (
        "Media Engine", "Search", "Related Content",
    )) else UNKNOWN


def gap(key: object, data: object, probe: object) -> str:
    if key not in GAPS or evidence(key, data, probe) != GAP:
        return UNKNOWN
    return f"{key}: {GAP} — {GAPS[key]}"


def operational(_key: object, _claim: object = None) -> str:
    return UNKNOWN


class BrechasBrvtal73Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.data = sources()
        cls.probe = observe_cache()

    def test_contrato_principal(self) -> None:
        self.assertEqual(set(CLAUSES), set(matrix_rows(self.data["matrix"])))
        self.assertEqual({"exactSha": True, "changesWithSha": True,
                          "notVersion": True}, self.probe)
        for key in CLAUSES:
            with self.subTest(key=key):
                self.assertTrue(spec_clause(self.data["spec"], key))
                self.assertEqual(EXPECTED[key], evidence(key, self.data, self.probe))
                self.assertEqual(UNKNOWN, operational(key, self.probe))
                if key in GAPS:
                    self.assertIn(f"{key}: {GAP}", gap(key, self.data, self.probe))

    def test_falla_cerrado(self) -> None:
        raw = self.data["matrix"]
        for key in CLAUSES:
            line = next(x for x in raw.splitlines()
                        if x.startswith(f"| {key} · "))
            for bad in (
                raw.replace(line, "", 1),
                raw + "\n" + line,
                raw.replace(line, line.replace("NO_VERIFICABLE", "CUBIERTO"), 1),
                raw.replace(line, line.replace(CLAUSES[key][2], "MISSING"), 1),
            ):
                with self.subTest(key=key), self.assertRaisesRegex(ValueError, UNKNOWN):
                    matrix_rows(bad)
                self.assertEqual(UNKNOWN, evidence(
                    key, {**self.data, "matrix": bad}, self.probe))
            for name in FILES:
                self.assertEqual(UNKNOWN, evidence(
                    key, {**self.data, name: ""}, self.probe))
            self.assertEqual(UNKNOWN, evidence(key, self.data, {}))
        self.assertEqual(UNKNOWN, evidence("S99-R999", self.data, self.probe))
        with self.assertRaisesRegex(ValueError, UNKNOWN):
            cache_probe("unsafe")
        self.assertEqual(UNKNOWN, evidence(
            "S46-R004", self.data, {**self.probe, "exactSha": False}))

    def test_sin_datos_reales(self) -> None:
        tree = ast.parse(Path(__file__).read_text(encoding="utf-8"))
        imported = {item.name.split(".")[0] for node in ast.walk(tree)
                    if isinstance(node, ast.Import) for item in node.names}
        imported |= {node.module.split(".")[0] for node in ast.walk(tree)
                     if isinstance(node, ast.ImportFrom) and node.module}
        self.assertFalse(imported & {"requests", "httpx", "socket",
                                     "urllib", "playwright", "pymysql"})
        with tempfile.TemporaryDirectory(prefix="brvtal-1163-env-") as home:
            env = sanitized_env(home, "a" * 40)
            self.assertEqual("testing", env["APP_ENV"])
            self.assertEqual({"HOME", "PATH", "LANG", "APP_ENV",
                              "BRVTAL_DEPLOY_COMMIT"}, set(env))
            for name in ("DB_PASSWORD", "APP_KEY", "GH_TOKEN", "GITHUB_TOKEN",
                         "MYSQL_PWD", "HTTP_PROXY", "HTTPS_PROXY", "COOKIE"):
                self.assertNotIn(name, env)

    def test_estados_unknown_explicitos(self) -> None:
        for key in CLAUSES:
            with self.subTest(key=key):
                self.assertEqual("NO_VERIFICABLE",
                                 matrix_rows(self.data["matrix"])[key][2])
                for claim in (None, True, {"ci": "success"},
                              {"production": "GREEN"}, {"image": "loaded"},
                              {"architecture": "no_duplication"}):
                    self.assertEqual(UNKNOWN, operational(key, claim))
                if key in GAPS:
                    self.assertIn(UNKNOWN, gap(key, self.data, self.probe))
        self.assertFalse(spec_clause("## 48. Avoid duplication\n" * 2,
                                     "S48-R001"))


if __name__ == "__main__":
    unittest.main()
