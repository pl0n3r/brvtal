#!/usr/bin/env python3
"""Strict Hostinger SSH transport for BRVTAL Factory adapters."""
from __future__ import annotations

import argparse
import contextlib
import json
import os
import re
import subprocess
import sys
import tempfile
from dataclasses import dataclass
from pathlib import Path, PurePosixPath

_ALLOWED_FIELDS = {"host", "user", "port", "site_root", "known_hosts"}
_HOST_RE = re.compile(r"^(?=.{1,253}$)(?!-)[A-Za-z0-9.-]+(?<!-)$")
_USER_RE = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$")
_PATH_RE = re.compile(r"^/[A-Za-z0-9._/-]+$")
_SHA_RE = re.compile(r"^[0-9a-f]{40}$")
_VERSION_RE = re.compile(r"^[0-9]+\.[0-9]+\.[0-9]+$")
_MIGRATION_RE = re.compile(r"^migration_[a-z0-9_]+\.sql$")


class TransportError(RuntimeError):
    pass


@dataclass(frozen=True)
class Config:
    host: str
    user: str
    port: int
    site_root: str
    known_hosts: str
    private_key: str


def _validate_host(value: object) -> str:
    host = str(value or "").strip()
    if not host or host.startswith("-") or not _HOST_RE.fullmatch(host):
        raise TransportError("DEPLOY_TOKEN.host is invalid")
    if re.fullmatch(r"\d{1,3}(?:\.\d{1,3}){3}", host):
        if any(int(part) > 255 for part in host.split(".")):
            raise TransportError("DEPLOY_TOKEN.host is invalid")
        return host
    labels = host.split(".")
    if any(not label or len(label) > 63 or label.startswith("-") or label.endswith("-") for label in labels):
        raise TransportError("DEPLOY_TOKEN.host is invalid")
    return host


def _validate_site_root(value: object) -> str:
    raw = str(value or "").strip()
    if not _PATH_RE.fullmatch(raw) or "//" in raw:
        raise TransportError("DEPLOY_TOKEN.site_root must be a safe absolute POSIX path")
    path = PurePosixPath(raw)
    if not path.is_absolute() or any(part in {"", ".", ".."} for part in path.parts[1:]):
        raise TransportError("DEPLOY_TOKEN.site_root must be normalized")
    normalized = str(path)
    if normalized == "/" or normalized.endswith("/public_html"):
        raise TransportError("DEPLOY_TOKEN.site_root must be the site root above public_html")
    return normalized


def _validate_known_hosts(raw: object, host: str, port: int) -> str:
    value = str(raw or "").strip()
    if not value or "\x00" in value or "\r" in value:
        raise TransportError("DEPLOY_TOKEN.known_hosts is invalid")
    expected = {host, f"[{host}]:{port}"}
    matched = False
    for line in value.splitlines():
        line = line.strip()
        if not line or line.startswith("#"):
            continue
        parts = line.split()
        if len(parts) < 3:
            raise TransportError("DEPLOY_TOKEN.known_hosts contains a malformed entry")
        hosts, key_type, key_data = parts[0], parts[1], parts[2]
        if hosts in expected and (
            key_type.startswith("ssh-") or key_type.startswith("ecdsa-") or key_type.startswith("sk-")
        ) and len(key_data) >= 16:
            matched = True
    if not matched:
        raise TransportError("DEPLOY_TOKEN.known_hosts does not pin the configured host")
    return value + "\n"


def load_config() -> Config:
    token = os.environ.get("DEPLOY_TOKEN", "")
    private_key = os.environ.get("DEPLOY_SSH_KEY", "")
    if not token:
        raise TransportError("DEPLOY_TOKEN is required")
    if not private_key or "\x00" in private_key or "PRIVATE KEY" not in private_key:
        raise TransportError("DEPLOY_SSH_KEY is required and must contain a private key")
    try:
        data = json.loads(token)
    except json.JSONDecodeError as exc:
        raise TransportError("DEPLOY_TOKEN must be valid JSON") from exc
    if not isinstance(data, dict) or set(data) != _ALLOWED_FIELDS:
        raise TransportError("DEPLOY_TOKEN must contain exactly host,user,port,site_root,known_hosts")
    host = _validate_host(data["host"])
    user = str(data["user"] or "").strip()
    if not _USER_RE.fullmatch(user):
        raise TransportError("DEPLOY_TOKEN.user is invalid")
    port = data["port"]
    if isinstance(port, bool) or not isinstance(port, int) or not (1 <= port <= 65535):
        raise TransportError("DEPLOY_TOKEN.port must be an integer from 1 to 65535")
    site_root = _validate_site_root(data["site_root"])
    known_hosts = _validate_known_hosts(data["known_hosts"], host, port)
    return Config(host, user, port, site_root, known_hosts, private_key)


def _safe_sha(value: str) -> str:
    sha = value.strip().lower()
    if not _SHA_RE.fullmatch(sha):
        raise TransportError("sha must be a full lowercase commit SHA")
    return sha


