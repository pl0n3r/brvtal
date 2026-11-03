#!/usr/bin/env python3
"""BRVTAL #1348: fail-closed acceptance for original Concept 05 replacement WebP.

This checks exact media contracts and original Git blob identities; it does NOT
certify visual originality, rights, or the use of the native ChatGPT image tool.
"""
from __future__ import annotations

import hashlib
import io
import re
import struct
import unittest
from unittest.mock import patch
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
MEDIA = ROOT / "assets" / "placeholders" / "concept05"
MAX_BYTES = 180_000  # 180 KB decimal; reject 180,001+ bytes

# Authoritative originals on main@6a7836da41e1a4ebb37f08fa4ceff4e0f13cb772.
# Values are the original Git *blob* SHA-1, not arbitrary text hashes.
ORIGINALS = {
    "hero-face.webp": ((128, 160), "c4f23c17adcf2e1c46f75aad4fc91ce78a67cae2"),
    "crowd.webp": ((256, 160), "9c73e216dcaad77cdda21bbb03655891c554ba95"),
    "artists.webp": ((256, 160), "ac4e6e45e086aa753e9602dbdca0152632adb7af"),
    "memories.webp": ((256, 160), "8f3d2f6ea7755c2e181257956ca30103ed94fd15"),
    "nights.webp": ((256, 160), "4cc78027f9b5d80721f279f4f1ca351936265d6c"),
}


def _git_blob_sha(data: bytes) -> str:
    return hashlib.sha1(b"blob " + str(len(data)).encode("ascii") + b"\x00" + data).hexdigest()


def _webp_dimensions(data: bytes) -> tuple[int, int]:
    """Validate the RIFF container and read VP8/VP8L/VP8X dimensions without I/O."""
    if (len(data) < 30 or data[:4] != b"RIFF" or data[8:12] != b"WEBP"
            or struct.unpack_from("<I", data, 4)[0] != len(data) - 8):
        raise ValueError("INVALID_WEBP_RIFF")
    pos = 12
    canvas = None
    frame = None
    while pos < len(data):
        if pos + 8 > len(data):
            raise ValueError("TRUNCATED_WEBP_CHUNK")
        kind = data[pos:pos + 4]
        # Synthetic editorial assets must not embed EXIF/XMP metadata: those
        # chunks can carry real names, locations and device identifiers.
        if kind in (b"EXIF", b"XMP "):
            raise ValueError("UNEXPECTED_WEBP_PERSONAL_METADATA")
        size = struct.unpack_from("<I", data, pos + 4)[0]
        start, end = pos + 8, pos + 8 + size
        if end > len(data):
            raise ValueError("INVALID_WEBP_CHUNK_LENGTH")
        payload = data[start:end]
        if kind == b"VP8X":
            if len(payload) != 10:
                raise ValueError("INVALID_WEBP_VP8X")
            canvas = (1 + int.from_bytes(payload[4:7], "little"),
                      1 + int.from_bytes(payload[7:10], "little"))
        elif kind == b"VP8 ":
            if len(payload) < 10 or payload[3:6] != b"\x9d\x01\x2a":
                raise ValueError("INVALID_WEBP_VP8")
            frame = (struct.unpack_from("<H", payload, 6)[0] & 0x3FFF,
                     struct.unpack_from("<H", payload, 8)[0] & 0x3FFF)
        elif kind == b"VP8L":
            if len(payload) < 5 or payload[0] != 0x2F:
                raise ValueError("INVALID_WEBP_VP8L")
            flags = int.from_bytes(payload[1:5], "little")
            frame = ((flags & 0x3FFF) + 1, ((flags >> 14) & 0x3FFF) + 1)
        pos = end + (size & 1)
    if pos != len(data) or frame is None:
        raise ValueError("MISSING_OR_TRUNCATED_WEBP_FRAME")
    if canvas and canvas != frame:
        raise ValueError("WEBP_CANVAS_FRAME_MISMATCH")
    if frame[0] < 1 or frame[1] < 1:
        raise ValueError("INVALID_WEBP_DIMENSIONS")
    return frame




def _require_decodable_webp(data: bytes, size: tuple[int, int]) -> None:
    """Fail closed unless a real decoder validates the entire WebP bitstream.

    RIFF/VP8 header parsing alone cannot distinguish an image from a plausible
    frame header followed by arbitrary bytes. Pillow is a required acceptance
    dependency, not an optional enhancement that can silently be skipped.
    """
    try:
        from PIL import Image
    except ImportError as exc:
        raise RuntimeError("Pillow is required to validate WebP decoding") from exc
    with Image.open(io.BytesIO(data)) as image:
        if image.format != "WEBP" or image.size != size:
            raise ValueError("DECODED_WEBP_FORMAT_OR_SIZE_MISMATCH")
        image.load()


