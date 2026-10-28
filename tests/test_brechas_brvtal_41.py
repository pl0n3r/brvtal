#!/usr/bin/env python3
"""BRVTAL #1131: privacy/analytics/legal S30-R003..S31-R002, synthetic only.

Offline behavioral contracts do not attest live tracking, cookie deployment,
actual attendee data, Colombia-law compliance, or legal sign-off. The matrix
remains NO_VERIFICABLE until a separate authorized reconciliation.
"""
from __future__ import annotations

import json
import re
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
SPEC = ROOT / "docs/BRVTAL-SPEC.md"
DATA = ROOT / "datos.yml"
MEASUREMENT = ROOT / "js/public-measurement.js"
BOOTSTRAP = ROOT / "js/public-analytics.js"
LEGAL = ROOT / "docs/privacidad"
CLAUSES = ("S30-R003", "S30-R004", "S30-R005", "S31-R001", "S31-R002")
UNKNOWN = "UNKNOWN"

# Run original measurement functions inside an isolated Node VM. We provide
# no real browser, no network APIs, no credentials and no persistent storage.
NODE_PROBE = r"""
'use strict';
const fs = require('node:fs');
const vm = require('node:vm');
const original = fs.readFileSync('js/public-measurement.js', 'utf8');
const end = original.lastIndexOf('})();');
if (end < 0 || original.slice(end + 5).trim()) {
  throw new Error('UNKNOWN: cannot safely isolate measurement source');
}
const expose = [
  'globalThis.__fixture = { sanitizeParams, safeDestination, push, pageContext };'
].join('\n') + '\n';
const sandbox = {
  window: {dataLayer:[], addEventListener(){}},
  document: {readyState:'loading', addEventListener(){}},
  location: {
    pathname:'/events/fixture', href:'https://fixture.invalid/events/fixture',
    origin:'https://fixture.invalid'
  }
};
vm.runInNewContext(original.slice(0,end) + expose + original.slice(end),
  sandbox, {timeout:1500, filename:'public-measurement.js'});
const f = sandbox.__fixture;
const params = f.sanitizeParams({
  section:'hero', content_id:3, email:'private-fixture', phone:'000',
  purchase_id:42, attendee:'private-fixture', amount:500, cookie:'private',
  ticket_price:100, personal_name:'private-fixture'
});
const accepted = f.push('brvtal_section_view', {
  section:'hero', email:'private-fixture', purchase_id:42,
  ticket_price:100, attendee:'private-fixture'
});
const rejected = f.push('unscoped_event', {email:'private-fixture'});
process.stdout.write(JSON.stringify({
  params, accepted, rejected, dataLayer:sandbox.window.dataLayer,
  safeLink:f.safeDestination(
    'https://outside.invalid/events?email=private-fixture#details'),
  context:f.pageContext()
}));
"""


def matrix_rows(document: str) -> dict[str, tuple[str, ...]]:
    """Exactly five canonical, complete rows; never infer missing evidence."""
    found: dict[str, tuple[str, ...]] = {}
    for line in document.splitlines():
        first = re.match(r"^\| (S3[01]-R\d{3}) · ", line)
        if not first or first.group(1) not in CLAUSES:
            continue
        key = first.group(1)
        parts = tuple(item.strip() for item in line.strip().strip("|").split("|"))
        if key in found or len(parts) != 5 or not all(parts):
            raise ValueError("UNKNOWN: duplicate/malformed coverage row: " + key)
        if not parts[0].startswith(key + " · "):
            raise ValueError("UNKNOWN: ambiguous coverage row: " + key)
        found[key] = parts
    if set(found) != set(CLAUSES):
        raise ValueError("UNKNOWN: missing coverage rows")
    return found


def operational_evidence(candidate: object = None) -> str:
    """Fixtures and declarative claims cannot authenticate legal/live status."""
    return UNKNOWN


