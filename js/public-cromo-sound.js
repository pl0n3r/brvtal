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
  let mediaEl = null;
  let mediaLoop = null;
  let mediaSession = 0;
  let webAudioSession = 0;
  let usingMedia = false;

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
    mediaSession += 1;
    webAudioSession += 1;
    usingMedia = false;
    if (mediaEl) mediaEl.pause();
    paint();
  };
  // iOS mutes Web Audio with the ring/silent switch unless the page declares
  // media playback; older iOS needs a silent <audio> started inside the gesture.
  let keepAlive = null;
  const SILENT_WAV = 'data:audio/wav;base64,UklGRkQDAABXQVZFZm10IBAAAAABAAEAQB8AAEAfAAABAAgAZGF0YSADAACAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgICAgA==';
  // The production CSP allows media from 'self', blob: and https: but not data:,
  // so the silent unlock clip is served from a blob: URL.
  let silentUrl = null;
  const silentClip = () => {
    if (!silentUrl) {
      const raw = atob(SILENT_WAV.slice(SILENT_WAV.indexOf(',') + 1));
      const bytes = Uint8Array.from(raw, (c) => c.codePointAt(0));
      silentUrl = URL.createObjectURL(new Blob([bytes], { type: 'audio/wav' }));
    }
    return silentUrl;
  };
  const claimPlayback = () => {
    try {
      if (navigator.audioSession) { navigator.audioSession.type = 'playback'; return; }
      if (!keepAlive) {
        keepAlive = new Audio(silentClip());
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
  // WebKit (Safari and every iOS browser) can show a running AudioContext with
  // no audible output. There the same loop is rendered offline once and played
  // through a regular <audio> element, which WebKit routes reliably.
  const BARS = 8;
  const STEP = 60 / 130 / 4;
  const useMedia = () => window.__cromoForceMediaSound === true || /apple/i.test(navigator.vendor || '');
  const wavUrl = (rendered) => {
    const samples = rendered.getChannelData(0);
    const bytes = new DataView(new ArrayBuffer(44 + samples.length * 2));
    const text = (at, value) => { for (let i = 0; i < value.length; i += 1) bytes.setUint8(at + i, value.codePointAt(i)); };
    text(0, 'RIFF'); bytes.setUint32(4, 36 + samples.length * 2, true); text(8, 'WAVEfmt ');
    bytes.setUint32(16, 16, true); bytes.setUint16(20, 1, true); bytes.setUint16(22, 1, true);
    bytes.setUint32(24, rendered.sampleRate, true); bytes.setUint32(28, rendered.sampleRate * 2, true);
    bytes.setUint16(32, 2, true); bytes.setUint16(34, 16, true); text(36, 'data'); bytes.setUint32(40, samples.length * 2, true);
    for (let i = 0; i < samples.length; i += 1) {
      bytes.setInt16(44 + i * 2, Math.max(-1, Math.min(1, samples[i])) * 32767, true);
    }
    return URL.createObjectURL(new Blob([bytes], { type: 'audio/wav' }));
  };
  const renderLoop = () => {
    const Offline = window.OfflineAudioContext || window.webkitOfflineAudioContext;
    const rate = 44100;
    const steps = 16 * BARS;
    const offline = new Offline(1, Math.ceil(rate * steps * STEP), rate);
    const liveCtx = ctx;
    const liveMaster = master;
    ctx = offline;
    master = offline.createGain();
    master.gain.value = .6;
    master.connect(offline.destination);
    for (let i = 0; i < steps; i += 1) {
      const t = i * STEP;
      const s = i % 16;
      if (s % 4 === 0) kick(t);
      if (s % 4 === 2) hat(t, true);
      else if (s % 2 === 1) hat(t, false);
      if (s % 4 === 2 || s === 7 || s === 15) bass(t);
    }
    ctx = liveCtx;
    master = liveMaster;
    return offline.startRendering().then(wavUrl);
  };
  const startMedia = () => {
    mediaSession += 1;
    const session = mediaSession;
    if (!mediaEl) {
      mediaEl = new Audio(silentClip());
      mediaEl.setAttribute('playsinline', '');
      mediaEl.loop = true;
    }
    // Starting playback inside the click unlocks the element for the real loop.
    const unlocked = mediaEl.play();
    const fallbackReady = Promise.resolve(prepareWebAudio());
    fallbackReady.catch(() => {});
    if (!mediaLoop) mediaLoop = renderLoop();
    const playRendered = async () => {
      await unlocked;
      const url = await mediaLoop;
      if (session !== mediaSession) return;
      mediaEl.src = url;
      await mediaEl.play();
      if (session !== mediaSession) return;
      on = true;
      usingMedia = true;
      let last = -1;
      window.clearInterval(timer);
      timer = window.setInterval(() => {
        const beat = Math.floor((mediaEl.currentTime % (STEP * 16)) / (STEP * 4));
        if (beat !== last) window.dispatchEvent(new CustomEvent('cromo:beat'));
        last = beat;
      }, 20);
      paint();
    };
    const run = async () => {
      try {
        await playRendered();
      } catch (error) {
        if (session !== mediaSession) return;
        window.console?.warn?.('BRVTAL sonido: falló el audio renderizado, se usa Web Audio.', error);
        mediaEl.pause();
        await fallbackReady;
        if (session !== mediaSession) return;
        if (ctx.state === 'running') begin();
        else stop();
      }
    };
    run().catch(() => { if (session === mediaSession) stop(); });
  };
  // Creates and unlocks the Web Audio context. Must run inside the click gesture,
  // also when it is only the fallback of the rendered-audio path.
  const prepareWebAudio = () => {
    claimPlayback();
    if (!ctx) {
      ctx = new AudioCtx();
      master = ctx.createGain();
      master.gain.value = .6;
      master.connect(ctx.destination);
      // Calls, backgrounding or the OS can suspend the context while "on":
      // reflect it honestly instead of showing a sound that is not playing.
      ctx.addEventListener('statechange', () => {
        if (on && !usingMedia && ctx.state !== 'running') stop();
      });
    }
    master.gain.setTargetAtTime(.6, ctx.currentTime, .05);
    unlock();
    return ctx.resume();
  };
  const startWebAudio = () => {
    const session = ++webAudioSession;
    const resumed = prepareWebAudio();
    const ready = () => {
      // An async resume must not revive the ambient loop after a Set starts.
      if (session !== webAudioSession) return;
      if (ctx.state === 'running') begin();
      else stop();
    };
    const failed = () => { if (session === webAudioSession) stop(); };
    if (resumed?.then) resumed.then(ready, failed);
    else ready();
  };
  const start = () => (useMedia() ? startMedia() : startWebAudio());
  const toggle = () => {
    if (on) stop();
    else {
      window.dispatchEvent(new CustomEvent('brvtal:cromo-ambient-start'));
      start();
    }
  };

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
    stop,
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
