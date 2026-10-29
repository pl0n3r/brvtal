#!/usr/bin/env python3
"""#1280: actual offline S30-S32 behavior, not deployment or legal approval.

Executes real public measurement, analytics bootstrap, Theme Studio and legal
handoff sources through the existing Node VM and temporary-fixture harnesses.
"""
from __future__ import annotations

import ast
import json
from pathlib import Path
import sys
import unittest

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / "tests"))
import test_brechas_brvtal_41 as privacy
import test_brechas_brvtal_42 as legal
import test_brechas_brvtal_43 as studio
import test_brechas_brvtal_44 as reset

IDS = (
    "S30-R003", "S30-R004", "S30-R005", "S31-R001", "S31-R002",
    "S31-R003", "S31-R004", "S32-R002", "S32-R003", "S32-R004",
    "S32-R005", "S32-R006", "S32-R007", "S32-R008", "S32-R009",
    "S32-R010", "S32-R011", "S32-R012", "S32-R013", "S32-R014",
)
# A = actual exercised offline code, never full product/live acceptance.
A = frozenset(IDS) - {"S31-R001", "S31-R002", "S32-R004", "S32-R009"}
B = {
    "S31-R002": "Legal/privacy/cookie applicability needs counsel and production evidence",
    "S32-R004": "Media Library selection needs authorized database/media integration",
    "S32-R009": "Persisted draft and single active theme need transactional DB evidence",
}
C = {"S31-R001": "Colombia-oriented legal structure is owner/counsel policy"}
D: dict[str, str] = {}


