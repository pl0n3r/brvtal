#!/usr/bin/env python3
"""#1096: five S06 i18n gaps, synthetic PHP contracts only.

LOCAL_PARTIAL never proves an owner decision, a deployed service, or a complete
editorial workflow. Missing/ambiguous evidence remains UNKNOWN, not success.
"""
from __future__ import annotations

from functools import lru_cache
import json
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
IDS = ("S06-R006", "S06-R007", "S06-R008", "S06-R010", "S06-R011")
PHRASES = {
    "S06-R006": "Editors maintain one canonical Spanish record rather than duplicating ES/EN editorial entities.",
    "S06-R007": "Owner decision #821 materializes the Phase 1 foundation in three leaves: #824 establishes locale policy/catalog/glossary, #825 adds the provider-agnostic server-side translation cache contract, and #826 adds the accessible no-reload selector.",
    "S06-R008": "Owner decision #842 authorizes Phase 2.",
    "S06-R010": "Leaf #845 adds English editorial overlays as projections bound to the stable Spanish route_type:id plus a canonical source hash; a source change invalidates the overlay, protected music-domain titles remain canonical, and draft/private/untrusted content fails closed before translation.",
    "S06-R011": "The overlay reuses #825's injected adapter/cache contract and does not select a paid provider or create duplicate ES/EN editorial records.",
}
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_PARTIAL"


def rows(raw: object) -> dict[str, tuple[str, ...]]:
    """Accept exactly the five complete, still-unreconciled matrix rows."""
    if not isinstance(raw, str):
        raise ValueError("UNKNOWN: coverage matrix is absent")
    found = {}
    for key in IDS:
        matches = [line for line in raw.splitlines() if line.startswith("| " + key + " · ")]
        if len(matches) != 1:
            raise ValueError("UNKNOWN: absent/duplicated " + key)
        cells = tuple(cell.strip() for cell in matches[0].strip("|").split("|"))
        if (len(cells) != 5 or cells[1] != PHRASES[key]
                or cells[2] != "NO_VERIFICABLE"
                or "Sin aserción contrastada" not in cells[3]
                or not cells[0].startswith(key + " · [S06 L")):
            raise ValueError("UNKNOWN: malformed or promoted " + key)
        found[key] = cells
    return found


