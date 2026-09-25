#!/usr/bin/env python3
"""Strict Hostinger SSH transport for BRVTAL Factory adapters."""
from __future__ import annotations

import argparse
import contextlib
import json
import os
import re
import secrets
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


def ssh_status(config: Config, script: str, *arguments: str) -> bool:
    """Return whether one fixed remote predicate succeeds without exposing output."""
    with credentials(config) as (key, hosts):
        command = [
            "ssh",
            *_ssh_options(config, key, hosts),
            f"{config.user}@{config.host}",
            "bash", "-s", "--", config.site_root, *arguments,
        ]
        completed = subprocess.run(
            command,
            input=script,
            text=True,
            stdout=subprocess.DEVNULL,
            stderr=subprocess.DEVNULL,
            check=False,
        )
        return completed.returncode == 0


def upload(config: Config, source: Path, remote_path: str) -> None:
    with credentials(config) as (key, hosts):
        target = f"{config.user}@{config.host}:{remote_path}"
        command = ["scp", *_ssh_options(config, key, hosts, scp=True), str(source), target]
        _run(command, label="SCP upload")


_PREPARE_UPLOAD = r"""
set -euo pipefail
site_root="$1"
artifacts="$site_root/factory-artifacts"
test ! -L "$artifacts"
if [ -e "$artifacts" ]; then
  test -d "$artifacts"
else
  mkdir -p -- "$artifacts"
fi
"""


_BOOTSTRAP_STATUS = r"""
set -euo pipefail
site_root="$1"
sha="$2"
public="$site_root/public_html"
candidate="$site_root/factory-releases/$sha"
shared="$site_root/factory-shared"
test -f "$shared/.prepared-v1"
test ! -L "$shared/.prepared-v1"
test ! -L "$candidate"
test "$(cat "$candidate/.factory-release-sha" 2>/dev/null || true)" = "$sha"
test -L "$public/.factory-current"
test "$(readlink "$public/.factory-current")" = "$candidate"
grep -Fq '# BRVTAL FACTORY DISPATCHER v1' "$public/.htaccess"
"""

_BOOTSTRAP_HAS_DISPATCHER = r"""
set -euo pipefail
site_root="$1"
grep -Fq '# BRVTAL FACTORY DISPATCHER v1' "$site_root/public_html/.htaccess"
"""

_BOOTSTRAP_PROBE_CREATE = r"""
set -euo pipefail
site_root="$1"
name="$2"
token="$3"
public="$site_root/public_html"
target="$site_root/.factory-symlink-probe-$name.txt"
link="$public/factory-symlink-probe-$name.txt"
test ! -e "$target"
test ! -L "$target"
test ! -e "$link"
test ! -L "$link"
printf '%s' "$token" > "$target"
ln -s -- "$target" "$link"
"""

_BOOTSTRAP_PROBE_CLEANUP = r"""
set -euo pipefail
site_root="$1"
name="$2"
rm -f -- "$site_root/public_html/factory-symlink-probe-$name.txt"
rm -f -- "$site_root/.factory-symlink-probe-$name.txt"
"""

