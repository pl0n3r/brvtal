(() => {
  'use strict';

  const track = document.querySelector('.events-track');
  const events = document.querySelector('.events');
  if (!track || !events) return;

  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  document.documentElement.classList.add('native-events-scroll');
  track.setAttribute('tabindex', '0');
  track.setAttribute('role', 'region');
  track.setAttribute('aria-label', 'Events. Drag, swipe or scroll horizontally to browse.');

  const releasePinnedEvents = () => {
    let released = false;

    if (window.ScrollTrigger?.getAll) {
      window.ScrollTrigger.getAll().forEach((trigger) => {
        const isEventsPin = trigger?.trigger === events && Boolean(trigger?.vars?.pin);
        if (!isEventsPin) return;

        const animation = trigger.animation;
        trigger.kill(true);
        if (animation?.kill) animation.kill();
        released = true;
      });
    }

    if (window.gsap?.set) {
      window.gsap.set(track, { clearProps: 'transform' });
    } else {
      track.style.transform = '';
    }

    return released;
  };

  const clampHorizontalPosition = () => {
    const max = Math.max(0, track.scrollWidth - track.clientWidth);
    if (track.scrollLeft > max) track.scrollLeft = max;
  };

  let viewportFrame = 0;
  const syncViewport = () => {
    cancelAnimationFrame(viewportFrame);
    viewportFrame = requestAnimationFrame(() => {
      releasePinnedEvents();
      clampHorizontalPosition();
      window.ScrollTrigger?.refresh?.();
    });
  };

  // app.js may create the legacy Events pin before enhancements load. Remove it
  // for every viewport so vertical wheel input always belongs to the page.
  releasePinnedEvents();
  requestAnimationFrame(syncViewport);
  window.addEventListener('resize', syncViewport, { passive: true });
  window.addEventListener('orientationchange', syncViewport, { passive: true });

  let dragging = false;
  let dragged = false;
  let startX = 0;
  let startScrollLeft = 0;

  track.addEventListener('pointerdown', event => {
    if (event.pointerType !== 'mouse' || event.button !== 0) return;
    dragging = true;
    dragged = false;
    startX = event.clientX;
    startScrollLeft = track.scrollLeft;
    track.classList.add('is-dragging');
    track.setPointerCapture?.(event.pointerId);
  });

  track.addEventListener('pointermove', event => {
    if (!dragging) return;
    const delta = event.clientX - startX;
    if (Math.abs(delta) > 5) dragged = true;
    track.scrollLeft = startScrollLeft - delta;
    if (dragged) event.preventDefault();
  });

  const stopDrag = event => {
    if (!dragging) return;
    dragging = false;
    track.classList.remove('is-dragging');
    if (event && track.hasPointerCapture?.(event.pointerId)) {
      track.releasePointerCapture(event.pointerId);
    }
  };

  track.addEventListener('pointerup', stopDrag);
  track.addEventListener('pointercancel', stopDrag);
  track.addEventListener('lostpointercapture', () => stopDrag());
  track.addEventListener('click', event => {
    if (!dragged) return;
    event.preventDefault();
    event.stopPropagation();
    dragged = false;
  }, true);

  track.addEventListener('keydown', event => {
    if (event.key !== 'ArrowLeft' && event.key !== 'ArrowRight') return;
    event.preventDefault();
    const direction = event.key === 'ArrowRight' ? 1 : -1;
    track.scrollBy({
      left: direction * Math.max(320, track.clientWidth * 0.62),
      behavior: reduceMotion ? 'auto' : 'smooth'
    });
  });
})();
