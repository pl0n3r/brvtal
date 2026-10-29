#!/usr/bin/env python3
"""#1106: deterministic, fail-closed evidence for five Sets/Releases/Tickets gaps.

Source assertions demonstrate repository contracts, not integration or
production behavior. No network, database, user data or browser required.
"""
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
CONTRAST = ROOT / "docs/contraste-spec_resto.md"
SPEC = ROOT / "docs/BRVTAL-SPEC.md"
RELEASES = ROOT / "tests/releases-contract.php"
TICKETS = ROOT / "tests/e2e/ticket-types-editor.spec.mjs"
UNKNOWN = "UNKNOWN"
GAP = "DOCUMENTED_GAP"
LOCAL = "LOCAL_ASSERTION_ONLY"
CLAUSES = {
    "S13-R004": (183, "Sets can relate to artists and events."),
    "S14-R001": (187, "Releases are part of BRVTAL’s evolution into a label."),
    "S14-R003": (189, "Do not collapse Releases back into generic pages or Sets."),
    "S15-R001": (193, "BRVTAL does not initially process full payments internally."),
    "S15-R002": (193, "Ticket types may direct users to external methods such as ticketing platforms, Nequi, QR or external links."),
}
TICKET_IDS = {"S15-R001", "S15-R002"}


def read_rows(source, ids, contrast=False):
    """Accept each exact canonical row once; missing/duplicate/malformed => UNKNOWN."""
    if not isinstance(source, str):
        raise ValueError(UNKNOWN + ": unreadable evidence")
    rows = {}
    suffix = r"\s+\|" if contrast else r"\s+·"
    for line in source.splitlines():
        line = line.lstrip()
        match = re.match(r"^\|\s*(S(?:13|14|15)-R\d{3})" + suffix, line)
        if not match or match.group(1) not in ids:
            continue
        key = match.group(1)
        columns = tuple(part.strip() for part in line.strip().strip("|").split("|"))
        if key in rows or len(columns) != (6 if contrast else 5):
            raise ValueError(UNKNOWN + ": duplicate or malformed row")
        rows[key] = columns
    if set(rows) != set(ids):
        raise ValueError(UNKNOWN + ": missing coverage row")
    return rows


def source_asserted(key, releases, tickets):
    """Limited source evidence from contract tests, never a runtime claim."""
    if key == "S14-R001":
        fragments = (
            "CREATE TABLE IF NOT EXISTS releases",
            "CREATE TABLE IF NOT EXISTS release_artists",
            "ENUM('draft','published','archived')",
            "brvtal_public_releases",
            "WHERE status='published'",
        )
        return all(fragment in releases for fragment in fragments)
    if key == "S14-R003":
        fragments = (
            'data-admin-module="releases"',
            "releases: {url:'/discadmin/releases.php'",
            "['releases', ['media','releases']]",
            "'releases' => ",
        )
        return all(fragment in releases for fragment in fragments)
    if key == "S15-R002":
        fragments = (
            "expect(workflows[0].ticket_types).toEqual([",
            '[data-k="external_url"]',
            '[data-k="payment_instructions"]',
            '[data-k="qr_image"]',
            "external_url:'https://tickets.example/preventa'",
            "payment_instructions:'Paga por Nequi",
            "qr_image:'/uploads/qr/preventa.webp'",
        )
        return all(fragment in tickets for fragment in fragments)
    return False


def inspect(key, matrix, contrast, spec, releases, tickets):
    """Document gaps without promoting source checks to full compliance."""
    state = {"gap": UNKNOWN, "source": UNKNOWN, "integration": UNKNOWN,
             "browser": UNKNOWN, "ci": UNKNOWN, "production": UNKNOWN}
    if key not in CLAUSES or not isinstance(matrix, dict) or not isinstance(contrast, dict):
        return state
    if not all(isinstance(value, str) for value in (spec, releases, tickets)):
        return state
    row = matrix.get(key)
    if not isinstance(row, tuple) or len(row) != 5:
        return state
    anchor, statement = CLAUSES[key]
    if (not row[0].startswith(key + " · ") or "#L" + str(anchor) not in row[0]
            or row[1] != statement or row[2] != "NO_VERIFICABLE"
            or not row[3].startswith("Sin aserción contrastada para «")
            or row[4] != "—" or statement not in spec):
        return state
    if key in TICKET_IDS:
        old = contrast.get(key)
        if (not isinstance(old, tuple) or len(old) != 6 or old[0] != key
                or old[1] != "NO_VERIFICABLE" or old[2] != "—"
                or "ticket-types-editor.spec.mjs" not in old[3]
                or "falta aserción específica" not in old[5]
                or "[contraste](contraste-spec_resto.md)" not in row[3]):
            return state
    state["gap"] = GAP
    if source_asserted(key, releases, tickets):
        state["source"] = LOCAL
    return state