_BOOTSTRAP_PREPARE = r"""
set -euo pipefail
site_root="$1"
sha="$2"
version="$3"
dispatcher_upload="$4"
public="$site_root/public_html"
releases="$site_root/factory-releases"
shared="$site_root/factory-shared"
state="$site_root/factory-state"
candidate="$releases/$sha"

test -d "$public"
test -f "$public/.htaccess"
test -f "$public/config/config.php"
test ! -L "$public/config/config.php"
for path in uploads storage .private; do
  test -d "$public/$path"
  test ! -L "$public/$path"
done
test -f "$public/.private/.htaccess"
test -f "$public/storage/.htaccess"
test -f "$public/storage/backups/.htaccess"
test -f "$public/uploads/.htaccess"
test -f "$dispatcher_upload"
grep -Fq '# BRVTAL FACTORY DISPATCHER v1' "$dispatcher_upload"

guard_dir() {
  path="$1"
  test ! -L "$path"
  if [ -e "$path" ]; then
    test -d "$path"
  else
    mkdir -p -- "$path"
  fi
}

test ! -L "$public"
guard_dir "$releases"
guard_dir "$shared"
guard_dir "$shared/config"
guard_dir "$state"
test ! -L "$candidate"

link_exact() {
  source_path="$1"
  target_path="$2"
  if [ -L "$target_path" ]; then
    test "$(readlink "$target_path")" = "$source_path"
    return
  fi
  test ! -e "$target_path"
  ln -s -- "$source_path" "$target_path"
}

link_exact "$public/config/config.php" "$shared/config/config.php"
link_exact "$public/uploads" "$shared/uploads"
link_exact "$public/storage" "$shared/storage"
link_exact "$public/.private" "$shared/.private"
test -f "$shared/.private/.htaccess"
test -f "$shared/storage/.htaccess"
test -f "$shared/storage/backups/.htaccess"
test -f "$shared/uploads/.htaccess"

test ! -L "$shared/.prepared-v1"
prepared_tmp="$shared/.prepared-v1.tmp.$BASHPID"
printf 'prepared-v1\n' > "$prepared_tmp"
mv -f -- "$prepared_tmp" "$shared/.prepared-v1"

if [ -d "$candidate" ]; then
  test "$(cat "$candidate/.factory-release-sha" 2>/dev/null || true)" = "$sha"
  test "$(cat "$candidate/.factory-release-version" 2>/dev/null || true)" = "$version"
else
  tmp="$releases/.$sha.bootstrap.$BASHPID"
  rm -rf -- "$tmp"
  mkdir -p -- "$tmp"
  trap 'rm -rf -- "$tmp"' EXIT
  (
    cd "$public"
    tar --exclude='./.git' --exclude='./.factory-current' --exclude='./.factory-current.*'       --exclude='./config/config.php' --exclude='./uploads' --exclude='./storage' --exclude='./.private'       -cf - .
  ) | tar -xf - -C "$tmp"
  test -f "$tmp/index.php"
  test -f "$tmp/.htaccess"
  mkdir -p "$tmp/.git" "$tmp/config"
  printf '%s\n' "$sha" > "$tmp/.git/HEAD"
  printf '%s\n' "$sha" > "$tmp/.factory-release-sha"
  printf '%s\n' "$version" > "$tmp/.factory-release-version"
  ln -s -- "$shared/config/config.php" "$tmp/config/config.php"
  rm -rf -- "$tmp/uploads" "$tmp/storage" "$tmp/.private"
  ln -s -- "$shared/uploads" "$tmp/uploads"
  ln -s -- "$shared/storage" "$tmp/storage"
  ln -s -- "$shared/.private" "$tmp/.private"
  mv -- "$tmp" "$candidate"
  trap - EXIT
fi

backup_marker="$state/bootstrap-backup-$sha.ok"
test ! -L "$backup_marker"
if [ ! -f "$backup_marker" ]; then
  (
    cd "$public"
    php <<'PHP'
<?php
declare(strict_types=1);
require "config/bootstrap.php";
require "config/backups.php";
$manifest = brvtal_backup_create(db(), [
    "include_media_archive" => false,
    "created_by" => ["id" => 0, "name" => "Factory bootstrap"],
]);
if (($manifest["status"] ?? "") !== "ready") {
    fwrite(STDERR, "bootstrap backup did not reach ready state\n");
    exit(1);
}
PHP
  )
  marker_tmp="$backup_marker.tmp.$BASHPID"
  printf 'backup-ready\n' > "$marker_tmp"
  mv -f -- "$marker_tmp" "$backup_marker"
fi

legacy="$state/legacy-public-htaccess"
test ! -L "$legacy"
if [ ! -f "$legacy" ]; then
  test ! -L "$public/.htaccess"
  cp -- "$public/.htaccess" "$legacy.tmp.$BASHPID"
  mv -f -- "$legacy.tmp.$BASHPID" "$legacy"
fi
grep -Fq '# BRVTAL FACTORY DISPATCHER v1' "$legacy" && exit 86

dispatcher="$state/dispatcher-v1.htaccess"
test ! -L "$dispatcher"
cp -- "$dispatcher_upload" "$dispatcher.tmp.$BASHPID"
mv -f -- "$dispatcher.tmp.$BASHPID" "$dispatcher"
rm -f -- "$dispatcher_upload"
"""

_BOOTSTRAP_ACTIVATE = r"""
set -euo pipefail
site_root="$1"
sha="$2"
public="$site_root/public_html"
candidate="$site_root/factory-releases/$sha"
state="$site_root/factory-state"
current="$public/.factory-current"
dispatcher="$state/dispatcher-v1.htaccess"
legacy="$state/legacy-public-htaccess"

test -f "$state/bootstrap-backup-$sha.ok"
test ! -L "$state/bootstrap-backup-$sha.ok"
test -f "$legacy"
test ! -L "$legacy"
test -f "$dispatcher"
test ! -L "$dispatcher"
test ! -L "$candidate"
test "$(cat "$candidate/.factory-release-sha" 2>/dev/null || true)" = "$sha"
grep -Fq '# BRVTAL FACTORY DISPATCHER v1' "$dispatcher"

if [ -e "$current" ] && [ ! -L "$current" ]; then exit 91; fi
if [ -L "$current" ]; then
  case "$(readlink "$current")" in "$site_root/factory-releases/"*) ;; *) exit 92 ;; esac
fi

pointer_tmp="$public/.factory-current.bootstrap.$BASHPID"
ln -s -- "$candidate" "$pointer_tmp"
mv -Tf -- "$pointer_tmp" "$current"

htaccess_tmp="$public/.htaccess.factory.$BASHPID"
cp -- "$dispatcher" "$htaccess_tmp"
mv -f -- "$htaccess_tmp" "$public/.htaccess"
"""

