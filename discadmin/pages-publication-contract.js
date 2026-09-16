(() => {
  const originalPageForm = window.pageForm;
  if (typeof originalPageForm !== 'function') return;

  window.pageForm = function brvtalPageForm(record) {
    const page = record && typeof record === 'object' ? { ...record } : {};
    if (!page.locale) page.locale = 'en';
    const result = originalPageForm(page);

    const title = document.getElementById('f_title');
    const slug = document.getElementById('f_slug');
    if (title) {
      title.required = true;
      title.setAttribute('aria-required', 'true');
    }
    if (slug) {
      slug.required = true;
      slug.setAttribute('aria-required', 'true');
      if (!slug.placeholder) slug.placeholder = 'Generated from title when creating a Page';
    }

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
