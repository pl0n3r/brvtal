#!/usr/bin/env bash
set -euo pipefail

if [[ "${BRVTAL_INTEGRATION_TESTS:-}" != "1" ]]; then
  echo "BRVTAL Artist membership migration integration skipped. Set BRVTAL_INTEGRATION_TESTS=1 to run."
  exit 0
fi

host="${BRVTAL_TEST_DB_HOST:-127.0.0.1}"
port="${BRVTAL_TEST_DB_PORT:-3306}"
user="${BRVTAL_TEST_DB_USER:-root}"
pass="${BRVTAL_TEST_DB_PASS:-}"
base="${BRVTAL_TEST_DB_NAME:-}"
if [[ ! "$base" =~ ^brvtal_test[a-zA-Z0-9_]*$ ]]; then
  echo "Refusing membership migration test against non-test database: $base" >&2
  exit 1
fi

db="${base}_membership"
cli=(mysql -h"$host" -P"$port" -u"$user")
if [[ -n "$pass" ]]; then
  cli+=("-p$pass")
fi
cleanup() {
  "${cli[@]}" -e "DROP DATABASE IF EXISTS \`$db\`" >/dev/null 2>&1 || true
}
trap cleanup EXIT

"${cli[@]}" -e "DROP DATABASE IF EXISTS \`$db\`; CREATE DATABASE \`$db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
"${cli[@]}" "$db" -e "
CREATE TABLE artists (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(180) NOT NULL,
  status ENUM('draft','published') NOT NULL DEFAULT 'draft',
  collective_status ENUM('none','active','alumni') NOT NULL DEFAULT 'none',
  collective_order INT NOT NULL DEFAULT 0,
  collective_joined_at DATETIME NULL,
  collective_left_at DATETIME NULL,
  sort_order INT NOT NULL DEFAULT 0
);
INSERT INTO artists(name,collective_status,sort_order) VALUES
  ('ACTIVE LEGACY','active',1),
  ('ALUMNI LEGACY','alumni',2),
  ('NETWORK LEGACY','none',3);
"

"${cli[@]}" "$db" < database/migration_artist_collective_membership_01.sql

mapped=$("${cli[@]}" -N -B "$db" -e "SELECT GROUP_CONCAT(CONCAT(name,':',is_collective_member) ORDER BY id SEPARATOR '|') FROM artists;")
[[ "$mapped" == "ACTIVE LEGACY:1|ALUMNI LEGACY:0|NETWORK LEGACY:0" ]]

index_count=$("${cli[@]}" -N -B "$db" -e "SELECT COUNT(DISTINCT INDEX_NAME) FROM information_schema.STATISTICS WHERE TABLE_SCHEMA='$db' AND TABLE_NAME='artists' AND INDEX_NAME='idx_artists_collective_member';")
[[ "$index_count" == "1" ]]

# Direct reruns must not re-derive state from legacy columns after the boolean
# becomes authoritative.
"${cli[@]}" "$db" -e "UPDATE artists SET is_collective_member=1 WHERE name='ALUMNI LEGACY';"
"${cli[@]}" "$db" < database/migration_artist_collective_membership_01.sql
preserved=$("${cli[@]}" -N -B "$db" -e "SELECT is_collective_member FROM artists WHERE name='ALUMNI LEGACY';")
[[ "$preserved" == "1" ]]

echo "BRVTAL Artist membership migration integration passed."
