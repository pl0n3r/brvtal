#!/usr/bin/env python3
"""Gate de contenido pre-live de BRVTAL. Solo lectura, sin DB ni red.

Uso: python3 scripts/check_contenido_real.py --snapshot /ruta/export-cms.json
El snapshot debe ser exportado y contrastado por el operador desde DISCADMIN.
Nunca considera un fixture offline como autorización de producción.
"""
from __future__ import annotations

import argparse
from dataclasses import dataclass
import json
from pathlib import Path
import re
import sys

MAX_SNAPSHOT_BYTES = 4 * 1024 * 1024
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
FAKE_TEXT = re.compile(r"\blorem\s+ipsum\b|\b(?:texto|contenido|artista|evento|fecha)\s+(?:de\s+)?(?:ejemplo|prueba)\b|\b(?:sample|dummy|placeholder)\b|\b(?:test|demo)[- _](?:artist|event|date|content)\b", re.I)
COMING_SOON = re.compile(r"\bpr[oó]ximamente\b|\bcoming\s+soon\b", re.I)
FAKE_DATE = re.compile(r"^(?:1970-01-01|2000-01-01|2099-12-31|0000-00-00|0000-00-00 00:00:00|YYYY-MM-DD|DD/MM/YYYY)(?:[ T]00:00:00)?$", re.I)
DATE_ISO = re.compile(r"^\d{4}-\d{2}-\d{2}(?:[ T]\d{2}:\d{2}(?::\d{2})?)?(?:Z|[+-]\d{2}:\d{2})?$", re.I)
URL_FIELD = re.compile(r"(?:^|_)(?:url|href|link)$|(?:^|_)(?:ticket_url|instagram_url|soundcloud_url|website_url|external_url|embed_url)$", re.I)
DATE_FIELD = re.compile(r"(?:^|_)(?:event_date|date|starts_at|start_date)$", re.I)


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


def read_snapshot(path: Path) -> dict:
    """No atraviesa symlinks en el archivo terminal; rechaza datos masivos."""
    if path.is_symlink() or not path.is_file():
        raise SnapshotError("SNAPSHOT_MISSING_OR_LINKED")
    try:
        with path.open("rb") as stream:
            data = stream.read(MAX_SNAPSHOT_BYTES + 1)
    except OSError as exc:
        raise SnapshotError("SNAPSHOT_UNREADABLE") from exc
    if len(data) > MAX_SNAPSHOT_BYTES:
        raise SnapshotError("SNAPSHOT_TOO_LARGE")
    try:
        result = json.loads(data.decode("utf-8"))
    except (UnicodeError, ValueError) as exc:
        raise SnapshotError("SNAPSHOT_INVALID_JSON") from exc
    if not isinstance(result, dict) or set(result) != {"version", "source", "complete", "collections"}:
        raise SnapshotError("SNAPSHOT_INVALID_CONTRACT")
    if result["version"] != 1 or result["source"] != "discadmin-readonly-export" or result["complete"] is not True:
        raise SnapshotError("SNAPSHOT_NOT_ATTESTED_COMPLETE")
    tables = result["collections"]
    if not isinstance(tables, dict) or set(tables) != set(COLLECTIONS):
        raise SnapshotError("SNAPSHOT_MISSING_COLLECTIONS")
    for name, rows in tables.items():
        if not isinstance(rows, list) or any(not isinstance(row, dict) for row in rows):
            raise SnapshotError(f"INVALID_COLLECTION_{name}")
    return result


def _walk(value: object, prefix: str = ""):
    """Recorre texto de CMS/JSON sin ejecutar HTML ni interpretar rutas."""
    if isinstance(value, str):
        if prefix.rsplit(".", 1)[-1] == "content_json" and value.strip():
            try:
                nested = json.loads(value)
            except ValueError as exc:
                raise SnapshotError("INVALID_PUBLISHED_CONTENT_JSON") from exc
            if not isinstance(nested, (dict, list)):
                raise SnapshotError("INVALID_PUBLISHED_CONTENT_JSON")
            yield from _walk(nested, prefix)
        else:
            yield prefix, value
    elif value is None:
        yield prefix, ""
    elif isinstance(value, dict):
        for name, child in value.items():
            if isinstance(name, str):
                yield from _walk(child, f"{prefix}.{name}" if prefix else name)
    elif isinstance(value, list):
        for i, child in enumerate(value):
            yield from _walk(child, f"{prefix}[{i}]")


