"""#1043 QA: contrato reproducible de evidencias sintéticas offline, sin navegador ni red."""
from __future__ import annotations

import hashlib
from html.parser import HTMLParser
import math
from pathlib import Path
import re
import struct
import tempfile
import unittest
import zlib

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs" / "prelive-web-quality.md"
VIEWS = {"1440": 1440, "390": 390}
BUDGET = {"fcp_ms": 1800.0, "lcp_ms": 2500.0, "tbt_ms": 200.0, "cls": 0.10}
KEYS = ["Tab", "Shift+Tab", "Enter", "Escape"]
PNG_MAGIC = b"\x89PNG\r\n\x1a\n"


class EvidenceError(ValueError):
    pass


def guard(ok, code):
    if not ok:
        raise EvidenceError(code)


def rgb_luminance(value):
    guard(isinstance(value, str) and re.fullmatch(r"#[a-fA-F0-9]{6}", value), "UNKNOWN_COLOR")
    comps = [int(value[k:k + 2], 16) / 255 for k in (1, 3, 5)]
    linear = [c / 12.92 if c <= .04045 else ((c + .055) / 1.055) ** 2.4 for c in comps]
    return .2126 * linear[0] + .7152 * linear[1] + .0722 * linear[2]


def contrast(fg, bg):
    a, b = rgb_luminance(fg), rgb_luminance(bg)
    return (max(a, b) + .05) / (min(a, b) + .05)


class SemanticSnapshot(HTMLParser):
    def __init__(self):
        super().__init__()
        self.tags = set()
        self.bad_alt = False
        self.bad_control = False

    def handle_starttag(self, tag, attrs):
        self.tags.add(tag)
        a = dict(attrs)
        if tag == "img" and not (isinstance(a.get("alt"), str) and a["alt"].strip()):
            self.bad_alt = True
        if tag in ("a", "button") and not (
            isinstance(a.get("aria-label"), str) and a["aria-label"].strip()
        ):
            self.bad_control = True


def asset(root, entry, suffix):
    guard(type(entry) is dict and set(entry) == {"path", "sha256"}, "UNKNOWN_ASSET_SHAPE")
    name, sha = entry["path"], entry["sha256"]
    guard(isinstance(name, str) and
          re.fullmatch(r"synthetic/[0-9a-z_.-]+" + re.escape(suffix), name), "UNKNOWN_ASSET_PATH")
    guard(isinstance(sha, str) and re.fullmatch(r"[a-f0-9]{64}", sha), "UNKNOWN_ASSET_SHA")
    path = root / name
    guard(path.is_file() and not path.is_symlink(), "UNKNOWN_ASSET_MISSING")
    raw = path.read_bytes()
    guard(0 < len(raw) <= 2 * 1024 * 1024, "UNKNOWN_ASSET_BYTES")
    guard(hashlib.sha256(raw).hexdigest() == sha, "UNKNOWN_HASH_MISMATCH")
    return raw


def png_size(raw):
    guard(len(raw) >= 60 and raw.startswith(PNG_MAGIC), "UNKNOWN_PNG_FORMAT")
    offset, size, complete, data_seen = 8, None, False, False
    while offset + 12 <= len(raw):
        length = struct.unpack(">I", raw[offset:offset + 4])[0]
        guard(length <= len(raw) - offset - 12, "UNKNOWN_PNG_TRUNCATED")
        tag = raw[offset + 4:offset + 8]
        chunk = raw[offset + 8:offset + 8 + length]
        crc = struct.unpack(">I", raw[offset + 8 + length:offset + 12 + length])[0]
        guard(zlib.crc32(tag + chunk) & 0xffffffff == crc, "UNKNOWN_PNG_CRC")
        if offset == 8:
            guard(tag == b"IHDR" and length == 13, "UNKNOWN_PNG_IHDR")
            width, height, depth, kind, *_ = struct.unpack(">IIBBBBB", chunk)
            guard(1 <= width <= 20000 and 600 <= height <= 30000 and
                  depth == 8 and kind == 2, "UNKNOWN_PNG_DIMENSIONS")
            size = (width, height)
        if tag == b"IDAT":
            data_seen = bool(chunk)
        if tag == b"IEND":
            guard(length == 0 and offset + 12 == len(raw), "UNKNOWN_PNG_END")
            complete = True
            break
        offset += length + 12
    guard(complete and data_seen and size is not None, "UNKNOWN_PNG_INCOMPLETE")
    return size


