#!/usr/bin/env bash
set -euo pipefail

base_sha="${1:-}"
head_sha="${2:-}"

if [[ ! "$base_sha" =~ ^[0-9a-fA-F]{40}$ || ! "$head_sha" =~ ^[0-9a-fA-F]{40}$ ]]; then
  echo "Static analysis requires exact 40-character base/head SHAs." >&2
  exit 2
fi

git cat-file -e "${base_sha}^{commit}"
git cat-file -e "${head_sha}^{commit}"

root="$(git rev-parse --show-toplevel)"
tmp="$(mktemp -d)"
base_tree="$tmp/base"
head_tree="$tmp/head"
base_report="$tmp/phpstan-base.json"
head_report="$tmp/phpstan-head.json"

remove_worktree() {
  local path="$1"
  if git worktree list --porcelain | grep -Fq "worktree $path"; then
    git worktree remove --force "$path" >/dev/null 2>&1 || true
  fi
}

cleanup() {
  remove_worktree "$head_tree"
  remove_worktree "$base_tree"
  rm -rf "$tmp"
}
trap cleanup EXIT

git worktree add --detach "$base_tree" "$base_sha" >/dev/null
git worktree add --detach "$head_tree" "$head_sha" >/dev/null
cp "$head_tree/phpstan.neon" "$base_tree/phpstan.neon"

run_phpstan_json() {
  local tree="$1"
  local output="$2"
  local status=0
  set +e
  (
    cd "$tree"
    phpstan analyse \
      --configuration=phpstan.neon \
      --no-progress \
      --error-format=json \
      --memory-limit=1G
  ) >"$output"
  status=$?
  set -e

  if (( status > 1 )); then
    echo "PHPStan execution failed before producing a comparable report (exit $status)." >&2
    exit "$status"
  fi
}

run_phpstan_json "$base_tree" "$base_report"
run_phpstan_json "$head_tree" "$head_report"

{
  printf '{"base":'
  cat "$base_report"
  printf ',"head":'
  cat "$head_report"
  printf '}\n'
} | python3 "$root/scripts/phpstan_diff.py" \
  --base-root "$base_tree" \
  --head-root "$head_tree"

mapfile -t changed_php < <(
  git diff --name-only --diff-filter=ACMR "$base_sha" "$head_sha" -- '*.php' |
    while IFS= read -r file; do
      [[ -f "$head_tree/$file" ]] && printf '%s\n' "$file"
    done
)

if (( ${#changed_php[@]} == 0 )); then
  echo "Rector: no changed PHP files; dry-run skipped."
  exit 0
fi

(
  cd "$head_tree"
  rector process "${changed_php[@]}" \
    --config="$head_tree/rector.php" \
    --dry-run \
    --no-progress-bar
)
