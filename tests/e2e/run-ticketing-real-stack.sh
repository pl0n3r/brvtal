#!/usr/bin/env bash
set -euo pipefail
umask 077
if [[ "${1:-}" != --inside ]]; then
  [[ "${GITHUB_ACTIONS:-}" == true || "${BRVTAL_T31_FIXTURE_LOCAL:-}" == 1 ]] || exit 1
  command -v docker >/dev/null && docker info >/dev/null
  scratch="$(mktemp -d)"
  image="brvtal-t31-stack-${GITHUB_RUN_ID:-local}-$$"
  trap 'docker image rm "$image" >/dev/null 2>&1 || true; rm -rf "$scratch"' EXIT
  # Export only tracked source, not local config, .env, Git credentials or uploads.
  mkdir "$scratch/source"
  git archive HEAD config api database ops templates partials scripts index.php tests/e2e | tar -x -C "$scratch/source"
  [[ ! -e "$scratch/source/config/config.php" ]] || exit 1
  cat > "$scratch/Dockerfile" <<'DOCKERFILE'
FROM php:8.5-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends mariadb-server mariadb-client nodejs openssl libcurl4-openssl-dev libonig-dev ca-certificates \
    && docker-php-ext-install curl pdo_mysql mbstring && rm -rf /var/lib/apt/lists/*
DOCKERFILE
  docker build --pull -q -t "$image" "$scratch" >/dev/null
  docker run --rm --network none --read-only --security-opt no-new-privileges \
    --pids-limit 192 --memory 1536m --cpus 2 \
    --add-host sandbox.wompi.co:127.0.0.1 \
    --mount "type=bind,source=$scratch/source,target=/source,readonly" \
    --tmpfs /tmp:rw,exec,mode=1777 --tmpfs /fixture:rw,mode=0700 \
    -e HTTPS_PROXY= -e HTTP_PROXY= -e ALL_PROXY= -e NO_PROXY='*' \
    -e https_proxy= -e http_proxy= -e all_proxy= -e no_proxy='*' \
    -e BRVTAL_T31_ISOLATED=1 "$image" bash /source/tests/e2e/run-ticketing-real-stack.sh --inside
  exit 0
fi
# No external DB parameters are accepted. Validate isolation before any DB connection.
[[ "${BRVTAL_T31_ISOLATED:-}" == 1 && -f /.dockerenv && -d /source && -d /fixture ]] || exit 1
[[ "$(ls /sys/class/net)" == lo ]] || exit 1
if awk 'NR>1 && $2=="00000000" {found=1} END {exit !found}' /proc/net/route; then exit 1; fi
export DB_NAME="brvtal_test_t31_$(openssl rand -hex 6)"
[[ "$DB_NAME" =~ ^brvtal_test_t31_[a-f0-9]{12}$ ]] || exit 1
cp -a /source /tmp/app
cd /tmp/app
mkdir -p storage/logs storage/rate_limits storage/buyer_rate_limits /tmp/sessions /tmp/db
mariadb-install-db --no-defaults --datadir=/tmp/db --auth-root-authentication-method=normal --skip-test-db --user=root >/fixture/db-init.log 2>&1
mariadbd --no-defaults --user=root --datadir=/tmp/db --socket=/tmp/t31.sock --pid-file=/tmp/t31.pid \
  --bind-address=127.0.0.1 --port=3306 --skip-name-resolve >/fixture/db.log 2>&1 &
db_pid=$!
cleanup() {
  [[ -z "${php_pid:-}" ]] || kill "$php_pid" 2>/dev/null || true
  [[ -z "${provider_pid:-}" ]] || kill "$provider_pid" 2>/dev/null || true
  mariadb --no-defaults --socket=/tmp/t31.sock -uroot -e "DROP DATABASE IF EXISTS $DB_NAME" >/dev/null 2>&1 || true
  kill "$db_pid" 2>/dev/null || true
}
trap cleanup EXIT
for _ in $(seq 1 60); do
  mariadb-admin --no-defaults --socket=/tmp/t31.sock -uroot ping >/dev/null 2>&1 && break
  sleep 0.25
done
root=(mariadb --no-defaults --socket=/tmp/t31.sock -uroot)
"${root[@]}" -e "CREATE DATABASE $DB_NAME CHARACTER SET utf8mb4; CREATE USER 'fixture'@'127.0.0.1' IDENTIFIED BY 'fixture'; GRANT ALL ON $DB_NAME.* TO 'fixture'@'127.0.0.1';"
sed -e "s/CREATE DATABASE IF NOT EXISTS brvtal /CREATE DATABASE IF NOT EXISTS $DB_NAME /" \
    -e "s/^USE brvtal;/USE $DB_NAME;/" database/schema.sql | "${root[@]}"
# Real additive migrations; parents before their dependent tables.
for migration in content_core_01 seo_01 integration_secrets_01 ticketing_orders_01 buyer_auth_tokens_01 \
  ticketing_reservations_01 ticketing_stages_01 ticketing_promos_01 ticketing_waitlist_01 \
  ticketing_entries_01 ticket_entry_secret_01 ticketing_webhook_01 ticketing_refund_events_01 \
  ticketing_mail_outbox_01 ticket_mail_html_01 mail_templates_01 ticketing_mail_history_01; do
  "${root[@]}" "$DB_NAME" < "database/migration_$migration.sql"
done
cat > config/config.php <<'PHP'
<?php
return [
 'app'=>['base_url'=>'https://brvtal.invalid','timezone'=>'America/Bogota','debug'=>false],
 'db'=>['host'=>'127.0.0.1','port'=>3306,'name'=>getenv('DB_NAME'),'user'=>'fixture','pass'=>'fixture','charset'=>'utf8mb4'],
 'security'=>['csrf_key'=>str_repeat('c',32),'encryption_key'=>str_repeat('e',32),'integration_secret_master_key'=>str_repeat('a',64)],
];
PHP
export BRVTAL_TICKET_WOMPI_MODE=sandbox BRVTAL_TICKET_WOMPI_PUBLIC_KEY=pub_test_fixture_only
export BRVTAL_TICKET_WOMPI_PRIVATE_KEY=prv_test_fixture_only_123456
export BRVTAL_TICKET_WOMPI_INTEGRITY_SECRET=test_integrity_fixture_only BRVTAL_TICKET_WOMPI_EVENTS_SECRET=test_events_fixture_only
export BRVTAL_TICKET_WOMPI_ENABLED_METHODS=CARD BRVTAL_TICKET_WOMPI_RETURN_URL=https://brvtal.invalid/return
export BRVTAL_TICKET_MAIL_MODE=mock BRVTAL_TICKET_MAIL_TESTING=1 BRVTAL_TICKET_MAIL_API_KEY=fixture_only
export BRVTAL_TICKET_MAIL_DOMAIN=brvtal.invalid BRVTAL_TICKET_MAIL_FROM=test@brvtal.invalid
# Only event, stock and pre-existing verified synthetic buyers are seeded.
php <<'PHP'
<?php
require 'config/bootstrap.php';
$pdo=db();
$pdo->exec("INSERT INTO events(id,title,slug,event_date,status,published_at)
 VALUES(1,'T31 fixture','t31-fixture',DATE_ADD(NOW(),INTERVAL 5 DAY),'published',DATE_SUB(NOW(),INTERVAL 1 DAY))");
$pdo->exec("INSERT INTO event_ticket_types(id,event_id,name,price,currency,status) VALUES(1,1,'T31 entry',456,'COP','active')");
$pdo->exec('INSERT INTO ticket_inventory_events VALUES(1,1)');
$pdo->exec('INSERT INTO ticket_inventory_types(ticket_type_id,event_id,capacity) VALUES(1,1,1)');
$q=$pdo->prepare("INSERT INTO ticket_buyers(public_id,email,full_name,password_hash,email_verified_at,status)
 VALUES(RANDOM_BYTES(16),?,?,?,UTC_TIMESTAMP(6),'active')");
foreach (['buyer','other'] as $name) $q->execute([$name.'@example.test',$name,password_hash('T31 fixture password only!',PASSWORD_DEFAULT)]);
PHP
openssl req -x509 -newkey rsa:2048 -sha256 -nodes -days 1 -subj '/CN=T31 Ephemeral CA' -keyout /fixture/ca.key -out /fixture/ca.pem >/dev/null 2>&1
openssl req -newkey rsa:2048 -sha256 -nodes -subj '/CN=sandbox.wompi.co' -keyout /fixture/server.key -out /fixture/server.csr >/dev/null 2>&1
printf 'subjectAltName=DNS:sandbox.wompi.co,DNS:localhost,IP:127.0.0.1\n' > /fixture/san.ext
openssl x509 -req -in /fixture/server.csr -CA /fixture/ca.pem -CAkey /fixture/ca.key -CAcreateserial \
  -days 1 -sha256 -extfile /fixture/san.ext -out /fixture/server.pem >/dev/null 2>&1
cat > /fixture/router.php <<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($_SERVER['REMOTE_ADDR'] !== '127.0.0.1' || ($_SERVER['HTTP_X_FIXTURE_TLS'] ?? '') !== '1') {
    http_response_code(403); exit;
}
$_SERVER['HTTPS']='on';
if (!in_array($path, ['/index.php','/api/buyer/auth.php','/api/buyer/account.php','/api/ticketing/webhook-wompi.php'], true)) {
    http_response_code(404); exit;
}
$_SERVER['SCRIPT_FILENAME']='/tmp/app'.$path;
require $_SERVER['SCRIPT_FILENAME'];
PHP
# Real cycle and real mail adapter, with only its outbound transport captured.
cat > /fixture/worker.php <<'PHP'
<?php
require '/tmp/app/config/bootstrap.php';
require '/tmp/app/ops/ticketing-mail-worker.php';
require '/tmp/app/config/ticketing_mail_outbox.php';
require '/tmp/app/config/ticketing_wompi_reconcile.php';
require '/tmp/app/config/ticketing_issue_delivery.php';
$cycle=brvtal_ticket_mail_worker_cycle(
    static fn()=>['pdo'=>db(),'gate'=>brvtal_ticket_mail_worker_gate(brvtal_secret_value(...))],
    static fn($pdo)=>brvtal_wompi_reconcile_next($pdo),
    static fn($pdo)=>brvtal_ticket_issue_process_next($pdo,'https://brvtal.invalid'),
    static fn($pdo)=>brvtal_ticket_mail_deliver_one($pdo,static function($payload,$headers) {
        $key=$headers['Idempotency-Key'];
        file_put_contents('/fixture/mail.jsonl',json_encode(['key'=>$key,'payload'=>$payload],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
        return ['status'=>200,'body'=>json_encode(['id'=>'fixture-'.hash('sha256',$key)])];
    })
);
echo json_encode($cycle['output'],JSON_THROW_ON_ERROR);
exit($cycle['exit_code']);
PHP
php -d curl.cainfo=/fixture/ca.pem -d session.save_path=/tmp/sessions -S 127.0.0.1:8080 -t /tmp/app /fixture/router.php >/fixture/php.log 2>&1 &
php_pid=$!
node tests/e2e/wompi-provider-fixture.mjs >/fixture/provider.log 2>&1 &
provider_pid=$!
node tests/e2e/ticketing-checkout-real-stack.spec.mjs
