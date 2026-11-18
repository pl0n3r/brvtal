#!/usr/bin/env python3
"""Gate de contenido pre-live de BRVTAL. Solo lectura, sin DB ni red.

Uso: python3 scripts/check_contenido_real.py
El snapshot fijo .private/contenido-real-snapshot.json debe ser exportado y contrastado por el operador desde DISCADMIN.
Nunca considera un fixture offline como autorización de producción.
"""
from __future__ import annotations

import os
import stat
from dataclasses import dataclass
import json
from pathlib import Path
import re
import sys

MAX_SNAPSHOT_BYTES = 4 * 1024 * 1024
SNAPSHOT_PATH = Path(__file__).resolve().parents[1] / ".private" / "contenido-real-snapshot.json"
COLLECTIONS = {
    "events": ("/discadmin/?module=content-core#eventsTab", frozenset({
        "published", "upcoming", "tickets_available", "last_tickets", "sold_out",
        "finished", "archived", "cancelled",
    })),
    "artists": ("/discadmin/?module=content-core#artistsTab", frozenset({"published"})),
    "sets_media": ("/discadmin/?module=content-core#setsTab", frozenset({"published"})),
    "media": ("/discadmin/?module=media", frozenset({"published"})),
    "memories": ("/discadmin/?module=memories", frozenset({"published"})),
    "pages": ("/discadmin/?module=pages", frozenset({"published"})),
}
FAKE_TEXT_PATTERNS = (
    re.compile(r"\blorem\s+ipsum\b", re.I),
    re.compile(r"\b(?:texto|contenido|artista|evento|fecha)\s+(?:de\s+)?(?:ejemplo|prueba)\b", re.I),
    re.compile(r"\b(?:sample|dummy|placeholder)\b", re.I),
    re.compile(r"\b(?:test|demo)[- _](?:artist|event|date|content)\b", re.I),
)
COMING_SOON = re.compile(r"\bpr[oó]ximamente\b|\bcoming\s+soon\b", re.I)
FAKE_DATES = frozenset({"1970-01-01", "2000-01-01", "2099-12-31", "0000-00-00", "0000-00-00 00:00:00", "YYYY-MM-DD", "DD/MM/YYYY"})
DATE_ISO = re.compile(r"^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?(?:Z|[+-]\d{2}:\d{2})?$", re.I)
URL_FIELD = re.compile(r"(?:^|_)(?:url|href|link)$", re.I)
DATE_FIELD = re.compile(r"(?:^|_)(?:event_date|date|starts_at|start_date)$", re.I)
EMPTY_HTML_LINK = re.compile(r"(?i)\b(?:href|src)\s*=\s*[\x22\x27](?:#|\s*|https?://example\.com)[\x22\x27]")
SAMPLE_LINKS = frozenset({"", "#", "about:blank", "javascript:void(0)", "http://example.com", "https://example.com"})

class SnapshotError(ValueError):
    """La evidencia ausente/ambigua jamás permite publicar."""


@dataclass(frozen=True)
class Finding:
    collection: str
    record_id: str
    field: str
    code: str
    panel: str

    def line(self) -> str:
        return f"{self.collection}[id={self.record_id}].{self.field} | {self.code} | DISCADMIN {self.panel}"


def _read_bytes() -> bytes:
    """Ruta fija de control, apertura no-follow y límite aplicado al stream."""
    target = SNAPSHOT_PATH
    if not hasattr(os, "O_NOFOLLOW") or not hasattr(os, "O_NONBLOCK"):
        raise SnapshotError("SAFE_FILE_OPEN_UNSUPPORTED")
    if target.parent.is_symlink() or target.is_symlink():
        raise SnapshotError("SNAPSHOT_MISSING_OR_LINKED")
    try:
        flags = os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK
        fd = os.open(target, flags)
        try:
            if not stat.S_ISREG(os.fstat(fd).st_mode):
                raise SnapshotError("SNAPSHOT_NOT_REGULAR_FILE")
            with os.fdopen(fd, "rb", closefd=False) as stream:
                data = stream.read(MAX_SNAPSHOT_BYTES + 1)
        finally:
            os.close(fd)
    except OSError as exc:
        raise SnapshotError("SNAPSHOT_UNREADABLE") from exc
    if len(data) > MAX_SNAPSHOT_BYTES:
        raise SnapshotError("SNAPSHOT_TOO_LARGE")
    return data


def _validate_snapshot(snapshot: object) -> dict:
    if not isinstance(snapshot, dict) or set(snapshot) != {"version", "source", "complete", "collections"}:
        raise SnapshotError("SNAPSHOT_INVALID_CONTRACT")
    if snapshot["version"] != 1 or snapshot["source"] != "discadmin-readonly-export" or snapshot["complete"] is not True:
        raise SnapshotError("SNAPSHOT_NOT_ATTESTED_COMPLETE")
    tables = snapshot["collections"]
    if not isinstance(tables, dict) or set(tables) != set(COLLECTIONS):
        raise SnapshotError("SNAPSHOT_MISSING_COLLECTIONS")
    for name, rows in tables.items():
        if not isinstance(rows, list) or any(not isinstance(row, dict) for row in rows):
            raise SnapshotError(f"INVALID_COLLECTION_{name}")
    return snapshot