class CoberturaBrvtal11Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        self.assertEqual(A | B.keys() | C.keys() | D.keys(), set(IDS))
        self.assertFalse((A & B.keys()) or (A & C.keys()))

        # Original, unmodified application source executed, not row-status checks.
        measurement = privacy.run_probe(privacy.NODE_PROBE)
        bootstrap = privacy.run_probe(privacy.BOOTSTRAP_PROBE)
        handoff = legal.legal_fixture()  # real generator in temporary fixture dir
        theme = studio.probe()  # real Theme Studio functions in Node VM
        reset_run = reset.offline_probe(reset.theme_fixture())
        fixture = reset.theme_fixture()
        projected, bounded = theme["projected"], theme["bounded"]
        full = reset_run["reset"]
        fonts = {opt["stack"] for group in theme["fonts"].values() for opt in group}
        contract = reset.source_contract(
            reset.STUDIO.read_text(encoding="utf-8"),
            reset.RUNTIME.read_text(encoding="utf-8"))
        panel = contract["S32-R013"]
        evidence = {
            "S30-R003": (
                measurement["params"] == {"section": "hero", "content_id": 3}
                and measurement["accepted"] is True
                and measurement["rejected"] is False
                and measurement["safeLink"] == "https://outside.invalid/events"),
            "S30-R004": (
                len(measurement["dataLayer"]) == 1
                and measurement["dataLayer"][0]["event"] == "brvtal_section_view"
                and not any(x in json.dumps(measurement["dataLayer"]).lower()
                            for x in ("attendee", "purchase", "email", "ticket_price"))),
            "S30-R005": (
                len(bootstrap["valid"]["tags"]) == 1
                and len(bootstrap["valid"]["consent"]) == 1
                and bootstrap["valid"]["consent"][0][2]["ad_storage"] == "denied"
                and bootstrap["valid"]["consent"][0][2]["ad_user_data"] == "denied"
                and bootstrap["missing"]["tags"] == []
                and bootstrap["invalid"]["tags"] == []),
            "S31-R003": handoff["unapproved"] is True,
            "S31-R004": handoff["human_review"] is True
                and handoff["not_mutating_check"] is True,
            "S32-R002": (
                "layout" not in reset_run["projected"]
                and "renderer" not in reset_run["projected"]
                and "customCode" not in projected),
            "S32-R003": (
                set(reset_run["projected"]) ==
                {"slug", "branding", "colors", "typography", "navigation", "effects", "sound"}
                and studio.evidence_status(projected, fonts) == "CONTRACT_ONLY"),
            "S32-R005": (
                theme["colorsReset"]["colors"]["bg"] == "#050505"
                and theme["colorsReset"]["colors"]["primary"] == "#E31B23"
                and theme["contrast"] >= 4.5
                and theme["sameColorContrast"] == 1),
            "S32-R006": (
                set(theme["fonts"]) == {"display", "body", "mono"}
                and "Space Grotesk" in theme["fonts"]["display"][0]["label"]
                and "Space Mono" in theme["fonts"]["mono"][0]["label"]
                and theme["typographyReset"]["colors"] == studio.fixture()["colors"]),
            "S32-R007": (
                bounded["navigation"]["menuStyle"] == "fullscreen"
                and bounded["navigation"]["logoPosition"] == "left"
                and bounded["effects"]["motion"] == "brvtal"
                and bounded["sound"]["enabled"] is False),
            "S32-R008": (
                theme["desktop"]["attrs"] == {"width": "1440", "height": "900"}
                and theme["mobile"]["attrs"] == {"width": "390", "height": "844"}
                and theme["desktop"]["dataset"]["canonicalWidth"] == "1440"
                and theme["mobile"]["dataset"]["canonicalWidth"] == "390"),
            "S32-R010": (
                full["colors"] == reset_run["visual"]["colors"]
                and full["typography"] == reset_run["visual"]["typography"]
                and full["effects"] == reset_run["visual"]["effects"]),
            "S32-R011": (
                all(full[k] == fixture[k] for k in
                    ("slug", "branding", "seo", "layout", "legacy"))
                and reset_run["palette"]["typography"] == fixture["typography"]
                and reset_run["type"]["colors"] == fixture["colors"]),
            "S32-R012": (
                reset_run["projected"]["branding"] == reset_run["accent"]["branding"]
                and reset_run["projected"]["slug"] == reset_run["accent"]["slug"]
                and reset_run["projected"]["colors"]["primary"] == "#E31B23"
                and reset_run["projected"]["colors"]["accent"] == "#B6FF00"
                and reset_run["accent"]["colors"]["accent"] == "#C6FF33"),
            "S32-R013": (
                contract["S32-R013"] != "UNKNOWN"
                and all(x in panel for x in (
                    "fontSelectField('display'", "toggleField('grain'",
                    "selectField('menuStyle'", "selectField('motion'"))
                and "textField('analytics'" not in panel
                and "textField('seo'" not in panel),
            "S32-R014": (
                not set(reset_run["projected"]) &
                    {"layout", "zIndex", "breakpoints", "legacy", "customCode"}
                and "textField('bodySize'" not in panel
                and "textField('animationTimeline'" not in panel),
        }
        self.assertEqual(set(evidence), A)
        for key, behaves in evidence.items():
            with self.subTest(row=key):
                self.assertTrue(behaves, key + ": executable behavior regressed")

    def test_falla_cerrado(self) -> None:
        theme = studio.probe()
        fonts = {opt["stack"] for group in theme["fonts"].values() for opt in group}
        for bad in (
            None, {}, theme["missing"],
            {**theme["projected"], "colors": {
                **theme["projected"]["colors"], "bg": "script(unsafe)"}},
            {**theme["projected"], "typography": {
                **theme["projected"]["typography"], "display": "unknown-remote-font"}},
        ):
            self.assertEqual(studio.evidence_status(bad, fonts), "UNKNOWN")
        bootstrap = privacy.run_probe(privacy.BOOTSTRAP_PROBE)
        self.assertEqual(bootstrap["missing"]["tags"], [])
        self.assertEqual(bootstrap["invalid"]["tags"], [])
        self.assertTrue(legal.legal_fixture()["not_mutating_check"])

    def test_sin_datos_reales(self) -> None:
        own = Path(__file__).read_text(encoding="utf-8")
        forbidden = {"requests", "httpx", "socket", "urllib", "pymysql", "psycopg2"}
        for node in ast.walk(ast.parse(own)):
            if isinstance(node, ast.Import):
                self.assertFalse({a.name.split(".")[0] for a in node.names} & forbidden)
            elif isinstance(node, ast.ImportFrom) and node.module:
                self.assertNotIn(node.module.split(".")[0], forbidden)
        for probe in (privacy.NODE_PROBE, privacy.BOOTSTRAP_PROBE,
                      studio.NODE_PROBE, reset.NODE_PROBE):
            self.assertNotIn("fetch(", probe)
            self.assertNotIn("https.request", probe)
            self.assertNotIn("child_process", probe)
        output = json.dumps(studio.probe()["projected"])
        for token in ("synthetic-never-export", "synthetic-never-execute",
                      "private-fixture", "password", "access_token"):
            self.assertNotIn(token, output)
        self.assertEqual(len(A | B.keys() | C.keys() | D.keys()), 20)


if __name__ == "__main__":
    unittest.main()
