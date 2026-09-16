#!/usr/bin/env bash
set -euo pipefail

if [[ "${GITHUB_ACTIONS:-}" != "true" && "${BRVTAL_REAL_STACK_FORCE:-0}" != "1" ]]; then
  echo "Skipping Content Core real-stack smoke outside GitHub Actions. Set BRVTAL_REAL_STACK_FORCE=1 to run it locally."
  exit 0
fi

DB_HOST="${BRVTAL_TEST_DB_HOST:-127.0.0.1}"
DB_PORT="${BRVTAL_TEST_DB_PORT:-3306}"
DB_NAME="${BRVTAL_TEST_DB_NAME:-brvtal_test_ci}"
DB_USER="${BRVTAL_TEST_DB_USER:-root}"
DB_PASS="${BRVTAL_TEST_DB_PASS:-brvtal_root}"
BASE_URL="${BRVTAL_REAL_STACK_URL:-http://127.0.0.1:4174}"
ADMIN_EMAIL="${BRVTAL_REAL_STACK_ADMIN_EMAIL:-ci-admin@brvtal.test}"
ADMIN_PASSWORD="${BRVTAL_REAL_STACK_ADMIN_PASSWORD:-brvtal-ci-password}"
PHP_LOG="${RUNNER_TEMP:-/tmp}/brvtal-real-stack-php.log"

if [[ ! "$DB_NAME" =~ ^brvtal_test[a-zA-Z0-9_]*$ ]]; then
  echo "Refusing to run real-stack smoke against non-test database: $DB_NAME" >&2
  exit 1
fi

DB_CLI="${BRVTAL_TEST_DB_CLI:-}"
if [[ -z "$DB_CLI" ]]; then
  if command -v mariadb >/dev/null 2>&1; then
    DB_CLI="mariadb"
  elif command -v mysql >/dev/null 2>&1; then
    DB_CLI="mysql"
  else
    echo "Neither mariadb nor mysql client is available." >&2
    exit 1
  fi
fi

mysql_root=("$DB_CLI" -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASS")
mysql_db=("$DB_CLI" -h"$DB_HOST" -P"$DB_PORT" -u"$DB_USER" -p"$DB_PASS" "$DB_NAME")

"${mysql_root[@]}" -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

sed \
  -e "s/CREATE DATABASE IF NOT EXISTS brvtal /CREATE DATABASE IF NOT EXISTS $DB_NAME /" \
  -e "s/USE brvtal;/USE $DB_NAME;/" \
  database/schema.sql | "${mysql_root[@]}"

"${mysql_db[@]}" < database/migration_content_core_01.sql
"${mysql_db[@]}" < database/migration_releases_01.sql
"${mysql_db[@]}" < database/migration_seo_01.sql
"${mysql_db[@]}" < database/migration_totp_foundation.sql
"${mysql_db[@]}" < database/migration_admin_activity_01.sql

ADMIN_HASH="$(php -r 'echo password_hash(getenv("BRVTAL_REAL_STACK_ADMIN_PASSWORD") ?: "brvtal-ci-password", PASSWORD_DEFAULT);')"
"${mysql_db[@]}" -e "INSERT INTO admins(email,password_hash,name,is_active,totp_enabled) VALUES ('$ADMIN_EMAIL','$ADMIN_HASH','CI Admin',1,0);"
"${mysql_db[@]}" -e "INSERT INTO artists(name,slug,bio,status,collective_status,collective_order) VALUES ('PL0N3R SMOKE','pl0n3r-smoke','Real-stack smoke artist','published','active',1),('DNL5 SMOKE','dnl5-smoke','Secondary smoke artist','published','active',2);"

mkdir -p storage/logs storage/rate_limits uploads
cat > config/config.php <<PHP
<?php
return [
    'app' => [
        'name' => 'BRVTAL',
        'base_url' => '$BASE_URL',
        'timezone' => 'America/Bogota',
        'debug' => false,
    ],
    'db' => [
        'host' => '$DB_HOST',
        'port' => $DB_PORT,
        'name' => '$DB_NAME',
        'user' => '$DB_USER',
        'pass' => '$DB_PASS',
        'charset' => 'utf8mb4',
    ],
    'security' => [
        'session_name' => 'BRVTAL_ADMIN_CI',
        'csrf_key' => 'ci-only-csrf-key-9c7bff9-real-stack-smoke',
        'encryption_key' => 'ci-only-encryption-key-32-bytes-minimum-real-stack',
    ],
    'hosting' => [
        'storage_quota_bytes' => 25 * 1024 * 1024 * 1024,
    ],
    'backups' => [
        'media_archive_max_bytes' => 2 * 1024 * 1024 * 1024,
    ],
];
PHP

cleanup() {
  local status=$?
  if [[ -n "${PHP_PID:-}" ]]; then kill "$PHP_PID" >/dev/null 2>&1 || true; fi
  rm -f config/config.php
  if [[ $status -ne 0 && -f "$PHP_LOG" ]]; then
    echo "--- PHP server log ---" >&2
    cat "$PHP_LOG" >&2 || true
  fi
  exit "$status"
}
trap cleanup EXIT INT TERM

php -S 127.0.0.1:4174 -t . >"$PHP_LOG" 2>&1 &
PHP_PID=$!

ready=0
for _ in $(seq 1 40); do
  if curl -fsS "$BASE_URL/api/index.php/health" >/dev/null 2>&1; then
    ready=1
    break
  fi
  if ! kill -0 "$PHP_PID" >/dev/null 2>&1; then
    echo "PHP development server exited before becoming ready." >&2
    exit 1
  fi
  sleep 0.25
done

if [[ "$ready" != "1" ]]; then
  echo "Timed out waiting for BRVTAL real-stack test server." >&2
  exit 1
fi

export BRVTAL_REAL_STACK_URL="$BASE_URL"
export BRVTAL_REAL_STACK_ADMIN_EMAIL="$ADMIN_EMAIL"
export BRVTAL_REAL_STACK_ADMIN_PASSWORD="$ADMIN_PASSWORD"
npx playwright test \
  tests/e2e/content-core-real-stack.spec.mjs \
  tests/e2e/event-publication-invariant-real-stack.spec.mjs \
  tests/e2e/blog-relation-integrity-real-stack.spec.mjs \
  --project=chromium
