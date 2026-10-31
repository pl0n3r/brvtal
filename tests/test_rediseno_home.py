"""Offline, deterministic CSS acceptance contract for BRVTAL #1361."""
import re
import unittest
from pathlib import Path

ROOT=Path(__file__).resolve().parents[1]
FILES=("css/public-concept05-home.css","css/public-concept05-hero.css","css/hero-slider.css","css/hero-slider-v2.css","css/public-home-phase-a.css")
FONT=re.compile(r"(?<![\w-])(?:font-size|font)\s*:\s*([^;{}]*)",re.I)
PX=re.compile(r"(\d+(?:\.\d+)?)px\b",re.I)
REDUCED=re.compile(r"@media\s*\(\s*prefers-reduced-motion\s*:\s*reduce\s*\)",re.I)

def violations(name,source):
    css=re.sub(r"/\*.*?\*/","",source,flags=re.S)
    errors=[]
    for decl in FONT.finditer(css):
        if any(float(m.group(1))<12 for m in PX.finditer(decl.group(1))):
            errors.append(f"{name}: font-size below 12px")
    if re.search(r"(?<![\w-])transition\s*:\s*all\b",css,re.I):
        errors.append(f"{name}: transition: all")
    if re.search(r"(?<![\w-])outline\s*:\s*(?:none|0(?:px)?)(?=\s*[;}])",css,re.I):
        errors.append(f"{name}: outline removed")
    if re.search(r"(?:animation|transition)\s*:|@keyframes",css,re.I) and not REDUCED.search(css):
        errors.append(f"{name}: motion without prefers-reduced-motion")
    return errors

class RedisenoHomeTests(unittest.TestCase):
    def test_criterio_principal(self):
        sources={}
        for path in FILES:
            file=ROOT/path
            self.assertTrue(file.is_file(),path)
            sources[path]=file.read_text(encoding="utf-8")
            self.assertTrue(sources[path].strip(),path)
            self.assertFalse(violations(path,sources[path]),path)
        slider=sources["css/hero-slider.css"]
        layer=sources["css/hero-slider-v2.css"]
        for token in ("text-wrap:balance","text-wrap:pretty","font-variant-numeric:tabular-nums"):
            self.assertIn(token,slider)
        for token in (":focus-visible","outline:2px solid","min-height:44px",":active","scale:.96"):
            self.assertIn(token,layer)
        self.test_negative_fixtures()

    def test_negative_fixtures(self):
        invalid=(("p{font-size:11px}","below 12px"),("p{font:700 9px/1.5 mono}","below 12px"),
         ("a{transition:all .3s}","transition: all"),("a:focus{outline:none}","outline removed"),
         ("a:focus{outline:0}","outline removed"),("a{animation:pulse 3s infinite}","without prefers-reduced-motion"))
        for source,expected in invalid:
            with self.subTest(source=source):
                self.assertTrue(any(expected in e for e in violations("fixture",source)))
        self.assertEqual([],violations("good","a{font-size:12px;transition:opacity .2s}@media(prefers-reduced-motion:reduce){a{transition:none}}"))
        self.assertEqual([],violations("comment","/*x{font-size:9px}*/p{font-size:12px}"))

if __name__=="__main__":
    unittest.main()