def read_snapshot() -> dict:
    try:
        payload = json.loads(_read_bytes().decode("utf-8"))
    except ValueError as exc:
        raise SnapshotError("SNAPSHOT_INVALID_JSON") from exc
    return _validate_snapshot(payload)


def _decode_editorial_json(value: str):
    try:
        decoded = json.loads(value)
    except ValueError as exc:
        raise SnapshotError("INVALID_PUBLISHED_CONTENT_JSON") from exc
    if not isinstance(decoded, (dict, list)):
        raise SnapshotError("INVALID_PUBLISHED_CONTENT_JSON")
    return decoded

def _walk(value: object, prefix: str = ""):
    if isinstance(value, str):
        if prefix.rsplit(".", 1)[-1] == "content_json" and value.strip():
            yield from _walk(_decode_editorial_json(value), prefix)
        else:
            yield prefix, value
        return
    if value is None:
        yield prefix, ""
        return
    if isinstance(value, dict):
        children = ((f"{prefix}.{key}" if prefix else key, val)
                    for key, val in value.items() if isinstance(key, str))
    elif isinstance(value, list):
        children = ((f"{prefix}[{key}]", val) for key, val in enumerate(value))
    else:
        return
    for path, child in children:
        yield from _walk(child, path)


def _record_status(collection: str, record: dict) -> tuple[str, bool]:
    if "id" not in record or "status" not in record:
        raise SnapshotError(f"{collection}:MISSING_ID_OR_STATUS")
    rid = record["id"]
    if not isinstance(rid, int) or isinstance(rid, bool) or rid <= 0:
        raise SnapshotError(f"{collection}:INVALID_RECORD_ID")
    status = record["status"]
    visible = COLLECTIONS[collection][1]
    if not isinstance(status, str) or status.lower() not in visible | {"draft", "private"}:
        raise SnapshotError(f"{collection}[{rid}]:UNKNOWN_PUBLICATION_STATUS")
    return str(rid), status.lower() in visible


def _fake_date(value: str) -> bool:
    return value.strip().upper() in FAKE_DATES


def _has_real_date(pairs: list[tuple[str, str]]) -> bool:
    return any(DATE_FIELD.search(path.rsplit(".", 1)[-1]) and DATE_ISO.fullmatch(val.strip()) and not _fake_date(val)
               for path, val in pairs)


def _field_codes(path: str, value: str, has_date: bool) -> list[str]:
    leaf = path.rsplit(".", 1)[-1].split("[", 1)[0]
    cleaned = value.strip()
    codes = []
    if any(pattern.search(cleaned) for pattern in FAKE_TEXT_PATTERNS):
        codes.append("EXAMPLE_TEXT")
    if COMING_SOON.search(cleaned) and not has_date:
        codes.append("UNDATED_COMING_SOON")
    if DATE_FIELD.search(leaf):
        if _fake_date(cleaned):
            codes.append("FAKE_DATE")
        elif cleaned and not DATE_ISO.fullmatch(cleaned):
            codes.append("INVALID_DATE")
    if EMPTY_HTML_LINK.search(cleaned) or (URL_FIELD.search(leaf) and cleaned.lower() in SAMPLE_LINKS):
        codes.append("EMPTY_OR_SAMPLE_LINK")
    return codes


def _scan_record(collection: str, record: dict) -> list[Finding]:
    rid, visible = _record_status(collection, record)
    if not visible:
        return []
    panel = COLLECTIONS[collection][0]
    flat = list(_walk({key: val for key, val in record.items() if key not in {"status", "id"}}))
    dated = _has_real_date(flat)
    return [Finding(collection, rid, field, code, panel)
            for field, value in flat for code in _field_codes(field, value, dated)]

def audit(snapshot: dict) -> list[Finding]:
    tables = _validate_snapshot(snapshot)["collections"]
    return [finding for name, rows in tables.items()
            for record in rows for finding in _scan_record(name, record)]

import unittest


class CheckContenidoRealTests(unittest.TestCase):
    """Caso canónico #1355 ejecutable desde scripts.check_contenido_real."""

    def test_criterio_principal(self):
        data = {"version": 1, "source": "discadmin-readonly-export", "complete": True,
                "collections": {name: [] for name in COLLECTIONS}}
        data["collections"]["events"] = [
            {"id": 1, "status": "published", "title": "Lorem ipsum", "event_date": "YYYY-MM-DD", "ticket_url": "#"},
            {"id": 2, "status": "draft", "title": "Lorem ipsum"},
        ]
        findings = audit(data)
        codes = {finding.code for finding in findings}
        self.assertLessEqual({"EXAMPLE_TEXT", "FAKE_DATE", "EMPTY_OR_SAMPLE_LINK"}, codes)
        self.assertTrue(all(finding.record_id == "1" for finding in findings))
        self.assertTrue(all(finding.panel.startswith("/discadmin/") for finding in findings))

def main() -> int:
    try:
        findings = audit(read_snapshot())
    except SnapshotError as exc:
        print(f"BLOCKED_LIVE: {exc}", file=sys.stderr)
        return 2
    if findings:
        print(f"BLOCKED_LIVE: {len(findings)} ocurrencia(s) en contenido publicable")
        for finding in findings:
            print(finding.line())
        return 1
    print("PASS_CONTENT_CHECK: snapshot sin marcadores detectados (no autoriza live por sí solo)")
    return 0

if __name__ == "__main__":
    raise SystemExit(main())
