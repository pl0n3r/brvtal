#!/usr/bin/env python3
"""BRVTAL #1270: five synthetic-only gaps S03-R010..S04-R001.

LOCAL_PARTIAL is limited to a *local* behavior/guard. The desirability of an
effect, WebGL availability, real device UX, media playback and deployment all
remain UNKNOWN; the coverage matrix is not promoted by these tests.
"""
from __future__ import annotations

import ast
from functools import lru_cache
import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_PARTIAL"
IDS = ("S03-R010", "S03-R011", "S03-R012", "S03-R013", "S04-R001")
SUMMARY = {
    "S03-R010": "selective WebGL/GLSL;",
    "S03-R011": "reduced-motion support.",
    "S03-R012": "Effects must never be added merely for decoration.",
    "S03-R013": "Mobile is a first-class requirement.",
    "S04-R001": (
        "Public audio remains content-led (sets/media) and must respect "
        "browser autoplay restrictions."
    ),
}
SOURCES = {
    "spec": "docs/BRVTAL-SPEC.md",
    "motion": "js/public-concept05-motion.js",
    "app": "js/app.js",
    "style": "css/style.css",
    "visual": "css/public-visual-identity.css",
    "input": "css/input-accessibility.css",
    "menu": "js/menu-accessibility.js",
    "menu_e2e": "tests/e2e/public-menu-accessibility.spec.mjs",
    "sound": "js/public-concept05-sound-memories.js",
}
FILE = "tests/test_cobertura_brvtal_01.py"

# Executes the ACTUAL motion module in a sterile Node vm, with synthetic DOM,
# fake GSAP, fake matchMedia, no browser, network, real content or credentials.
NODE_MOTION_PROBE = r"""
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync('js/public-concept05-motion.js', 'utf8');
function exercise(mode) {
  let animations = 0;
  let registrations = 0;
  const gsap = {
    from() { animations++; },
    registerPlugin() { registrations++; }
  };
  const host = {
    querySelectorAll(selector) {
      return selector === '.c5-module' ? [{}] : [];
    }
  };
  const document = {
    readyState: 'complete',
    documentElement: {
      getAttribute(name) {
        return name === 'data-theme-motion' && mode === 'theme-reduced'
          ? 'reduced' : null;
      },
      classList: { contains(value) { return mode === 'visual-test'
        && value === 'c5-visual-test'; } }
    },
    querySelectorAll(selector) {
      return selector === '[data-concept="05"]' && mode !== 'no-host'
        ? [host] : [];
    }
  };
  const window = {
    matchMedia(query) {
      return { matches: (query === '(prefers-reduced-motion: reduce)'
         && mode === 'reduced') || (query === '(pointer: coarse)'
         && mode === 'coarse') };
    },
    gsap: mode === 'no-gsap' ? null : gsap,
    ScrollTrigger: null
  };
  vm.runInNewContext(source, {window, document}, {timeout: 1500});
  return animations;
}
const modes = ['normal','reduced','coarse','theme-reduced','visual-test',
               'no-host','no-gsap'];
console.log(JSON.stringify(Object.fromEntries(
  modes.map(mode => [mode, exercise(mode)])
)));
"""


def sources() -> dict[str, str]:
    return {key: (ROOT / path).read_text(encoding="utf-8")
            for key, path in SOURCES.items()}


@lru_cache(maxsize=1)
def motion_probe() -> dict[str, int]:
    node = shutil.which("node")
    if not node:
        raise AssertionError("UNKNOWN: falta Node para probar comportamiento")
    executed = subprocess.run(
        [node, "-e", NODE_MOTION_PROBE], cwd=ROOT, capture_output=True,
        text=True, check=False, timeout=15,
    )
    if executed.returncode or executed.stderr.strip():
        raise AssertionError("UNKNOWN: módulo de motion no verificable: "
                             + executed.stderr[-350:])
    try:
        result = json.loads(executed.stdout)
    except ValueError as exc:
        raise AssertionError("UNKNOWN: observación Node corrupta") from exc
    if type(result) is not dict or set(result) != {
        "normal", "reduced", "coarse", "theme-reduced", "visual-test",
        "no-host", "no-gsap",
    } or any(type(v) is not int or v < 0 for v in result.values()):
        raise AssertionError("UNKNOWN: observación Node incompleta")
    return result


