"""#1504: execute the real DISCADMIN preview renderer with synthetic DOM fixtures."""
import json
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
JS = ROOT / "discadmin/hero-slider.js"
CSS = ROOT / "discadmin/hero-slider.css"
NODE = shutil.which("node")

RUNNER = r"""
const fs = require('node:fs');
const vm = require('node:vm');
class Element {
  constructor(tag) {
    this.tag = tag;
    this.children = [];
    this.dataset = {};
    this.attributes = {};
    this.style = {setProperty: (key, value) => { this.style[key] = value; }};
    this.className = '';
    this.textContent = '';
  }
  appendChild(child) { this.children.push(child); return child; }
  replaceChildren(...children) { this.children = children; }
  setAttribute(key, value) { this.attributes[key] = value; }
}
const source = fs.readFileSync(process.argv[1], 'utf8');
const begin = source.indexOf('  function appendPreviewText(');
const end = source.indexOf('  function intervalOptions()', begin);
if (begin < 0 || end < 0) throw Error('Preview renderer missing');
const context = {document: {createElement: tag => new Element(tag)},
  selectedLayerId: 'layer-a', previewMode: 'desktop',
  config: {slides: [{id:'slide-a'}, {id:'slide-b'}]}};
vm.createContext(context);
vm.runInContext(source.slice(begin, end) + '\nglobalThis.draw = renderPreview;', context);
const slide = {id:'slide-b', mediaType:'image', desktopSrc:'/uploads/synthetic.png',
  mobileSrc:'/uploads/synthetic-mobile.png', overlay:35, contentAlign:'left',
  kicker:'Próxima noche',title:'NOCHE BRVTAL',body:'Texto de prueba',ctaLabel:'Tickets',
  layers: [{id:'layer-a',type:'text',text:'Título sintetico',name:'Text',
    x:25,y:50,width:60,mobileX:50,mobileY:60,mobileWidth:90,
    hiddenMobile:false,animation:'fade',delay:0,duration:650,align:'left'}]};
const visit = (node, name) => {
  if ((node.className || '').split(' ').includes(name)) return node;
  for (const child of node.children) { const found = visit(child, name); if (found) return found; }
  return null;
};
const frame = new Element('div');
context.draw(frame, slide);
const stage = visit(frame,'hero-preview-stage');
const tint = visit(stage,'hero-preview-tint');
const layer = visit(stage,'hero-preview-layer');
const counter = visit(stage,'hero-preview-counter');
const progress = visit(stage,'hero-preview-progress');
const desktop = {classes:stage.className, order:stage.children.map(x => x.className || x.tag),
  tint:tint && tint.attributes['aria-hidden'], x:layer.style.left, y:layer.style.top,
  width:layer.style.width, counter:counter.children.map(x => x.textContent),
  progress:progress.children.map(x => x.dataset.active),
  focus:stage.tabIndex, role:stage.attributes['role']};
context.previewMode = 'mobile';
const mobileFrame = new Element('div');
context.draw(mobileFrame, slide);
const mobileLayer = visit(mobileFrame,'hero-preview-layer');
const mobile = {classes:mobileFrame.className,
  x:mobileLayer.style.left, y:mobileLayer.style.top, width:mobileLayer.style.width};
slide.layers = [];
const legacyFrame = new Element('div');
context.draw(legacyFrame, slide);
const legacy = {copy:!!visit(legacyFrame,'hero-preview-copy'), tint:!!visit(legacyFrame,'hero-preview-tint'),
  counter:!!visit(legacyFrame,'hero-preview-counter')};
process.stdout.write(JSON.stringify({desktop,mobile,legacy}));
"""


class HeroSliderCromoPreview(unittest.TestCase):
    def test_real_preview_dom_duotone_layer_drag_positions_and_counter(self):
        self.assertIsNotNone(NODE, "Node.js is required; cannot skip browser preview contract")
        result = subprocess.run([NODE, "-e", RUNNER, str(JS)], capture_output=True,
                                text=True, timeout=12, check=False)
        self.assertEqual(result.returncode, 0, result.stderr[-600:])
        dom = json.loads(result.stdout)
        self.assertEqual(dom["desktop"]["tint"], "true")
        self.assertEqual(dom["desktop"]["counter"], ["02", "/ 02"])
        self.assertEqual(dom["desktop"]["progress"], ["false", "true"])
        self.assertEqual(dom["desktop"]["focus"], 0)
        self.assertEqual(dom["desktop"]["role"], "region")
        self.assertEqual(dom["desktop"]["x"], "25%")
        self.assertEqual(dom["desktop"]["width"], "60%")
        self.assertLess(dom["desktop"]["order"].index("hero-preview-tint"),
                        dom["desktop"]["order"].index("hero-preview-overlay"))
        self.assertEqual(dom["mobile"]["x"], "50%")
        self.assertEqual(dom["mobile"]["y"], "60%")
        self.assertEqual(dom["mobile"]["width"], "90%")
        self.assertIn("mobile", dom["mobile"]["classes"])
        self.assertEqual(dom["legacy"], {"copy": True, "tint": True, "counter": True})

    def test_cromo_css_filters_text_plates_and_reduced_motion(self):
        css = CSS.read_text(encoding="utf-8")
        for token in ('grayscale(1) contrast(1.3)', 'mix-blend-mode:multiply',
                      'background:#ff2a1a', 'background:rgba(3,3,3,.92)',
                      '"Broken Glass"', 'hero-preview-counter', 'focus-within',
                      'prefers-reduced-motion:reduce', 'transition:none',
                      'box-decoration-break:clone', 'hero-preview-progress-bar',
                      'src:url("../assets/fonts/BrokenGlass.ttf")'):
            with self.subTest(token=token):
                self.assertIn(token, css)

    def test_saved_slide_contract_and_drag_events_stay_unchanged(self):
        js = JS.read_text(encoding="utf-8")
        for marker in ('function bindPreviewDrag()', 'pointerdown', 'pointermove',
                       "data-save-slider", 'async function save()', 'normalizeConfig',
                       "const KEY = 'home.hero.slider';"):
            self.assertIn(marker, js)
        version = (ROOT / 'config/version.php').read_text(encoding='utf-8')
        package = json.loads((ROOT / 'package.json').read_text(encoding='utf-8'))
        self.assertIn("BRVTAL_APP_VERSION = '0.1.198'", version)
        self.assertEqual(package['version'], '0.1.198')


if __name__ == "__main__":
    unittest.main()