# This executes production PHP domain functions with a fake in-memory adapter
# and injected cache; no DB connection, HTTP request, credentials or real data.
PHP_PROBE = r'''
require_once 'config/public_translation_overlay.php';
require_once 'config/public_i18n_routing.php';
$spanish = [
  'id'=>41, 'route_type'=>'blog', 'slug'=>'synthetic-nota',
  'status'=>'published', 'title'=>'Ritmo ficticio',
  'description'=>'Descripción inventada', 'seo_title'=>'Crónica inventada',
  'seo_description'=>'Texto ficticio', 'body'=>'Cuerpo inventado'
];
$adapter = new class implements BrvtalPublicTranslationAdapter {
    public int $calls = 0;
    public function version(): string { return 'fixture-v1'; }
    public function translate(string $text,string $from,string $to): string {
        $this->calls++;
        if ($from !== 'es' || $to !== 'en') throw new RuntimeException('BAD_LOCALE');
        return 'EN ' . $text;
    }
};
$store = [];
$read = static function(array $identity) use (&$store): ?string {
    return $store[$identity['cache_key']] ?? null;
};
$write = static function(array $identity, string $value) use (&$store): void {
    $store[$identity['cache_key']] = $value;
};
$identity = brvtalPublicEditorialOverlaySourceIdentity($spanish);
$overlay = brvtalPublicEditorialOverlayResolve($spanish, 'en', $adapter, $read, $write, true, true);
$firstCalls = $adapter->calls;
$again = brvtalPublicEditorialOverlayResolve($spanish, 'en', $adapter, $read, $write, true, true);
$secondCalls = $adapter->calls;
$applied = is_array($overlay) ? brvtalPublicEditorialOverlayApply($spanish, $overlay, true, true) : null;
$changed = array_replace($spanish, ['description'=>'Nuevo texto inventado']);
$changedIdentity = brvtalPublicEditorialOverlaySourceIdentity($changed);
$stale = is_array($overlay) ? brvtalPublicEditorialOverlayApply($changed,$overlay,true,true) : null;
$malformed = $overlay;
if (is_array($malformed)) unset($malformed['source_hash']);
$badOverlay = is_array($malformed) ? brvtalPublicEditorialOverlayApply($spanish,$malformed,true,true) : null;
$draft = brvtalPublicEditorialOverlayResolve(array_replace($spanish,['status'=>'draft']),'en',$adapter,$read,$write,true,true);
$private = brvtalPublicEditorialOverlayResolve(array_replace($spanish,['private'=>true]),'en',$adapter,$read,$write,true,true);
$untrusted = brvtalPublicEditorialOverlayResolve(array_replace($spanish,['trusted'=>false]),'en',$adapter,$read,$write,true,true);
$blockedPublic = brvtalPublicEditorialOverlayResolve($spanish,'en',$adapter,$read,$write,false,true);
$badLocale = brvtalPublicEditorialOverlayResolve($spanish,'fr',$adapter,$read,$write,true,true);
$blockedCalls = $adapter->calls;
$music = array_replace($spanish,[
  'id'=>9,'route_type'=>'sets','slug'=>'synthetic-set',
  'title'=>'HARD TECHNO'
]);
unset($music['body']);
$musicOverlay = brvtalPublicEditorialOverlayResolve($music,'en',$adapter,$read,$write,true,true);
$failedAdapter = new class implements BrvtalPublicTranslationAdapter {
    public function version(): string { return 'fixture-failure'; }
    public function translate(string $text,string $from,string $to): string {
        throw new RuntimeException('synthetic-adapter-failure');
    }
};
$fail = brvtalPublicEditorialOverlayResolve(
    $spanish,'en',$failedAdapter,
    static fn(array $key): ?string => null,
    static function(array $key,string $value): void {},true,true
);
$policy = brvtalPublicI18nPayload([]);
$routing = brvtalPublicLocalizedRouteUrls('https://www.brvtal.com.co','en','blog','synthetic-nota');
echo json_encode([
  'S06-R006'=>[
    'one_canonical'=>is_array($applied)
      && $applied['entity']['id'] === 41
      && $applied['entity']['slug'] === $spanish['slug']
      && $applied['entity']['route_type'] === 'blog'
      && !array_key_exists('locale',$applied['entity']),
    'spanish_source'=>is_array($overlay)
      && $overlay['source_locale'] === 'es'
      && $overlay['source_identity'] === 'blog:41'
  ],
  'S06-R007'=>[
    'locale_policy'=>($policy['canonical_locale'] ?? null) === 'es'
      && ($policy['default_locale'] ?? null) === 'es'
      && in_array('en',$policy['available_locales'] ?? [],true),
    'catalog'=>isset($policy['catalog']['es']['nav.events'])
      && isset($policy['catalog']['en']['nav.events']),
    'cache_adapter'=>is_array($overlay) && $firstCalls === 4,
  ],
  'S06-R008'=>[
    'phase2_route'=>is_array($routing)
      && ($routing['canonical'] ?? null) === 'https://www.brvtal.com.co/en/blog/synthetic-nota'
      && ($routing['alternates']['es'] ?? null) === 'https://www.brvtal.com.co/blog/synthetic-nota',
  ],
  'S06-R010'=>[
    'source_hash'=>is_array($overlay)
      && $overlay['source_hash'] === $identity['source_hash']
      && strlen($overlay['source_hash']) === 64,
    'invalidates'=>is_array($overlay)
      && $changedIdentity['source_identity'] === $identity['source_identity']
      && $changedIdentity['source_hash'] !== $identity['source_hash']
      && $stale === null,
    'protected_title'=>is_array($musicOverlay)
      && !array_key_exists('title',$musicOverlay['fields']),
    'private_draft_untrusted'=>($draft === null && $private === null
      && $untrusted === null && $blockedPublic === null),
  ],
  'S06-R011'=>[
    'injected_cache'=>is_array($again) && $firstCalls === 4
      && $secondCalls === $firstCalls && count($store) === 6,
    'no_duplicate'=>is_array($applied)
      && $applied['entity']['id'] === $spanish['id']
      && !isset($applied['entity']['locale']),
    'synthetic_adapter'=>($firstCalls === 4),
  ],
  'negatives'=>[
    'partial_overlay_denied'=>($badOverlay === null),
    'unsupported_locale_denied'=>($badLocale === null),
    'blocked_without_adapter_calls'=>($blockedCalls === $secondCalls),
    'provider_failure_denied'=>($fail === null),
  ],
], JSON_THROW_ON_ERROR);
'''


