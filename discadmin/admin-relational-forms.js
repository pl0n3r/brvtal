(function () {
  'use strict';

  function normalizeDateTimeLocal(value) {
    const raw = String(value || '').trim();
    if (!raw) return '';
    return raw.replace(' ', 'T').slice(0, 16);
  }

  const originalEventForm = window.eventForm;
  if (typeof originalEventForm === 'function') {
    window.eventForm = function (record) {
      if (!record) return originalEventForm(record);
      return originalEventForm({
        ...record,
        event_date: normalizeDateTimeLocal(record.event_date)
      });
    };
  }

  const originalGo = window.go;
  if (typeof originalGo === 'function') {
    window.go = async function (section) {
      if (section !== 'sets') return originalGo(section);

      state.section = 'sets';
      const [setsResponse, artistsResponse, eventsResponse] = await Promise.all([
        req('/sets'),
        req('/artists'),
        req('/events')
      ]);

      state.rows = Array.isArray(setsResponse.data) ? setsResponse.data : [];
      state.artists = Array.isArray(artistsResponse.data) ? artistsResponse.data : [];
      state.events = Array.isArray(eventsResponse.data) ? eventsResponse.data : [];
      render();
    };
  }

  window.BRVTALAdminRelationalForms = { normalizeDateTimeLocal };
})();
