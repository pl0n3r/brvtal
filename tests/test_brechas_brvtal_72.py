#!/usr/bin/env python3
"""BRVTAL #1162: five SPEC §45–46 gaps, honest offline witnesses.

Real checked-in JS is exercised with synthetic DOM/media-query inputs; no real
administrator, browser layout, network, profiling, deployments or secrets.
Structural/local evidence does NOT establish complete accessibility or speed.
"""
from __future__ import annotations

import ast
import json
import os
from pathlib import Path
import re
import shutil
import subprocess
import tempfile
import unittest

ROOT = Path(__file__).resolve().parents[1]
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_SYNTHETIC_ONLY"
GAP = "BRECHA_DOCUMENTADA"
CLAUSES = {
    "S45-R004": (709, "Security / 2FA → Security workspace;"),
    "S45-R005": (710, "System Status → operations/control-room workspace."),
    "S46-R001": (714, "Maintain contrast, focus states, labels, reasonable keyboard navigation, semantic HTML and reduced-motion support."),
    "S46-R002": (716, "Optimize images, scripts, animations, fonts, video, WebGL, lazy loading and caching."),
    "S46-R003": (716, "Avoid heavy effects on devices that do not benefit from them."),
}
FILES = {
    "matrix": "docs/matriz-cobertura.md",
    "spec": "docs/BRVTAL-SPEC.md",
    "ia": "discadmin/admin-information-architecture.js",
    "shell": "discadmin/index-core.php",
    "ia_e2e": "tests/e2e/discadmin-information-architecture.spec.mjs",
    "menu": "js/menu-accessibility.js",
    "menu_css": "css/input-accessibility.css",
    "menu_e2e": "tests/e2e/public-menu-accessibility.spec.mjs",
    "mobile": "js/mobile-performance.js",
    "motion": "js/public-concept05-motion.js",
    "media": "discadmin/media-library.js",
}
EXPECTED = {
    "securityRoute": True, "systemRoute": True, "unknownRoute": True,
    "menuSemantics": True, "focusOnOpen": True, "escapeCloses": True,
    "tabWraps": True, "finePreserves": True, "coarseRemoves": True,
}
GAPS = {
    "S46-R002": "No complete device/network budget across images, scripts, video, fonts, WebGL and cache; production performance UNKNOWN.",
}