class BrechasBrvtal16Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.matrix = read_rows(MATRIX.read_text(encoding="utf-8"), CLAUSES)
        cls.contrast = read_rows(CONTRAST.read_text(encoding="utf-8"), TICKET_IDS, True)
        cls.spec = SPEC.read_text(encoding="utf-8")
        cls.releases = RELEASES.read_text(encoding="utf-8")
        cls.tickets = TICKETS.read_text(encoding="utf-8")

    def observe(self, key, matrix=None, contrast=None, spec=None, releases=None, tickets=None):
        return inspect(key,
                       self.matrix if matrix is None else matrix,
                       self.contrast if contrast is None else contrast,
                       self.spec if spec is None else spec,
                       self.releases if releases is None else releases,
                       self.tickets if tickets is None else tickets)

    def test_contrato_principal(self):
        """AC-01: each of the five requirements is explicitly covered."""
        self.assertEqual(set(CLAUSES), set(self.matrix))
        self.assertEqual(TICKET_IDS, set(self.contrast))
        for key in CLAUSES:
            with self.subTest(key=key):
                result = self.observe(key)
                self.assertEqual(GAP, result["gap"])
                self.assertEqual(LOCAL if key in {"S14-R001", "S14-R003", "S15-R002"} else UNKNOWN,
                                 result["source"])

    def test_falla_cerrado(self):
        """AC-02: absent, duplicate or promoted evidence never passes."""
        for path, keys, is_contrast in ((MATRIX, CLAUSES, False),
                                        (CONTRAST, TICKET_IDS, True)):
            raw = path.read_text(encoding="utf-8")
            first = next(line for line in raw.splitlines()
                         if line.startswith("| " + next(iter(keys)) + (" |" if is_contrast else " ·")))
            for changed in (raw + "\n" + first, raw + "\n  " + first,
                            raw.replace(first, "", 1)):
                with self.assertRaisesRegex(ValueError, UNKNOWN):
                    read_rows(changed, keys, is_contrast)
        for key in CLAUSES:
            with self.subTest(key=key):
                without = dict(self.matrix)
                without.pop(key)
                self.assertEqual(UNKNOWN, self.observe(key, matrix=without)["gap"])
                promoted = dict(self.matrix)
                promoted[key] = (promoted[key][0], promoted[key][1], "CUBIERTO",
                                 promoted[key][3], promoted[key][4])
                self.assertEqual(UNKNOWN, self.observe(key, matrix=promoted)["gap"])
                self.assertEqual(UNKNOWN, self.observe(key, spec=self.spec.replace(CLAUSES[key][1], ""))["gap"])
        for key in TICKET_IDS:
            self.assertEqual(UNKNOWN, self.observe(key, contrast={})["gap"])
        self.assertEqual(UNKNOWN, self.observe("S14-R001", releases="")["source"])
        self.assertEqual(UNKNOWN, self.observe("S14-R003", releases="")["source"])
        self.assertEqual(UNKNOWN, self.observe("S15-R002", tickets="")["source"])
        self.assertEqual(UNKNOWN, inspect("S15-R999", {}, {}, "", "", "")["gap"])

    def test_sin_datos_reales(self):
        """AC-03: file-only synthetic inspection with no network or PII."""
        source = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(source, r"(?m)^(?:import|from)\s+(?:requests|httpx|urllib|socket|boto3|pymysql)\b")
        for path in (MATRIX, CONTRAST, SPEC, RELEASES, TICKETS):
            self.assertTrue(path.is_file())
            self.assertTrue(path.resolve().is_relative_to(ROOT.resolve()))
        self.assertTrue(all(self.matrix[key][2] == "NO_VERIFICABLE" for key in CLAUSES))

    def test_estados_unknown_explicitos(self):
        """AC-04: no inference of integration, CI, browser or production status."""
        for key in CLAUSES:
            with self.subTest(key=key):
                result = self.observe(key)
                for dimension in ("integration", "browser", "ci", "production"):
                    self.assertEqual(UNKNOWN, result[dimension])
                self.assertEqual(UNKNOWN, inspect(key, {}, {}, "", "", "")["gap"])


if __name__ == "__main__":
    unittest.main()