def signals(files: object, observed: object) -> dict[str, bool]:
    result = dict.fromkeys(IDS, False)
    if (type(files) is not dict or set(files) != set(SOURCES)
            or not all(type(x) is str and x.strip() for x in files.values())
            or type(observed) is not dict):
        return result
    spec, motion, app, style, visual, inp, menu, menu_e2e, sound = (
        files["spec"], files["motion"], files["app"], files["style"],
        files["visual"], files["input"], files["menu"], files["menu_e2e"],
        files["sound"],
    )
    if any(phrase not in spec.replace(chr(96), "") for phrase in SUMMARY.values()):
        return result

    # Selective WebGL/GLSL is a design option, NOT a demonstrated renderer.
    result["S03-R010"] = False

    # The real JS module is run with reduced motion, coarse pointer, missing
    # host and disabled GSAP, rather than merely trusting a source string.
    result["S03-R011"] = all((
        "function motionDisabled()" in motion,
        "if (!window.gsap || motionDisabled()) return;" in motion,
        "(prefers-reduced-motion: reduce)" in motion,
        "if (reduce || !motionReady) { loader.remove(); return; }" in app,
        "@media (prefers-reduced-motion:reduce)" in style,
        "@media(prefers-reduced-motion:reduce)" in visual,
        observed == {
            "normal": 1, "reduced": 0, "coarse": 0,
            "theme-reduced": 0, "visual-test": 0,
            "no-host": 0, "no-gsap": 0,
        },
    ))

    # Whether an effect is narratively justified is a creative/user judgment.
    # Even good guards cannot automatically promote this row.
    result["S03-R012"] = False

    # Mobile partial: actual media-query and touch/keyboard contract plus an
    # existing synthetic browser regression, NOT a real-device attestation.
    result["S03-R013"] = all((
        "@media(max-width:900px)" in visual,
        "@media(max-width:900px)" in inp,
        ".sound,.menu{min-height:44px;" in inp,
        "(pointer:coarse)" in inp,
        "@media (max-width:900px)" in style,
        "menu.setAttribute('aria-expanded', String(open));" in menu,
        "test('mobile menu opens repeatedly" in menu_e2e,
        "width: 390, height: 844" in menu_e2e,
        "geometry.scrollWidth" in menu_e2e,
        observed.get("coarse") == 0,
    ))

    # Sound is set-identity bound, official HTTPS embeds are allowlisted and
    # iframe insertion occurs ONLY inside the click listener. Playback and
    # browser autoplay policy remain unproven without a browser/proxy.
    toggle = sound.find("toggle.addEventListener('click', function () {")
    iframe = sound.find("document.createElement('iframe');", toggle)
    stop = sound.find("frame.replaceChildren();", toggle)
    result["S04-R001"] = all((
        "function safeEmbedUrl(value)" in sound,
        "url.protocol !== 'https:'" in sound,
        "host === 'w.soundcloud.com'" in sound,
        "host === 'open.spotify.com'" in sound,
        "host === 'www.youtube-nocookie.com'" in sound,
        "records.find(function (set)" in sound,
        "safeEmbedUrl(record?.embed_url)" in sound,
        "if (!url) {" in sound,
        "frame.hidden = true;" in sound,
        toggle >= 0 and iframe > toggle and stop > toggle,
        "frame.replaceChildren(iframe);" in sound,
        "toggle.setAttribute('aria-expanded', String(!opened));" in sound,
        "No network requests." in sound,
    ))
    return result


