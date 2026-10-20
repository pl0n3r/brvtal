"""Executable #969 real home board acceptance: PHP partial output and browser."""
import re
import subprocess
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
HOME=ROOT/"index.html"
REF=ROOT/"docs/reference/home-concept05-owner-reference-v2.png"
SPEC=ROOT/"tests/e2e/public-concept05-fidelity-matrix.spec.mjs"
PARTS=("nav","hero","experience","nights","artists","sound","memories","journal","connected","footer")

def composed():
    source=HOME.read_text(encoding="utf-8")
    for part in PARTS:
        marker="<!-- BRVTAL-HOME-PARTIAL:"+part+" -->"
        assert source.count(marker)==1
        source=source.replace(marker,(ROOT/"partials/home"/(part+".html")).read_text(encoding="utf-8"))
    assert "BRVTAL-HOME-PARTIAL:" not in source
    return source

class Concept05BoardV2Tests(unittest.TestCase):
    @classmethod
    def browser(cls):
        if not hasattr(cls,"_browser"):
            cls._browser=subprocess.run(
                ["npx","playwright","test","tests/e2e/public-concept05-fidelity-matrix.spec.mjs",
                 "--grep","C05 owner v2 board real partial layout","--reporter=line","--workers=1"],
                cwd=ROOT,capture_output=True,text=True,timeout=180,check=False)
        return cls._browser

    def test_numbered_sections_render_in_reference_grid_order(self):
        source=HOME.read_text(encoding="utf-8")
        self.assertEqual(re.findall(r'data-c5-board="([^"]+)"',source),["01","02-03","04-05","06-07"])
        html=composed()
        indices=[html.index('id="'+section+'"') for section in ("genesis","events","artists","sets","media","transmissions")]
        self.assertEqual(indices,sorted(indices))
        for label in ("02 Nights, 03 Artists","04 Sound, 05 Memories","06 Journal, 07 Connected"):
            self.assertIn(label,html)

    def test_legacy_sections_and_empty_image_sources_are_not_rendered(self):
        html=composed()
        self.assertNotRegex(html,r'<section[^>]+id="manifesto"')
        self.assertEqual(html.count('id="connected"'),0)  # Connected owned by future #973
        self.assertNotRegex(html,r'\bsrc\s*=\s*["\']\s*["\']')
        self.assertIn('data-dynamic="events"',html)
        self.assertIn('data-dynamic="artists"',html)

    def test_playwright_proves_no_horizontal_overflow_at_390_and_1440(self):
        result=self.browser()
        self.assertEqual(result.returncode,0,result.stdout[-5000:]+result.stderr[-1000:])

    def test_reference_evidence_requires_390_and_1440_captures(self):
        self.assertEqual(REF.read_bytes()[:8],bytes.fromhex("89504e470d0a1a0a"))
        source=SPEC.read_text(encoding="utf-8")
        for name in ("owner-reference-v2","board-1440-actual","board-390-actual","board-comparison-note"):
            self.assertIn(name,source)
        result=self.browser()
        self.assertEqual(result.returncode,0,result.stdout[-5000:]+result.stderr[-1000:])

if __name__=="__main__":
    unittest.main()
