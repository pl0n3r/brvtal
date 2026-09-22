/*
 * BRVTAL — Concept 05 "07 / CONNECTED" real relational summary.
 * Reads the SAME public API payload app.js already fetches
 * (window.BRVTALPublicDataPromise -> { payload }): payload.events/
 * artists/sets/releases/memories for node counts, and
 * payload.relations.counts (event_memory/artist_memory/set_memory/
 * release_memory — built server-side by brvtal_public_add_memory_edges()
 * over real Memory relations) for the edge total. No fabricated data:
 * if the API is unavailable, the section stays at its static "—"
 * placeholders instead of inventing numbers.
 */
(function () {
  'use strict';

  function count(value) {
    return Array.isArray(value) ? value.length : 0;
  }

  function edgeTotal(relations) {
    var counts = relations && typeof relations.counts === 'object' ? relations.counts : {};
    return ['event_memory', 'artist_memory', 'set_memory', 'release_memory'].reduce(
      function (sum, key) {
        var value = Number(counts[key]);
        return sum + (Number.isFinite(value) ? value : 0);
      },
      0
    );
  }

  function render(section, payload) {
    var nodes = {
      events: count(payload.events),
      artists: count(payload.artists),
      sets: count(payload.sets),
      releases: count(payload.releases),
      memories: count(payload.memories),
    };

    Object.keys(nodes).forEach(function (key) {
      var el = section.querySelector('[data-connected-node="' + key + '"] [data-connected-count]');
      if (el) el.textContent = String(nodes[key]);
    });

    var edges = edgeTotal(payload.relations);
    var edgesEl = section.querySelector('[data-connected-edges]');
    if (edgesEl) {
      edgesEl.textContent = edges > 0
        ? edges + ' SEÑALES CONECTADAS ENTRE EVENTOS, ARTISTAS, SONIDO Y MEMORIAS.'
        : 'EL ARCHIVO CRECE CON CADA MEMORIA RELACIONADA.';
    }
  }

  async function waitForDataPromise(maxWaitMs) {
    var start = Date.now();
    while (!window.BRVTALPublicDataPromise && Date.now() - start < maxWaitMs) {
      await new Promise(function (resolve) { setTimeout(resolve, 100); });
    }
    return window.BRVTALPublicDataPromise || null;
  }

  async function init() {
    var section = document.querySelector('#connected[data-scene] , section#connected');
    if (!section) return;

    var request = await waitForDataPromise(4000);
    if (!request) return;

    try {
      var result = await request;
      var payload = result && result.payload ? result.payload : result;
      if (payload) render(section, payload);
    } catch (err) {
      // Leave the static "—" placeholders; never fabricate a count.
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init);
  } else {
    init();
  }
})();