# Execute the original IA router, public menu and mobile-performance code.
# Fake DOM callbacks are intentionally narrow; no browser, network or timers.
NODE_PROBE = r"""
'use strict';
const fs = require('node:fs'), vm = require('node:vm');
const sources = JSON.parse(fs.readFileSync(0, 'utf8'));
const iaWindow = {
  state: {authed:true,section:'dashboard'}, go:async()=>true,
  tech:async()=>true, addEventListener(){},
};
let search = '?module=security';
const iaContext = {
  window:iaWindow, state:iaWindow.state,
  location:{get search(){return search;},pathname:'/discadmin/',hash:'',
            href:'http://localhost/discadmin/'},
  URL, URLSearchParams, history:{pushState(){},replaceState(){}},
  document:{documentElement:{},querySelector:()=>null},
  MutationObserver:class{observe(){} disconnect(){}},
  setTimeout(){},queueMicrotask(){},
};
vm.runInNewContext(sources.ia,iaContext,{timeout:2500});
const ia=iaWindow.BRVTALAdminIA;
if(!ia || typeof ia.readRoute!=='function')process.exit(3);
const securityRoute=ia.readRoute()==='security';
search='?module=system';
const systemRoute=ia.readRoute()==='system';
search='?module=unrecognized';
const unknownRoute=ia.readRoute()==='dashboard';

const handlers={};
let active=null, focusedOpen=false, closedByEscape=false, wrapped=false;
const menu={attrs:{},dataset:{},style:{},type:'',
  addEventListener(type,fn){handlers['menu:'+type]=fn;},
  setAttribute(k,v){this.attrs[k]=v;},
  getAttribute(k){return this.attrs[k]??null;},
  querySelector(k){return k==='strong'?icon:null;},
  focus(){active=this;}
};
const icon={attrs:{},setAttribute(k,v){this.attrs[k]=v;}};
const first={hidden:false,focus(){active=this;},getAttribute:()=>null};
const last={hidden:false,focus(){active=this;},getAttribute:()=>null};
const panel={id:'menuPanel',attrs:{'aria-hidden':'true'},style:{},
  inert:true,hidden:false,
  setAttribute(k,v){this.attrs[k]=v;},
  getAttribute(k){return this.attrs[k]??null;},
  querySelectorAll(){return [first,last];},
  addEventListener(type,fn){handlers['panel:'+type]=fn;},
  focus(){active=this;}
};
const doc={
  activeElement:null,
  getElementById(k){return k==='menuToggle'?menu:k==='menuPanel'?panel:null;},
  addEventListener(type,fn){handlers['document:'+type]=fn;}
};
vm.runInNewContext(sources.menu,{
  window:{},document:doc,MutationObserver:class{observe(){}},
  requestAnimationFrame:fn=>fn(),
},{timeout:2500});
const menuSemantics=menu.type==='button'&&
  menu.attrs['aria-controls']==='menuPanel' &&
  panel.attrs.role==='dialog' && panel.attrs['aria-modal']==='true' &&
  panel.attrs['aria-label']==='Site navigation';
handlers['menu:click']({preventDefault(){},stopImmediatePropagation(){}});
focusedOpen=active===first && menu.attrs['aria-expanded']==='true'
  && panel.attrs['aria-hidden']==='false' && panel.inert===false;
doc.activeElement=last;
handlers['document:keydown']({
  key:'Tab',shiftKey:false,preventDefault(){wrapped=true;}
});
const tabWraps=wrapped && active===first;
let prevented=false;
handlers['document:keydown']({
  key:'Escape',preventDefault(){prevented=true;}
});
closedByEscape=prevented&&active===menu&&
  menu.attrs['aria-expanded']==='false'&&panel.inert===true;

function mobile(coarse){
  const removed=[],classes=[];
  const loader={remove(){removed.push('loader');}};
  const fx={remove(){removed.push('fxCanvas');}};
  const win={Lenis:function Lenis(){},
    matchMedia:()=>({matches:coarse})};
  const document={
    getElementById(id){return id==='loader'?loader:id==='fxCanvas'?fx:null;},
    documentElement:{classList:{add(x){classes.push(x);}}}
  };
  vm.runInNewContext(sources.mobile,{window:win,document},{timeout:2500});
  return {removed,classes,lenis:win.Lenis};
}
const fine=mobile(false),coarse=mobile(true);
console.log(JSON.stringify({
  securityRoute,systemRoute,unknownRoute,menuSemantics,
  focusOnOpen:focusedOpen,escapeCloses:closedByEscape,tabWraps,
  finePreserves:fine.removed.length===0 && typeof fine.lenis==='function'
    && fine.classes.length===0,
  coarseRemoves:coarse.removed.join(',')==='loader,fxCanvas'
    && coarse.lenis===null
    && coarse.classes.join(',')==='touch-performance-mode',
}));
"""


def sources() -> dict[str, str]:
    return {key: (ROOT / path).read_text(encoding="utf-8")
            for key, path in FILES.items()}


def matrix_rows(raw: object) -> dict[str, tuple[str, ...]]:
    """All five source rows must be unique and remain NO_VERIFICABLE."""
    if type(raw) is not str or not raw.strip():
        raise ValueError(UNKNOWN + ": empty matrix")
    found = {}
    for line in raw.splitlines():
        m = re.match(r"^\| (S45-R00[45]|S46-R00[1-3]) · ", line)
        if not m:
            continue
        key = m.group(1)
        cells = tuple(cell.strip() for cell in line.strip("|").split("|"))
        lineno, phrase = CLAUSES[key]
        if (key in found or len(cells) != 5 or
                cells[1] != phrase or cells[2] != "NO_VERIFICABLE" or
                f"[S{key[1:3]} L{lineno}]" not in cells[0] or
                f"BRVTAL-SPEC.md#L{lineno})" not in cells[0] or
                "Sin aserción contrastada" not in cells[3] or
                "falta enlazar prueba de ese inciso, archivo de código y PR fusionado"
                not in cells[3] or cells[4] != "—"):
            raise ValueError(UNKNOWN + ": missing, duplicate or promoted " + key)
        found[key] = cells
    if set(found) != set(CLAUSES):
        raise ValueError(UNKNOWN + ": incomplete five-row scope")
    return found