def verdicts(files: object, observed: object) -> dict[str, dict[str, str]]:
    result = {
        key: {"local": UNKNOWN, "browser": UNKNOWN, "ci": UNKNOWN,
              "production": UNKNOWN} for key in IDS
    }
    flags = signals(files, observed)
    for key in IDS:
        if flags[key] is True:
            result[key]["local"] = LOCAL
    return result


# #1270: Complete 20-row accounting; source behaviors only for group A.
CLASSIFICATION_1270 = {"H212-01":["B","Proveedor DeepL real y aprobación de privacidad siguen fuera de alcance"],"S02-R001":["C","Titularidad de logo y lengua visual son hechos de procedencia editorial"],"S02-R002":["B","Color, grano y diseño final exigen renderizado y contraste visual"],"S02-R003":["B","Evaluar si el flyer es genérico exige evaluación visual"],"S02-R004":["B","Composición de recortes y máscaras exige capturas y comparación visual"],"S02-R005":["C","Reconocibilidad de marca es juicio editorial"],"S03-L41":["A","Runtime Lenis y lock/unlock realmente ejecutados con DOM sintético"],"S03-R001":["B","Velocidad y accesibilidad integral requieren pruebas de navegador"],"S03-R003":["B","Geometría de navegación fija necesita motor de layout"],"S03-R004":["A","Runtime del menú realmente cambia ARIA, foco y Escape"],"S03-R005":["D","Preloader está implementado pero transiciones completas de páginas no"],"S03-R006":["A","Runtime de GSAP y ScrollTrigger ejecutado en VM estéril"],"S03-R007":["C","Justificación narrativa de scroll horizontal es juicio editorial"],"S03-R008":["B","Tipografía gigante debe verse en viewport real"],"S03-R009":["B","Experiencia cursor magnético exige prueba de puntero real"],"S03-R010":["D","WebGL/GLSL selectivo aún no está implementado como renderer"],"S03-R011":["A","Guardas de reducción de movimiento realmente ejecutadas"],"S03-R012":["C","Efecto decorativo o narrativo es juicio editorial"],"S03-R013":["B","Mobile-first requiere layout de navegador y dispositivo"],"S04-R001":["B","Autoplay y proveedores multimedia necesitan navegador/proveedor real"]}

