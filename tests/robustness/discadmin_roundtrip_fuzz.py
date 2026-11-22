#!/usr/bin/env python3
"""Real HTTP create/update/readback round trips in disposable DISCADMIN CI."""
from __future__ import annotations

import json
import sys
import time
import urllib.error
from discadmin_save_fuzz import BASE, login, make_client, request, require_disposable

CREATE_VALUE = "Creación inicial: Ø Ñandú 🎛️ 東京 مرحبا"
VALUE = "Actualización posterior: música ácida 🦉 שלום"


def call(opener, csrf: str, method: str, path: str, payload=None):
    data = None if payload is None else json.dumps(
        payload, ensure_ascii=False, separators=(",", ":")
    ).encode("utf-8")
    status, raw = request(opener, method, path, data, csrf)
    try:
        body = json.loads(raw)
    except ValueError as exc:
        raise AssertionError(f"{method} {path}: non-JSON HTTP {status}") from exc
    if not isinstance(body, dict) or status >= 400 or body.get("ok") is not True:
        raise AssertionError(f"{method} {path}: HTTP {status}, error={body.get('error')}")
    return body


def expect_roundtrip(opener, csrf: str, name: str, create_path: str,
                     update_path, read_path, delete_path, initial: dict,
                     field: str, value: str, create_id):
    created = call(opener, csrf, "POST", create_path, initial)
    record_id = int(create_id(created))
    if record_id < 1:
        raise AssertionError(f"{name}: missing created ID")
    try:
        initial_value = initial.get(field)
        if not isinstance(initial_value, str) or initial_value == value:
            raise AssertionError(f"{name}.{field}: CREATE must supply distinct Unicode")
        created_row = call(opener, csrf, "GET", read_path(record_id))["data"]
        if not isinstance(created_row, dict) or created_row.get(field) != initial_value:
            raise AssertionError(
                f"{name}.{field} lost initial UTF-8: {created_row.get(field)!r}"
            )
        call(opener, csrf, "PUT", update_path(record_id), {field: value})
        row = call(opener, csrf, "GET", read_path(record_id))["data"]
        if not isinstance(row, dict) or row.get(field) != value:
            raise AssertionError(f"{name}.{field} lost UTF-8 on readback: {row.get(field)!r}")
        print(f"ROUNDTRIP PASS: {name}.{field} preserved distinct Unicode on CREATE/GET/PUT/GET")
    finally:
        call(opener, csrf, "DELETE", delete_path(record_id))


def main() -> int:
    require_disposable()
    opener = make_client()
    csrf = login(opener)
    nonce = str(time.time_ns())
    expect_roundtrip(
        opener, csrf, "events", "/api/index.php/events",
        lambda i: f"/api/index.php/events/{i}",
        lambda i: f"/api/index.php/events/{i}",
        lambda i: f"/api/index.php/events/{i}",
        {"title": "CI Robustness Event " + nonce + " " + CREATE_VALUE, "slug": "ci-robust-event-" + nonce,
         "event_date": "2027-11-20 21:00:00", "venue": "CI Only",
         "city": "Pereira", "status": "draft"},
        "title", VALUE, lambda data: data.get("id", 0),
    )
    expect_roundtrip(
        opener, csrf, "artists", "/api/index.php/artists",
        lambda i: f"/api/index.php/artists/{i}",
        lambda i: f"/api/index.php/artists/{i}",
        lambda i: f"/api/index.php/artists/{i}",
        {"name": "CI Robustness Artist " + nonce, "slug": "ci-robust-artist-" + nonce,
         "status": "draft", "bio": CREATE_VALUE},
        "bio", VALUE, lambda data: data.get("id", 0),
    )
    expect_roundtrip(
        opener, csrf, "media", "/api/media-library.php?action=register",
        lambda i: f"/api/media-library.php?action=update&id={i}",
        lambda i: f"/api/media-library.php?action=detail&id={i}",
        lambda i: f"/api/media-library.php?id={i}",
        {"type": "image", "title": "CI Robustness Image " + nonce,
         "file_path": "https://example.invalid/brvtal-ci-" + nonce + ".jpg",
         "mime_type": "image/jpeg", "status": "draft", "alt_text": CREATE_VALUE},
        "alt_text", VALUE, lambda data: data.get("data", {}).get("id", 0),
    )
    print("ROUNDTRIP PASS: events/artists/media CREATE→GET→PUT→GET→DELETE")
    return 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except (AssertionError, OSError, RuntimeError, urllib.error.URLError) as error:
        print("DISCADMIN ROUNDTRIP FAILED:", error, file=sys.stderr)
        sys.exit(1)
