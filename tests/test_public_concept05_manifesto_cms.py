#!/usr/bin/env python3
"""Regression contracts for editable Concept 05 hero manifesto (#977)."""
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
ADMIN = (ROOT / "discadmin/settings-v2.js").read_text(encoding="utf-8")
HERO = (ROOT / "js/public-concept05-hero.js").read_text(encoding="utf-8")
E2E = (ROOT / "tests/e2e/public-concept05-hero.spec.mjs").read_text(encoding="utf-8")
ADMIN_E2E = (ROOT / "tests/e2e/discadmin-settings-v2.spec.mjs").read_text(encoding="utf-8")
PHP_HOME = (ROOT / "config/public_home.php").read_text(encoding="utf-8")


class Concept05ManifestoCmsTests(unittest.TestCase):
    def test_site_settings_expose_and_persist_editable_manifesto_without_losing_other_site_keys(self) -> None:
        self.assertIn("text('site_hero_manifesto_es'", ADMIN)
        self.assertIn("text('site_hero_manifesto_en'", ADMIN)
        self.assertIn("const next = { ...current, ...patch };", ADMIN)
        self.assertIn("hero_manifesto_es:heroManifestoEs", ADMIN)
        self.assertIn("hero_manifesto_en:heroManifestoEn", ADMIN)
        self.assertIn("await persistJson('site',{name,tagline,", ADMIN)
        self.assertIn("Concept 05 bilingual manifesto settings save is persistent", ADMIN_E2E)
        self.assertIn("custom_keep:'preserve-me'", ADMIN_E2E)

    def test_playwright_two_distinct_cms_payloads_390_1440(self) -> None:
        self.assertIn("CMS manifesto changes per locale and viewport without overflow", E2E)
        self.assertIn("{ width:390, height:844 }", E2E)
        self.assertIn("{ width:1440, height:900 }", E2E)
        self.assertIn("SONIDO. MEMORIA. COMUNIDAD.", E2E)
        self.assertIn("MORE THAN A SCENE. A CULTURE.", E2E)
        self.assertIn("window.BRVTALConcept05Hero.projectManifesto", E2E)
        self.assertIn("document.documentElement.scrollWidth <= document.documentElement.clientWidth", E2E)

    def test_es_en_fallback_and_html_injection_fail_closed(self) -> None:
        script = r"""
const fs = require('node:fs');
const source = fs.readFileSync('js/public-concept05-hero.js','utf8');
const begin = source.indexOf('  function projectManifesto(data) {');
const end = source.indexOf('  function projectDescription(data) {', begin);
if (begin < 0 || end <= begin) throw new Error('MANIFESTO_PROJECTION_MISSING');
const defaults = {
  c5DefaultEs:'MÁS QUE FIESTAS.\nUNA CULTURA EN MOVIMIENTO.',
  c5DefaultEn:'MORE THAN PARTIES.\nA CULTURE IN MOTION.'
};
const element = {
  dataset:{...defaults,c5DisplayLocale:'es'},
  textContent:'MÁS QUE FIESTAS. UNA CULTURA EN MOVIMIENTO.',
  replaceChildren(...parts) {
    this.textContent = parts.map(part => part.tagName === 'br' ? ' ' : part.textContent).join('');
  }
};
const document = {
  documentElement: { lang:'es', dataset:{} },
  createElement: tag => ({tagName:tag,textContent:''}),
  createTextNode: value => ({textContent:value}),
  querySelectorAll: selector => selector === '[data-c5-hero-manifesto]' ? [element] : [],
};
const projectManifesto = new Function('document', source.slice(begin,end) + ';return projectManifesto;')(document);
const apply = (lang, site) => {
  document.documentElement.lang = lang;
  document.documentElement.dataset.locale = lang;
  projectManifesto({settings:{site}});
  return element.textContent;
};
const observed = {
  es:apply('es',{hero_manifesto_es:'MANIFIESTO EDITABLE'}),
  en:apply('en',{hero_manifesto_en:'MANAGED MANIFESTO'}),
  missing:apply('es',{}),
  blank:apply('en',{hero_manifesto_en:''}),
  customAgain:apply('es',{hero_manifesto_es:'SONIDO ACTIVO'}),
  invalid:apply('es',{hero_manifesto_es:'<script>alert(1)</script>'}),
  customEnglish:apply('en',{hero_manifesto_en:'ACTIVE CULTURE'}),
  tooLong:apply('en',{hero_manifesto_en:'X'.repeat(65)}),
};
console.log(JSON.stringify(observed));
"""
        result = subprocess.run(
            ["node", "-e", script], cwd=ROOT,
            capture_output=True, text=True, check=True,
        )
        actual = json.loads(result.stdout)
        self.assertEqual(
            {
                "es": "MANIFIESTO EDITABLE",
                "en": "MANAGED MANIFESTO",
                "missing": "MÁS QUE FIESTAS. UNA CULTURA EN MOVIMIENTO.",
                "blank": "MORE THAN PARTIES. A CULTURE IN MOTION.",
                "customAgain": "SONIDO ACTIVO",
                "invalid": "MÁS QUE FIESTAS. UNA CULTURA EN MOVIMIENTO.",
                "customEnglish": "ACTIVE CULTURE",
                "tooLong": "MORE THAN PARTIES. A CULTURE IN MOTION.",
            },
            actual,
        )
        self.assertIn("value.length > 64", ADMIN)
        self.assertIn("node.textContent = manifesto", HERO)
        self.assertIn("node.replaceChildren(...parts)", HERO)
        self.assertIn("node.dataset.c5DisplayLocale === locale", HERO)

    def test_original_owner_v2_copy_and_existing_cms_description_remain_unchanged(self) -> None:
        self.assertIn("MÁS QUE FIESTAS.<br>UNA CULTURA EN MOVIMIENTO.", PHP_HOME)
        self.assertIn("MORE THAN PARTIES.<br>A CULTURE IN MOTION.", PHP_HOME)
        self.assertIn("projectDescription(data);", HERO)
        self.assertIn("projectManifesto(data);", HERO)
        self.assertIn("document.querySelectorAll('[data-c5-hero-description]')", HERO)
        self.assertIn("document.querySelectorAll('[data-c5-hero-manifesto]')", HERO)


if __name__ == "__main__":
    unittest.main()