def _scan_record(collection: str, record: dict) -> list[Finding]:
    panel, published = COLLECTIONS[collection]
    if "id" not in record or "status" not in record:
        raise SnapshotError(f"{collection}:MISSING_ID_OR_STATUS")
    rid = record["id"]
    if not isinstance(rid, int) or isinstance(rid, bool) or rid <= 0:
        raise SnapshotError(f"{collection}:INVALID_RECORD_ID")
    if not isinstance(record["status"], str) or record["status"].lower() not in published | {"draft", "private"}:
        raise SnapshotError(f"{collection}[{rid}]:UNKNOWN_PUBLICATION_STATUS")
    if record["status"].lower() not in published:
        return []
    flat = list(_walk({k: v for k, v in record.items() if k not in {"status", "id"}}))
    has_date = any(DATE_FIELD.search(path.rsplit(".", 1)[-1]) and DATE_ISO.fullmatch(value.strip()) and not FAKE_DATE.fullmatch(value.strip()) for path, value in flat)
    findings: list[Finding] = []
    for field, value in flat:
        clean = value.strip()
        leaf = field.rsplit(".", 1)[-1].split("[", 1)[0]
        codes = set()
        if FAKE_TEXT.search(clean):
            codes.add("EXAMPLE_TEXT")
        if COMING_SOON.search(clean) and not has_date:
            codes.add("UNDATED_COMING_SOON")
        if DATE_FIELD.search(leaf) and FAKE_DATE.fullmatch(clean):
            codes.add("FAKE_DATE")
        if DATE_FIELD.search(leaf) and clean and not FAKE_DATE.fullmatch(clean) and not DATE_ISO.fullmatch(clean):
            codes.add("INVALID_DATE")
        if re.search(r"(?i)\b(?:href|src)\s*=\s*[\x22\x27](?:#|\s*|https?://example\.com)[\x22\x27]", clean):
            codes.add("EMPTY_OR_SAMPLE_LINK")
        if URL_FIELD.search(leaf) and (clean == "" or clean == "#" or clean.lower() in {"about:blank", "javascript:void(0)", "http://example.com", "https://example.com"}):
            codes.add("EMPTY_OR_SAMPLE_LINK")
        for code in sorted(codes):
            findings.append(Finding(collection, str(rid), field, code, panel))
    return findings


def audit(snapshot: dict) -> list[Finding]:
    if not isinstance(snapshot, dict) or snapshot.get("version") != 1 or snapshot.get("complete") is not True or snapshot.get("source") != "discadmin-readonly-export":
        raise SnapshotError("SNAPSHOT_NOT_ATTESTED_COMPLETE")
    tables = snapshot.get("collections")
    if not isinstance(tables, dict) or set(tables) != set(COLLECTIONS):
        raise SnapshotError("SNAPSHOT_MISSING_COLLECTIONS")
    result = []
    for collection, records in tables.items():
        if not isinstance(records, list):
            raise SnapshotError(f"INVALID_COLLECTION_{collection}")
        for record in records:
            if not isinstance(record, dict):
                raise SnapshotError(f"INVALID_RECORD_{collection}")
            result.extend(_scan_record(collection, record))
    return result


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--snapshot", type=Path, required=True, help="export JSON completo y de solo lectura desde DISCADMIN")
    args = parser.parse_args(argv)
    try:
        findings = audit(read_snapshot(args.snapshot))
    except SnapshotError as exc:
        print(f"BLOCKED_LIVE: {exc}", file=sys.stderr)
        return 2
    if findings:
        print(f"BLOCKED_LIVE: {len(findings)} ocurrencia(s) en contenido publicable")
        for finding in findings:
            print(finding.line())
        return 1
    print("PASS_CONTENT_CHECK: sin marcadores detectados en snapshot completo (no autoriza live por sí solo)")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