def audit(report, root):
    guard(type(report) is dict and set(report) == {
        "version", "source", "source_sha", "observed_at", "visual_decision",
        "viewports", "comparison",
    }, "UNKNOWN_REPORT")
    guard(type(report["version"]) is int and report["version"] == 1 and
          report["source"] == "synthetic", "UNKNOWN_SOURCE")
    guard(isinstance(report["source_sha"], str) and
          re.fullmatch(r"[a-f0-9]{40}", report["source_sha"]), "UNKNOWN_REVISION")
    guard(isinstance(report["observed_at"], str) and
          re.fullmatch(r"20\d\d-\d\d-\d\dT\d\d:\d\d:\d\dZ", report["observed_at"]),
          "UNKNOWN_TIME")
    guard(report["visual_decision"] == "PENDING_OWNER", "UNKNOWN_OWNER_GATE")
    guard(type(report["viewports"]) is dict and set(report["viewports"]) == set(VIEWS),
          "UNKNOWN_VIEWPORTS")
    guard(type(report["comparison"]) is dict and set(report["comparison"]) == set(VIEWS),
          "UNKNOWN_COMPARISON")
    digests = {}
    for key, width in VIEWS.items():
        row = report["viewports"][key]
        guard(type(row) is dict and set(row) == {
            "capture", "html", "css", "images", "keyboard_trace",
            "normal_text_fg", "normal_text_bg", "metrics",
        }, "UNKNOWN_VIEW_FIELDS")
        screenshot = asset(root, row["capture"], ".png")
        guard(png_size(screenshot)[0] == width, "FAIL_CAPTURE_WIDTH")
        digests[key] = row["capture"]["sha256"]
        html = asset(root, row["html"], ".html").decode("utf-8")
        css = asset(root, row["css"], ".css").decode("utf-8")
        parsed = SemanticSnapshot()
        parsed.feed(html)
        guard({"main", "nav", "h1"} <= parsed.tags, "FAIL_SEMANTICS")
        guard(not parsed.bad_alt and not parsed.bad_control, "FAIL_ALTERNATIVES")
        guard(":focus-visible" in css and "outline:" in css, "FAIL_FOCUS")
        guard("prefers-reduced-motion: reduce" in css and "animation: none" in css,
              "FAIL_REDUCED_MOTION")
        guard(row["keyboard_trace"] == KEYS, "FAIL_KEYBOARD")
        guard(contrast(row["normal_text_fg"], row["normal_text_bg"]) >= 4.5,
              "FAIL_CONTRAST")
        guard(type(row["images"]) is list and bool(row["images"]), "UNKNOWN_IMAGES")
        image_sizes = [len(asset(root, image, ".webp")) for image in row["images"]]
        guard(max(image_sizes) <= 300 * 1024 and sum(image_sizes) <= 750 * 1024,
              "FAIL_IMAGES")
        metrics = row["metrics"]
        guard(type(metrics) is dict and set(metrics) == set(BUDGET), "UNKNOWN_METRICS")
        for metric, limit in BUDGET.items():
            value = metrics[metric]
            guard(type(value) in (float, int) and math.isfinite(value) and value >= 0,
                  "UNKNOWN_METRIC_VALUE")
            guard(value <= limit, "FAIL_" + metric.upper())
    guard(digests == report["comparison"] and len(set(digests.values())) == 2,
          "UNKNOWN_CAPTURE_COMPARISON")
    return {"technical": "PASS_SYNTHETIC", "production": "UNKNOWN",
            "visual": "PENDING_OWNER"}


def png_fixture(width, height=900):
    def block(tag, data):
        return (struct.pack(">I", len(data)) + tag + data
                + struct.pack(">I", zlib.crc32(tag + data) & 0xffffffff))
    rgb = (b"\x00" + b"\x11\x11\x11" * width) * height
    return (PNG_MAGIC + block(b"IHDR", struct.pack(">IIBBBBB", width, height, 8, 2, 0, 0, 0))
            + block(b"IDAT", zlib.compress(rgb, 9)) + block(b"IEND", b""))


