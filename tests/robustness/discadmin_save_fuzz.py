#!/usr/bin/env python3
"""Fail-closed DISCADMIN write fuzzing against a disposable PHP/MariaDB stack.

Discover the API surface from the actual PHP router and top-level API scripts.
Never send these requests to public hosts or non-test databases.
"""
from __future__ import annotations

import argparse
import http.cookiejar
import http.server
import io
import json
import os
from pathlib import Path
import re
import sys
import tempfile
import threading
import urllib.error
import urllib.parse
import urllib.request
from dataclasses import dataclass

ROOT = Path(__file__).resolve().parents[2]
API = ROOT / "api"
BASE = os.environ.get("BRVTAL_REAL_STACK_URL", "http://127.0.0.1:4174").rstrip("/")
ADMIN_EMAIL = os.environ.get("BRVTAL_REAL_STACK_ADMIN_EMAIL", "ci-admin@brvtal.test")
ADMIN_PASSWORD = os.environ.get("BRVTAL_REAL_STACK_ADMIN_PASSWORD", "")
BASELINE = Path(__file__).with_name("known_findings.json")
SENTINEL_ID = "2147483647"
METHODS = ("POST", "PUT", "PATCH", "DELETE")
LEAK = re.compile(
    r"Stack trace|Uncaught|Fatal error|SQLSTATE|PDOException|"
    r"Doctrine\\|vendor/|/var/www/|/home/[a-z0-9_.-]+/|"
    r"(?:^|\n)\s*#\d+\s+[/\\]", re.IGNORECASE
)
ISSUE = re.compile(r"^https://github\.com/pl0n3r/brvtal/issues/[1-9]\d*$")
RESPONSE_LIMIT = 131072


class OversizedResponse(ValueError):
    """A response exceeded the hard cap; never parse a truncated JSON prefix."""


class NoRedirect(urllib.request.HTTPRedirectHandler):
    """Never follow a server-supplied Location, even to another loopback URL."""

    def redirect_request(self, req, fp, code, msg, headers, newurl):
        return None



@dataclass(frozen=True, order=True)
class Target:
    method: str
    path: str


def _php_array(source: str, name: str) -> list[str]:
    block = re.search(r"\$" + re.escape(name) + r"\s*=\s*\[([^\]]*)\]", source, re.S)
    if not block:
        raise ValueError("Cannot discover router array " + name)
    return re.findall(r"['\"]([a-z][a-z_0-9]*)['\"]", block.group(1))


def discover_targets(api: Path = API) -> list[Target]:
    """Discover routes anew on every run, including previously unknown API scripts."""
    router = (api / "route.php").read_text(encoding="utf-8")
    index = (api / "index.php").read_text(encoding="utf-8")
    items = _php_array(router, "itemResources")
    singles = _php_array(router, "singleSegmentResources")
    resources = _php_array(index, "resources")
    targets: set[Target] = set()
    for resource in sorted(set(resources) & set(items + singles)):
        if resource in ("media", "upload"):  # Deliberately read-only legacy routes.
            continue
        targets.add(Target("POST", "/api/index.php/" + resource))
        if resource in items:
            for method in ("PUT", "DELETE"):
                targets.add(Target(method, "/api/index.php/" + resource + "/" + SENTINEL_ID))
        if resource == "settings":
            targets.add(Target("DELETE", "/api/index.php/settings?key=__fuzz_absent__"))
    if "auth" in singles:
        targets.add(Target("POST", "/api/index.php/auth"))
    if "events" in items and "lineup" in router:
        targets.add(Target("POST", "/api/index.php/events/" + SENTINEL_ID + "/lineup"))
    for file in sorted(api.glob("*.php")):
        if file.name in ("index.php", "route.php"):
            continue
        source = file.read_text(encoding="utf-8")
        if "REQUEST_METHOD" not in source or not re.search(r"json_response\s*\(", source):
            continue
        verbs = set(re.findall(r"['\"](POST|PUT|PATCH|DELETE)['\"]", source))
        if not verbs:
            continue
        actions = set(re.findall(r"\$action\s*={2,3}\s*['\"]([a-z][a-z_0-9-]*)['\"]", source))
        # The bare endpoint and every action are exercised. An unsupported
        # method/action combination must itself fail safely with JSON.
        for method in verbs:
            targets.add(Target(method, "/api/" + file.name + "?id=" + SENTINEL_ID))
            for action in actions:
                query = urllib.parse.urlencode({"action": action, "id": SENTINEL_ID})
                targets.add(Target(method, "/api/" + file.name + "?" + query))
    if len(targets) < 15:
        raise ValueError("Discovered too few write routes; refusing a false green")
    return sorted(targets)


def require_disposable() -> None:
    parsed = urllib.parse.urlsplit(BASE)
    db = os.environ.get("BRVTAL_TEST_DB_NAME", "")
    if (parsed.scheme != "http" or parsed.hostname not in ("localhost", "127.0.0.1", "::1")
            or not re.fullmatch(r"brvtal_test[a-zA-Z0-9_]*", db)
            or not (os.environ.get("GITHUB_ACTIONS") == "true"
                    or os.environ.get("BRVTAL_REAL_STACK_FORCE") == "1")):
        raise RuntimeError("Fuzzing only permits local disposable brvtal_test* stack")


