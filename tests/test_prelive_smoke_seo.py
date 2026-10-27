"""#1044: synthetic, fail-closed smoke/SEO evidence contract. No network I/O."""
from __future__ import annotations

import copy
import re
import unittest
from pathlib import Path
from urllib.parse import urlsplit

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs" / "prelive-smoke-seo.md"
ORIGIN = "https://www.brvtal.com.co"
SHA = "a" * 40  # Synthetic SHA, NOT a real deployment.
NOW = 1_800_000_000
PUBLIC_PATHS = ("/", "/en", "/events/synthetic-event", "/en/events/synthetic-event")
PRIVATE_PATHS = ("/discadmin/", "/api/", "/config/", "/database/", "/storage/")


class InvalidEvidence(ValueError):
    """Invalid or unknown evidence never certifies production readiness."""


def require_exact(data, fields):
    if not isinstance(data, dict) or set(data) != set(fields):
        raise InvalidEvidence("unexpected, missing or sensitive fields")
    return data


def canonical_url(value, path):
    if not isinstance(value, str) or value != ORIGIN + path:
        return False
    try:
        parsed = urlsplit(value)
    except ValueError:
        return False
    return (parsed.scheme == "https" and parsed.netloc == "www.brvtal.com.co"
            and parsed.path == path and not parsed.query and not parsed.fragment
            and parsed.username is None and parsed.password is None)


def synthetic_fixture():
    """Pure fixture; never reads DNS, TLS, HTTP, browsers or profile data."""
    pages = {}
    for path in PUBLIC_PATHS:
        suffix = path.removeprefix("/en") if path.startswith("/en") else path
        suffix = suffix or "/"
        es = ORIGIN + suffix
        en = ORIGIN + ("/en" if suffix == "/" else "/en" + suffix)
        pages[path] = {
            "status": 200, "url": ORIGIN + path,
            "canonical": ORIGIN + path, "indexable": True,
            "hreflang": {"es": es, "en": en, "x-default": es},
        }
    return {
        "mode": "synthetic-offline", "sha": SHA, "version": "0.0.0-fixture",
        "observed_at": NOW - 10, "origin": ORIGIN, "redirects": [],
        "dns": {"host": "www.brvtal.com.co", "resolved": True},
        "tls": {"host": "www.brvtal.com.co", "valid": True,
                "expires_at": NOW + 86400},
        "pages": pages,
        "robots": {"status": 200, "allow_public": True,
                   "sitemap": ORIGIN + "/sitemap.xml",
                   "disallow": list(PRIVATE_PATHS)},
        "sitemap": {"status": 200, "content_type": "application/xml",
                    "urls": [ORIGIN + "/", ORIGIN + "/en"]},
        "auth": {"identity": "synthetic", "result": "simulated-success",
                 "writes": 0, "network_calls": 0},
    }


def validate_synthetic_evidence(evidence, expected_sha, now):
    """Return True only on an exact, safe fixture, without external effects."""
    row = require_exact(evidence, {
        "mode", "sha", "version", "observed_at", "origin", "redirects",
        "dns", "tls", "pages", "robots", "sitemap", "auth",
    })
    if row["mode"] != "synthetic-offline":
        raise InvalidEvidence("real/live observations outside synthetic scope")
    if (type(expected_sha) is not str
            or re.fullmatch(r"[0-9a-f]{40}", expected_sha) is None
            or row["sha"] != expected_sha):
        raise InvalidEvidence("exact source SHA not verified")
    if (type(row["version"]) is not str
            or re.fullmatch(r"[0-9]+\.[0-9]+\.[0-9]+-fixture", row["version"]) is None):
        raise InvalidEvidence("version is not an explicit fixture version")
    observed = row["observed_at"]
    if (type(now) is not int or type(observed) is not int
            or observed > now or now - observed > 900):
        raise InvalidEvidence("stale or future evidence")
    if row["origin"] != ORIGIN or row["redirects"] != []:
        raise InvalidEvidence("unapproved origin or redirect")
    dns = require_exact(row["dns"], {"host", "resolved"})
    tls = require_exact(row["tls"], {"host", "valid", "expires_at"})
    if dns != {"host": "www.brvtal.com.co", "resolved": True}:
        raise InvalidEvidence("DNS evidence is not verified")
    if (tls["host"] != "www.brvtal.com.co" or tls["valid"] is not True
            or type(tls["expires_at"]) is not int or tls["expires_at"] <= now):
        raise InvalidEvidence("TLS evidence expired or invalid")
    pages = require_exact(row["pages"], PUBLIC_PATHS)
    for path in PUBLIC_PATHS:
        page = require_exact(pages[path], {
            "status", "url", "canonical", "indexable", "hreflang",
        })
        if (type(page["status"]) is not int or page["status"] != 200
                or page["indexable"] is not True
                or not canonical_url(page["url"], path)
                or not canonical_url(page["canonical"], path)):
            raise InvalidEvidence("noncanonical or nonindexable public route")
        alternate = require_exact(page["hreflang"], {"es", "en", "x-default"})
        suffix = path.removeprefix("/en") if path.startswith("/en") else path
        suffix = suffix or "/"
        es = ORIGIN + suffix
        en = ORIGIN + ("/en" if suffix == "/" else "/en" + suffix)
        if alternate != {"es": es, "en": en, "x-default": es}:
            raise InvalidEvidence("missing/nonreciprocal hreflang")
    robots = require_exact(
        row["robots"], {"status", "allow_public", "sitemap", "disallow"})
    if (type(robots["status"]) is not int or robots["status"] != 200
            or robots["allow_public"] is not True
            or not canonical_url(robots["sitemap"], "/sitemap.xml")
            or robots["disallow"] != list(PRIVATE_PATHS)):
        raise InvalidEvidence("robots disclosure/indexability")
    sitemap = require_exact(
        row["sitemap"], {"status", "content_type", "urls"})
    if (type(sitemap["status"]) is not int or sitemap["status"] != 200
            or sitemap["content_type"] != "application/xml"
            or sitemap["urls"] != [ORIGIN + "/", ORIGIN + "/en"]):
        raise InvalidEvidence("sitemap incomplete or altered")
    auth = require_exact(
        row["auth"], {"identity", "result", "writes", "network_calls"})
    if (auth["identity"] != "synthetic"
            or auth["result"] != "simulated-success"
            or type(auth["writes"]) is not int or auth["writes"] != 0
            or type(auth["network_calls"]) is not int
            or auth["network_calls"] != 0):
        raise InvalidEvidence("authenticated smoke would have real effects")
    return True