def _safe_version(value: str) -> str:
    version = value.strip()
    if not _VERSION_RE.fullmatch(version):
        raise TransportError("version must be semantic X.Y.Z")
    return version


@contextlib.contextmanager
def credentials(config: Config):
    with tempfile.TemporaryDirectory(prefix="brvtal-factory-ssh-") as tmp:
        root = Path(tmp)
        key = root / "id"
        hosts = root / "known_hosts"
        key.write_text(config.private_key.rstrip("\n") + "\n", encoding="utf-8")
        hosts.write_text(config.known_hosts, encoding="utf-8")
        os.chmod(key, 0o600)
        os.chmod(hosts, 0o600)
        yield key, hosts


def _ssh_options(config: Config, key: Path, hosts: Path, *, scp: bool = False) -> list[str]:
    return [
        "-F", "/dev/null",
        "-o", "BatchMode=yes",
        "-o", "StrictHostKeyChecking=yes",
        "-o", f"UserKnownHostsFile={hosts}",
        "-o", "IdentitiesOnly=yes",
        "-o", "LogLevel=ERROR",
        "-o", "ConnectTimeout=15",
        "-i", str(key),
        "-P" if scp else "-p", str(config.port),
    ]


def _run(args: list[str], *, input_text: str | None = None, label: str) -> None:
    completed = subprocess.run(
        args,
        input=input_text,
        text=True,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.PIPE,
        check=False,
    )
    if completed.returncode != 0:
        raise TransportError(f"{label} failed with exit code {completed.returncode}")


def ssh_script(config: Config, script: str, *arguments: str) -> None:
    with credentials(config) as (key, hosts):
        command = [
            "ssh",
            *_ssh_options(config, key, hosts),
            f"{config.user}@{config.host}",
            "bash", "-s", "--", config.site_root, *arguments,
        ]
        _run(command, input_text=script, label="SSH command")


def upload(config: Config, source: Path, remote_path: str) -> None:
    with credentials(config) as (key, hosts):
        target = f"{config.user}@{config.host}:{remote_path}"
        command = ["scp", *_ssh_options(config, key, hosts, scp=True), str(source), target]
        _run(command, label="SCP upload")


_PREPARE_UPLOAD = r"""
set -euo pipefail
site_root="$1"
mkdir -p "$site_root/factory-artifacts"
"""

_STAGE = r"""
set -euo pipefail
site_root="$1"
sha="$2"
version="$3"
archive="$4"
releases="$site_root/factory-releases"
shared="$site_root/factory-shared"
state="$site_root/factory-state"
public="$site_root/public_html"
candidate="$releases/$sha"

test -d "$public" || exit 31
test -f "$shared/.prepared-v1" || exit 32
test -f "$shared/config/config.php" || exit 33
test -d "$shared/uploads" || exit 34
test -d "$shared/storage" || exit 35
test -d "$shared/.private" || exit 36
mkdir -p "$releases" "$state"

if [ -d "$candidate" ]; then
  test "$(cat "$candidate/.factory-release-sha" 2>/dev/null || true)" = "$sha" || exit 37
  rm -f -- "$archive"
  exit 0
fi

tmp="$releases/.$sha.tmp.$$"
rm -rf -- "$tmp"
mkdir -p -- "$tmp"
trap 'rm -rf -- "$tmp"' EXIT
tar -xzf "$archive" -C "$tmp"
rm -f -- "$archive"

test -f "$tmp/index.php" || exit 38
test -f "$tmp/.htaccess" || exit 39
mkdir -p "$tmp/.git" "$tmp/config"
printf '%s\n' "$sha" > "$tmp/.git/HEAD"
printf '%s\n' "$sha" > "$tmp/.factory-release-sha"
printf '%s\n' "$version" > "$tmp/.factory-release-version"

rm -f -- "$tmp/config/config.php"
ln -s -- "$shared/config/config.php" "$tmp/config/config.php"
rm -rf -- "$tmp/uploads" "$tmp/storage" "$tmp/.private"
ln -s -- "$shared/uploads" "$tmp/uploads"
ln -s -- "$shared/storage" "$tmp/storage"
ln -s -- "$shared/.private" "$tmp/.private"

mv -- "$tmp" "$candidate"
trap - EXIT
"""

_BACKUP = r"""
set -euo pipefail
site_root="$1"
sha="$2"
release="$site_root/factory-releases/$sha"
test "$(cat "$release/.factory-release-sha" 2>/dev/null || true)" = "$sha" || exit 41
cd "$release"
php <<'PHP'
<?php
declare(strict_types=1);
require "config/bootstrap.php";
require "config/backups.php";
$manifest = brvtal_backup_create(db(), [
    "include_media_archive" => false,
    "created_by" => ["id" => 0, "name" => "Factory deploy"],
]);
if (($manifest["status"] ?? "") !== "ready") {
    fwrite(STDERR, "backup did not reach ready state\n");
    exit(1);
}
PHP
"""

