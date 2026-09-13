(() => {
  const coarsePointer = window.matchMedia('(pointer: coarse)').matches;
  if (!coarsePointer) return;

  const loader = document.getElementById('loader');
  if (loader) loader.remove();

  const fxCanvas = document.getElementById('fxCanvas');
  if (fxCanvas) fxCanvas.remove();

  if (window.Lenis) window.Lenis = null;
  document.documentElement.classList.add('touch-performance-mode');
})();