NODE_INTERACTION_PROBE_1270 = r"""
const fs = require('node:fs'), vm = require('node:vm');
const mSource = fs.readFileSync('js/menu-accessibility.js', 'utf8');
const sSource = fs.readFileSync('js/menu-scroll-lock.js', 'utf8');
function menu() {
  let doc;
  function el(id) {
    return {id, attrs:{}, style:{}, dataset:{}, hidden:false, listeners:{},
      getAttribute(k){return this.attrs[k] ?? null;},
      setAttribute(k,v){this.attrs[k]=String(v);},
      addEventListener(k,f){this.listeners[k]=f;},
      focus(){doc.activeElement=this;},
      querySelector(s){return s==='strong'?icon:null;},
      querySelectorAll(){return [];}
    };
  }
  const icon=el('icon'),first=el('first'),last=el('last');
  const toggle=el('menuToggle'),panel=el('menuPanel'),listeners={};
  panel.attrs['aria-hidden']='true';
  panel.querySelectorAll=()=>[first,last];
  doc={activeElement:null,getElementById:k=>k==='menuToggle'?toggle:k==='menuPanel'?panel:null,
    addEventListener:(k,f)=>{listeners[k]=f;}};
  class MutationObserver{observe(){}}
  vm.runInNewContext(mSource,{document:doc,MutationObserver,requestAnimationFrame:f=>f()},{timeout:2000});
  const ev=(key,shiftKey=false)=>({key,shiftKey,preventDefault(){},stopImmediatePropagation(){}});
  const startsClosed=toggle.attrs['aria-expanded']==='false' && panel.inert===true;
  toggle.listeners.click(ev('click'));
  const opens=toggle.attrs['aria-expanded']==='true' && panel.attrs['aria-hidden']==='false'
      && panel.inert===false && doc.activeElement===first && panel.attrs['aria-modal']==='true';
  doc.activeElement=last; listeners.keydown(ev('Tab'));const forwards=doc.activeElement===first;
  listeners.keydown(ev('Tab',true));const backwards=doc.activeElement===last;
  listeners.keydown(ev('Escape'));
  const closes=toggle.attrs['aria-expanded']==='false' && panel.attrs['aria-hidden']==='true'
       && panel.inert===true && doc.activeElement===toggle;
  return {startsClosed,opens,forwards,backwards,closes};
}
function lenis() {
  const stats={stops:0,starts:0,resizes:0,restored:null};
  const classes={add(){},remove(){},toggle(){}};
  const html={style:{},classList:classes};
  const body={style:{},classList:classes};
  class FakeLenis{
    constructor(){this.isStopped=false;}
    stop(){stats.stops++;this.isStopped=true;}
    start(){stats.starts++;this.isStopped=false;}
    resize(){stats.resizes++;}
  }
  const win={Lenis:FakeLenis,scrollX:7,scrollY:500,
    scrollTo:(x,y)=>{stats.restored=[x,y];},addEventListener(){},ScrollTrigger:null};
  const doc={documentElement:html,body,readyState:'complete',getElementById:()=>null};
  vm.runInNewContext(sSource,{window:win,document:doc,
     cancelAnimationFrame(){},requestAnimationFrame:fn=>{fn();return 1;}},{timeout:2000});
  const instance=new win.Lenis(),manager=win.BRVTALScrollLock;
  const initiallyUnlocked=!manager.isLocked();
  manager.lock('menu');manager.lock('menu');
  const held=manager.isLocked() && manager.has('menu') && instance.isStopped;
  manager.unlock('unknown');const stillHeld=manager.isLocked();
  manager.unlock('menu');
  return {initiallyUnlocked,held,stillHeld,
    released:!manager.isLocked()&&!instance.isStopped,
    stops:stats.stops,starts:stats.starts,restored:stats.restored};
}
console.log(JSON.stringify({menu:menu(),lenis:lenis()}));
"""

def interaction_probe_1270():
    node = shutil.which("node")
    if not node:
        raise AssertionError("UNKNOWN: Node not installed")
    job = subprocess.run([node, "-e", NODE_INTERACTION_PROBE_1270],
                         cwd=ROOT, capture_output=True, text=True,
                         timeout=15, check=False)
    if job.returncode or job.stderr.strip():
        raise AssertionError("UNKNOWN: real JS probe failed: " + job.stderr[-400:])
    value = json.loads(job.stdout)
    if type(value) is not dict or set(value) != {"menu", "lenis"}:
        raise AssertionError("UNKNOWN: incomplete JS observation")
    return value


