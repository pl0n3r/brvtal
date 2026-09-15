(() => {
  const originalPageForm = window.pageForm;
  if (typeof originalPageForm !== 'function') return;

  window.pageForm = function brvtalPageForm(record) {
    const page = record && typeof record === 'object' ? { ...record } : {};
    if (!page.locale) page.locale = 'en';
    return originalPageForm(page);
  };
})();