def spec_clause(raw: object, key: object) -> bool:
    if type(raw) is not str or key not in CLAUSES:
        return False
    marker = "## 45. Workspace behavior\n" if key.startswith("S45") else (
        "## 46. Accessibility and performance\n"
    )
    if raw.count(marker) != 1:
        return False
    section = raw.split(marker, 1)[1].split("\n## ", 1)[0]
    return section.count(CLAUSES[key][1]) == 1


def clean_env(home: str) -> dict[str, str]:
    return {"HOME": home, "PATH": os.defpath, "LANG": "C", "APP_ENV": "testing"}


def observe(data: object) -> dict[str, bool]:
    if (type(data) is not dict or set(data) != set(FILES) or
            any(type(v) is not str or not v for v in data.values()) or
            shutil.which("node") is None):
        raise AssertionError(UNKNOWN + ": required offline source/runtime missing")
    with tempfile.TemporaryDirectory(prefix="brvtal-1162-local-") as home:
        proc = subprocess.run(
            ["node", "-e", NODE_PROBE],
            input=json.dumps({"ia": data["ia"], "menu": data["menu"],
                              "mobile": data["mobile"]}),
            cwd=ROOT, env=clean_env(home), capture_output=True, text=True,
            check=False, timeout=20,
        )
    if proc.returncode != 0 or proc.stderr.strip():
        raise AssertionError(UNKNOWN + ": JS source behavior not proven")
    try:
        result = json.loads(proc.stdout)
    except (ValueError, TypeError) as exc:
        raise AssertionError(UNKNOWN + ": malformed JS result") from exc
    if type(result) is not dict or set(result) != set(EXPECTED):
        raise AssertionError(UNKNOWN + ": partial JS observation")
    return result


def evidence(key: object, data: object, probe: object) -> str:
    """Local witness only. Absence or tampering never yields success."""
    if (type(key) is not str or key not in CLAUSES or
            type(data) is not dict or set(data) != set(FILES) or
            any(type(v) is not str or not v for v in data.values()) or
            type(probe) is not dict or probe != EXPECTED or
            not spec_clause(data["spec"], key)):
        return UNKNOWN
    try:
        matrix_rows(data["matrix"])
    except ValueError:
        return UNKNOWN
    ia, core, e2e = data["ia"], data["shell"], data["ia_e2e"]
    if key == "S45-R004":
        valid = ("'security', tokens:['SECURITY','2FA']" in ia and
                 "'theme','settings','security','system'" in ia and
                 "'security','backups','activity'" in ia and
                 "s==='content-core'||s==='security'" in core and
                 "/discadmin/security.js" in core and
                 "SECURITY / 2FA" in core and
                 "'ACCOUNT 2FA':'security'" in e2e and
                 probe["securityRoute"])
        return LOCAL if valid else UNKNOWN
    if key == "S45-R005":
        valid = ("'system', tokens:['SYSTEM STATUS']" in ia and
                 "canonical === 'system'" in ia and
                 "window.tech('system')" in ia and
                 "tech('system')" in core and
                 "test('system destination participates in the same URL state'" in e2e
                 and probe["systemRoute"])
        return LOCAL if valid else UNKNOWN
    if key == "S46-R001":
        css, browser = data["menu_css"], data["menu_e2e"]
        valid = (all(probe[k] for k in ("menuSemantics", "focusOnOpen",
                                       "escapeCloses", "tabWraps")) and
                 "a:focus-visible,button:focus-visible" in css and
                 "outline:2px solid var(--acid)" in css and
                 "@media(prefers-reduced-motion:reduce)" in css and
                 "menu traps focus" in browser and
                 "aria-label" in data["menu"] and
                 "role', 'dialog'" in data["menu"])
        return LOCAL if valid else UNKNOWN
    if key == "S46-R002":
        # Cannot infer global WebGL/video/font/cache/bandwidth optimization
        # from an isolated lazy-image or reduced-motion test.
        return GAP if (
            'loading="lazy"' in data["media"] and
            "loading, lazy loading" not in data["spec"] and
            "data-theme-motion" in data["motion"] and
            probe["finePreserves"]
        ) else UNKNOWN
    valid = (probe["coarseRemoves"] and probe["finePreserves"] and
             "matchMedia('(pointer: coarse)')" in data["mobile"] and
             "touch-performance-mode" in data["mobile"] and
             "prefers-reduced-motion: reduce" in data["motion"] and
             "window.matchMedia('(pointer: coarse)')" in data["motion"])
    return LOCAL if valid else UNKNOWN