class PreliveSmokeSeoTests(unittest.TestCase):
    def test_matrix_urls_sitemap_canonical_robots_and_locales(self):
        doc = DOC.read_text(encoding="utf-8")
        for term in ("DNS", "TLS", "robots.txt", "sitemap.xml",
                     "canonical", "hreflang", "ES/EN", "indexability",
                     "fail-closed", ORIGIN, "x-default"):
            self.assertIn(term, doc)
        self.assertTrue(validate_synthetic_evidence(
            synthetic_fixture(), SHA, NOW))
        self.assertEqual(set(synthetic_fixture()["pages"]), set(PUBLIC_PATHS))

    def test_smoke_exact_sha_version_and_no_provider_writes(self):
        doc = DOC.read_text(encoding="utf-8")
        for term in ("SHA", "versión", "sintética", "sin red", "no go-live",
                     "sin escrituras", "autenticado", "público"):
            self.assertIn(term.casefold(), doc.casefold())
        self.assertTrue(validate_synthetic_evidence(
            synthetic_fixture(), SHA, NOW))
        for field, value in (
            ("sha", "b" * 40), ("mode", "live"), ("version", "latest"),
        ):
            changed = synthetic_fixture()
            changed[field] = value
            with self.assertRaises(InvalidEvidence):
                validate_synthetic_evidence(changed, SHA, NOW)

    def test_stale_redirects_and_secret_leakage_fail_closed(self):
        invalid = []
        stale = synthetic_fixture()
        stale["observed_at"] = NOW - 901
        invalid.append(stale)
        future = synthetic_fixture()
        future["observed_at"] = NOW + 1
        invalid.append(future)
        redirect = synthetic_fixture()
        redirect["redirects"] = [ORIGIN + "/login"]
        invalid.append(redirect)
        off_host = synthetic_fixture()
        off_host["pages"]["/"]["canonical"] = "https://other.example/"
        invalid.append(off_host)
        secret = synthetic_fixture()
        secret["auth"]["token"] = "synthetic-secret"
        invalid.append(secret)
        extra = synthetic_fixture()
        extra["cookie"] = "synthetic-private"
        invalid.append(extra)
        leak = synthetic_fixture()
        leak["pages"]["/"]["url"] += "?api_key=synthetic-secret"
        invalid.append(leak)
        expired = synthetic_fixture()
        expired["tls"]["expires_at"] = NOW
        invalid.append(expired)
        exposed = synthetic_fixture()
        exposed["robots"]["disallow"] = []
        invalid.append(exposed)
        for bad in invalid:
            with self.subTest(case=str(bad)[:80]):
                original = copy.deepcopy(bad)
                with self.assertRaises(InvalidEvidence):
                    validate_synthetic_evidence(bad, SHA, NOW)
                self.assertEqual(bad, original)


if __name__ == "__main__":
    unittest.main()
