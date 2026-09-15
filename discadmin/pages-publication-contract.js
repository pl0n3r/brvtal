(() => {
  const originalPageForm = window.pageForm;
  if (typeof originalPageForm !== 'function') return;

  window.pageForm = function brvtalPageForm(record) {
    const page = record && typeof record === 'object' ? { ...record } : {};
    if (!page.locale) page.locale = 'en';
    const result = originalPageForm(page);

    const content = document.getElementById('f_content_json');
    if (content) {
      content.placeholder = '{"blocks":[{"type":"heading","content":"Title"},{"type":"paragraph","content":"Body"}]}';
      const section = content.closest('.section');
      const helper = section?.querySelector('.sectionhead .helper');
      if (helper) helper.textContent = 'JSON: text/body/content or text · paragraph · heading blocks';
    }

    return result;
  };
})();
