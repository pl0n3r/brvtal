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
    (Array.isArray(payload.events) ? payload.events : []).forEach(function (item) {
      if (item && item.id != null) ids.add(String(item.id));
    });
    (Array.isArray(payload.archive?.events) ? payload.archive.events : []).forEach(function (item) {
      if (item && item.id != null) ids.add(String(item.id));
    });
    return ids.size;
  }

  function relationCounts(relations) {
    var input = relations && typeof relations.counts === 'object' ? relations.counts : {};
    var out = {};
    EDGE_SPECS.forEach(function (spec) {
      var value = Number(input[spec[0]]);
      out[spec[0]] = Number.isFinite(value) && value > 0 ? Math.floor(value) : 0;
    });
    return out;
  }

  function edgeTotal(counts) {
    return Object.values(counts).reduce(function (sum, value) { return sum + value; }, 0);
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
      edgesEl.textContent = edges > 0
        ? edges + ' VERIFIED RELATIONAL LINKS IN THE PUBLIC ARCHIVE.'
        : 'NO STRUCTURED RELATIONSHIPS PUBLISHED YET.';
    }

    requestAnimationFrame(function () { drawEdges(section, counts); });
    var resizeTimer = 0;
    var redraw = function () {
      window.clearTimeout(resizeTimer);
      resizeTimer = window.setTimeout(function () { drawEdges(section, counts); }, 80);
    };
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

    var request = await waitForDataPromise(4000);
    if (!request) {
      section.dataset.state = 'unavailable';
      return;
    }

    try {
      var result = await request;
      var payload = result && result.payload ? result.payload : result;
      if (payload) render(section, payload.data || payload);
    } catch (_) {
      section.dataset.state = 'unavailable';
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', init, {once:true});
  } else {
    init();
  }
})();