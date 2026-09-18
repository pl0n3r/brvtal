(() => {
  const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const coarsePointer = window.matchMedia('(pointer: coarse)').matches;
  const motionReady = Boolean(window.gsap && window.ScrollTrigger);
  const qs = (s, r=document) => r.querySelector(s);
  const qsa = (s, r=document) => [...r.querySelectorAll(s)];

  const loader = qs('#loader');
  const pct = qs('#loadPct'), bar = qs('.loader-progress i');
  const loaderSteps = [11, 7, 14, 9, 13, 6];
  let loaderStepIndex = 0;

  const boot = () => {
    if (!loader) return;
    if (reduce || !motionReady) { loader.remove(); return; }
    let n = 0;
    const timer = setInterval(() => {
      n = Math.min(100, n + loaderSteps[loaderStepIndex++ % loaderSteps.length]);
      if (pct) pct.textContent = String(n).padStart(2,'0') + '%';
      if (bar) bar.style.width = n + '%';
      if (n >= 100) {
        clearInterval(timer);
        gsap.to(loader,{duration:1.2,yPercent:-100,ease:'power4.inOut',delay:.2,onComplete:()=>loader.remove()});
      }
    }, 70);
  };
  boot();

  if (motionReady) gsap.registerPlugin(ScrollTrigger);

  let lenis;
  if (!reduce && motionReady && window.Lenis) {
    lenis = new Lenis({duration:1.1,smoothWheel:true,syncTouch:false});
    lenis.on('scroll', ScrollTrigger.update);
    gsap.ticker.add(t => lenis.raf(t * 1000));
    gsap.ticker.lagSmoothing(0);
  }

  if (!reduce && motionReady) {
    if (!coarsePointer) {
      gsap.from('.hero-copy .eyebrow',{y:20,opacity:0,duration:1,delay:.55,ease:'power4.out'});
      gsap.from('.hero-title',{yPercent:90,opacity:0,skewX:8,duration:1.5,delay:.6,ease:'power4.out'});
      gsap.from('.hero-logo-wrap',{scale:1.35,opacity:0,rotation:3,duration:1.8,delay:.75,ease:'power3.out'});
      gsap.from('.hero-sub span',{y:15,opacity:0,stagger:.1,duration:.7,delay:1.2});
    }

    gsap.to('.hero-title',{yPercent:-38,scale:.78,skewX:-2,scrollTrigger:{trigger:'.hero',start:'top top',end:'bottom top',scrub:1}});
    gsap.to('.hero-logo-wrap',{y:'-13vh',rotation:-5,scale:.9,scrollTrigger:{trigger:'.hero',start:'top top',end:'bottom top',scrub:1.2}});
    gsap.to('.hero-glitch-lines',{xPercent:30,scaleX:1.2,scrollTrigger:{trigger:'.hero',start:'top top',end:'bottom top',scrub:1}});
    gsap.to('.hero-grid',{yPercent:18,scrollTrigger:{trigger:'.hero',start:'top top',end:'bottom top',scrub:1.2}});

    qsa('.manifesto-line').forEach((el,i)=>{
      gsap.fromTo(el,{x:i%2?-140:140,opacity:0,skewX:i%2?7:-7},{x:0,opacity:1,skewX:0,scrollTrigger:{trigger:el,start:'top 92%',end:'top 40%',scrub:1}});
    });
    gsap.from('.manifesto-final',{y:140,opacity:0,scrollTrigger:{trigger:'.manifesto-final',start:'top 90%',end:'top 40%',scrub:1}});
    gsap.to('.manifesto-art',{y:-140,rotation:-6,scrollTrigger:{trigger:'.manifesto',start:'top bottom',end:'bottom top',scrub:1.2}});

    gsap.to('.genesis-bg',{scale:1,rotation:.5,scrollTrigger:{trigger:'.genesis',start:'top bottom',end:'bottom top',scrub:1.5}});
    gsap.to('.genesis-copy h2',{xPercent:-5,skewX:-3,scrollTrigger:{trigger:'.genesis',start:'top bottom',end:'bottom top',scrub:1}});
    gsap.to('.genesis-eyes span',{x:(i)=>i%2?90:-90,rotation:(i)=>i*4,stagger:.05,scrollTrigger:{trigger:'.genesis',start:'top bottom',end:'bottom top',scrub:1.4}});
    gsap.to('.genesis-orbit',{rotation:35,scrollTrigger:{trigger:'.genesis',start:'top bottom',end:'bottom top',scrub:2}});

    const track = qs('.events-track');
    if(track) {
      gsap.to(track,{x:()=>-(track.scrollWidth-innerWidth+innerWidth*.08),ease:'none',
        scrollTrigger:{trigger:'.events',start:'top top',end:()=>'+='+Math.max(1100,track.scrollWidth-innerWidth+innerWidth*.2),pin:true,scrub:1.1,invalidateOnRefresh:true}});
    }

    gsap.from('.artist',{y:80,opacity:0,stagger:.08,scrollTrigger:{trigger:'.artists',start:'top 75%',end:'top 20%',scrub:1}});
    gsap.to('.artist-preview',{y:90,rotation:4,scrollTrigger:{trigger:'.artists',start:'top bottom',end:'bottom top',scrub:1.3}});

    gsap.from('.set-item',{x:120,opacity:0,stagger:.1,scrollTrigger:{trigger:'.sets',start:'top 75%',end:'top 25%',scrub:1}});
    gsap.to('.audio-bg',{rotation:-12,scale:1.15,scrollTrigger:{trigger:'.sets',start:'top bottom',end:'bottom top',scrub:1.5}});

    gsap.from('.media-grid .m',{y:120,opacity:0,rotation:(i)=>i%2?3:-3,stagger:.12,scrollTrigger:{trigger:'.media',start:'top 78%',end:'top 25%',scrub:1}});
  }

  // Scene indicator
  const sceneName = qs('#sceneName'), sceneCount = qs('#sceneCount');
  if (motionReady) qsa('.scene').forEach(sec => {
    ScrollTrigger.create({
      trigger:sec,start:'top 55%',end:'bottom 45%',
      onEnter:()=>setScene(sec),onEnterBack:()=>setScene(sec)
    });
  });
  function setScene(sec){
    document.body.dataset.scene = sec.dataset.scene || 'CORE';
    if(sceneName) sceneName.textContent = sec.dataset.scene || 'CORE';
    if(sceneCount) sceneCount.textContent = sec.dataset.index || '00';
  }

  // Menu
  const menu = qs('#menuToggle'), panel = qs('#menuPanel');
  let open=false;
  const setMenu = v => {
    open=v;
    menu.querySelector('strong').textContent=open?'×':'+';
    panel.setAttribute('aria-hidden',String(!open));
    if(motionReady && !reduce){
      gsap.to(panel,{duration:.9,yPercent:open?0:-100,ease:'power4.inOut',
        onStart:()=>panel.style.visibility='visible',
        onComplete:()=>{if(!open)panel.style.visibility='hidden'}});
      if(open) gsap.fromTo('.menu-panel nav a',{y:70,opacity:0},{y:0,opacity:1,duration:.8,stagger:.06,delay:.15,ease:'power4.out'});
    } else {
      panel.style.transform=open?'translateY(0)':'translateY(-100%)';
      panel.style.visibility=open?'visible':'hidden';
    }
  };
  menu.addEventListener('click',()=>setMenu(!open));
  qsa('.menu-panel a').forEach(a=>a.addEventListener('click',()=>setMenu(false)));

  // Cursor
  const cursor=qs('.cursor'), label=qs('.cursor-label');
  if(cursor && window.matchMedia('(pointer:fine)').matches && window.gsap){
    window.addEventListener('pointermove',e=>{
      gsap.to(cursor,{x:e.clientX,y:e.clientY,duration:.16,ease:'power2.out'});
      gsap.to(label,{x:e.clientX,y:e.clientY,duration:.16,ease:'power2.out'});
      const cross=qs('.artist-crosshair');
      if(cross){cross.querySelectorAll('span')[0].textContent=String(Math.round(e.clientX)).padStart(3,'0');cross.querySelectorAll('span')[1].textContent=String(Math.round(e.clientY)).padStart(3,'0');}
    });
    qsa('[data-cursor]').forEach(el=>{
      el.addEventListener('mouseenter',()=>{
        label.textContent=el.dataset.cursor;
        gsap.to(label,{opacity:1,duration:.18});
        gsap.to(cursor,{scale:2.1,duration:.25,ease:'power3.out'});
      });
      el.addEventListener('mouseleave',()=>{
        gsap.to(label,{opacity:0,duration:.18});
        gsap.to(cursor,{scale:1,duration:.25});
      });
    });
    qsa('.artist').forEach(a=>{
      a.addEventListener('mouseenter',()=>{
        const img=qs('.artist-preview img');
        if(img&&a.dataset.image) img.src=a.dataset.image;
      });
    });
    qsa('.magnetic').forEach(el=>{
      el.addEventListener('pointermove',e=>{
        const r=el.getBoundingClientRect();
        gsap.to(el,{x:(e.clientX-r.left-r.width/2)*.18,y:(e.clientY-r.top-r.height/2)*.18,duration:.35});
      });
      el.addEventListener('pointerleave',()=>gsap.to(el,{x:0,y:0,duration:.55,ease:'elastic.out(1,.4)'}));
    });
  }

  // Sound design — user initiated only.
  let audioCtx=null, soundOn=false;
  const soundBtn=qs('#soundToggle');
  const ensureAudio=()=>{
    if(!audioCtx) audioCtx=new (window.AudioContext||window.webkitAudioContext)();
    if(audioCtx.state==='suspended') audioCtx.resume();
  };
  const blip=(freq=90,dur=.07)=>{
    if(!soundOn)return;
    ensureAudio();
    const o=audioCtx.createOscillator(),g=audioCtx.createGain();
    o.type='sawtooth';
    o.frequency.setValueAtTime(freq,audioCtx.currentTime);
    o.frequency.exponentialRampToValueAtTime(freq*.35,audioCtx.currentTime+dur);
    g.gain.setValueAtTime(.0001,audioCtx.currentTime);
    g.gain.exponentialRampToValueAtTime(.035,audioCtx.currentTime+.008);
    g.gain.exponentialRampToValueAtTime(.0001,audioCtx.currentTime+dur);
    o.connect(g).connect(audioCtx.destination); o.start(); o.stop(audioCtx.currentTime+dur+.01);
  };
  soundBtn.addEventListener('click',()=>{
    soundOn=!soundOn; ensureAudio();
    soundBtn.querySelector('b').textContent=soundOn?'ON':'OFF';
    blip(soundOn?110:65,.12);
  });
  qsa('button,a').forEach(el=>el.addEventListener('mouseenter',()=>blip(75,.035)));

  // Lightweight grain / scan canvas.
  const canvas=qs('#fxCanvas'), ctx=canvas&&canvas.getContext('2d');
  if(canvas&&ctx&&!reduce){
    let w,h,t=0;
    // Decorative-only PRNG: never used for IDs, tokens or security decisions.
    let visualNoiseState = 0x9e3779b9;
    const nextVisualNoise = () => {
      visualNoiseState ^= visualNoiseState << 13;
      visualNoiseState ^= visualNoiseState >>> 17;
      visualNoiseState ^= visualNoiseState << 5;
      return (visualNoiseState >>> 0) / 4294967296;
    };
    const resize=()=>{w=canvas.width=innerWidth;h=canvas.height=innerHeight};
    resize(); addEventListener('resize',resize);
    const draw=()=>{
      t++; ctx.clearRect(0,0,w,h);
      ctx.globalAlpha=.035;
      for(let i=0;i<110;i++){
        ctx.fillStyle=nextVisualNoise()>.5?'#fff':'#000';
        ctx.fillRect(nextVisualNoise()*w,nextVisualNoise()*h,nextVisualNoise()*140+20,1);
      }
      ctx.globalAlpha=.025;ctx.fillStyle='#fff';ctx.fillRect(0,(t*2)%h,w,1);
      requestAnimationFrame(draw);
    };
    draw();
  }
})();


  // ============================================================
  // BRVTAL DYNAMIC FRONTEND V3
  // Uses the public API when available; existing HTML remains the
  // visual fallback so a temporary API failure never blanks the site.
  // ============================================================
  const Dynamic = (() => {
    const reduce = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    const qs = (selector, root=document) => root.querySelector(selector);
    const qsa = (selector, root=document) => [...root.querySelectorAll(selector)];
    const cursor = qs('.cursor');
    const label = qs('.cursor-label');

    const API_CANDIDATES = [
      '/api/public.php',
      '/api/public',
      '/api/public/',
      '/api/index.php?route=public',
      '/api/index.php?action=public'
    ];

    const statusEl = qs('#dynamicStatus');
    const fallbackEl = qs('#apiFallback');

    const esc = (value) => String(value ?? '')
      .replaceAll('&','&amp;').replaceAll('<','&lt;')
      .replaceAll('>','&gt;').replaceAll('"','&quot;')
      .replaceAll("'",'&#039;');

    const cleanUrl = (value) => {
      const raw = String(value ?? '').trim();
      if (!raw) return '';
      try {
        const u = new URL(raw, location.href);
        if (!/^https?:$/i.test(u.protocol)) return '';
        return u.href;
      } catch { return ''; }
    };

    const imgUrl = (value) => {
      if (!value) return '';
      const s = String(value);
      if (/^https?:\/\//i.test(s) || s.startsWith('/')) return s;
      return s.replace(/^\.?\//, '');
    };

    const pick = (obj, keys, fallback='') => {
      for (const key of keys) {
        if (obj && obj[key] !== undefined && obj[key] !== null && obj[key] !== '') return obj[key];
      }
      return fallback;
    };

    const arrayFrom = (payload, keys) => {
      if (Array.isArray(payload)) return payload;
      for (const key of keys) {
        if (Array.isArray(payload?.[key])) return payload[key];
      }
      return [];
    };

    const normalize = (payload) => {
      // Supports common envelopes without requiring a specific response shape.
      const root = payload?.data && typeof payload.data === 'object' ? payload.data : payload;
      return {
        events: arrayFrom(root, ['events','event']),
        settings: (root?.settings && typeof root.settings === 'object') ? root.settings : {},
        artists: arrayFrom(root, ['artists','artist']),
        sets: arrayFrom(root, ['sets','sets_media','audio','sound']),
        media: arrayFrom(root, ['media','gallery','images'])
      };
    };

    const fetchJSON = async (url) => {
      const response = await fetch(url, {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' },
        cache: 'no-store'
      });
      if (!response.ok) throw new Error(`API ${response.status}`);
      const json = await response.json();
      return json;
    };

    const firstWorkingPayload = async () => {
      let lastError = null;
      for (const url of API_CANDIDATES) {
        try {
          const payload = await fetchJSON(url);
          return { payload, url };
        } catch (err) {
          lastError = err;
        }
      }
      throw lastError || new Error('API unavailable');
    };

    const formatDate = (value) => {
      if (!value) return '';
      const d = new Date(String(value).replace(' ', 'T'));
      if (Number.isNaN(d.getTime())) return String(value);
      return new Intl.DateTimeFormat(document.documentElement.lang || 'en', {
        day:'2-digit', month:'2-digit', year:'numeric'
      }).format(d);
    };

    const applyPublicSettings = (settings) => {
      if (!settings || typeof settings !== 'object') return;

      const site = settings.site && typeof settings.site === 'object' ? settings.site : {};
      const social = settings.social && typeof settings.social === 'object' ? settings.social : {};
      const appearance = settings.appearance && typeof settings.appearance === 'object' ? settings.appearance : {};
      const theme = settings.theme && typeof settings.theme === 'object' ? settings.theme : {};
      const branding = theme.branding && typeof theme.branding === 'object' ? theme.branding : {};
      const colors = theme.colors && typeof theme.colors === 'object' ? theme.colors : {};

      const siteName = pick(site, ['name','siteName','title'], 'BRVTAL');
      const tagline = pick(site, ['tagline','description'], 'RAVE TILL GRAVE');
      const accent = pick(appearance, ['defaultAccent','accent','primaryColor'], pick(colors, ['accent','primary','primaryColor'], ''));

      document.title = `${siteName} — ${tagline}`;
      const description = pick(site, ['description','metaDescription'], '');
      const meta = qs('meta[name=description]');
      if (meta && description) meta.setAttribute('content', String(description));
      const themeColor = qs('meta[name=theme-color]');
      if (themeColor && /^#[0-9a-f]{3,8}$/i.test(String(accent))) themeColor.setAttribute('content', String(accent));
      if (/^#[0-9a-f]{3,8}$/i.test(String(accent))) {
        document.documentElement.style.setProperty('--cms-accent', String(accent));
        document.body.style.setProperty('--red', String(accent));
      }

      qsa('[data-site-name]').forEach(el => { el.textContent = siteName; });
      qsa('[data-site-tagline]').forEach(el => { el.textContent = tagline; });

      const socialMap = {
        instagram: ['instagram','instagram_url','instagramUrl'],
        soundcloud: ['soundcloud','soundcloud_url','soundcloudUrl'],
        youtube: ['youtube','youtube_url','youtubeUrl'],
        website: ['website','website_url','websiteUrl']
      };
      Object.entries(socialMap).forEach(([key, keys]) => {
        const url = cleanUrl(pick(social, keys, ''));
        qsa(`[data-social="${key}"]`).forEach(el => {
          if (url) {
            el.href = url;
            el.hidden = false;
          } else {
            el.hidden = true;
          }
        });
      });

      const logo = pick(branding, ['logo','logoUrl','logo_url'], '');
      if (logo) qsa('[data-site-logo]').forEach(img => { img.src = imgUrl(logo); });
    };

    const renderEvents = (items) => {
      if (!items.length) return false;
      const track = qs('.events-track');
      if (!track) return false;
      const cards = items.map((e, i) => {
        const title = pick(e,['title','name'],'UNTITLED EVENT');
        const image = imgUrl(pick(e,['cover_image','coverImage','image','photo','flyer'],''));
        const date = formatDate(pick(e,['event_date','eventDate','date'],''));
        const city = pick(e,['city'],'');
        const venue = pick(e,['venue'],'');
        const desc = pick(e,['description','tagline'],'BRVTAL');
        const status = String(pick(e,['status'],'published')).toUpperCase();
        const ticket = cleanUrl(pick(e,['ticket_url','ticketUrl','url'],''));
        const fallback = i === 0 ? 'NEXT EXPERIENCE' : 'ARCHIVE';
        return `<article class="event-card ${i===0?'event-active':''}">
          <div class="event-img">${image ? `<img src="${esc(image)}" alt="${esc(title)}" loading="${i?'lazy':'eager'}">` : ''}</div>
          <div class="event-info">
            <span class="mono">${esc([date,city].filter(Boolean).join(' / '))}</span>
            <h3>${esc(title)}</h3>
            <p>${esc([venue,desc].filter(Boolean).join(' / '))}</p>
            <span class="event-status">${esc(status==='PUBLISHED'?fallback:status)}</span>
            ${ticket ? `<a class="event-ticket mono" href="${esc(ticket)}" target="_blank" rel="noopener">TICKETS ↗</a>` : ''}
          </div>
        </article>`;
      }).join('');
      track.innerHTML = cards;
      return true;
    };

    const renderArtists = (items) => {
      if (!items.length) return false;
      const list = qs('.artist-list');
      if (!list) return false;
      const preview = qs('.artist-preview img');
      list.innerHTML = items.map((a,i) => {
        const name = pick(a,['name','title'],'UNKNOWN');
        const bio = pick(a,['bio','genre','style'],'BRVTAL ARTIST');
        const photo = imgUrl(pick(a,['photo','image','cover_image'],''));
        return `<a class="artist" href="${esc(cleanUrl(pick(a,['website_url','website','instagram_url','instagram'],'#')) || '#')}"
          ${photo ? `data-image="${esc(photo)}"` : ''} data-cursor="PROFILE">
          <span>${String(i+1).padStart(2,'0')}</span><strong>${esc(name)}</strong><i>${esc(bio)}</i>
        </a>`;
      }).join('');
      if (preview && items[0]) {
        const p = imgUrl(pick(items[0],['photo','image','cover_image'],''));
        if (p) preview.src = p;
      }
      return true;
    };

    const renderSets = (items) => {
      if (!items.length) return false;
      const list = qs('.set-list');
      if (!list) return false;
      list.innerHTML = items.map((s,i) => {
        const title = pick(s,['title','name'],'BRVTAL SET');
        const artist = pick(s,['artist_name','artistName','artist'],'BRVTAL');
        const desc = pick(s,['description','genre','style'],'ELECTRONIC MUSIC');
        const url = cleanUrl(pick(s,['external_url','externalUrl','url','soundcloud_url'],''));
        const platform = String(pick(s,['platform'],'soundcloud')).toUpperCase();
        return `<article class="set-item" data-cursor="PLAY">
          <div class="set-num mono">${String(i+1).padStart(3,'0')}</div>
          <div class="set-main"><span class="mono">${esc(platform)}</span><h4>${esc(title)}</h4><p>${esc([artist,desc].filter(Boolean).join(' / '))}</p></div>
          ${url ? `<a href="${esc(url)}" target="_blank" rel="noopener" class="set-action magnetic" data-cursor="${esc(platform)}">↗</a>` : ''}
        </article>`;
      }).join('');
      return true;
    };

    const renderMedia = (items) => {
      if (!items.length) return false;
      if (window.BRVTALPublicMedia?.render) return window.BRVTALPublicMedia.render(items);
      const grid = qs('.media-grid');
      if (!grid) return false;
      const slots = ['m1','m2','m3','m4'];
      grid.innerHTML = items.slice(0,8).map((m,i) => {
        const image = imgUrl(pick(m,['file_path','filePath','image','url','cover_image'],''));
        const title = pick(m,['title','name','alt_text'],'BRVTAL media');
        if (!image) return '';
        return `<figure class="m ${slots[i%slots.length]}"><img src="${esc(image)}" alt="${esc(title)}" loading="lazy"></figure>`;
      }).join('');
      return true;
    };

    const bindCursorInteraction = (el) => {
      if (el.dataset.dynamicBound) return;
      el.dataset.dynamicBound = '1';
      el.addEventListener('mouseenter', () => {
        if (label) {
          label.textContent = el.dataset.cursor || '';
          gsap.to(label,{opacity:1,duration:.18});
        }
        if (cursor) {
          gsap.to(cursor,{scale:2.1,duration:.25,ease:'power3.out'});
        }
      });
      el.addEventListener('mouseleave', () => {
        if (label) {
          gsap.to(label,{opacity:0,duration:.18});
        }
        if (cursor) {
          gsap.to(cursor,{scale:1,duration:.25});
        }
      });
    };

    const bindMagneticInteraction = (el) => {
      if (el.dataset.dynamicMagnetic) return;
      el.dataset.dynamicMagnetic = '1';
      el.addEventListener('pointermove', e => {
        const r = el.getBoundingClientRect();
        gsap.to(el,{
          x:(e.clientX-r.left-r.width/2)*.18,
          y:(e.clientY-r.top-r.height/2)*.18,
          duration:.35
        });
      });
      el.addEventListener('pointerleave', () => {
        gsap.to(el,{x:0,y:0,duration:.55,ease:'elastic.out(1,.4)'});
      });
    };

    const bindArtistPreviewInteraction = (artist) => {
      if (artist.dataset.dynamicArtistBound) return;
      artist.dataset.dynamicArtistBound = '1';
      artist.addEventListener('mouseenter', () => {
        const image = qs('.artist-preview img');
        if (image && artist.dataset.image) {
          image.src = artist.dataset.image;
        }
      });
    };

    const bindDynamicInteractions = () => {
      const enhancedPointer = (
        typeof window.gsap !== 'undefined'
        && !reduce
        && window.matchMedia('(pointer:fine)').matches
      );
      if (enhancedPointer) {
        qsa('[data-cursor]').forEach(bindCursorInteraction);
        qsa('.magnetic').forEach(bindMagneticInteraction);
      }
      qsa('.artist').forEach(bindArtistPreviewInteraction);
    };

    const refreshSceneAnimations = () => {
      if (window.ScrollTrigger) {
        ScrollTrigger.refresh();
      }
    };

    const updateStatus = (ok, detail='') => {
      if (statusEl) {
        statusEl.textContent = ok ? 'LIVE / CMS CONNECTED' : 'STATIC / API OFFLINE';
        statusEl.dataset.state = ok ? 'live' : 'offline';
      }
      if (fallbackEl) fallbackEl.hidden = ok;
      if (detail && statusEl) statusEl.title = detail;
    };

    const init = async () => {
      const request = window.BRVTALPublicDataPromise || firstWorkingPayload();
      window.BRVTALPublicDataPromise = request;
      try {
        const {payload, url} = await request;
        const data = normalize(payload);
        applyPublicSettings(data.settings);
        let changed = 0;
        if (renderEvents(data.events)) changed++;
        if (renderArtists(data.artists)) changed++;
        if (renderSets(data.sets)) changed++;
        if (renderMedia(data.media)) changed++;
        bindDynamicInteractions();
        refreshSceneAnimations();
        updateStatus(true, url);
        document.documentElement.dataset.api = 'online';
        return {ok:true, changed};
      } catch (err) {
        if (window.BRVTALPublicDataPromise === request) window.BRVTALPublicDataPromise = null;
        updateStatus(false, err?.message || 'API unavailable');
        document.documentElement.dataset.api = 'offline';
        return {ok:false, changed:0};
      }
    };

    return { init };
  })();

  // Start after the static experience is ready; never block first paint.
  window.addEventListener('load', () => {
    window.setTimeout(() => Dynamic.init(), 120);
  });