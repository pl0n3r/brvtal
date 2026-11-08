#!/usr/bin/env python3
"""Fail-closed offline contracts for authorized Instagram artist photographs."""
from __future__ import annotations

import json
import os
import re
import shutil
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class ArtistInstagramPhotoTests(unittest.TestCase):
    def php(self, code: str, authorized: bool = True):
        executable = shutil.which("php")
        if not executable:
            self.skipTest("PHP executable not installed")
        env = dict(os.environ)
        for key in ("BRVTAL_META_IG_BUSINESS_ID", "BRVTAL_META_IG_ACCESS_TOKEN",
                    "BRVTAL_META_IG_ALLOWED_USERNAMES"):
            env.pop(key, None)
        if authorized:
            env.update(BRVTAL_META_IG_BUSINESS_ID="123456789",
                       BRVTAL_META_IG_ACCESS_TOKEN="fixture-only-never-valid",
                       BRVTAL_META_IG_ALLOWED_USERNAMES="professional.dj,artist_two")
        code = "require " + json.dumps(str(ROOT / "config/instagram_profile_photo.php")) + "; " + code
        run = subprocess.run([executable, "-r", code], cwd=ROOT, env=env,
                             capture_output=True, text=True, timeout=12, check=False)
        self.assertEqual(run.returncode, 0, run.stderr)
        return json.loads(run.stdout)

    def test_authorized_professional_photo_source(self):
        actual = self.php("""echo json_encode([
            brvtalIgUsername('https://www.instagram.com/Professional.DJ/?igsh=abc'),
            brvtalIgPhotoEndpoint('https://instagram.com/professional.dj/'),
            brvtalIgPhotoEndpoint('https://instagram.com/unapproved.dj/'),
            brvtalIgUsername('https://evil.invalid/professional.dj'),
            brvtalIgUsername('https://instagram.com/p/'),
            brvtalIgUsername('http://instagram.com/professional.dj/'),
            brvtalIgUsername('https://user:pass@instagram.com/professional.dj/'),
            brvtalIgUsername('https://instagram.com/professional.dj/extra')
        ]);""")
        self.assertEqual(actual, [
            "professional.dj",
            "/api/instagram-profile-photo.php?username=professional.dj",
            None, None, None, None, None, None,
        ])
        self.assertIsNone(self.php(
            "echo json_encode(brvtalIgPhotoEndpoint('https://instagram.com/professional.dj/'));",
            authorized=False,
        ))
        endpoint = (ROOT / "api/instagram-profile-photo.php").read_text()
        self.assertIn("brvtalIgConfiguredFor", endpoint)
        self.assertIn("Cache-Control: no-store", endpoint)

    def test_high_quality_and_safe_fallback(self):
        result = self.php("""$a=brvtalIgEnrichArtist([
            'name'=>'Example','photo'=>'/uploads/editorial-highres.png',
            'instagram_url'=>'https://instagram.com/professional.dj/'
        ]); echo json_encode([
            $a['photo'], $a['photo_cms'], $a['instagram_photo_url'],
            brvtalIgApprovedHost('scontent-x.cdninstagram.com'),
            brvtalIgApprovedHost('scontent.cdninstagram.com.attacker.invalid'),
            brvtalIgApprovedHost('127.0.0.1'),
            brvtalIgApprovedHost('scontent.fna.fbcdn.net')
        ]);""")
        self.assertEqual(result[:3], [
            "/uploads/editorial-highres.png",
            "/uploads/editorial-highres.png",
            "/api/instagram-profile-photo.php?username=professional.dj",
        ])
        self.assertEqual(result[3:], [True, False, False, True])
        php = (ROOT / "config/instagram_profile_photo.php").read_text()
        for contract in ("business_discovery.username(", "profile_picture_url",
                         "CURLOPT_FOLLOWLOCATION=>false", "CURLOPT_RESOLVE",
                         "getimagesizefromstring", "min((int)$cached['meta']['width']",
                         "return $cached; // Preserve last authorized good image"):
            self.assertIn(contract, php)
        self.assertNotIn("instagram.com/profiles/", php)

    def test_cropping_position_scale(self):
        markup = (ROOT / "discadmin/index-core.php").read_text()
        runtime = (ROOT / "discadmin/artist-hero-preview.js").read_text()
        styles = (ROOT / "discadmin/artist-hero-preview.css").read_text()
        for suffix in ("desktop", "mobile"):
            self.assertIn('data-hero-switch="' + suffix + '"', markup)
        for needle in ("data-artist-card-preview-img", "data-artist-meta-try",
                       "data-hero-preview-img", "data-artist-photo-source"):
            self.assertIn(needle, markup)
        for needle in ("data-artist-card-preview-img", "proposed.naturalWidth < 1024",
                       "root.dataset.igPreview", "const controls = ['zoom','x','y']"):
            self.assertIn(needle, runtime)
        self.assertIn("object-fit:contain", styles)
        self.assertIn("prefers-reduced-motion:reduce", styles)
        self.assertIn("object-fit:contain", (ROOT / "css/public-cromo-home.css").read_text())
        self.assertIn("object-fit:contain", (ROOT / "css/public-roster.css").read_text())

    def test_collective_membership(self):
        cromo = (ROOT / "js/public-cromo-home.js").read_text()
        roster = (ROOT / "js/public-roster.js").read_text()
        admin = (ROOT / "discadmin/admin-data-grid.js").read_text()
        preview = (ROOT / "discadmin/artist-hero-preview.js").read_text()
        for code in (cromo, roster, admin, preview):
            self.assertIn("MIEMBRO DEL COLECTIVO BRVTAL", code)
            self.assertIn("ARTISTA INVITADO", code)
        self.assertIn("is_collective_member", cromo)
        self.assertIn("is_collective_member", roster)
        self.assertIn("f_is_collective_member", preview)
        self.assertNotIn("'Colectivo. '", cromo)
        self.assertNotIn("'Colaborador. '", cromo)
        self.assertIn("instagram_photo_url", (ROOT / "api/public.php").read_text() +
                      (ROOT / "config/instagram_profile_photo.php").read_text())
        self.assertTrue((ROOT / "tests/e2e/discadmin-artists-instagram.spec.mjs").exists())


if __name__ == "__main__":
    unittest.main()
