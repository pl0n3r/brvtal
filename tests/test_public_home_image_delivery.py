"""Behavior-first Node/DOM regressions for Concept 05 image delivery (#1424)."""

from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]

# Runs the actual checked-in runtime, including its exported projection API.
HARNESS = r"""
const fs = require('fs');
const vm = require('vm');
const assert = require('assert').strict;
const requested = [];
const observers = [];
const paths = ['hero_face','crowd','nights','artists','memories'];
class Image {
  constructor() {
    this.dataset={};this.style={};this.hidden=false;
    this.width=0;this.height=0;this.alt='';this._src='';
  }
  set src(value) {this._src=value;requested.push(value);}
  get src() {return this._src;}
  removeAttribute(name) {
    if (name === 'src') this._src='';
    if (name === 'srcset') this.srcset='';
    if (name === 'sizes') this.sizes='';
  }
}
class Section {
  constructor(id){this.id=id;this.image=null;}
  querySelector(selector){
    const match = selector.match(/"([^"]+)"/);
    return this.image && match?.[1]===this.image.dataset.c5EditorialMedia ? this.image : null;
  }
  append(image){this.image=image;}
}
const sections = Object.fromEntries(paths.map(p=>[p,new Section(p)]));
const bySelector = {
  '.home-phase-a-hero':sections.hero_face,
  '#genesis':sections.crowd,
  '#events':sections.nights,
  '#artists':sections.artists,
  '#media':sections.memories,
};
const documentary = new Image(), documentaryLabel={textContent:''};
const figure = {
  hidden:true,
  querySelector(selector){
    if (selector === '[data-c5-hero-documentary-image]') return documentary;
    if (selector === '[data-c5-hero-documentary-label]') return documentaryLabel;
    return null;
  },
};
const document = {
  documentElement:{dataset:{},classList:{contains:()=>false}},
  readyState:'loading',
  querySelector:selector => selector === '[data-c5-hero-documentary]' ? figure : bySelector[selector] || null,
  querySelectorAll:selector=>selector === 'img[data-c5-editorial-media]'
    ? Object.values(sections).map(v=>v.image).filter(Boolean) : [],
  createElement:()=>new Image(),
};
const manifest = {
  '/uploads/media/hero-face.png':{
    src:'/uploads/media/hero-face.png',width:1800,height:1200,
    variants:{
      display:{src:'/uploads/media/hero-face--display.webp',width:800,height:533,type:'image/webp'},
      w1280:{src:'/uploads/media/hero-face--w1280.webp',width:1280,height:853,type:'image/webp'},
    }
  },
  '/uploads/media/crowd.png':{
    src:'/uploads/media/crowd.png',width:1600,height:900,
    variants:{
      display:{src:'/uploads/media/crowd--display.webp',width:640,height:360,type:'image/webp'},
      w1280:{src:'/uploads/media/crowd--w1280.webp',width:1280,height:720,type:'image/webp'},
    }
  }
};
const window = {
  addEventListener(){},
  matchMedia:()=>({matches:false}),
  BRVTALRuntimeReady:{then(){}},
  BRVTALPublicMedia:{loadImageDelivery:()=>Promise.resolve(manifest)},
};
class IntersectionObserver {
  constructor(callback,options){this.callback=callback;this.options=options;observers.push(this);}
  observe(section){this.section=section;}
  disconnect(){this.disconnected=true;}
  trigger(){this.callback([{isIntersecting:true,target:this.section}],this);}
}
vm.runInNewContext(
  fs.readFileSync('js/public-concept05-hero.js','utf8'),
  {window,document,IntersectionObserver,Promise,console},
  {filename:'public-concept05-hero.js'}
);
const flush = async()=>{for(let i=0;i<8;i++)await Promise.resolve();};
const source = {settings:{site:{
  c05_media_hero_face:{status:'published',id:1,url:'/uploads/media/hero-face.png'},
  c05_media_crowd:{status:'published',id:2,url:'/uploads/media/crowd.png'},
}}};
(async()=>{
  window.BRVTALConcept05Hero.projectEditorialMedia(source);
  await flush();
  assert.equal(sections.hero_face.image.loading,'eager');
  assert.equal(sections.crowd.image.loading,'lazy');
  assert.equal(requested.length,1,'only first viewport may request immediately');
  assert.equal(requested[0],'/uploads/media/hero-face--display.webp');
  assert(sections.hero_face.image.srcset.includes('800w'));
  assert(sections.hero_face.image.srcset.includes('1280w'));
  assert.equal(sections.hero_face.image.alt,'BRVTAL editorial hero face');
  assert.equal(sections.hero_face.image.width,384);
  assert.equal(sections.hero_face.image.height,240);
  assert.equal(observers.length,1);
  observers[0].trigger();
  await flush();
  assert.equal(sections.crowd.image.src,'/uploads/media/crowd--display.webp');
  assert(requested.every(u=>!u.endsWith('.png')),'WebP must precede originals');
  const crowd = sections.crowd.image;
  crowd.onerror();
  assert.equal(crowd.src,'/uploads/media/crowd.png');
  assert.equal(crowd.srcset,'');
  crowd.onerror();
  assert.equal(crowd.hidden,true);
  assert.equal(crowd.src,'');
  window.BRVTALConcept05Hero.projectDocumentary({
    memories:[{type:'image',file_path:'/uploads/media/hero-face.png',alt_text:'Accessible documentary'}]
  });
  await flush();
  assert.equal(documentary.alt,'Accessible documentary');
  assert.equal(documentary.src,'/uploads/media/hero-face--display.webp');
  assert.equal(documentary.width,1800);
  assert.equal(documentary.height,1200);
  documentary.onload();
  assert.equal(figure.hidden,false);
  window.BRVTALConcept05Hero.projectEditorialMedia(source);
  await flush();
  assert.equal(observers.length,1,'repeat render must not start redundant observer');
  console.log('OK: WebP before PNG; srcset; dimensions; alt; offscreen lazy; finite fallback; idempotence');
})().catch(error=>{console.error(error);process.exitCode=1;});
"""


class PublicHomeImageDeliveryTests(unittest.TestCase):
    def test_unavailable_sidecar_keeps_original_and_accessible_alt(self):
        # This executes the PHP renderer without a CMS, network or fake WebP proof.
        snippet = (
            "require 'config/public_assets.php';"
            "echo brvtal_public_optimize_home_images("
            "'<img src=\\\"/uploads/media/unavailable.png\\\" alt=\\\"Archive poster\\\">');"
        )
        result = subprocess.run(
            ["php", "-r", snippet], cwd=ROOT, capture_output=True,
            text=True, timeout=10, check=False,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertIn('src="/uploads/media/unavailable.png"', result.stdout)
        self.assertIn('alt="Archive poster"', result.stdout)
        self.assertIn('loading="lazy"', result.stdout)
        self.assertNotIn('srcset=', result.stdout)

    def test_live_runtime_uses_webp_before_png_and_defers_offscreen(self):
        result = subprocess.run(
            ["node", "-e", HARNESS],
            cwd=ROOT,
            capture_output=True,
            text=True,
            timeout=30,
            check=False,
        )
        self.assertEqual(result.returncode, 0, result.stdout + "\n" + result.stderr)
        self.assertIn("OK: WebP before PNG", result.stdout)


if __name__ == "__main__":
    unittest.main()