class PreliveWebQualityTests(unittest.TestCase):
    def setUp(self):
        temporary = tempfile.TemporaryDirectory()
        self.addCleanup(temporary.cleanup)
        self.root = Path(temporary.name)
        (self.root / "synthetic").mkdir()
        self.report = {
            "version": 1, "source": "synthetic", "source_sha": "a" * 40,
            "observed_at": "2026-10-27T08:00:00Z",
            "visual_decision": "PENDING_OWNER", "viewports": {}, "comparison": {},
        }
        for key, width in VIEWS.items():
            def save(ext, raw):
                rel = "synthetic/" + key + ext
                (self.root / rel).write_bytes(raw)
                return {"path": rel, "sha256": hashlib.sha256(raw).hexdigest()}
            capture = save(".png", png_fixture(width))
            html = save(".html", (b'<html><nav>Menu</nav><main><h1>BRVTAL</h1>'
                                  b'<a href="#test" aria-label="Abrir">Abrir</a>'
                                  b'<img src="fixture.webp" alt="Poster sintetico"></main></html>'))
            css = save(".css", b':focus-visible { outline: 2px solid white; } '
                               b'@media (prefers-reduced-motion: reduce) '
                               b'{ * { animation: none !important; } }')
            image = save(".webp", b"RIFF" + bytes(60) + b"WEBP")
            self.report["viewports"][key] = {
                "capture": capture, "html": html, "css": css, "images": [image],
                "keyboard_trace": list(KEYS),
                "normal_text_fg": "#ffffff", "normal_text_bg": "#111111",
                "metrics": {"fcp_ms": 1300, "lcp_ms": 2100, "tbt_ms": 90, "cls": .02},
            }
            self.report["comparison"][key] = capture["sha256"]

    def expect_error(self, code):
        with self.assertRaisesRegex(EvidenceError, "^" + re.escape(code) + "$"):
            audit(self.report, self.root)

    def test_a11y_contrast_keyboard_semantics_alt_reduced_motion(self):
        self.assertGreater(contrast("#ffffff", "#111111"), 4.5)
        self.assertEqual(audit(self.report, self.root)["technical"], "PASS_SYNTHETIC")
        row = self.report["viewports"]["390"]
        row["normal_text_fg"], row["normal_text_bg"] = "#999999", "#aaaaaa"
        self.expect_error("FAIL_CONTRAST")
        row["normal_text_fg"], row["normal_text_bg"] = "#ffffff", "#111111"
        row["keyboard_trace"] = ["Tab"]
        self.expect_error("FAIL_KEYBOARD")
        row["keyboard_trace"] = list(KEYS)
        for slot, invalid, result in (
            ("html", b"<div>missing structure</div>", "FAIL_SEMANTICS"),
            ("html", b'<main><nav></nav><h1>H</h1><img></main>', "FAIL_ALTERNATIVES"),
            ("css", b':focus-visible { outline: solid 2px; }', "FAIL_REDUCED_MOTION"),
        ):
            field = row[slot]
            path = self.root / field["path"]
            original = path.read_bytes()
            path.write_bytes(invalid)
            field["sha256"] = hashlib.sha256(invalid).hexdigest()
            self.expect_error(result)
            path.write_bytes(original)
            field["sha256"] = hashlib.sha256(original).hexdigest()

    def test_performance_budget_by_viewport(self):
        self.assertEqual(audit(self.report, self.root)["technical"], "PASS_SYNTHETIC")
        for name in VIEWS:
            row = self.report["viewports"][name]
            for metric, max_allowed in BUDGET.items():
                old = row["metrics"][metric]
                row["metrics"][metric] = max_allowed + 1
                self.expect_error("FAIL_" + metric.upper())
                row["metrics"][metric] = old
            row["metrics"]["lcp_ms"] = True
            self.expect_error("UNKNOWN_METRIC_VALUE")
            row["metrics"]["lcp_ms"] = 2100
            pic = row["images"][0]
            path = self.root / pic["path"]
            original = path.read_bytes()
            large = b"RIFF" + bytes(310 * 1024)
            path.write_bytes(large)
            pic["sha256"] = hashlib.sha256(large).hexdigest()
            self.expect_error("FAIL_IMAGES")
            path.write_bytes(original)
            pic["sha256"] = hashlib.sha256(original).hexdigest()

    def test_comparison_and_missing_captures_fail_closed(self):
        self.assertEqual(audit(self.report, self.root)["technical"], "PASS_SYNTHETIC")
        row = self.report["viewports"]["390"]
        path = self.root / row["capture"]["path"]
        original = path.read_bytes()
        path.unlink()
        self.expect_error("UNKNOWN_ASSET_MISSING")
        path.write_bytes(original)
        row["capture"]["sha256"] = "0" * 64
        self.expect_error("UNKNOWN_HASH_MISMATCH")
        row["capture"]["sha256"] = hashlib.sha256(original).hexdigest()
        wrong = png_fixture(400)
        path.write_bytes(wrong)
        row["capture"]["sha256"] = hashlib.sha256(wrong).hexdigest()
        self.expect_error("FAIL_CAPTURE_WIDTH")
        path.write_bytes(original)
        row["capture"]["sha256"] = hashlib.sha256(original).hexdigest()
        self.report["comparison"]["390"] = self.report["comparison"]["1440"]
        self.expect_error("UNKNOWN_CAPTURE_COMPARISON")
        self.report["comparison"]["390"] = row["capture"]["sha256"]
        self.report["viewports"].pop("1440")
        self.expect_error("UNKNOWN_VIEWPORTS")

    def test_evidence_and_owner_gates(self):
        self.assertEqual(audit(self.report, self.root), {
            "technical": "PASS_SYNTHETIC",
            "production": "UNKNOWN", "visual": "PENDING_OWNER",
        })
        self.report["visual_decision"] = "APPROVED"
        self.expect_error("UNKNOWN_OWNER_GATE")
        self.report["visual_decision"] = "PENDING_OWNER"
        self.report["source"] = "production"
        self.expect_error("UNKNOWN_SOURCE")
        self.report["source"] = "synthetic"
        self.report["source_sha"] = "wrong"
        self.expect_error("UNKNOWN_REVISION")
        doc = DOC.read_text(encoding="utf-8")
        for required in ("1440", "390", "WCAG", "contraste", "teclado", "alt",
                         "reduced-motion", "FCP", "LCP", "TBT", "CLS",
                         "300 KiB", "750 KiB", "No autoriza go-live",
                         "PENDING_OWNER", "PASS_SYNTHETIC", "UNKNOWN", "validate"):
            self.assertIn(required.casefold(), doc.casefold())


if __name__ == "__main__":
    unittest.main()
