#!/usr/bin/env bash
set -euo pipefail

BRVTAL_FACTORY_REPO_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd -P)"

factory_die() {
  printf 'BRVTAL FACTORY ADAPTER: %s\n' "$*" >&2
  exit 64
}

factory_mode() {
  local explicit="${BRVTAL_FACTORY_ADAPTER_MODE:-}"
  if [[ -n "$explicit" ]]; then
    case "$explicit" in fixture|production|disabled) printf '%s' "$explicit" ;; *) factory_die "invalid adapter mode" ;; esac
    return
  fi
  if [[ -n "${DEPLOY_TOKEN:-}" || -n "${DEPLOY_SSH_KEY:-}" ]]; then
    [[ -n "${DEPLOY_TOKEN:-}" && -n "${DEPLOY_SSH_KEY:-}" ]] || factory_die "remote deploy credentials are incomplete"
    printf 'production'
    return
  fi
  printf 'disabled'
}

factory_require_fixture() {
  [[ "$(factory_mode)" == "fixture" ]] || factory_die "fixture mode required"
}

factory_transport() {
  python3 "$BRVTAL_FACTORY_REPO_ROOT/ops/factory/transport.py" "$@"
}

factory_require_production_activation() {
  [[ "$(factory_mode)" == "production" ]] || factory_die "production adapter mode required"
  factory_transport validate >/dev/null
}

factory_sha() {
  local sha="${GITHUB_SHA:-}"
  [[ "$sha" =~ ^[0-9a-fA-F]{40}$ ]] || factory_die "GITHUB_SHA must be a full commit SHA"
  printf '%s' "${sha,,}"
}

factory_version() {
  local version="${VERSION:-}"
  [[ "$version" =~ ^[0-9]+\.[0-9]+\.[0-9]+$ ]] || factory_die "VERSION must be semantic X.Y.Z"
  printf '%s' "$version"
}

factory_fixture_root() {
  local raw="${BRVTAL_FACTORY_FIXTURE_ROOT:-}"
  [[ -n "$raw" ]] || factory_die "BRVTAL_FACTORY_FIXTURE_ROOT is required"
  case "$raw" in
    "$BRVTAL_FACTORY_REPO_ROOT"/.factory-fixture/*) ;;
    *) factory_die "fixture root must stay under .factory-fixture/" ;;
  esac
  mkdir -p -- "$raw"
  local resolved
  resolved="$(cd "$raw" && pwd -P)"
  case "$resolved" in
    "$BRVTAL_FACTORY_REPO_ROOT"/.factory-fixture/*) ;;
    *) factory_die "fixture root escaped repository guard" ;;
  esac
  printf '%s' "$resolved"
}

factory_maybe_fail() {
  local stage="$1"
  if [[ "${BRVTAL_FACTORY_FAIL_STAGE:-}" == "$stage" ]]; then
    printf 'BRVTAL FACTORY ADAPTER: simulated %s failure\n' "$stage" >&2
    exit 70
  fi
}

factory_atomic_write() {
  local target="$1" value="$2"
  local tmp="${target}.tmp.$$"
  printf '%s\n' "$value" > "$tmp"
  mv -f -- "$tmp" "$target"
}