_REMOVE_ARTIFACT = r"""
set -euo pipefail
site_root="$1"
artifact="$2"
case "$artifact" in
  "$site_root/factory-artifacts/"*) ;;
  *) exit 98 ;;
esac
rm -f -- "$artifact"
"""

_BOOTSTRAP_RESTORE = r"""
set -euo pipefail
site_root="$1"
sha="$2"
public="$site_root/public_html"
state="$site_root/factory-state"
legacy="$state/legacy-public-htaccess"
current="$public/.factory-current"
candidate="$site_root/factory-releases/$sha"

test -d "$state"
test ! -L "$state"
test -f "$legacy"
test ! -L "$legacy"
if [ -f "$public/.htaccess" ] && grep -Fq '# BRVTAL FACTORY DISPATCHER v1' "$public/.htaccess"; then
  restore_tmp="$public/.htaccess.restore.$BASHPID"
  cp -- "$legacy" "$restore_tmp"
  mv -f -- "$restore_tmp" "$public/.htaccess"
fi
if [ -L "$current" ]; then
  target="$(readlink "$current")"
  case "$target" in "$site_root/factory-releases/"*) ;; *) exit 96 ;; esac
  if [ "$target" = "$candidate" ] || [ -d "$target" ]; then rm -f -- "$current"; fi
elif [ -e "$current" ]; then
  exit 97
fi
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
test -f "$shared/.private/.htaccess" || exit 37
test -f "$shared/storage/.htaccess" || exit 38
test -f "$shared/storage/backups/.htaccess" || exit 39
test -f "$shared/uploads/.htaccess" || exit 40
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

test -f "$tmp/index.php" || exit 42
test -f "$tmp/.htaccess" || exit 43
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
release="$site_root/factory-releases/$sha"
releases="$site_root/factory-releases"
current="$site_root/public_html/.factory-current"
test "$(cat "$release/.factory-release-sha" 2>/dev/null || true)" = "$sha" || exit 51
test ! -L "$release"
test -f "$release/ops/factory/migration-plan"
test ! -L "$release/ops/factory/migration-plan"

previous="__NONE__"
if [ -L "$current" ]; then
  previous="$(readlink "$current")"
  case "$previous" in "$releases"/*) ;; *) exit 52 ;; esac
  test -d "$previous" || exit 53
elif [ -e "$current" ]; then
  exit 54
fi

migration="$(bash "$release/ops/factory/migration-plan" "$release" "$previous")" || exit 55
case "$migration" in
  __NONE__|migration_[a-z0-9_]*.sql) ;;
  *) exit 56 ;;
esac

cd "$release"
php scripts/migrations.php verify-plan "$migration" >/dev/null || exit 57
if [ "$migration" = "__NONE__" ]; then
  exit 0
fi

BRVTAL_MIGRATIONS_ALLOW_WRITE=1 BRVTAL_MIGRATION_ACTOR=factory-deploy \
  php scripts/migrations.php apply "$migration" --confirm
php scripts/migrations.php verify-plan __NONE__ >/dev/null || exit 58
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



def _safe_origin(value: str) -> str:
    origin = value.strip().rstrip("/")
    if not re.fullmatch(r"https://[A-Za-z0-9.-]+(?::[0-9]{1,5})?", origin):
        raise TransportError("origin must be one HTTPS origin without path, query or credentials")
    return origin


def _curl_get(url: str) -> str:
    completed = subprocess.run(
        [
            "curl", "--fail", "--silent", "--show-error", "--proto", "=https",
            "--connect-timeout", "5", "--max-time", "10",
            "-H", "Cache-Control: no-cache", url,
        ],
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        check=False,
    )
    if completed.returncode != 0:
        raise TransportError("HTTP bootstrap probe failed")
    return completed.stdout


def _assert_identity(origin: str, sha: str, version: str) -> None:
    raw = _curl_get(f"{origin}/api/deployment.php?__factory_bootstrap={secrets.token_hex(8)}")
    try:
        payload = json.loads(raw)
    except json.JSONDecodeError as exc:
        raise TransportError("production identity endpoint returned invalid JSON") from exc
    data = payload.get("data") if isinstance(payload, dict) else None
    if not isinstance(data, dict):
        raise TransportError("production identity payload is missing")
    if data.get("exact") is not True or data.get("commit") != sha or data.get("version") != version:
        raise TransportError("production exact SHA/version do not match bootstrap expectation")


def _assert_public_smoke(origin: str) -> None:
    _curl_get(f"{origin}/")
    _curl_get(f"{origin}/discadmin")


def _probe_symlink(config: Config, origin: str) -> None:
    name = secrets.token_hex(8)
    token = "brvtal-symlink-" + secrets.token_hex(16)
    try:
        ssh_script(config, _BOOTSTRAP_PROBE_CREATE, name, token)
        if _curl_get(f"{origin}/factory-symlink-probe-{name}.txt") != token:
            raise TransportError("Hostinger HTTP symlink probe did not resolve exact target")
    finally:
        try:
            ssh_script(config, _BOOTSTRAP_PROBE_CLEANUP, name)
        except TransportError:
            pass


def restore_bootstrap(config: Config, sha: str) -> None:
    ssh_script(config, _BOOTSTRAP_RESTORE, sha)


def bootstrap_dispatcher_active(config: Config) -> bool:
    """Return whether the Factory dispatcher is currently installed remotely."""
    return ssh_status(config, _BOOTSTRAP_HAS_DISPATCHER)


def bootstrap(config: Config, sha: str, version: str, origin: str) -> None:
    if os.environ.get("BRVTAL_HOSTINGER_GIT_AUTODEPLOY_DISABLED") != "1":
        raise TransportError("Hostinger Git auto-deploy must be disabled before bootstrap")
    origin = _safe_origin(origin)
    _assert_identity(origin, sha, version)

    if ssh_status(config, _BOOTSTRAP_STATUS, sha):
        _assert_public_smoke(origin)
        return

    if ssh_status(config, _BOOTSTRAP_HAS_DISPATCHER):
        restore_bootstrap(config, sha)
        _assert_identity(origin, sha, version)

    _probe_symlink(config, origin)
    dispatcher = Path(__file__).with_name("public_html-dispatcher.htaccess").resolve(strict=True)
    remote_dispatcher = (
        f"{config.site_root}/factory-artifacts/"
        f"bootstrap-dispatcher-{sha}-{secrets.token_hex(8)}.htaccess"
    )
    ssh_script(config, _PREPARE_UPLOAD)
    try:
        upload(config, dispatcher, remote_dispatcher)
        ssh_script(config, _BOOTSTRAP_PREPARE, sha, version, remote_dispatcher)
    except Exception:
        try:
            ssh_script(config, _REMOVE_ARTIFACT, remote_dispatcher)
        except TransportError:
            pass
        raise

    try:
        ssh_script(config, _BOOTSTRAP_ACTIVATE, sha)
        _assert_identity(origin, sha, version)
        _assert_public_smoke(origin)
        if not ssh_status(config, _BOOTSTRAP_STATUS, sha):
            raise TransportError("bootstrap remote state is incomplete after activation")
    except Exception:
        try:
            restore_bootstrap(config, sha)
        except TransportError as rollback_error:
            raise TransportError("bootstrap validation failed and legacy dispatcher restore also failed") from rollback_error
        raise


def stage(config: Config, archive: Path, sha: str, version: str) -> None:
    if not archive.is_file() or archive.is_symlink():
        raise TransportError("release archive is missing or unsafe")
    remote_archive = (
        f"{config.site_root}/factory-artifacts/"
        f"{sha}-{secrets.token_hex(8)}.tar.gz"
    )
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
    p_activate = sub.add_parser("activate")
    p_activate.add_argument("--sha", required=True)
    p_rollback = sub.add_parser("rollback")
    p_rollback.add_argument("--sha", required=True)
    p_bootstrap = sub.add_parser("bootstrap")
    p_bootstrap.add_argument("--sha", required=True)
    p_bootstrap.add_argument("--version", required=True)
    p_bootstrap.add_argument("--origin", required=True)
    p_restore_bootstrap = sub.add_parser("restore-bootstrap")
    p_restore_bootstrap.add_argument("--sha", required=True)
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
            ssh_script(config, _MIGRATE, sha)
        elif args.command == "activate":
            ssh_script(config, _ACTIVATE, sha)
        elif args.command == "rollback":
            ssh_script(config, _ROLLBACK, sha)
        elif args.command == "bootstrap":
            bootstrap(config, sha, _safe_version(args.version), args.origin)
        elif args.command == "restore-bootstrap":
            restore_bootstrap(config, sha)
        return 0
    except (TransportError, OSError) as exc:
        print(f"BRVTAL FACTORY TRANSPORT: {exc}", file=sys.stderr)
        return 64


if __name__ == "__main__":
    raise SystemExit(main())
