#!/usr/bin/env bash
set -euo pipefail

if [[ "${BRVTAL_INTEGRATION_TESTS:-}" != "1" ]]; then
  echo "BRVTAL releases schema integration skipped. Set BRVTAL_INTEGRATION_TESTS=1 to run."
  exit 0
fi

host="${BRVTAL_TEST_DB_HOST:-127.0.0.1}"
port="${BRVTAL_TEST_DB_PORT:-3306}"
user="${BRVTAL_TEST_DB_USER:-root}"
pass="${BRVTAL_TEST_DB_PASS:-}"
base="${BRVTAL_TEST_DB_NAME:-}"

if [[ ! "$base" =~ ^brvtal_test[a-zA-Z0-9_]*$ ]]; then
  echo "RELEASES SCHEMA INTEGRATION FAILED: test database name must start with brvtal_test" >&2
  exit 1
fi

test_db="${base}_releases_schema"
mysql_args=(-h"$host" -P"$port" -u"$user")
if [[ -n "$pass" ]]; then
  mysql_args+=("-p$pass")
fi

cleanup() {
  mysql "${mysql_args[@]}" -e "DROP DATABASE IF EXISTS \`$test_db\`;" >/dev/null 2>&1 || true
}
trap cleanup EXIT

mysql "${mysql_args[@]}" -e "DROP DATABASE IF EXISTS \`$test_db\`; CREATE DATABASE \`$test_db\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# Simulate a legacy/pre-existing Releases schema: the table exists, but newer columns do not.
mysql "${mysql_args[@]}" "$test_db" <<'SQL'
CREATE TABLE artists (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name VARCHAR(180) NOT NULL,
  slug VARCHAR(190) NOT NULL,
  photo VARCHAR(500) NULL,
  status VARCHAR(30) NOT NULL DEFAULT 'draft'
) ENGINE=InnoDB;

CREATE TABLE releases (
  id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  title VARCHAR(180) NOT NULL,
  slug VARCHAR(190) NOT NULL
) ENGINE=InnoDB;

CREATE TABLE release_artists (
  release_id INT UNSIGNED NOT NULL,
  artist_id INT UNSIGNED NOT NULL,
  PRIMARY KEY (release_id, artist_id)
) ENGINE=InnoDB;
SQL

mysql "${mysql_args[@]}" "$test_db" < database/migration_releases_02.sql
mysql "${mysql_args[@]}" "$test_db" < database/migration_releases_02.sql

required=(release_type catalog_number release_date description seo_title seo_description artwork spotify_url soundcloud_url bandcamp_url youtube_url beatport_url status featured sort_order published_at created_at updated_at)
for column in "${required[@]}"; do
  count=$(mysql -N -B "${mysql_args[@]}" "$test_db" -e "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$test_db' AND TABLE_NAME='releases' AND COLUMN_NAME='$column';")
  [[ "$count" == "1" ]] || { echo "RELEASES SCHEMA INTEGRATION FAILED: missing releases.$column" >&2; exit 1; }
done

for column in role sort_order; do
  count=$(mysql -N -B "${mysql_args[@]}" "$test_db" -e "SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA='$test_db' AND TABLE_NAME='release_artists' AND COLUMN_NAME='$column';")
  [[ "$count" == "1" ]] || { echo "RELEASES SCHEMA INTEGRATION FAILED: missing release_artists.$column" >&2; exit 1; }
done

mysql "${mysql_args[@]}" "$test_db" <<'SQL'
INSERT INTO releases(title,slug,release_date,status) VALUES ('Schema Test','schema-test','2026-09-14','published');
UPDATE releases SET release_date='2026-10-01' WHERE slug='schema-test';
SQL

date_value=$(mysql -N -B "${mysql_args[@]}" "$test_db" -e "SELECT release_date FROM releases WHERE slug='schema-test';")
[[ "$date_value" == "2026-10-01" ]] || { echo "RELEASES SCHEMA INTEGRATION FAILED: release_date did not persist" >&2; exit 1; }

echo "BRVTAL Releases schema integration passed."
