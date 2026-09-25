#!/usr/bin/env python3
"""One-time Hostinger Git authority cutover for BRVTAL Factory bootstrap."""
from __future__ import annotations

import argparse
import json
import os
import re
import sys
import urllib.error
import urllib.request
from dataclasses import asdict, dataclass, replace
from typing import Any, Callable

import transport

API_ROOT = "https://developers.hostinger.com/api/hosting/v1"
DOMAIN = "brvtal.com.co"
ORIGIN = "https://www.brvtal.com.co"
EXPECTED_OWNER = "pl0n3r"
EXPECTED_REPOSITORY = "brvtal"
EXPECTED_BRANCH = "main"
_ALLOWED_DIRECTORIES = {"", "public_html"}
_ID_RE = re.compile(r"^[A-Za-z0-9._:-]{1,160}$")
_SHA_RE = re.compile(r"^[0-9a-f]{40}$")
_VERSION_RE = re.compile(r"^[0-9]+\.[0-9]+\.[0-9]+$")


class CutoverError(RuntimeError):
    pass


@dataclass(frozen=True)
class GitSettings:
    installation_uuid: str
    is_enabled: bool
    owner: str
    repository: str
    branch: str
    directory: str

    @classmethod
    def from_payload(cls, payload: Any) -> "GitSettings":
        allowed = {"installation_uuid", "is_enabled", "owner", "repository", "branch", "directory"}
        if not isinstance(payload, dict) or set(payload) != allowed:
            raise CutoverError("Hostinger Git settings schema is unexpected")
        installation_uuid = payload["installation_uuid"]
        if not isinstance(installation_uuid, str) or not _ID_RE.fullmatch(installation_uuid):
            raise CutoverError("Hostinger Git installation identifier is invalid")
        if not isinstance(payload["is_enabled"], bool):
            raise CutoverError("Hostinger Git is_enabled must be boolean")
        values: dict[str, str] = {}
        for key in ("owner", "repository", "branch", "directory"):
            value = payload[key]
            if not isinstance(value, str) or "\x00" in value or "\r" in value or "\n" in value:
                raise CutoverError(f"Hostinger Git {key} is invalid")
            values[key] = value
        return cls(installation_uuid, payload["is_enabled"], **values)

    def validate_expected(self) -> None:
        if self.owner != EXPECTED_OWNER or self.repository != EXPECTED_REPOSITORY:
            raise CutoverError("Hostinger Git repository does not match BRVTAL")
        if self.branch != EXPECTED_BRANCH:
            raise CutoverError("Hostinger Git branch is not main")
        if self.directory not in _ALLOWED_DIRECTORIES:
            raise CutoverError("Hostinger Git target directory is outside the BRVTAL website root")

    def update_payload(self) -> dict[str, Any]:
        return asdict(self)


class HostingerGitClient:
    def __init__(
        self,
        token: str,
        username: str,
        *,
        opener: Callable[..., Any] = urllib.request.urlopen,
    ) -> None:
        if not token or len(token) > 4096 or any(ch in token for ch in "\x00\r\n"):
            raise CutoverError("HOSTINGER_API_TOKEN is missing or invalid")
        if not re.fullmatch(r"[A-Za-z0-9._-]{1,64}", username):
            raise CutoverError("Hostinger account username is invalid")
        self._token = token
        self._opener = opener
        self._url = (
            f"{API_ROOT}/accounts/{username}/websites/{DOMAIN}"
            "/git/auto-deployments/settings"
        )

    def _request(self, method: str, payload: dict[str, Any] | None = None) -> Any:
        data = None if payload is None else json.dumps(
            payload, separators=(",", ":"), sort_keys=True
        ).encode("utf-8")
        headers = {
            "Accept": "application/json",
            "Authorization": f"Bearer {self._token}",
        }
        if data is not None:
            headers["Content-Type"] = "application/json"
        request = urllib.request.Request(
            self._url, data=data, headers=headers, method=method
        )
        try:
            with self._opener(request, timeout=15) as response:
                status = int(getattr(response, "status", 200))
                raw = response.read(65537)
        except urllib.error.HTTPError as exc:
            raise CutoverError(
                f"Hostinger API {method} failed with HTTP {exc.code}"
            ) from None
        except (urllib.error.URLError, OSError, ValueError):
            raise CutoverError(f"Hostinger API {method} transport failed") from None
        if not 200 <= status < 300:
            raise CutoverError(f"Hostinger API {method} failed with HTTP {status}")
        if len(raw) > 65536:
            raise CutoverError("Hostinger API response exceeded the safe limit")
        if method != "GET":
            return None
        try:
            return json.loads(raw.decode("utf-8"))
        except (UnicodeDecodeError, json.JSONDecodeError):
            raise CutoverError("Hostinger API returned invalid JSON") from None

    def get(self) -> GitSettings:
        settings = GitSettings.from_payload(self._request("GET"))
        settings.validate_expected()
        return settings

    def put(self, settings: GitSettings) -> None:
        settings.validate_expected()
        self._request("PUT", settings.update_payload())


def _require_runtime() -> tuple[str, str, str]:
    token = os.environ.get("HOSTINGER_API_TOKEN", "")
    sha = os.environ.get("GITHUB_SHA", "").strip().lower()
    version = os.environ.get("VERSION", "").strip()
    if not _SHA_RE.fullmatch(sha):
        raise CutoverError("GITHUB_SHA must be one full lowercase commit SHA")
    if not _VERSION_RE.fullmatch(version):
        raise CutoverError("VERSION must be semantic X.Y.Z")
    return token, sha, version


def run_cutover(
    client: HostingerGitClient,
    config: transport.Config,
    sha: str,
    version: str,
    *,
    transport_api: Any = transport,
) -> GitSettings:
    original = client.get()
    disabled = replace(original, is_enabled=False)
    disabled_confirmed = False
    try:
        if original.is_enabled:
            client.put(disabled)
        observed = client.get()
        if observed != disabled:
            raise CutoverError("Hostinger Git auto-deploy did not reach disabled state")
        disabled_confirmed = True
        os.environ["BRVTAL_HOSTINGER_GIT_AUTODEPLOY_DISABLED"] = "1"
        transport_api.bootstrap(config, sha, version, ORIGIN)
        final = client.get()
        if final != disabled:
            raise CutoverError("Hostinger Git auto-deploy changed during bootstrap")
        return final
    except Exception as cutover_error:
        if disabled_confirmed:
            try:
                client.put(original)
                if client.get() != original:
                    raise CutoverError("restored Hostinger Git settings do not match snapshot")
            except Exception:
                raise CutoverError(
                    "cutover failed and prior Hostinger Git authority could not be restored"
                ) from None
            raise CutoverError(
                "cutover failed; prior Hostinger Git authority was restored"
            ) from cutover_error
        if isinstance(cutover_error, CutoverError):
            raise
        raise CutoverError("cutover failed before Hostinger Git disable was confirmed") from cutover_error


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument(
        "--confirm",
        required=True,
        choices=("CUTOVER-BRVTAL",),
        help="explicit one-time production cutover confirmation",
    )
    parser.parse_args()
    try:
        token, sha, version = _require_runtime()
        config = transport.load_config()
        client = HostingerGitClient(token, config.user)
        run_cutover(client, config, sha, version)
        print(f"BRVTAL Hostinger authority cutover validated: {sha}")
        return 0
    except (CutoverError, transport.TransportError, OSError) as exc:
        print(f"BRVTAL HOSTINGER CUTOVER: {exc}", file=sys.stderr)
        return 64


if __name__ == "__main__":
    raise SystemExit(main())