_MIGRATE = r"""
set -euo pipefail
site_root="$1"
sha="$2"
migration="$3"
release="$site_root/factory-releases/$sha"
test "$(cat "$release/.factory-release-sha" 2>/dev/null || true)" = "$sha" || exit 51
cd "$release"
BRVTAL_MIGRATIONS_ALLOW_WRITE=1 BRVTAL_MIGRATION_ACTOR=factory-deploy \
  php scripts/migrations.php apply "$migration" --confirm
"""

_ACTIVATE = r"""
set -euo pipefail
site_root="$1"
sha="$2"
releases="$site_root/factory-releases"
public="$site_root/public_html"
state="$site_root/factory-state"
candidate="$releases/$sha"
current="$public/.factory-current"

test "$(cat "$candidate/.factory-release-sha" 2>/dev/null || true)" = "$sha" || exit 61
test -f "$public/.htaccess" || exit 62
grep -Fq '# BRVTAL FACTORY DISPATCHER v1' "$public/.htaccess" || exit 63
grep -Fq '.factory-current' "$public/.htaccess" || exit 64
mkdir -p "$state"

if [ -e "$current" ] && [ ! -L "$current" ]; then
  exit 65
fi

if [ -L "$current" ]; then
  existing="$(readlink "$current")"
  case "$existing" in "$releases"/*) ;; *) exit 66 ;; esac
  if [ "$existing" = "$candidate" ]; then
    exit 0
  fi
  previous="$existing"
else
  previous="__NONE__"
fi

state_tmp="$state/.previous-release.$$"
printf '%s\n' "$previous" > "$state_tmp"
mv -f -- "$state_tmp" "$state/previous-release"

pointer_tmp="$public/.factory-current.$$"
ln -s -- "$candidate" "$pointer_tmp"
mv -Tf -- "$pointer_tmp" "$current"
"""

_ROLLBACK = r"""
set -euo pipefail
site_root="$1"
failed_sha="$2"
releases="$site_root/factory-releases"
public="$site_root/public_html"
state="$site_root/factory-state/previous-release"
current="$public/.factory-current"
failed="$releases/$failed_sha"

test -L "$current" || exit 71
test "$(readlink "$current")" = "$failed" || exit 72
test -f "$state" || exit 73
previous="$(cat "$state")"

if [ "$previous" = "__NONE__" ]; then
  rm -f -- "$current"
  exit 0
fi

case "$previous" in "$releases"/*) ;; *) exit 74 ;; esac
test -d "$previous" || exit 75
pointer_tmp="$public/.factory-current.rollback.$$"
ln -s -- "$previous" "$pointer_tmp"
mv -Tf -- "$pointer_tmp" "$current"
"""


def stage(config: Config, archive: Path, sha: str, version: str) -> None:
    if not archive.is_file() or archive.is_symlink():
        raise TransportError("release archive is missing or unsafe")
    remote_archive = f"{config.site_root}/factory-artifacts/{sha}.tar.gz"
    ssh_script(config, _PREPARE_UPLOAD)
    upload(config, archive, remote_archive)
    ssh_script(config, _STAGE, sha, version, remote_archive)


def main() -> int:
    parser = argparse.ArgumentParser()
    sub = parser.add_subparsers(dest="command", required=True)
    sub.add_parser("validate")
    p_stage = sub.add_parser("stage")
    p_stage.add_argument("--archive", required=True)
    p_stage.add_argument("--sha", required=True)
    p_stage.add_argument("--version", required=True)
    p_backup = sub.add_parser("backup")
    p_backup.add_argument("--sha", required=True)
    p_migrate = sub.add_parser("migrate")
    p_migrate.add_argument("--sha", required=True)
    p_migrate.add_argument("--migration", required=True)
    p_activate = sub.add_parser("activate")
    p_activate.add_argument("--sha", required=True)
    p_rollback = sub.add_parser("rollback")
    p_rollback.add_argument("--sha", required=True)
    args = parser.parse_args()

    try:
        config = load_config()
        if args.command == "validate":
            return 0
        sha = _safe_sha(args.sha)
        if args.command == "stage":
            stage(config, Path(args.archive).resolve(strict=True), sha, _safe_version(args.version))
        elif args.command == "backup":
            ssh_script(config, _BACKUP, sha)
        elif args.command == "migrate":
            migration = args.migration.strip()
            if not _MIGRATION_RE.fullmatch(migration):
                raise TransportError("migration must be one explicit migration_*.sql file")
            ssh_script(config, _MIGRATE, sha, migration)
        elif args.command == "activate":
            ssh_script(config, _ACTIVATE, sha)
        elif args.command == "rollback":
            ssh_script(config, _ROLLBACK, sha)
        return 0
    except (TransportError, OSError) as exc:
        print(f"BRVTAL FACTORY TRANSPORT: {exc}", file=sys.stderr)
        return 64


if __name__ == "__main__":
    raise SystemExit(main())