class Concept05GeneratedImagesTests(unittest.TestCase):
    def test_replacements_keep_name_format_dimensions_and_weight(self) -> None:
        """AC-01: all five binaries must replace the actual old placeholders."""
        self.assertEqual(5, len(ORIGINALS))
        self.assertTrue(MEDIA.is_dir(), "Concept 05 media directory must exist")
        self.assertFalse(MEDIA.is_symlink(), "Media directory cannot be a symlink")
        self.assertEqual(
            set(ORIGINALS), {entry.name for entry in MEDIA.iterdir()},
            "Unexpected or missing Concept 05 media files",
        )
        seen = set()
        for name, (size, old_blob) in ORIGINALS.items():
            with self.subTest(image=name):
                self.assertRegex(name, r"^[a-z][a-z-]*\.webp$")
                path = MEDIA / name
                self.assertFalse(path.is_symlink(), "Generated asset must be a regular file, not a symlink")
                self.assertTrue(path.is_file(), f"Missing original generated asset: {path}")
                data = path.read_bytes()
                self.assertGreater(len(data), 2048, "A tiny placeholder is not acceptable artwork")
                self.assertLessEqual(len(data), MAX_BYTES)
                self.assertNotEqual(_git_blob_sha(data), old_blob,
                                    "Image must not be the original placeholder")
                self.assertEqual(size, _webp_dimensions(data))
                _require_decodable_webp(data, size)
                seen.add(hashlib.sha256(data).digest())
        self.assertEqual(5, len(seen), "All five images must be distinct")

    def test_published_editorial_media_preserves_safe_alt_text(self) -> None:
        """AC-01: the CMS media projection still supplies meaningful image alt."""
        source = (ROOT / "js/public-concept05-hero.js").read_text(encoding="utf-8")
        self.assertIn("image.dataset.c5EditorialMedia = key", source)
        self.assertRegex(source, r"image\.alt\s*=\s*['\"]BRVTAL editorial ['\"]\s*\+")
        for name in ("hero_face", "crowd", "artists", "memories", "nights"):
            with self.subTest(key=name):
                self.assertRegex(source, r"\b" + re.escape(name) + r":\s*['\"]")

    def test_no_placeholder_labels_on_home(self) -> None:
        """AC-03: fail on visible legacy text in the treated public home surfaces."""
        for path in sorted((ROOT / "partials/home").glob("*.html")) + [
            ROOT / "js/public-concept05-hero.js",
            ROOT / "index.html",
        ]:
            with self.subTest(path=str(path.relative_to(ROOT))):
                self.assertTrue(path.is_file())
                content = path.read_text(encoding="utf-8")
                for forbidden in ("Espacio para imagen", "Placeholder image"):
                    self.assertNotIn(forbidden.casefold(), content.casefold())


    def test_unclaimed_media_file_is_rejected(self) -> None:
        """Adding an unexpected file may not pass the five-asset acceptance."""
        from tempfile import TemporaryDirectory

        with TemporaryDirectory() as temp:
            directory = Path(temp)
            for name in ORIGINALS:
                (directory / name).write_bytes(b"QA placeholder (not an editorial image)")
            (directory / "unapproved.webp").write_bytes(b"Unexpected file")
            with patch(__name__ + ".MEDIA", directory):
                with self.assertRaisesRegex(AssertionError, "Unexpected or missing"):
                    self.test_replacements_keep_name_format_dimensions_and_weight()

    def test_header_only_fake_webp_is_not_considered_generated_art(self) -> None:
        """A valid-looking RIFF/VP8 header with fake payload is not a real image."""
        size = (128, 160)
        frame = (b"\x00\x00\x00\x9d\x01\x2a"
                 + struct.pack("<HH", *size) + b"\x00" * 4096)
        data = (b"RIFF" + (4 + 8 + len(frame)).to_bytes(4, "little")
                + b"WEBPVP8 " + len(frame).to_bytes(4, "little") + frame)
        self.assertEqual(size, _webp_dimensions(data))
        with self.assertRaises((OSError, ValueError)):
            _require_decodable_webp(data, size)

    def test_webp_exif_and_xmp_metadata_are_rejected(self) -> None:
        """No hidden private or location metadata in otherwise decodable artwork."""
        from PIL import Image

        clean = io.BytesIO()
        Image.new("RGB", (128, 160), (20, 0, 0)).save(clean, "WEBP")
        self.assertEqual((128, 160), _webp_dimensions(clean.getvalue()))
        _require_decodable_webp(clean.getvalue(), (128, 160))

        for label, payload in (
            ("EXIF", {"exif": b"Exif\x00\x00QA synthetic metadata"}),
            ("XMP", {"xmp": b"<x:xmpmeta>QA synthetic metadata</x:xmpmeta>"}),
        ):
            with self.subTest(chunk=label):
                stream = io.BytesIO()
                Image.new("RGB", (128, 160), (20, 0, 0)).save(
                    stream, "WEBP", **payload
                )
                # Both carry real, Pillow-decodable pixel data. Header-only and
                # decompression validation from v8 would accept these metadata.
                _require_decodable_webp(stream.getvalue(), (128, 160))
                with self.assertRaisesRegex(ValueError, "UNEXPECTED_WEBP_PERSONAL_METADATA"):
                    _webp_dimensions(stream.getvalue())

    def test_decoder_missing_fails_closed_instead_of_skipping_validation(self) -> None:
        """The acceptance test must not silently skip decoding on a bare runner."""
        from PIL import Image
        content = io.BytesIO()
        Image.new("RGB", (128, 160), "black").save(content, "WEBP")
        real_import = __import__

        def without_pillow(name, *args, **kwargs):
            if name == "PIL" or name.startswith("PIL."):
                raise ImportError("QA simulated missing Pillow")
            return real_import(name, *args, **kwargs)

        with patch("builtins.__import__", side_effect=without_pillow):
            with self.assertRaisesRegex(RuntimeError, "Pillow is required"):
                _require_decodable_webp(content.getvalue(), (128, 160))

    def test_webp_parser_fails_closed_for_malformed_containers(self) -> None:
        """Guard against accepting structurally invalid files as original art."""
        for invalid in (
            b"",
            b"RIFF" + b"\x00" * 30,
            b"RIFF" + (4).to_bytes(4, "little") + b"WEBP",
            b"RIFF" + (21).to_bytes(4, "little") + b"WEBP"
            + b"VP8 " + (10).to_bytes(4, "little") + b"\x00" * 9,
        ):
            with self.subTest(size=len(invalid)):
                with self.assertRaises(ValueError):
                    _webp_dimensions(invalid)


if __name__ == "__main__":
    unittest.main()
