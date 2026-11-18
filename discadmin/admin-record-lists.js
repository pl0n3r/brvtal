(function () {
  'use strict';

  const schemas = {
    EVENTS: ['','DATE','LOCATION','STATUS','ACTIONS'],
    ARTISTS: ['','LINKS','STATUS','ORDER','ACTIONS'],
    SETS: ['','ARTIST','EVENT','STATUS','ACTIONS'],
    MEDIA: ['','TYPE','MIME','STATUS','ACTIONS'],
    PAGES: ['','LOCALE','STATUS','','ACTIONS'],
  };

  let timer = null;

  function currentModule() {
    const title = document.querySelector('.admin-page-title h1, .top h1');
    return String(title?.textContent || '').trim().toUpperCase();
  }

  /** Decorate canonical admin rows with responsive record metadata. */
  function decorate() {
    const moduleName = currentModule();
    const labels = schemas[moduleName];
    const table = document.querySelector('.main .table');
    if (!labels || !table) return false;

    table.dataset.recordList = moduleName.toLowerCase();
    table.querySelectorAll('#rows .tr').forEach(row => {
      const cells = [...row.children];
      if (!cells.length) return;

      row.dataset.recordCard = moduleName.toLowerCase();
      const identity = cells[0]?.querySelector('.title')?.textContent?.trim();
      if (identity) row.setAttribute('aria-label', `${moduleName.slice(0,-1)} ${identity}`);

      cells.forEach((cell, index) => {
        cell.classList.remove('record-main','record-field','record-status','record-actions','record-spacer');
        delete cell.dataset.label;

        if (index === 0) {
          cell.classList.add('record-main');
          return;
        }
        if (index === cells.length - 1) {
          cell.classList.add('record-actions');
          return;
        }

        const label = labels[index] || '';
        if (!label) {
          cell.classList.add('record-spacer');
          cell.setAttribute('aria-hidden', 'true');
          return;
        }

        cell.classList.add('record-field');
        cell.dataset.label = label;
        if (label === 'STATUS') cell.classList.add('record-status');
      });
    });
    return true;
  }

  function schedule() {
    clearTimeout(timer);
    timer = setTimeout(decorate, 30);
  }

  const observer = new MutationObserver(schedule);
  observer.observe(document.documentElement, { childList: true, subtree: true });
  window.addEventListener('resize', schedule, { passive: true });
  window.BRVTALAdminRecordLists = { decorate };
  schedule();
})();