def node_probe() -> dict:
    node = shutil.which("node")
    if not node:
        raise AssertionError("UNKNOWN: Node runtime unavailable")
    run = subprocess.run(
        [node, "-e", NODE_PROBE], cwd=ROOT,
        text=True, capture_output=True, check=False, timeout=20,
    )
    if run.returncode:
        raise AssertionError("UNKNOWN: measurement probe failed: " + run.stderr[-900:])
    try:
        return json.loads(run.stdout)
    except json.JSONDecodeError as exc:
        raise AssertionError("UNKNOWN: unreadable measurement probe") from exc


def treatments_by_id(document: str) -> dict[str, dict]:
    raw = json.loads(document)
    rows = raw.get("treatments")
    if raw.get("phase") != "construccion" or not isinstance(rows, list):
        raise ValueError("UNKNOWN: treatment registry unavailable")
    ids = [r.get("id") for r in rows if isinstance(r, dict)]
    if len(ids) != len(rows) or len(set(ids)) != len(ids):
        raise ValueError("UNKNOWN: ambiguous treatment registry")
    return {r["id"]: r for r in rows}


class BrechasBrvtal41Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.rows = matrix_rows(MATRIX.read_text(encoding="utf-8"))
        cls.spec = SPEC.read_text(encoding="utf-8")
        cls.data = DATA.read_text(encoding="utf-8")
        cls.treatments = treatments_by_id(cls.data)
        cls.result = node_probe()

    def test_contrato_principal(self):
        """AC-01: separate executable evidence for all five named clauses."""
        result = self.result
        registry = self.treatments
        measurement = MEASUREMENT.read_text(encoding="utf-8")
        bootstrap = BOOTSTRAP.read_text(encoding="utf-8")
        privacy = (LEGAL / "politica-tratamiento.md").read_text(encoding="utf-8")
        notice = (LEGAL / "aviso-privacidad.md").read_text(encoding="utf-8")
        terms = (LEGAL / "terminos-condiciones.md").read_text(encoding="utf-8")
        for clause in CLAUSES:
            with self.subTest(requirement=clause):
                self.assertIn(clause, self.rows)
                self.assertEqual(self.rows[clause][2], "NO_VERIFICABLE")
                self.assertIn("Sin aserción contrastada", self.rows[clause][3])
                self.assertEqual(operational_evidence(self.rows[clause]), UNKNOWN)
        self.assertIn("Use privacy-first data minimization.", self.spec)
        self.assertIn("Do not collect attendee purchasing data", self.spec)
        self.assertIn("Cookie handling should remain minimal", self.spec)
        self.assertIn("Legal structure is primarily oriented to Colombia", self.spec)
        self.assertIn("privacy, cookie and terms requirements", self.spec)

        # S30-R003: real measurement allowlist ignores extraneous visitor data.
        self.assertEqual(result["params"], {"section":"hero", "content_id":3})
        self.assertTrue(result["accepted"])
        self.assertFalse(result["rejected"])
        self.assertEqual(len(result["dataLayer"]), 1)
        payload = result["dataLayer"][0]
        self.assertEqual(payload["event"], "brvtal_section_view")
        self.assertEqual(payload["section"], "hero")
        self.assertNotIn("email", payload)
        self.assertNotIn("attendee", payload)
        self.assertEqual(result["safeLink"], "https://outside.invalid/events")
        self.assertIn("sanitizeParams(params)", measurement)

        # S30-R004: registry and emitted fixture exclude purchasing/attendee
        # detail. A missing live inventory still cannot be marked compliant.
        public = registry["public_gtm_measurement"]
        self.assertEqual(public["purpose"], "public_usage_measurement")
        forbidden = ("purchase", "payment", "ticket_price", "buyer", "attendee",
                     "card_number", "customer_email", "phone")
        for field in public["fields"]:
            with self.subTest(privacy_field=field):
                self.assertFalse(any(token in field.lower() for token in forbidden))
        self.assertTrue(all(token not in json.dumps(result["dataLayer"]).lower()
                            for token in ("purchase", "attendee", "ticket_price", "email")))

        # S30-R005: only explicitly configured tag delivery and documented
        # consent/storage defaults; not proof of actual deployed cookies.
        self.assertIn("script[data-gtm-id]", bootstrap)
        self.assertIn("if (!/^GTM-[A-Z0-9]{4,20}$/.test(id)) return;", bootstrap)
        self.assertIn("ad_storage: 'denied'", bootstrap)
        self.assertIn("ad_user_data: 'denied'", bootstrap)
        self.assertIn("ad_personalization: 'denied'", bootstrap)
        self.assertIn("analytics_storage: 'granted'", bootstrap)
        self.assertNotIn("localStorage.setItem", bootstrap)
        self.assertNotIn("document.cookie", bootstrap)

        # S31-R001: Colombia-oriented project with deliberately pending legal
        # owner/jurisdiction, not fabricated Colombian certification.
        self.assertEqual(json.loads(self.data)["project"], "pl0n3r/brvtal")
        self.assertIn("Jurisdicción y mecanismo de solución de controversias", terms)
        self.assertIn("[COMPLETAR POR EL DUEÑO]", terms)
        self.assertIn("revisión jurídica requerida", terms)

        # S31-R002: data-map purposes/providers are shown by the generated
        # privacy documents, but remain explicitly subject to legal review.
        self.assertIn("| public_gtm_measurement |", privacy)
        self.assertIn("| public_gtm_measurement |", notice)
        self.assertIn("google_analytics", privacy)
        self.assertIn("revisión jurídica requerida", notice)
        self.assertIn("no constituye aprobación jurídica", privacy)
        self.assertIn("revisión jurídica requerida", terms)
        self.assertTrue(all(row["basis"] == "review_required" and
                            row["consent"] == "review_required"
                            for row in registry.values()))

    def test_falla_cerrado(self):
        """AC-02: missing/duplicate/ambiguous rows and registries => UNKNOWN."""
        document = MATRIX.read_text(encoding="utf-8")
        row = next(line for line in document.splitlines()
                   if line.startswith("| S30-R003 · "))
        for damaged in (
            document.replace(row, ""),
            document + "\n" + row,
            document.replace(row, "| S30-R003 · truncated |"),
        ):
            with self.subTest(damage=damaged.count("S30-R003")):
                with self.assertRaises(ValueError):
                    matrix_rows(damaged)
        for bad in ('{"phase":"construccion","treatments":[]}',
                    '{"phase":"live","treatments":[]}',
                    '{"phase":"construccion","treatments":[{"id":"x"},{"id":"x"}]}'):
            if bad.startswith('{"phase":"construccion","treatments":[]}'):
                self.assertEqual(treatments_by_id(bad), {})
            else:
                with self.assertRaises(ValueError):
                    treatments_by_id(bad)
        self.assertEqual(operational_evidence({"ci":"success"}), UNKNOWN)
        self.assertEqual(operational_evidence({"legal":"approved"}), UNKNOWN)
        self.assertEqual(operational_evidence(None), UNKNOWN)

    def test_sin_datos_reales(self):
        """AC-03: test inputs only contain fictional browser/no-user data."""
        fixture = self.result
        self.assertEqual(fixture["context"]["content_slug"], "fixture")
        self.assertNotIn("http.request", NODE_PROBE)
        self.assertNotIn("https.request", NODE_PROBE)
        self.assertNotIn("fetch(", NODE_PROBE)
        self.assertNotIn("child_process", NODE_PROBE)
        self.assertNotIn("cookie=", json.dumps(fixture))
        self.assertNotIn("private-fixture", json.dumps(fixture))
        self.assertNotIn("email", json.dumps(fixture).lower())
        self.assertNotIn("token", json.dumps(fixture).lower())

    def test_estados_unknown_explicitos(self):
        """AC-04: no offline test can certify production, cookies or law."""
        self.assertEqual(set(self.rows), set(CLAUSES))
        for clause in CLAUSES:
            with self.subTest(clause=clause):
                self.assertEqual(self.rows[clause][2], "NO_VERIFICABLE")
                for claim in (None, {}, {"runtime": "green"},
                              {"cookies": "compliant"}, {"jurisdiction": "CO"},
                              {"attendee_records": 0}, {"legal_review": True}):
                    self.assertEqual(operational_evidence(claim), UNKNOWN)


if __name__ == "__main__":
    unittest.main()
