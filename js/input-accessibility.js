(() => {
  'use strict';

  const finePointer = window.matchMedia('(pointer:fine)').matches;
  const customCursorReady = finePointer && Boolean(window.gsap) && Boolean(document.querySelector('.cursor'));
  document.body.classList.toggle('cursor-enhanced', customCursorReady);
})();