def make_client() -> urllib.request.OpenerDirector:
    return urllib.request.build_opener(
        urllib.request.ProxyHandler({}),  # Ignore ambient http(s)_proxy.
        urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()),
        NoRedirect(),  # urllib surfaces 30x as HTTPError, without a second request.
    )


def request(
    opener: urllib.request.OpenerDirector, method: str, path: str,
    data: bytes | None = None, csrf: str = "",
) -> tuple[int, str]:
    headers = {"Accept": "application/json", "Content-Type": "application/json"}
    if csrf:
        headers["X-CSRF-Token"] = csrf
    req = urllib.request.Request(BASE + path, data=data, headers=headers, method=method)
    try:
        response = opener.open(req, timeout=10)
    except urllib.error.HTTPError as exc:
        response = exc
    with response:
        body = response.read(RESPONSE_LIMIT + 1)
        if len(body) > RESPONSE_LIMIT:
            raise OversizedResponse("HTTP response exceeded 131072 bytes")
        return response.status, body.decode("utf-8", errors="replace")


def response_defects(status: int, body: str) -> set[str]:
    """Classify response defects by stable fingerprint suffix."""
    defects: set[str] = set()
    if 300 <= status < 400:
        defects.add("redirect-response")
    if status >= 500:
        defects.add("server-5xx")
    if LEAK.search(body):
        defects.add("internal-leak")
    try:
        json.loads(body)
    except ValueError:
        defects.add("non-json")
    return defects


def login(opener: urllib.request.OpenerDirector) -> str:
    if not ADMIN_PASSWORD:
        raise RuntimeError("Disposable admin password not configured")
    body = json.dumps({"email": ADMIN_EMAIL, "password": ADMIN_PASSWORD}).encode()
    status, raw = request(opener, "POST", "/api/index.php/auth", body)
    try:
        payload = json.loads(raw)
    except ValueError as exc:
        raise RuntimeError("Disposable admin login returned non-JSON") from exc
    if status != 200 or not payload.get("ok") or not payload.get("csrf"):
        raise RuntimeError("Disposable admin login did not authenticate")
    return str(payload["csrf"])


def cases() -> list[tuple[str, bytes]]:
    encode = lambda obj: json.dumps(obj, ensure_ascii=False, separators=(",", ":")).encode()
    nested: object = "leaf"
    for _ in range(130):
        nested = {"n": nested}
    return [
        ("empty", b""),
        ("malformed-json", b'{"title":'),
        ("invalid-utf8", b'{"title":"\xff"}'),
        ("deep-json", encode({"title": nested})),
        ("2mb-body", encode({"__fuzz_payload": "x" * (2 * 1024 * 1024)})),
        ("unicode-emoji", encode({"title": "Ø Ñandú 🦉", "name": "Ø Ñandú 🦉"})),
        ("rtl", encode({"title": "שלום مرحبا \u202e", "name": "שלום مرحبا"})),
        ("html-script", encode({"title": "<script>alert(1)</script>", "name": "<script>x</script>"})),
        ("sql-metacharacters", encode({"title": "'; DROP TABLE artists; --", "name": "' OR '1'='1"})),
        ("nul-byte", encode({"title": "bad\u0000name", "name": "\u0000"})),
        ("extreme-numbers", encode({"id": 10**100, "sort_order": -(10**60), "title": 10**100})),
        ("impossible-date", encode({"event_date": "2026-02-31T99:99:99", "publish_at": "9999-99-99"})),
        ("wrong-types", encode({"title": [], "name": {}, "status": ["draft"], "lineup": "wrong"})),
    ]


def load_baseline(path: Path = BASELINE) -> set[str]:
    data = json.loads(path.read_text(encoding="utf-8"))
    if data.get("version") != 1 or not isinstance(data.get("findings"), list):
        raise ValueError("Invalid known_findings.json schema")
    fingerprints: set[str] = set()
    for finding in data["findings"]:
        if (not isinstance(finding, dict) or not isinstance(finding.get("fingerprint"), str)
                or not ISSUE.fullmatch(str(finding.get("issue", "")))):
            raise ValueError("Each known finding needs fingerprint and a BRVTAL issue URL")
        if finding["fingerprint"] in fingerprints:
            raise ValueError("Duplicate known finding " + finding["fingerprint"])
        fingerprints.add(finding["fingerprint"])
    return fingerprints


def unexpected(actual: set[str], known: set[str]) -> set[str]:
    return actual - known


