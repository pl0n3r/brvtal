(() => {
  'use strict';
  try {
    if (!Object.prototype.hasOwnProperty.call(window, 'state')) {
      Object.defineProperty(window, 'state', {
        configurable: true,
        get() { return state; },
        set(value) { state = value; }
      });
    }
  } catch (_) {
    // The canonical inline admin shell owns state; this bridge only exposes it to extension scripts.
  }
})();
