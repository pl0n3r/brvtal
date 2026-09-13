(() => {
  const MOBILE_QUERY = '(max-width: 900px), (pointer: coarse)';
  const track = document.querySelector('.events-track');
  const events = document.querySelector('.events');
  if (!track || !events || !window.matchMedia(MOBILE_QUERY).matches) return;

  document.documentElement.classList.add('native-events-scroll');
  track.setAttribute('tabindex', '0');
  track.setAttribute('role', 'region');
  track.setAttribute('aria-label', 'Events. Swipe or scroll horizontally to browse.');

  const releasePinnedEvents = () => {
    if (!window.ScrollTrigger?.getAll) return false;

    let released = false;
    window.ScrollTrigger.getAll().forEach((trigger) => {
      const isEventsPin = trigger?.trigger === events && Boolean(trigger?.vars?.pin);
      if (!isEventsPin) return;

      const animation = trigger.animation;
      trigger.kill(true);
      if (animation?.kill) animation.kill();
      released = true;
    });

    if (window.gsap?.set) {
      window.gsap.set(track, { clearProps: 'transform' });
    } else {
      track.style.transform = '';
    }

    return released;
  };

  // app.js creates the desktop pin synchronously when GSAP is available.
  // Run immediately and once more on the next frame to cover deferred CDN execution.
  releasePinnedEvents();
  requestAnimationFrame(() => {
    releasePinnedEvents();
    window.ScrollTrigger?.refresh?.();
  });
})();
