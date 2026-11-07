"""Executable contracts for owner-requested Cromo SoundCloud listening and artwork."""
import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
HOME = (ROOT / "js/public-cromo-home.js").read_text()
AMBIENT = (ROOT / "js/public-cromo-sound.js").read_text()
CSS = (ROOT / "css/public-cromo-home.css").read_text()
PHP = (ROOT / "api/soundcloud-artwork.php").read_text()
BROWSER = (ROOT / "tests/e2e/public-cromo-home.spec.mjs").read_text()


def php_json(expression: str):
    result = subprocess.run(
        ["php", "-r", "require 'api/soundcloud-artwork.php'; " + expression],
        cwd=ROOT, text=True, capture_output=True, check=True,
    )
    return json.loads(result.stdout)


class CromoSetsPlayerTests(unittest.TestCase):
    def test_inline_player_uses_the_selected_published_set(self):
        self.assertIn("const buildSets = (sets)", HOME)
        self.assertIn("soundcloudTrackUrl(set.external_url)", HOME)
        self.assertIn("https://w.soundcloud.com/player/?url=", HOME)
        self.assertIn("encodeURIComponent(art.url)", HOME)
        self.assertIn("const iframe = el('iframe'", HOME)
        self.assertIn("no iframe before click", BROWSER.lower())

    def test_single_active_player_and_safe_fallback(self):
        self.assertIn("active.player.remove()", HOME)
        self.assertIn("if (active?.card === card) { stop(); return; }", HOME)
        self.assertIn("window.BRVTALCromoSound?.stop?.()", HOME)
        self.assertIn("button.disabled = !art.url", HOME)
        self.assertIn("ABRIR EN ", HOME)
        self.assertIn("player switches", BROWSER.lower())

    def test_embedding_requires_gesture_and_safe_url(self):
        self.assertIn("new URL(String(raw || ''))", HOME)
        self.assertIn("soundcloud.com", HOME)
        self.assertIn("iframe = el('iframe'", HOME)
        self.assertIn("referrerpolicy: 'no-referrer'", HOME)
        self.assertIn("sandbox: 'allow-scripts allow-same-origin allow-presentation'", HOME)
        self.assertNotIn("iframe.src = set.embed_url", HOME)

    def test_ambient_sound_mutual_exclusion(self):
        self.assertIn("stop,", AMBIENT[AMBIENT.index("window.BRVTALCromoSound = {"):])
        self.assertIn("brvtal:cromo-ambient-start", AMBIENT)
        self.assertIn("window.addEventListener('brvtal:cromo-ambient-start', stop)", HOME)

    def test_soundcloud_artwork_and_cms_fallback(self):
        self.assertIn("soundcloudArtworkUrl(body.artwork_url)", HOME)
        self.assertIn("const fallback = safeUrl(set.cover_image)", HOME)
        self.assertIn("if (fallback && image.getAttribute('src') !== fallback)", HOME)
        self.assertIn("cr-is-empty", CSS)
        output = php_json("""
$track='https://soundcloud.com/pl0n3r/sunny-vibes';
$data=brvtal_soundcloud_resolve($track, static fn($url)=>[200,
json_encode(['provider_name'=>'SoundCloud',
'thumbnail_url'=>'https://i1.sndcdn.com/artworks-ABC-t500x500.jpg'])]);
echo json_encode($data);
""")
        self.assertEqual(output, {"ok": True, "artwork_url": "https://i1.sndcdn.com/artworks-ABC-t500x500.jpg"})

    def test_artwork_endpoint_restricts_hosts_and_caches(self):
        out = php_json("""
$bad=['http://soundcloud.com/a/b','https://soundcloud.com/a',
'https://soundcloud.com.evil.test/a/b','https://evil.test/a/b',
'https://soundcloud.com:443/a/b','https://soundcloud.com@evil.test/a/b'];
$invalid=array_map('brvtal_soundcloud_track_url',$bad);
$good=brvtal_soundcloud_track_url('https://www.soundcloud.com/pl0n3r/sunny-vibes?utm=1');
$artbad=brvtal_soundcloud_artwork_url('https://i1.sndcdn.com/avatars-XYZ-t500x500.jpg');
$tmp=sys_get_temp_dir().'/brvtal-test-'.bin2hex(random_bytes(6));
mkdir($tmp);
$n=0;
$request=static function($endpoint) use (&$n) {
    $n++; return [200,json_encode(['provider_name'=>'SoundCloud',
    'thumbnail_url'=>'https://i1.sndcdn.com/artworks-ABC-t500x500.jpg'])];
};
$a=brvtal_soundcloud_lookup($good,$request,$tmp);
$b=brvtal_soundcloud_lookup($good,$request,$tmp);
foreach(glob($tmp.'/*') as $f) unlink($f);
rmdir($tmp);
echo json_encode(compact('invalid','good','artbad','n','a','b'));
""")
        self.assertEqual(out["invalid"], [""] * 6)
        self.assertEqual(out["good"], "https://soundcloud.com/pl0n3r/sunny-vibes")
        self.assertEqual(out["artbad"], "")
        self.assertEqual(out["n"], 1, "cache must avoid repeated requests to SoundCloud")
        self.assertEqual(out["a"], out["b"])
        self.assertIn("CURLOPT_FOLLOWLOCATION => false", PHP)
        self.assertIn("CURLOPT_SSL_VERIFYPEER => true", PHP)

    def test_accessibility_and_390_1440_e2e_contract(self):
        self.assertIn("'aria-expanded': 'false'", HOME)
        self.assertIn("'aria-controls': playerId", HOME)
        self.assertIn("iframe", BROWSER)
        self.assertIn("390, 1440", BROWSER)
        self.assertIn("await noOverflow(page)", BROWSER)
        self.assertIn("focus-visible", CSS)
        self.assertIn("#cromo-home .cr-set-art", CSS)

    def test_featured_compact_layout_contract(self):
        # The featured plate must not inherit the height of all visible list rows.
        self.assertIn("grid-template-columns:1.4fr 1fr;gap:24px;align-items:start", CSS)
        self.assertIn("justify-content:flex-start", CSS)
        self.assertIn("min-height:0;min-width:0;align-self:start", CSS)
        self.assertIn('grid-template-areas:"eyebrow eyebrow" "artwork title" "artwork artists"', CSS)
        self.assertIn("#cromo-home .cr-feat .cr-sound-feature-meta>h3{grid-area:title", CSS)
        self.assertIn("#cromo-home .cr-feat .cr-sound-feature-meta>p:last-child", CSS)
        self.assertIn("grid-area:artwork;width:100%;height:auto;aspect-ratio:1;margin:0", CSS)
        self.assertIn("cr-sound-actions{position:relative;z-index:3;margin-top:0}", CSS)

    def test_featured_responsive_e2e_contract(self):
        self.assertIn("Cromo featured card has compact grouped content at 390/768/900/1440", BROWSER)
        self.assertIn("for (const width of [390, 768, 900, 1440])", BROWSER)
        self.assertIn("await noOverflow(page)", BROWSER)

    def test_catalog_all_published_reachable(self):
        self.assertNotIn("rest.slice(0, 4).map", HOME)
        self.assertIn("rest.slice(visible, visible + 4).map(makeRow)", HOME)
        self.assertIn("visible += added.length", HOME)
        self.assertIn("VER MÁS SETS", HOME)
        self.assertIn("0 sets publicados", HOME)
        self.assertIn("Catálogo completo", HOME)

    def test_catalog_editorial_order_and_public_artists(self):
        self.assertIn("const [first, ...rest] = sets;", HOME)
        self.assertIn("Set destacado", HOME)
        self.assertNotIn("Último set publicado", HOME)
        self.assertIn("set.artist_names", HOME)
        self.assertIn("publishedArtistNames(first)", HOME)
        self.assertIn("publishedArtistNames(set)", HOME)
        public = (ROOT / "api/public.php").read_text()
        self.assertIn("WHERE s.status='published'", public)
        self.assertIn("a.status='published'", public)
        self.assertIn("$publicSet['artist_names']", public)

    def test_catalog_uses_existing_player_and_artwork(self):
        self.assertIn("row.append(makeActions(set, row, art))", HOME)
        self.assertIn("const art = artwork(set);", HOME)
        self.assertIn("active.player.remove()", HOME)
        self.assertIn("rest.slice(0, visible).map(makeRow)", HOME)
        self.assertIn("const added = rest.slice(visible, visible + 4).map(makeRow)", HOME)
        self.assertIn("Cromo revealed row retains SoundCloud fallback", BROWSER)

    def test_catalog_accessibility_and_responsive_fixtures(self):
        self.assertIn("'aria-live': 'polite'", HOME)
        self.assertIn("'aria-controls': 'cromo-set-list'", HOME)
        self.assertIn("'aria-expanded': 'false'", HOME)
        self.assertIn("nextFocus || count", HOME)
        self.assertIn(".cr-set-more:focus-visible", CSS)
        self.assertIn("prefers-reduced-motion:reduce", CSS)
        self.assertIn("0/1/5/6/30", BROWSER)
        self.assertIn("390, 1440", BROWSER)
        self.assertIn("await noOverflow(page)", BROWSER)


if __name__ == "__main__":
    unittest.main()
