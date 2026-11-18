(() => {
  'use strict';

  window.BRVTALAdminStateBridge = {
    available: Object.prototype.hasOwnProperty.call(window, 'state')
      && typeof window.state === 'object'
      && window.state !== null
  };
})();
