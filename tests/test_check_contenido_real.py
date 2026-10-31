"""Fixtures sintéticos y offline del gate #1355, sin acceso a DISCADMIN ni producción."""
import contextlib
import io
import json
from pathlib import Path
from unittest.mock import patch
import tempfile
import unittest

from scripts.check_contenido_real import COLLECTIONS, SnapshotError, audit, main, read_snapshot


def snapshot(**rows):
    collections = {name: [] for name in COLLECTIONS}
    collections.update(rows)
    return {"version": 1, "source": "discadmin-readonly-export", "complete": True, "collections": collections}


class CheckContenidoRealTests(unittest.TestCase):
    def test_criterio_principal(self):
        cases = snapshot(
            events=[{"id": 10, "status": "published", "title": "Lorem ipsum", "event_date": "2099-12-31", "ticket_url": "#"},
                    {"id": 11, "status": "draft", "title": "Lorem ipsum", "ticket_url": "#"}],
            artists=[{"id": 2, "status": "published", "name": "Artista de prueba", "instagram_url": ""}],
            pages=[{"id": 5, "status": "published", "content_json": {"blocks": [{"text": "Próximamente"}]}}],
        )
        findings = audit(cases)
        self.assertEqual({"EXAMPLE_TEXT", "FAKE_DATE", "EMPTY_OR_SAMPLE_LINK", "UNDATED_COMING_SOON"}, {f.code for f in findings})
        self.assertTrue(any(f.collection == "events" and f.record_id == "10" and f.panel.startswith("/discadmin/") for f in findings))
        self.assertFalse(any(f.record_id == "11" for f in findings))
        with tempfile.TemporaryDirectory() as folder:
            p = Path(folder) / "export.json"
            p.write_text(json.dumps(cases), encoding="utf-8")
            with patch("scripts.check_contenido_real.SNAPSHOT_PATH", p), contextlib.redirect_stdout(io.StringIO()) as output:
                self.assertEqual(1, main())
            self.assertIn("BLOCKED_LIVE", output.getvalue())
            self.assertIn("DISCADMIN", output.getvalue())

    def test_page_json_and_empty_links_are_inspected(self):
        records = snapshot(pages=[{
            "id": 8, "status": "published", "content_json": json.dumps({
                "blocks": [{"type": "link", "href": "#"}, {"type": "text", "content": "Lorem ipsum"}]
            }),
        }])
        findings = audit(records)
        self.assertTrue(any(f.field == "content_json.blocks[0].href" and f.code == "EMPTY_OR_SAMPLE_LINK" for f in findings))
        self.assertTrue(any(f.code == "EXAMPLE_TEXT" for f in findings))
        records["collections"]["pages"][0]["content_json"] = "{truncated"
        with self.assertRaises(SnapshotError):
            audit(records)

    def test_clean_published_records_pass(self):
        good = snapshot(
            events=[{"id": 1, "status": "tickets_available", "title": "Noche de música", "event_date": "2026-12-01", "ticket_url": "https://tickets.example.org/123"}],
            artists=[{"id": 3, "status": "published", "name": "DJ Real"}],
        )
        self.assertEqual([], audit(good))
        with tempfile.TemporaryDirectory() as folder:
            p = Path(folder) / "export.json"
            p.write_text(json.dumps(good), encoding="utf-8")
            with patch("scripts.check_contenido_real.SNAPSHOT_PATH", p), contextlib.redirect_stdout(io.StringIO()) as output:
                self.assertEqual(0, main())
            self.assertIn("no autoriza live", output.getvalue())

    def test_coming_soon_requires_real_date(self):
        s = snapshot(events=[{"id": 1, "status": "upcoming", "title": "Próximamente", "event_date": "2026-12-01"}])
        self.assertFalse(audit(s))
        s["collections"]["events"][0]["event_date"] = "YYYY-MM-DD"
        self.assertEqual({"FAKE_DATE", "UNDATED_COMING_SOON"}, {f.code for f in audit(s)})

    def test_missing_incomplete_or_bogus_snapshot_denied(self):
        for bad in ({}, {"version": 1, "source": "discadmin-readonly-export", "complete": False, "collections": {}},
                    snapshot(events=[{"status": "published"}])):
            with self.subTest(bad=bad), self.assertRaises(SnapshotError):
                audit(bad)
        with tempfile.TemporaryDirectory() as folder:
            p = Path(folder) / "missing.json"
            with patch("scripts.check_contenido_real.SNAPSHOT_PATH", p), contextlib.redirect_stderr(io.StringIO()) as error:
                self.assertEqual(2, main())
            self.assertIn("BLOCKED_LIVE", error.getvalue())

    def test_symlink_oversize_and_unknown_status_fail_closed(self):
        with tempfile.TemporaryDirectory() as folder:
            base = Path(folder)
            p = base / "export.json"
            p.write_text(json.dumps(snapshot()), encoding="utf-8")
            link = base / "alias.json"
            try:
                link.symlink_to(p)
            except OSError:
                self.skipTest("Symlink unavailable")
            with patch("scripts.check_contenido_real.SNAPSHOT_PATH", link):
                with self.assertRaises(SnapshotError):
                    read_snapshot()
            p.write_bytes(b" " * (4 * 1024 * 1024 + 1))
            with patch("scripts.check_contenido_real.SNAPSHOT_PATH", p):
                with self.assertRaises(SnapshotError):
                    read_snapshot()
        with self.assertRaises(SnapshotError):
            audit(snapshot(events=[{"id": 1, "status": "mystery", "title": "Noche"}]))


if __name__ == "__main__":
    unittest.main()