def self_test() -> None:
    with tempfile.TemporaryDirectory(prefix="brvtal-discovery-") as directory:
        api = Path(directory)
        (api / "route.php").write_text((API / "route.php").read_text(encoding="utf-8"), encoding="utf-8")
        (api / "index.php").write_text((API / "index.php").read_text(encoding="utf-8"), encoding="utf-8")
        before = discover_targets(api)
        (api / "new-save.php").write_text(
            "<?php $method=$_SERVER['REQUEST_METHOD']; "
            "if ($method==='POST') json_response(['ok'=>false],422);",
            encoding="utf-8",
        )
        after = discover_targets(api)
        assert Target("POST", "/api/new-save.php?id=" + SENTINEL_ID) in after
        assert len(after) > len(before), "New write endpoint did not self-register"
    baseline = {"POST /api/probe.php [malformed-json]:server-5xx"}
    assert not unexpected(baseline, baseline)
    assert unexpected(baseline, set()) == baseline, "Removing a baseline entry must fail"
    load_baseline()

    assert LEAK.search("Fatal in /home/runner/work/brvtal/index.php")
    assert LEAK.search("File /home/deploy_user/application/index.php")
    assert not LEAK.search("Valid URL /home/"), "Leak matcher must require a username"

    # Use an actual loopback HTTP server: even a same-host Location must not
    # trigger a second request (outside-host redirects are also forbidden).
    class RedirectProbe(http.server.BaseHTTPRequestHandler):
        visits: list[str] = []

        def do_GET(self):
            type(self).visits.append(self.path)
            if self.path == "/redirect":
                self.send_response(302)
                self.send_header("Location", "/would-have-followed")
                self.send_header("Content-Type", "application/json")
                self.end_headers()
                self.wfile.write(b'{"ok":false}')
            else:
                self.send_response(200)
                self.end_headers()
                self.wfile.write(b'{"ok":true}')

        def log_message(self, format, *args):
            pass

    server = http.server.HTTPServer(("127.0.0.1", 0), RedirectProbe)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    try:
        opener = make_client()
        redirect_req = urllib.request.Request(
            f"http://127.0.0.1:{server.server_port}/redirect"
        )
        try:
            response = opener.open(redirect_req, timeout=5)
        except urllib.error.HTTPError as error:
            response = error
        with response:
            status, body = response.status, response.read().decode("utf-8")
        assert status == 302, "Redirect status was hidden"
        assert RedirectProbe.visits == ["/redirect"], "Client followed a redirect"
        assert "redirect-response" in response_defects(status, body)
        assert any(isinstance(h, urllib.request.HTTPCookieProcessor)
                   for h in opener.handlers), "Session cookie handling was removed"
        assert all(not h.proxies for h in opener.handlers
                   if isinstance(h, urllib.request.ProxyHandler)), "Proxy enabled"
    finally:
        server.shutdown()
        thread.join(timeout=5)
        server.server_close()

    # A valid JSON prefix plus a 131073rd byte is *not* valid evidence:
    # the cap must be checked before JSON parsing.
    prefix = b'{"ok":true}'
    oversized = prefix + b" " * (RESPONSE_LIMIT - len(prefix)) + b"x"
    assert json.loads(oversized[:RESPONSE_LIMIT]) == {"ok": True}

    class FakeResponse(io.BytesIO):
        status = 200

    class FakeOpener:
        def open(self, _req, timeout):
            return FakeResponse(oversized)

    try:
        request(FakeOpener(), "GET", "/api/probe")
    except OversizedResponse:
        pass
    else:
        raise AssertionError("Valid JSON prefix masked oversized-response")
    assert "redirect-response" not in response_defects(200, '{"ok":true}')
    print("SELF-TEST PASS: discovery, leak paths, no HTTP redirects/proxies, "
          "oversized JSON prefix rejected and known findings unchanged")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--self-test", action="store_true")
    parser.add_argument("--list-targets", action="store_true")
    parser.add_argument("--json-report", type=Path)
    args = parser.parse_args()
    if args.self_test:
        self_test()
        return 0
    targets = discover_targets()
    if args.list_targets:
        for target in targets:
            print(target.method, target.path)
        return 0
    require_disposable()
    known = load_baseline()
    opener = make_client()
    csrf = login(opener)
    found: set[str] = set()
    total = 0
    for target in targets:
        for name, body in cases():
            total += 1
            prefix = f"{target.method} {target.path} [{name}]:"
            try:
                status, response = request(opener, target.method, target.path, body, csrf)
            except OversizedResponse:
                found.add(prefix + "oversized-response")
                continue
            except (OSError, TimeoutError) as exc:
                found.add(prefix + "transport-error")
                print("REQUEST ERROR", prefix, type(exc).__name__, file=sys.stderr)
                continue
            for defect in response_defects(status, response):
                found.add(prefix + defect)
    new = unexpected(found, known)
    print(f"DISCADMIN FUZZ: {len(targets)} discovered endpoints, {total} requests, "
          f"{len(found)} findings ({len(new)} NEW, {len(found & known)} baselined)")
    for finding in sorted(new):
        print("NEW FINDING:", finding, file=sys.stderr)
    if args.json_report:
        args.json_report.write_text(json.dumps({
            "targets": len(targets), "requests": total, "new": sorted(new),
            "known_observed": sorted(found & known), "known_not_reproduced": sorted(known - found),
        }, indent=2) + "\n", encoding="utf-8")
    return 1 if new else 0


if __name__ == "__main__":
    try:
        sys.exit(main())
    except (OSError, RuntimeError, ValueError) as error:
        print("DISCADMIN FUZZ CONFIG ERROR:", error, file=sys.stderr)
        sys.exit(2)
