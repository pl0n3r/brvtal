#!/usr/bin/env python3
"""DOM regression for related-content.js mounting with a nested #media.

Executes the *actual* mount() implementation inside an isolated Node test DOM.
No network, production host, browser automation, or external npm modules.
"""
from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SOURCE = ROOT / "js" / "related-content.js"

NODE_HARNESS = r"""
'use strict';
const assert = require('node:assert/strict');
const fs = require('node:fs');

const file = process.argv[1];
const scenario = process.argv[2];
const script = fs.readFileSync(file, 'utf8');
const begin = script.indexOf('  function mount() {');
const end = script.indexOf('\n  function entityLabel(', begin);
assert.ok(begin >= 0 && end > begin, 'expected real related-content mount()');

function element(name) {
  return {
    name,
    isConnected: true,
    parentNode: null,
    children: [],
    addEventListener() {},
    querySelector() { return null; },
    appendChild(child) {
      assert.equal(child.parentNode, null, 'append should start unmounted');
      child.parentNode = this;
      this.children.push(child);
      return child;
    },
    insertBefore(child, reference) {
      const index = this.children.indexOf(reference);
      if (index < 0 || reference.parentNode !== this) {
        throw new Error('NotFoundError: reference is not a direct child');
      }
      assert.equal(child.parentNode, null, 'insert should start unmounted');
      child.parentNode = this;
      this.children.splice(index, 0, child);
      return child;
    },
  };
}

const host = element('main');
const wrapper = element('wrapper');
const media = element('media');
if (scenario === 'nested') {
  host.appendChild(wrapper);
  wrapper.appendChild(media);
} else if (scenario === 'direct') {
  host.appendChild(media);
} else if (scenario !== 'missing' && scenario !== 'detached') {
  throw new Error('Unknown fixture: ' + scenario);
}
host.querySelector = selector =>
  selector === '#media' && scenario !== 'missing' ? media : null;
const document = {
  body: host,
  createElement: tag => element(tag),
  querySelector: selector => selector === 'main' ? host : null,
};
const factory = new Function(
  'state', 'ensureStyles', 'qs', 'document',
  script.slice(begin, end) + '\nreturn mount;',
);
const state = { root: null };
const mount = factory(
  state, () => {}, (selector, node) => node.querySelector(selector), document,
);
const section = mount();
assert.equal(section.name, 'section');
assert.equal(section.id, 'network');
assert.equal(section.className, 'related-network');
assert.equal(state.root, section);
if (scenario === 'nested') {
  assert.equal(section.parentNode, wrapper);
  assert.deepEqual(host.children.map(node => node.name), ['wrapper']);
  assert.deepEqual(wrapper.children.map(node => node.name), ['section', 'media']);
} else if (scenario === 'direct') {
  assert.equal(section.parentNode, host);
  assert.deepEqual(host.children.map(node => node.name), ['section', 'media']);
} else {
  assert.equal(section.parentNode, host);
  assert.deepEqual(host.children.map(node => node.name), ['section']);
}
assert.equal(mount(), section, 'mount() is idempotent');
process.stdout.write(JSON.stringify({ scenario, passed: true }));
"""


class RelatedContentMountTests(unittest.TestCase):
    def run_fixture(self, scenario: str) -> None:
        result = subprocess.run(
            ["node", "-e", NODE_HARNESS, str(SOURCE), scenario],
            cwd=ROOT,
            capture_output=True,
            text=True,
            timeout=10,
            check=False,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual(
            json.loads(result.stdout), {"scenario": scenario, "passed": True}
        )

    def test_nested_media_inserts_before_reference_in_real_parent(self) -> None:
        self.run_fixture("nested")

    def test_direct_media_keeps_previous_order(self) -> None:
        self.run_fixture("direct")

    def test_no_media_appends_at_end(self) -> None:
        self.run_fixture("missing")

    def test_unusable_detached_media_falls_back_to_append(self) -> None:
        self.run_fixture("detached")


if __name__ == "__main__":
    unittest.main()