@lru_cache(maxsize=1)
def probe() -> dict[str, object]:
    php = shutil.which("php")
    if php is None:
        raise AssertionError("UNKNOWN: PHP CLI unavailable")
    completed = subprocess.run(
        [php, "-d", "allow_url_fopen=0", "-r", PHP_PROBE],
        cwd=ROOT, capture_output=True, text=True, timeout=20, check=False,
    )
    if completed.returncode or completed.stderr.strip():
        raise AssertionError("UNKNOWN: offline PHP probe failed: " + completed.stderr[-700:])
    result = json.loads(completed.stdout)
    if not isinstance(result, dict):
        raise AssertionError("UNKNOWN: invalid probe payload")
    return result


def verdict(key: object, table: object, evidence: object) -> dict[str, str]:
    """A local fixture cannot attest an owner decision or full release."""
    result = {"code": UNKNOWN, "owner_decision": UNKNOWN,
              "full_scope": UNKNOWN, "production": UNKNOWN}
    if key not in IDS or not isinstance(table, dict) or not isinstance(evidence, dict):
        return result
    if set(table) != set(IDS) or set(evidence) != set(IDS) | {"negatives"}:
        return result
    try:
        if table[key][1] != PHRASES[key] or table[key][2] != "NO_VERIFICABLE":
            return result
        checks = evidence[key]
        if not isinstance(checks, dict) or not checks or not all(value is True for value in checks.values()):
            return result
    except (KeyError, IndexError, TypeError):
        return result
    result["code"] = LOCAL
    return result


class BrechasBrvtal06Tests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.table = rows((ROOT / "docs/matriz-cobertura.md").read_text(encoding="utf-8"))
        cls.observed = probe()

    def test_contrato_principal(self) -> None:
        self.assertEqual(set(self.table), set(IDS))
        self.assertEqual(set(self.observed), set(IDS) | {"negatives"})
        for key in IDS:
            with self.subTest(row=key):
                self.assertTrue(all(value is True for value in self.observed[key].values()), key)
                self.assertEqual(verdict(key, self.table, self.observed)["code"], LOCAL)
        # Decisions #821 and #842 cannot be proven from an offline code fixture.
        self.assertEqual(verdict("S06-R007", self.table, self.observed)["owner_decision"], UNKNOWN)
        self.assertEqual(verdict("S06-R008", self.table, self.observed)["owner_decision"], UNKNOWN)

    def test_falla_cerrado(self) -> None:
        for key in IDS:
            with self.subTest(row=key):
                self.assertEqual(verdict(key, None, self.observed)["code"], UNKNOWN)
                self.assertEqual(verdict(key, self.table, {})["code"], UNKNOWN)
                changed = dict(self.observed)
                changed[key] = dict(self.observed[key], synthetic_failure=False)
                self.assertEqual(verdict(key, self.table, changed)["code"], UNKNOWN)
        for label, passed in self.observed["negatives"].items():
            with self.subTest(negative=label):
                self.assertIs(passed, True)
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            rows(None)
        raw = (ROOT / "docs/matriz-cobertura.md").read_text(encoding="utf-8")
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            rows(raw.replace("| S06-R010 · ", "| S06-R010X · "))
        with self.assertRaisesRegex(ValueError, "UNKNOWN"):
            rows(raw + "\n" + next(line for line in raw.splitlines() if line.startswith("| S06-R006 · ")))

    def test_sin_datos_reales(self) -> None:
        # Fixture-only: the PHP probe does not load an env file, initiate PDO,
        # execute HTTP, or instantiate a vendor translation client.
        self.assertIn("'slug'=>'synthetic-nota'", PHP_PROBE)
        self.assertIn("'fixture-v1'", PHP_PROBE)
        self.assertNotIn("getenv(", PHP_PROBE)
        self.assertNotIn("new PDO", PHP_PROBE)
        self.assertNotIn("curl_", PHP_PROBE)
        self.assertNotIn("file_get_contents(", PHP_PROBE)
        self.assertNotIn("sk_live_", PHP_PROBE)
        self.assertTrue(self.observed["S06-R011"]["synthetic_adapter"])

    def test_estados_unknown_explicitos(self) -> None:
        for key in IDS:
            with self.subTest(row=key):
                outcome = verdict(key, self.table, self.observed)
                self.assertEqual(outcome["owner_decision"], UNKNOWN)
                self.assertEqual(outcome["full_scope"], UNKNOWN)
                self.assertEqual(outcome["production"], UNKNOWN)
        self.assertEqual(verdict("S06-R009", self.table, self.observed)["code"], UNKNOWN)
        damaged = dict(self.observed)
        damaged.pop("S06-R011")
        self.assertEqual(verdict("S06-R006", self.table, damaged)["code"], UNKNOWN)


if __name__ == "__main__":
    unittest.main()
