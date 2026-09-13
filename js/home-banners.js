(() => {
  const root = document.querySelector('.hero-banner');
  if (!root) return;
  const slides = [...root.querySelectorAll('.banner-slide')];
  if (slides.length < 2) return;
  const count = root.querySelector('.banner-count');
  const pauseButton = root.querySelector('[data-banner-pause]');
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  let current = 0;
  let paused = reduceMotion;
  let hovered = false;
  let focused = false;
  let timer;

  const show = index => {
    current = (index + slides.length) % slides.length;
    slides.forEach((slide, i) => {
      const active = i === current;
      slide.classList.toggle('is-active', active);
      slide.setAttribute('aria-hidden', String(!active));
      slide.inert = !active;
    });
    if (count) count.textContent = `${String(current + 1).padStart(2, '0')} / ${String(slides.length).padStart(2, '0')}`;
  };
  const schedule = () => {
    clearInterval(timer);
    if (!paused && !hovered && !focused && !document.hidden) timer = setInterval(() => show(current + 1), 6500);
  };

  root.querySelector('[data-banner-prev]')?.addEventListener('click', () => { show(current - 1); schedule(); });
  root.querySelector('[data-banner-next]')?.addEventListener('click', () => { show(current + 1); schedule(); });
  pauseButton?.addEventListener('click', () => {
    paused = !paused;
    pauseButton.textContent = paused ? 'PLAY' : 'PAUSE';
    pauseButton.setAttribute('aria-label', paused ? 'Resume banner rotation' : 'Pause banner rotation');
    schedule();
  });
  root.addEventListener('mouseenter', () => { hovered = true; schedule(); });
  root.addEventListener('mouseleave', () => { hovered = false; schedule(); });
  root.addEventListener('focusin', () => { focused = true; schedule(); });
  root.addEventListener('focusout', () => { focused = root.contains(document.activeElement); schedule(); });
  document.addEventListener('visibilitychange', schedule);
  show(0);
  if (paused && pauseButton) { pauseButton.textContent = 'PLAY'; pauseButton.setAttribute('aria-label', 'Resume banner rotation'); }
  schedule();
})();
