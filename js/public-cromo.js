/*
 * BRVTAL — Cromo backdrop (public home).
 *
 * Progressive enhancement: a fixed WebGL "liquid chrome" field behind the
 * existing Concept 05 markup. `html.cromo` is added only after the shader
 * compiles; without WebGL, on constrained devices or with Save-Data the page
 * keeps today's look untouched. The accent streak follows the administrable
 * Theme Studio red (`--red`), so brand changes in DISCADMIN retint it.
 */
(() => {
  const root = document.documentElement;
  const connection = navigator.connection || {};
  if (
    connection.saveData ||
    (navigator.deviceMemory && navigator.deviceMemory <= 2) ||
    (navigator.hardwareConcurrency && navigator.hardwareConcurrency <= 2)
  ) return;

  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)');
  const canvas = document.createElement('canvas');
  canvas.id = 'cromoGL';
  canvas.setAttribute('aria-hidden', 'true');
  const gl = canvas.getContext('webgl', {
    antialias: false,
    alpha: false,
    powerPreference: 'low-power',
  });
  if (!gl) return;

  const VS = 'attribute vec2 p;void main(){gl_Position=vec4(p,0.,1.);}';
  const FS = `precision highp float;
uniform vec2 uRes;uniform float uT;uniform vec2 uM;uniform float uBeat;uniform float uScroll;uniform vec3 uAccent;
float h(vec2 p){p=fract(p*vec2(123.34,456.21));p+=dot(p,p+45.32);return fract(p.x*p.y);}
float field(vec2 p,vec2 m){
 float f=0.;
 for(int i=0;i<7;i++){
  float fi=float(i);
  vec2 c=vec2(sin(uT*.5+fi*1.9)*1.0,cos(uT*.62+fi*2.3)*.55)*(1.+uBeat*.2)+vec2(0.,uScroll*.4*sin(fi));
  float r=.36+.07*sin(fi*3.1+uT);
  vec2 d=p-c;f+=r*r/(dot(d,d)+.0006);
 }
 vec2 dm=p-m;f+=.16/(dot(dm,dm)+.0006);
 return f;
}
float hgt(vec2 p,vec2 m){return sqrt(max(field(p,m)-1.,0.));}
void main(){
 vec2 p=(gl_FragCoord.xy*2.-uRes)/uRes.y;
 vec2 m=(uM*2.-1.)*vec2(uRes.x/uRes.y,1.);
 float f=field(p,m);
 vec3 col=vec3(.02,.02,.03)+uAccent*.12*smoothstep(.3,1.,f);
 if(f>1.){
  vec2 e=vec2(.01,0.);
  vec2 gh=vec2(hgt(p+e.xy,m)-hgt(p-e.xy,m),hgt(p+e.yx,m)-hgt(p-e.yx,m))/(2.*e.x);
  vec3 n=normalize(vec3(-gh*.5,1.));
  vec3 r=reflect(vec3(0.,0.,-1.),n);
  float hor=r.y*.5+.5;
  float sky=smoothstep(.42,.58,hor);
  vec3 chrome=mix(vec3(.03,.035,.05),vec3(.96,.98,1.),sky);
  chrome=mix(chrome,vec3(.35,.4,.55),smoothstep(.2,.45,hor)*(1.-sky)*.8);
  chrome+=uAccent*(1.-smoothstep(.0,.2,abs(r.x-.35+.1*sin(uT))))*.9*(1.+uBeat*2.);
  chrome+=vec3(.9)*pow(clamp(dot(n,normalize(vec3(-.4,.6,.7))),0.,1.),60.);
  col=chrome;
 }else{
  col+=uAccent*smoothstep(.55,1.,f)*(.5+uBeat);
 }
 col*=.95+.05*sin(gl_FragCoord.y*1.6);
 col+=(h(gl_FragCoord.xy+uT)-.5)*.05;
 col*=1.-.45*dot(p*.4,p*.4);
 gl_FragColor=vec4(max(col,0.),1.);
}`;

  const compile = (type, source) => {
    const shader = gl.createShader(type);
    gl.shaderSource(shader, source);
    gl.compileShader(shader);
    return gl.getShaderParameter(shader, gl.COMPILE_STATUS) ? shader : null;
  };
  const vs = compile(gl.VERTEX_SHADER, VS);
  const fs = compile(gl.FRAGMENT_SHADER, FS);
  if (!vs || !fs) return;
  const program = gl.createProgram();
  gl.attachShader(program, vs);
  gl.attachShader(program, fs);
  gl.linkProgram(program);
  if (!gl.getProgramParameter(program, gl.LINK_STATUS)) return;
  gl.useProgram(program);

  const buffer = gl.createBuffer();
  gl.bindBuffer(gl.ARRAY_BUFFER, buffer);
  gl.bufferData(gl.ARRAY_BUFFER, new Float32Array([-1, -1, 3, -1, -1, 3]), gl.STATIC_DRAW);
  const position = gl.getAttribLocation(program, 'p');
  gl.enableVertexAttribArray(position);
  gl.vertexAttribPointer(position, 2, gl.FLOAT, false, 0, 0);
  const uniform = (name) => gl.getUniformLocation(program, name);
  const uRes = uniform('uRes');
  const uT = uniform('uT');
  const uM = uniform('uM');
  const uBeat = uniform('uBeat');
  const uScroll = uniform('uScroll');
  const uAccent = uniform('uAccent');

  // Theme Studio red -> shader accent. A 2D canvas converts any CSS color
  // (hex, rgb, color(display-p3 ...)) to 8-bit sRGB; falls back to the default red.
  const readAccent = () => {
    let rgb = [211, 24, 34];
    try {
      const raw = getComputedStyle(root).getPropertyValue('--red').trim() || '#d31822';
      const swatch = document.createElement('canvas');
      swatch.width = 1;
      swatch.height = 1;
      const ctx2d = swatch.getContext('2d', { willReadFrequently: true });
      ctx2d.fillStyle = '#d31822';
      ctx2d.fillStyle = raw;
      ctx2d.fillRect(0, 0, 1, 1);
      rgb = Array.from(ctx2d.getImageData(0, 0, 1, 1).data).slice(0, 3);
    } catch (error) { /* keep the default red */ }
    // Lift the brand red so the streak stays hot on the dark base.
    return [Math.min(1, rgb[0] / 255 + .12), Math.min(1, rgb[1] / 255 + .1), Math.min(1, rgb[2] / 255 + .05)];
  };

  let reduction = 0;
  const currentScale = () => Math.max(.3, (window.innerWidth < 820 ? .42 : .6) - reduction);
  const resize = () => {
    const scale = currentScale();
    canvas.width = Math.max(2, Math.floor(window.innerWidth * scale));
    canvas.height = Math.max(2, Math.floor(window.innerHeight * scale));
    gl.viewport(0, 0, canvas.width, canvas.height);
  };

  let pointerX = .5;
  let pointerY = .5;
  let mouseX = .5;
  let mouseY = .5;
  let beat = 0;
  let last = 0;
  let slow = 0;
  let running = false;
  let mounted = false;
  let frameId = 0;
  let accent = [.83, .14, .17];

  const draw = (now) => {
    const t = reduceMotion.matches ? 0 : now / 1000;
    mouseX += (pointerX - mouseX) * .06;
    mouseY += (pointerY - mouseY) * .06;
    const pulse = reduceMotion.matches ? 0 : .2 * Math.max(0, Math.sin(t * 3.1));
    beat += (pulse - beat) * .5;
    gl.uniform2f(uRes, canvas.width, canvas.height);
    gl.uniform1f(uT, t);
    gl.uniform2f(uM, mouseX, mouseY);
    gl.uniform1f(uBeat, beat);
    gl.uniform1f(uScroll, window.scrollY / window.innerHeight);
    gl.uniform3f(uAccent, accent[0], accent[1], accent[2]);
    gl.drawArrays(gl.TRIANGLES, 0, 3);
  };

  const loop = (now) => {
    if (!running) return;
    const delta = now - last;
    last = now;
    // Adaptive resolution: step down when frames keep landing late.
    if (delta > 0 && delta < 500) {
      slow = delta > 30 ? slow + 1 : Math.max(0, slow - 1);
      if (slow > 40 && currentScale() > .3) {
        reduction += .08;
        resize();
        slow = 0;
      }
    }
    draw(now);
    frameId = window.requestAnimationFrame(loop);
  };

  const start = () => {
    if (!mounted || running || reduceMotion.matches || document.hidden) return;
    running = true;
    last = performance.now();
    frameId = window.requestAnimationFrame(loop);
  };
  const stop = () => {
    running = false;
    window.cancelAnimationFrame(frameId);
  };
  const unmount = () => {
    stop();
    mounted = false;
    root.classList.remove('cromo');
    canvas.remove();
  };

  const mount = () => {
    accent = readAccent();
    document.body.insertBefore(canvas, document.body.firstChild);
    resize();
    draw(0);
    root.classList.add('cromo');
    mounted = true;
    // If the stylesheet failed to load the canvas would sit in normal flow.
    if (getComputedStyle(canvas).position !== 'fixed') {
      unmount();
      return;
    }
    start();
  };

  window.addEventListener('resize', () => { if (mounted) { resize(); draw(performance.now()); } });
  window.addEventListener('pointermove', (event) => {
    pointerX = event.clientX / window.innerWidth;
    pointerY = 1 - event.clientY / window.innerHeight;
  }, { passive: true });
  document.addEventListener('visibilitychange', () => (document.hidden ? stop() : start()));
  canvas.addEventListener('webglcontextlost', (event) => {
    event.preventDefault();
    unmount();
  });
  const onMotionChange = () => {
    if (!mounted) return;
    if (reduceMotion.matches) { stop(); draw(0); } else start();
  };
  if (reduceMotion.addEventListener) reduceMotion.addEventListener('change', onMotionChange);
  else if (reduceMotion.addListener) reduceMotion.addListener(onMotionChange);

  if (document.body) mount();
  else document.addEventListener('DOMContentLoaded', mount, { once: true });
})();
