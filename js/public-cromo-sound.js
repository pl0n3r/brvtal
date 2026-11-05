/*
 * BRVTAL — Cromo sound (public home).
 * Procedural techno with Web Audio (kick, hi-hat, bass at ~130 BPM). It only
 * starts after a user click, is off by default, never autoplays and stops when
 * the tab is hidden. Each kick dispatches `cromo:beat` so the chrome backdrop
 * can pulse. Nothing is stored except the on/off preference.
 */
(() => {
  const root = document.documentElement;
  const AudioCtx = window.AudioContext || window.webkitAudioContext;
  if (!AudioCtx) return;

  const LABEL_ON = 'Apagar el pulso';
  const LABEL_OFF = 'Activar sonido';
  let ctx = null;
  let master = null;
  let timer = null;
  let nextTime = 0;
  let step = 0;
  let on = false;

  const kick = (t) => {
    const o = ctx.createOscillator();
    const g = ctx.createGain();
    o.type = 'sine';
    o.frequency.setValueAtTime(170, t);
    o.frequency.exponentialRampToValueAtTime(42, t + .14);
    g.gain.setValueAtTime(.95, t);
    g.gain.exponentialRampToValueAtTime(.001, t + .34);
    o.connect(g).connect(master);
    o.start(t);
    o.stop(t + .36);
  };
  const hat = (t, open) => {
    const len = Math.floor(ctx.sampleRate * (open ? .12 : .04));
    const buffer = ctx.createBuffer(1, len, ctx.sampleRate);
    const data = buffer.getChannelData(0);
    for (let i = 0; i < len; i += 1) data[i] = (Math.random() * 2 - 1) * (1 - i / len);
    const source = ctx.createBufferSource();
    const filter = ctx.createBiquadFilter();
    const g = ctx.createGain();
    source.buffer = buffer;
    filter.type = 'highpass';
    filter.frequency.value = 7000;
    g.gain.value = open ? .16 : .1;
    source.connect(filter).connect(g).connect(master);
    source.start(t);
  };
  const bass = (t) => {
    const o = ctx.createOscillator();
    const f = ctx.createBiquadFilter();
    const g = ctx.createGain();
    o.type = 'sawtooth';
    o.frequency.value = 43.65;
    f.type = 'lowpass';
    f.Q.value = 9;
    f.frequency.setValueAtTime(900, t);
    f.frequency.exponentialRampToValueAtTime(90, t + .22);
    g.gain.setValueAtTime(.32, t);
    g.gain.exponentialRampToValueAtTime(.001, t + .24);
    o.connect(f).connect(g).connect(master);
    o.start(t);
    o.stop(t + .26);
  };
  const schedule = () => {
    const spb = 60 / 130 / 4;
    while (nextTime < ctx.currentTime + .15) {
      const s = step % 16;
      if (s % 4 === 0) {
        kick(nextTime);
        const delay = Math.max(0, (nextTime - ctx.currentTime) * 1000);
        window.setTimeout(() => window.dispatchEvent(new CustomEvent('cromo:beat')), delay);
      }
      if (s % 4 === 2) hat(nextTime, true);
      else if (s % 2 === 1) hat(nextTime, false);
      if (s % 4 === 2 || s === 7 || s === 15) bass(nextTime);
      nextTime += spb;
      step += 1;
    }
  };

  const buttons = [];
  const paint = () => {
    buttons.forEach((b) => {
      const labelOn = b.dataset.cromoSoundOn || LABEL_ON;
      const labelOff = b.dataset.cromoSoundOff || LABEL_OFF;
      b.setAttribute('aria-pressed', on ? 'true' : 'false');
      b.setAttribute('aria-label', on ? LABEL_ON : labelOff);
      b.classList.toggle('on', on);
      b.classList.toggle('cr-on', on);
      const label = b.querySelector('.cromo-sound-label, [data-cromo-sound-label]');
      if (label) label.textContent = on ? labelOn : labelOff;
    });
    root.classList.toggle('cromo-sound-on', on);
  };
  const stop = () => {
    on = false;
    window.clearInterval(timer);
    timer = null;
    if (master && ctx) master.gain.setTargetAtTime(0, ctx.currentTime, .05);
    if (keepAlive) keepAlive.pause();
    paint();
  };
  // iOS mutes Web Audio with the ring/silent switch unless the page declares
  // media playback; older iOS needs a silent <audio> started inside the gesture.
  let keepAlive = null;
  const SILENT_WAV = 'data:audio/wav;base64,UklGRiQAAABXQVZFZm10IBAAAAABAAEAESsAACJWAAACABAAZGF0YQAAAAA=';
  const claimPlayback = () => {
    try {
      if (navigator.audioSession) { navigator.audioSession.type = 'playback'; return; }
      if (!keepAlive) {
        keepAlive = new Audio(SILENT_WAV);
        keepAlive.setAttribute('playsinline', '');
        keepAlive.loop = true;
      }
      const played = keepAlive.play();
      played?.catch?.(() => {});
    } catch (e) { /* best effort */ }
  };
  const unlock = () => {
    // A node started inside the click gesture unlocks the context on Safari.
    const buffer = ctx.createBuffer(1, 1, ctx.sampleRate);
    const source = ctx.createBufferSource();
    source.buffer = buffer;
    source.connect(ctx.destination);
    source.start(0);
  };
  const begin = () => {
    window.clearInterval(timer);
    on = true;
    nextTime = ctx.currentTime + .05;
    step = 0;
    timer = window.setInterval(schedule, 25);
    paint();
  };
  const start = () => {
    claimPlayback();
    if (!ctx) {
      ctx = new AudioCtx();
      master = ctx.createGain();
      master.gain.value = .6;
      master.connect(ctx.destination);
      // Calls, backgrounding or the OS can suspend the context while "on":
      // reflect it honestly instead of showing a sound that is not playing.
      ctx.addEventListener('statechange', () => {
        if (on && ctx.state !== 'running') stop();
      });
    }
    master.gain.setTargetAtTime(.6, ctx.currentTime, .05);
    unlock();
    const resumed = ctx.resume();
    const ready = () => (ctx.state === 'running' ? begin() : stop());
    if (resumed?.then) resumed.then(ready, stop);
    else ready();
  };
  const toggle = () => (on ? stop() : start());

  const makeButton = () => {
    const b = document.createElement('button');
    b.type = 'button';
    b.className = 'cromo-sound';
    b.setAttribute('aria-pressed', 'false');
    b.innerHTML = '<span class="cromo-sound-dot" aria-hidden="true"></span><span class="cromo-sound-label">' + LABEL_OFF + '</span>';
    b.addEventListener('click', toggle);
    buttons.push(b);
    return b;
  };

  // The Cromo home builds its own switches later and registers them here.
  window.BRVTALCromoSound = {
    bind: (button) => {
      if (!button || buttons.includes(button)) return;
      button.addEventListener('click', toggle);
      buttons.push(button);
      paint();
    },
  };

  const mount = () => {
    const navRight = document.querySelector('header.nav .nav-right');
    if (navRight && !navRight.querySelector('.cromo-sound')) navRight.insertBefore(makeButton(), navRight.firstChild);
    const sets = document.querySelector('#sets .sets-intro');
    if (sets && !sets.querySelector('.cromo-sound')) sets.appendChild(makeButton());
    paint();
  };

  document.addEventListener('visibilitychange', () => { if (document.hidden && on) stop(); });
  window.addEventListener('pagehide', () => { if (on) stop(); });
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', mount, { once: true });
  else mount();
})();
