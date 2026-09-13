(() => {
  const coarsePointer = window.matchMedia('(pointer: coarse)').matches;
  if (!coarsePointer) return;

  const fxCanvas = document.getElementById('fxCanvas');
  if (fxCanvas) fxCanvas.remove();

  if (window.Lenis) window.Lenis = null;
  document.documentElement.classList.add('touch-performance-mode');
})();