def gap(key: object, data: object, probe: object) -> str:
    if key not in GAPS or evidence(key, data, probe) != GAP:
        return UNKNOWN
    return f"{key}: {GAP} — {GAPS[key]}"


def operational(_key: object, _claim: object = None) -> str:
    return UNKNOWN


class BrechasBrvtal72Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.data = sources()
        cls.probe = observe(cls.data)

    def test_contrato_principal(self) -> None:
        self.assertEqual(set(CLAUSES), set(matrix_rows(self.data["matrix"])))
        self.assertEqual(EXPECTED, self.probe)
        for key in CLAUSES:
            with self.subTest(key=key):
                self.assertTrue(spec_clause(self.data["spec"], key))
                target = GAP if key in GAPS else LOCAL
                self.assertEqual(target, evidence(key, self.data, self.probe))
                self.assertEqual(UNKNOWN, operational(key, self.probe))
                if key in GAPS:
                    self.assertIn(f"{key}: {GAP}", gap(key, self.data, self.probe))

    def test_falla_cerrado(self) -> None:
        raw = self.data["matrix"]
        for key in CLAUSES:
            line = next(x for x in raw.splitlines()
                        if x.startswith("| " + key + " · "))
            for invalid in (
                raw.replace(line, "", 1), raw + "\n" + line,
                raw.replace(line, line.replace("NO_VERIFICABLE", "CUBIERTO"), 1),
                raw.replace(line, line.replace(CLAUSES[key][1], "INDEFINIDO"), 1),
            ):
                with self.subTest(row=key):
                    with self.assertRaisesRegex(ValueError, UNKNOWN):
                        matrix_rows(invalid)
                    self.assertEqual(
                        UNKNOWN, evidence(key, {**self.data, "matrix": invalid},
                                          self.probe))
            for name in FILES:
                self.assertEqual(UNKNOWN, evidence(
                    key, {**self.data, name: ""}, self.probe))
            self.assertEqual(UNKNOWN, evidence(key, self.data, {}))
        for bad in ({**EXPECTED, "coarseRemoves": False},
                    {**EXPECTED, "escapeCloses": None}, {"ci": "success"}):
            self.assertEqual(UNKNOWN, evidence("S46-R003", self.data, bad))
        self.assertEqual(UNKNOWN, evidence("S46-R999", self.data, self.probe))
        self.assertEqual(UNKNOWN, gap("S45-R004", self.data, self.probe))

    def test_sin_datos_reales(self) -> None:
        tree = ast.parse(Path(__file__).read_text(encoding="utf-8"))
        imported = {alias.name.split(".")[0]
                    for node in ast.walk(tree) if isinstance(node, ast.Import)
                    for alias in node.names}
        imported |= {node.module.split(".")[0]
                     for node in ast.walk(tree)
                     if isinstance(node, ast.ImportFrom) and node.module}
        self.assertFalse(imported & {"requests", "httpx", "socket",
                                     "urllib", "playwright", "pymysql"})
        for forbidden in ("require('node:net')", "require('node:http')",
                          "require('node:https')", "require('node:child_process')"):
            self.assertNotIn(forbidden, NODE_PROBE)
        with tempfile.TemporaryDirectory(prefix="brvtal-1162-check-") as home:
            env = clean_env(home)
            self.assertEqual("testing", env["APP_ENV"])
            for name in ("DB_PASSWORD", "DATABASE_URL", "APP_KEY", "GH_TOKEN",
                         "GITHUB_TOKEN", "HTTP_PROXY", "HTTPS_PROXY",
                         "MYSQL_PWD", "COOKIE", "AUTHORIZATION"):
                self.assertNotIn(name, env)
        self.assertEqual(set(FILES), set(self.data))

    def test_estados_unknown_explicitos(self) -> None:
        for key in CLAUSES:
            with self.subTest(key=key):
                self.assertEqual("NO_VERIFICABLE",
                                 matrix_rows(self.data["matrix"])[key][2])
                for claim in (None, True, {"ci": "success"},
                              {"contrast": 4.5}, {"fps": 60},
                              {"production": "healthy"}, {"WebGL": "optimized"}):
                    self.assertEqual(UNKNOWN, operational(key, claim))
        self.assertIn("performance", gap("S46-R002", self.data, self.probe))
        self.assertFalse(spec_clause(
            "## 46. Accessibility and performance\n" * 2, "S46-R001"))


if __name__ == "__main__":
    unittest.main()
