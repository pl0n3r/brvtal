"""Contracts for official SoundCloud artwork/metadata in DISCADMIN."""
import json
import re
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
GRID = (ROOT / "discadmin/admin-data-grid.js").read_text()
CSS = (ROOT / "discadmin/admin-data-grid.css").read_text()
ADMIN = (ROOT / "discadmin/index-core.php").read_text()
SCRIPTS = (ROOT / "discadmin/index.php").read_text()
HELPER = (ROOT / "discadmin/soundcloud-artwork.js").read_text()
API = (ROOT / "api/soundcloud-artwork.php").read_text()
ORDERING = (ROOT / "discadmin/content-ordering.js").read_text()


def php_json(code):
    result = subprocess.run(["php", "-r", "require 'api/soundcloud-artwork.php';" + code],
                            cwd=ROOT, check=True, capture_output=True, text=True)
    return json.loads(result.stdout)


class DiscadminSoundcloudArtworkTests(unittest.TestCase):
    def test_admin_grid_uses_official_cover_fallback(self):
        self.assertIn("imageCell(r.cover_image,r.title,r.slug,r.platform==='soundcloud'", GRID)
        self.assertIn("data-soundcloud-url", GRID)
        self.assertIn("window.BRVTALAdminSoundCloud?.hydrate?.(state.host)", GRID)
        self.assertIn("window.BRVTALAdminSoundCloud?.hydrate?.(document.getElementById('mcontent'))", ADMIN)
        self.assertIn("sets.map(x=>", ADMIN)
        self.assertIn("thumb(x.cover_image,x.title,false,x.platform==='soundcloud'", ADMIN)
        self.assertIn("window.BRVTALAdminSoundCloud", HELPER)
        self.assertIn("discadmin/soundcloud-artwork.js", SCRIPTS)
        self.assertIn("artworkUrl(data.artwork_url)", HELPER)
        self.assertIn("placeholder.replaceWith(img)", HELPER)
        self.assertIn("placeholder.isConnected", HELPER)
        self.assertIn("requests.set(url, promise)", HELPER)

    def test_safe_metadata_form_autofill(self):
        data = php_json("""
$track='https://soundcloud.com/pl0n3r/valid-track';
echo json_encode(brvtal_soundcloud_resolve($track,static fn($url)=>[200,json_encode([
 'provider_name'=>'SoundCloud','title'=>'BRVTAL Podcast #1',
 'author_name'=>'PL0N3R','duration'=>123,
 'thumbnail_url'=>'https://i1.sndcdn.com/artworks-ABC-t500x500.jpg'])]));
""")
        self.assertTrue(data["ok"])
        self.assertEqual(data["title"], "BRVTAL Podcast #1")
        self.assertEqual(data["author_name"], "PL0N3R")
        self.assertEqual(data["artwork_url"], "https://i1.sndcdn.com/artworks-ABC-t500x500.jpg")
        self.assertNotIn("duration", data)
        for item in ["data-soundcloud-title", "bindSetEditor", "dirty.has(name)",
                     "trackUrl(urlInput.value) !== value", "matches.length !== 1",
                     "typeof data.author_name", "urlInput.addEventListener('input'"]:
            self.assertIn(item, HELPER)
        self.assertIn("No se pudo consultar SoundCloud", HELPER)
        self.assertIn("No se pudo consultar SoundCloud", HELPER)

    def test_actions_have_nonoverlapping_grid_space(self):
        self.assertIn("minmax(264px,270px)", GRID)
        self.assertIn('data-grid-module="sets"', CSS)
        self.assertIn("padding-right: 112px!important", CSS)
        self.assertIn("min-height: 44px", CSS)
        self.assertIn("right: 55px", CSS)
        self.assertIn("right: 6px", CSS)
        self.assertIn("flex-wrap: nowrap", CSS)

    def test_drag_handle_accessibility(self):
        self.assertIn("content-order-help-mark { display: none;", CSS)
        self.assertIn(".content-order-handle::before", CSS)
        self.assertIn("radial-gradient(circle at center", CSS)
        self.assertIn("aria-label','Reorder ", ORDERING)
        self.assertIn("aria-keyshortcuts','ArrowUp ArrowDown'", ORDERING)
        self.assertIn("prefers-reduced-motion: reduce", CSS)


if __name__ == "__main__":
    unittest.main()
