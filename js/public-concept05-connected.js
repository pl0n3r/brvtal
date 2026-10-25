/*
 * BRVTAL — Concept 05 "07 / CONNECTED".
 * Builds only aggregate category edges that are backed by the canonical
 * payload.relations.counts graph. No fabricated edge is ever rendered.
 */
(function () {
  'use strict';

  var EDGE_SPECS = [
    ['event_artist','events','artists','EVENTS ↔ ARTISTS'],
    ['event_set','events','sets','EVENTS ↔ SOUND'],
    ['artist_set','artists','sets','ARTISTS ↔ SOUND'],
    ['artist_release','artists','releases','ARTISTS ↔ RECORDS'],
    ['event_memory','events','memories','EVENTS ↔ MEMORIES'],
    ['artist_memory','artists','memories','ARTISTS ↔ MEMORIES'],
    ['set_memory','sets','memories','SOUND ↔ MEMORIES'],
    ['release_memory','releases','memories','RECORDS ↔ MEMORIES'],
  ];

  function count(value) {
    return Array.isArray(value) ? value.length : 0;
  }

  function uniqueEventCount(payload) {
    var ids = new Set();
    var addId = function (item) {
      // Only real positive CMS IDs are countable. Normalize number/string
      // representations so the public feed and archive cannot double count.
      // Only own CMS fields establish identity; inherited IDs/getters are untrusted.
      var raw = item && typeof item === 'object' && !Array.isArray(item)
        && Object.hasOwn(item, 'id') ? item.id : undefined;
      var id = typeof raw === 'number' ? raw
        : (typeof raw === 'string' && /^\d+$/.test(raw.trim()) ? Number(raw.trim()) : NaN);
      if (Number.isSafeInteger(id) && id > 0) ids.add(String(id));
    };
    (Array.isArray(payload.events) ? payload.events : []).forEach(addId);
    (Array.isArray(payload.archive?.events) ? payload.archive.events : []).forEach(addId);
    return ids.size;
  }

  function relationCounts(relations) {
    var input = relations && typeof relations.counts === 'object' && relations.counts !== null && !Array.isArray(relations.counts)
      ? relations.counts : {};
    var out = {};
    EDGE_SPECS.forEach(function (spec) {
      // The CMS contract carries integer counts. Do not coerce booleans,
      // objects, fractional values, or unsafe integers into invented edges.
      // Only CMS-owned keys certify a relationship; inherited keys do not.
      var raw = Object.hasOwn(input, spec[0]) ? input[spec[0]] : undefined;
      var value = typeof raw === 'number' ? raw
        : (typeof raw === 'string' && /^(?:0|[1-9]\d*)$/.test(raw.trim()) ? Number(raw.trim()) : NaN);
      out[spec[0]] = Number.isSafeInteger(value) && value > 0 ? value : 0;
    });
    return out;
  }

  function edgeTotal(counts) {
    // Each CMS count is safe on its own, but the aggregate may overflow.
    // Report an unknown total rather than round a verified link count.
    return Object.values(counts).reduce(function (sum, value) {
      if (sum === null) return null;
      var next = sum + value;
      return Number.isSafeInteger(next) ? next : null;
    }, 0);
  }

  function nodeCenter(section, key) {
    var node = section.querySelector('[data-connected-node="' + key + '"]');
    var graph = section.querySelector('[data-connected-graph]');
    if (!node || !graph) return null;
    var nodeRect = node.getBoundingClientRect();
    var graphRect = graph.getBoundingClientRect();
    return {
      x: nodeRect.left - graphRect.left + (nodeRect.width / 2),
      y: nodeRect.top - graphRect.top + (nodeRect.height / 2),
    };
  }

  function drawEdges(section, counts) {
    var graph = section.querySelector('[data-connected-graph]');
    var svg = section.querySelector('[data-connected-lines]');
    var ledger = section.querySelector('[data-connected-ledger]');
    if (!graph || !svg || !ledger) return;

    var width = Math.max(1, Math.round(graph.clientWidth));
    var height = Math.max(1, Math.round(graph.clientHeight));
    svg.setAttribute('viewBox', '0 0 ' + width + ' ' + height);
    svg.replaceChildren();
    ledger.replaceChildren();

    EDGE_SPECS.forEach(function (spec) {
      var key = spec[0], from = spec[1], to = spec[2], label = spec[3];
      var value = counts[key] || 0;
      if (value < 1) return;
      var start = nodeCenter(section, from);
      var end = nodeCenter(section, to);
      if (!start || !end) return;

      var line = document.createElementNS('http://www.w3.org/2000/svg', 'line');
      line.setAttribute('x1', String(start.x));
      line.setAttribute('y1', String(start.y));
      line.setAttribute('x2', String(end.x));
      line.setAttribute('y2', String(end.y));
      line.setAttribute('vector-effect', 'non-scaling-stroke');
      line.dataset.connectedEdge = key;
      line.dataset.connectedCount = String(value);
      line.style.setProperty('--edge-weight', String(Math.min(4, 1 + Math.log2(value + 1))));
      svg.appendChild(line);

      var item = document.createElement('span');
      item.className = 'c5-connected-ledger-item mono';
      item.dataset.connectedLedgerEdge = key;
      item.textContent = label + ' / ' + value;
      ledger.appendChild(item);
    });

    if (!svg.childElementCount) {
      var empty = document.createElement('span');
      empty.className = 'c5-connected-ledger-empty mono';
      empty.textContent = 'NO STRUCTURED LINKS YET.';
      ledger.appendChild(empty);
    }
  }

  function render(section, payload) {
    var nodes = {
      events: uniqueEventCount(payload),
      artists: count(payload.artists),
      sets: count(payload.sets),
      releases: count(payload.releases),
      memories: count(payload.memories),
    };

    Object.keys(nodes).forEach(function (key) {
      var el = section.querySelector('[data-connected-node="' + key + '"] [data-connected-count]');
      if (el) el.textContent = String(nodes[key]);
    });

    var counts = relationCounts(payload.relations);
    var edges = edgeTotal(counts);
    var edgesEl = section.querySelector('[data-connected-edges]');
    if (edgesEl) {
      edgesEl.textContent = edges === null
        ? 'RELATIONAL LINK TOTAL UNAVAILABLE.'
        : edges > 0
          ? edges + ' VERIFIED RELATIONAL LINKS IN THE PUBLIC ARCHIVE.'
          : 'NO STRUCTURED RELATIONSHIPS PUBLISHED YET.';
    }

    requestAnimationFrame(function () {
      if (section.isConnected) drawEdges(section, counts);
    });
    // PHP partials can be replaced without a full page load. The latest
    // Connected section owns exactly one resize listener across script reloads.
    if (typeof window.__brvtalConnectedResizeCleanup === 'function') {
      window.__brvtalConnectedResizeCleanup();
    }
    var resizeTimer = 0;
    var redraw = function () {
      if (!section.isConnected) {
        cleanupResize();
        return;
      }
      window.clearTimeout(resizeTimer);
      resizeTimer = window.setTimeout(function () {
        if (section.isConnected) drawEdges(section, counts);
      }, 80);
    };
    var cleanupResize = function () {
      window.removeEventListener('resize', redraw);
      window.clearTimeout(resizeTimer);
      if (window.__brvtalConnectedResizeCleanup === cleanupResize) {
        delete window.__brvtalConnectedResizeCleanup;
      }
    };
    window.__brvtalConnectedResizeCleanup = cleanupResize;
    window.addEventListener('resize', redraw, {passive:true});

    document.documentElement.dataset.publicConnected = 'relational';
    window.dispatchEvent(new CustomEvent('brvtal:connected-rendered', {
      detail: {nodes:nodes, edges:edges}
    }));
  }

  async function waitForDataPromise(maxWaitMs) {
    var start = Date.now();
    while (!window.BRVTALPublicDataPromise && Date.now() - start < maxWaitMs) {
      await new Promise(function (resolve) { setTimeout(resolve, 100); });
    }
    return window.BRVTALPublicDataPromise || null;
  }

  async function init() {
    var section = document.querySelector('#connected[data-scene], section#connected');
    if (!section) return;
    // Same DOM section is mounted once even if PHP and loader both inject us.
    // A new section after a partial re-render must remain independently mountable.
    if (section.dataset.brvtalConnectedMounted === '1'
      || section.dataset.brvtalConnectedMounting === '1') return;
    section.dataset.brvtalConnectedMounting = '1';

    try {
      var request = await waitForDataPromise(4000);
      if (!request) {
        section.dataset.state = 'unavailable';
        return;
      }

      var result = await request;
      var payload = result && result.payload ? result.payload : result;
      if (!payload || !section.isConnected) {
        section.dataset.state = 'unavailable';
        return;
      }
      render(section, payload.data || payload);
      section.dataset.brvtalConnectedMounted = '1';
      section.dataset.state = 'ready';
    } catch (_) {
      section.dataset.state = 'unavailable';
    } finally {
      delete section.dataset.brvtalConnectedMounting;
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, {once:true});
  } else {
    init();
  }
  // app.js may not publish its promise until window.load + 120 ms;
  // recover from an earlier timeout without an unbounded polling loop.
  window.addEventListener('load', function () {
    window.setTimeout(init, 180);
  }, {once:true});
})();