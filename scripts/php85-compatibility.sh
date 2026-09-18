#!/usr/bin/env bash
set -euo pipefail

if ! php -r 'exit(PHP_VERSION_ID >= 80500 && PHP_VERSION_ID < 80600 ? 0 : 1);'; then
  echo "PHP 8.5.x is required for this compatibility suite; got $(php -r 'echo PHP_VERSION;')." >&2
  exit 1
fi

echo "Linting PHP sources under PHP $(php -r 'echo PHP_VERSION;')..."
lint_jobs="${BRVTAL_LINT_JOBS:-4}"
find config discadmin api tests -name '*.php' -print0 | xargs -0 -r -n1 -P "$lint_jobs" php -l >/dev/null
find . -maxdepth 1 -type f -name '*.php' -print0 | xargs -0 -r -n1 -P "$lint_jobs" php -l >/dev/null

mapfile -t contracts < <(find tests -maxdepth 1 -type f -name '*-contract.php' -print | LC_ALL=C sort)
if [[ ${#contracts[@]} -eq 0 ]]; then
  echo "No top-level contract tests were discovered." >&2
  exit 1
fi

echo "Running ${#contracts[@]} auto-discovered PHP contracts..."
for test_file in "${contracts[@]}"; do
  diagnostics="$(mktemp)"
  echo "Running ${test_file}"
  if ! php -d error_reporting=E_ALL -d display_errors=stderr "$test_file" 2>"$diagnostics"; then
    cat "$diagnostics" >&2
    rm -f "$diagnostics"
    exit 1
  fi

  cat "$diagnostics" >&2
  if grep -Eiq '(^|PHP )(Warning|Deprecated|Notice):' "$diagnostics"; then
    echo "PHP 8.5 compatibility diagnostics detected in ${test_file}." >&2
    rm -f "$diagnostics"
    exit 1
  fi
  rm -f "$diagnostics"
done

echo "PHP 8.5 compatibility + contract suite passed without warnings, notices or deprecations."