class CoberturaBrvtal01Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        files, observed = sources(), motion_probe()
        self.assertEqual(
            {key for key, (kind, _) in CLASSIFICATION_1270.items() if kind == "A"},
            {"S03-L41", "S03-R004", "S03-R006", "S03-R011"},
        )
        for key, (kind, reason) in CLASSIFICATION_1270.items():
            with self.subTest(coverage_id=key):
                self.assertIn(kind, {"A", "B", "C", "D"})
                self.assertTrue(reason)
        observed_ui = interaction_probe_1270()
        self.assertTrue(all(observed_ui["menu"].values()))
        self.assertTrue(all(observed_ui["lenis"][key] for key in
                            ("initiallyUnlocked", "held", "stillHeld", "released")))
        self.assertEqual(observed_ui["lenis"]["stops"], 1)
        self.assertEqual(observed_ui["lenis"]["starts"], 1)
        self.assertEqual(observed_ui["lenis"]["restored"], [7, 500])
        self.assertEqual(observed["normal"], 1)
        self.assertEqual(observed["reduced"], 0)
        self.assertEqual(observed["coarse"], 0)
        self.assertEqual(observed["no-host"], 0)
        verdict = verdicts(files, observed)
        expected = {
            "S03-R010": UNKNOWN,       # selective WebGL not proven
            "S03-R011": LOCAL,         # JS motion actually suppressed
            "S03-R012": UNKNOWN,       # no automated "not mere decoration" proof
            "S03-R013": LOCAL,         # mobile local guards + E2E fixture
            "S04-R001": LOCAL,         # opt-in player source guards
        }
        for key in IDS:
            with self.subTest(brecha=key):
                self.assertEqual(verdict[key]["local"], expected[key])

    def test_falla_cerrado(self) -> None:
        observed_ui = interaction_probe_1270()
        self.assertTrue(observed_ui["menu"]["closes"])
        self.assertTrue(observed_ui["lenis"]["released"])
        files, observed = sources(), motion_probe()
        self.assertEqual(verdicts({}, observed), verdicts(None, observed))
        for key in IDS:
            changed = dict(files)
            changed["spec"] = ""
            self.assertEqual(verdicts(changed, observed)[key]["local"], UNKNOWN)
            self.assertEqual(verdicts(files, None)[key]["local"], UNKNOWN)
        for key, name, token in (
            ("S03-R011", "motion", "if (!window.gsap || motionDisabled()) return;"),
            ("S03-R013", "input", ".sound,.menu{min-height:44px;"),
            ("S04-R001", "sound", "frame.replaceChildren(iframe);"),
        ):
            changed = dict(files)
            changed[name] = changed[name].replace(token, "", 1)
            self.assertEqual(verdicts(changed, observed)[key]["local"],
                             UNKNOWN)
        bad_observed = dict(observed)
        bad_observed["reduced"] = 1
        self.assertEqual(verdicts(files, bad_observed)["S03-R011"]["local"],
                         UNKNOWN)
        self.assertEqual(verdicts({}, observed),
                         verdicts(None, observed))

    def test_sin_datos_reales(self) -> None:
        self.assertEqual(len(CLASSIFICATION_1270), 20)
        self.assertEqual(CLASSIFICATION_1270["H212-01"][0], "B")
        self.assertEqual(CLASSIFICATION_1270["S02-R001"][0], "C")
        self.assertEqual(CLASSIFICATION_1270["S04-R001"][0], "B")
        self.assertNotIn("fetch(", NODE_INTERACTION_PROBE_1270)
        self.assertNotIn("https://", NODE_INTERACTION_PROBE_1270)
        raw = (ROOT / FILE).read_text(encoding="utf-8")
        tree = ast.parse(raw)
        imports = set()
        for node in ast.walk(tree):
            if isinstance(node, ast.Import):
                imports.update(a.name.split(".")[0] for a in node.names)
            elif isinstance(node, ast.ImportFrom) and node.module:
                imports.add(node.module.split(".")[0])
        self.assertFalse(imports & {
            "requests", "httpx", "urllib", "socket", "pymysql",
            "mysql", "psycopg2",
        })
        self.assertNotIn("fetch(", NODE_MOTION_PROBE)
        self.assertNotIn("http://", NODE_MOTION_PROBE)
        self.assertNotIn("https://", NODE_MOTION_PROBE)
        self.assertTrue(all(not Path(p).is_absolute()
                            and ".." not in Path(p).parts
                            for p in SOURCES.values()))
        self.assertEqual(set(sources()), set(SOURCES))

    def test_estados_unknown_explicitos(self) -> None:
        result = verdicts(sources(), motion_probe())
        for key in IDS:
            with self.subTest(brecha=key):
                self.assertEqual(result[key]["browser"], UNKNOWN)
                self.assertEqual(result[key]["ci"], UNKNOWN)
                self.assertEqual(result[key]["production"], UNKNOWN)
        for key in ("S03-R010", "S03-R012"):
            self.assertEqual(result[key]["local"], UNKNOWN)
        self.assertEqual(
            verdicts(None, motion_probe())["S04-R001"]["local"],
            UNKNOWN,
        )


if __name__ == "__main__":
    unittest.main()
