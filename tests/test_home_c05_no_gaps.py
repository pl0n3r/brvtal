#!/usr/bin/env python3
"""Safety regression for Concept 05 scroll-deferred motion (#976)."""
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MOTION = ROOT / "js/public-concept05-motion.js"
E2E = ROOT / "tests/e2e/public-concept05-fidelity-matrix.spec.mjs"


class HomeC05NoGapsTests(unittest.TestCase):
    def test_e2e_fullpage_motion_contract(self) -> None:
        """AC-04: E2E exercises offscreen DOM before scroll and saves two captures."""
        spec = E2E.read_text(encoding="utf-8")
        self.assertIn("full-page pre-scroll content remains visible", spec)
        self.assertIn("width:390, height:844", spec)
        self.assertIn("width:1440, height:900", spec)
        self.assertIn("beforeScroll.scrollY", spec)
        self.assertIn("beforeScroll.tweens", spec)
        self.assertIn("fullPage:true", spec)
        self.assertIn("await info.attach", spec)
        self.assertIn("deferred GSAP tween must not hide content", spec)
        self.assertIn("deferred GSAP tween must not clip a section", spec)

        # Execute the real shipped JS with a deterministic, postponed GSAP stub.
        # An opacity=0 or clipPath=inset(0 0 100% 0) regression fails here too.
        script = r"""
const fs = require('node:fs');
const source = fs.readFileSync('js/public-concept05-motion.js','utf8');
function check(reduced) {
  const tweens = [];
  const element = { style:{} };
  const section = { style:{}, querySelector:() => ({}) };
  const host = { querySelectorAll: selector => selector === '.c5-module'
    ? [element] : selector === '.c5-numbered' ? [section] : [] };
  const document = {
    readyState:'complete',
    documentElement:{
      getAttribute:() => null,
      classList:{ contains:() => false },
    },
    querySelectorAll:selector => selector === '[data-concept="05"]' ? [host] : [],
  };
  const window = {
    ScrollTrigger:{},
    matchMedia:query => ({ matches:reduced && query === '(prefers-reduced-motion: reduce)' }),
    gsap:{
      registerPlugin:() => {},
      from:(_node,options) => { tweens.push(options); },
      timeline:() => ({ fromTo:() => {} }),
    },
  };
  new Function('window','document',source)(window,document);
  return tweens.map(v => Object.keys(v));
}
console.log(JSON.stringify({normal:check(false),reduced:check(true)}));
"""
        completed = subprocess.run(
            ["node", "-e", script], cwd=ROOT, check=True,
            capture_output=True, text=True, timeout=10,
        )
        result = json.loads(completed.stdout)
        self.assertEqual(result["reduced"], [])
        self.assertEqual(len(result["normal"]), 2)
        for keys in result["normal"]:
            self.assertNotIn("opacity", keys)
            self.assertNotIn("clipPath", keys)
            self.assertIn("scrollTrigger", keys)


if __name__ == "__main__":
    unittest.main()
