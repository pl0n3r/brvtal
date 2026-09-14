#!/usr/bin/env bash
set -euo pipefail

if ! php -r 'exit(PHP_VERSION_ID >= 80500 && PHP_VERSION_ID < 80600 ? 0 : 1);'; then
  echo "PHP 8.5.x is required for this compatibility suite; got $(php -r 'echo PHP_VERSION;')." >&2
  exit 1
fi

echo "Linting PHP sources under PHP $(php -r 'echo PHP_VERSION;')..."
find config discadmin api tests -name '*.php' -print0 | xargs -0 -n1 php -l >/dev/null
php -l index.php >/dev/null
php -l sitemap.php >/dev/null

contracts=(
  tests/api-contract.php
  tests/media-library-contract.php
  tests/releases-contract.php
  tests/blog-contract.php
  tests/content-health-contract.php
  tests/seo-contract.php
  tests/global-search-contract.php
  tests/bulk-actions-contract.php
  tests/public-archive-contract.php
  tests/public-entity-contract.php
  tests/related-content-contract.php
  tests/admin-activity-contract.php
  tests/backups-contract.php
  tests/project-operations-contract.php
)

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

echo "PHP 8.5 compatibility suite passed without warnings, notices or deprecations."
