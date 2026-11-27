#!/usr/bin/env bash
set -euo pipefail
umask 077
if [[ "${1:-}" != --inside ]]; then
  [[ $# == 0 ]] || exit 1
  [[ "${GITHUB_ACTIONS:-}" == true || "${BRVTAL_T31_FIXTURE_LOCAL:-}" == 1 ]] || exit 1
  command -v docker >/dev/null && docker info >/dev/null
  scratch="$(mktemp -d)"
  image="brvtal-t31-stack-${GITHUB_RUN_ID:-local}-$$"
  trap 'docker image rm "$image" >/dev/null 2>&1 || true; rm -rf "$scratch"' EXIT
  # Export only tracked source, not local config, .env, Git credentials or uploads.
  mkdir "$scratch/source"
  git archive HEAD config api database ops templates partials scripts index.php tests/e2e \
    discadmin/ticketing-door.php discadmin/ticketing-door.js discadmin/ticketing-door.css | tar -x -C "$scratch/source"
  [[ ! -e "$scratch/source/config/config.php" ]] || exit 1
  promo_path=tests/e2e/ticketing-promos-real-stack.spec.mjs
  tracked_promo="$(git ls-tree --name-only HEAD -- "$promo_path")"
  scenarios=(checkout)
  if [[ -n "$tracked_promo" ]]; then
    [[ "$tracked_promo" == "$promo_path" && -f "$scratch/source/$promo_path" && ! -L "$scratch/source/$promo_path" ]] || exit 1
    scenarios+=(promo)
  else
    echo 'T31_PROMO_STACK_NOT_PRESENT: no promo evidence'
  fi
  if [[ "${BRVTAL_T31_RECOVERY_REQUIRED:-}" == 1 ]]; then scenarios+=(resend); fi
  cat > "$scratch/Dockerfile" <<'DOCKERFILE'
FROM node:22-bookworm-slim AS browser
RUN npm install --prefix /opt/t31-browser --ignore-scripts --no-audit --no-fund --save-exact playwright-core@1.63.0
FROM php:8.5-cli-bookworm
COPY --from=browser /usr/local/bin/node /usr/local/bin/node
COPY --from=browser /opt/t31-browser /opt/t31-browser
RUN apt-get update && apt-get install -y --no-install-recommends mariadb-server mariadb-client openssl libcurl4-openssl-dev libonig-dev ca-certificates chromium libnss3-tools \
    && docker-php-ext-install curl pdo_mysql mbstring && rm -rf /var/lib/apt/lists/*
DOCKERFILE
  docker build --pull -q -t "$image" "$scratch" >/dev/null
  for scenario in "${scenarios[@]}"; do
    docker run --rm --network none --read-only --security-opt no-new-privileges \
      --pids-limit 384 --memory 2048m --cpus 2 \
      --add-host sandbox.wompi.co:127.0.0.1 \
      --mount "type=bind,source=$scratch/source,target=/source,readonly" \
      --tmpfs /tmp:rw,exec,mode=1777 --tmpfs /fixture:rw,mode=0700 \
      -e HTTPS_PROXY= -e HTTP_PROXY= -e ALL_PROXY= -e NO_PROXY='*' \
      -e https_proxy= -e http_proxy= -e all_proxy= -e no_proxy='*' \
      -e BRVTAL_T31_ISOLATED=1 "$image" bash /source/tests/e2e/run-ticketing-real-stack.sh --inside "$scenario"
  done
  echo 'T31_CHECKOUT_STACK_PASS: HTTP checkout, signed payment, MariaDB, worker, mail, account and negative cases'
  exit 0
fi
# No external DB parameters are accepted. Validate isolation before any DB connection.
[[ $# == 2 && "$1" == --inside ]] || exit 1
case "$2" in
  checkout)
    spec=tests/e2e/ticketing-checkout-real-stack.spec.mjs
    test_name='T31 real checkout, payment, issuance, mail and buyer isolation'
    ;;
  resend)
    spec=tests/e2e/ticketing-checkout-real-stack.spec.mjs
    test_name='T31 real checkout, payment, issuance, mail and buyer isolation'
    ;;
  promo)
    spec=tests/e2e/ticketing-promos-real-stack.spec.mjs
    test_name='T31 real promo checkout net payment and idempotent issuance'
    ;;
  *) exit 1 ;;
esac
[[ "${BRVTAL_T31_ISOLATED:-}" == 1 && -f /.dockerenv && -d /source && -d /fixture ]] || exit 1
[[ "$(ls /sys/class/net)" == lo ]] || exit 1
if awk 'NR>1 && $2=="00000000" {found=1} END {exit !found}' /proc/net/route; then exit 1; fi
export DB_NAME="brvtal_test_t31_$(openssl rand -hex 6)"
[[ "$DB_NAME" =~ ^brvtal_test_t31_[a-f0-9]{12}$ ]] || exit 1
# Never inherit the synthetic promo switch from the host.
unset BRVTAL_INTEGRATION_TESTS BRVTAL_T31_RECOVERY
if [[ "$2" == resend ]]; then export BRVTAL_T31_RECOVERY=1; fi
if [[ "$2" == promo ]]; then
  export BRVTAL_INTEGRATION_TESTS=1
fi
[[ -f "/source/$spec" && ! -L "/source/$spec" ]] || exit 1
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
for migration in content_core_01 releases_01 seo_01 integration_secrets_01 ticketing_orders_01 buyer_auth_tokens_01 \
  ticketing_reservations_01 ticketing_stages_01 ticketing_promos_01 ticketing_waitlist_01 \
  ticketing_entries_01 ticket_entry_secret_01 ticketing_webhook_01 ticketing_refund_events_01 \
  ticketing_mail_outbox_01 ticket_mail_html_01 mail_templates_01 ticketing_mail_history_01 totp_foundation; do
  "${root[@]}" "$DB_NAME" < "database/migration_$migration.sql"
done
if [[ "$2" == resend ]]; then
  "${root[@]}" "$DB_NAME" < database/migration_ticketing_order_notes_01.sql
fi
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
if (getenv('BRVTAL_T31_RECOVERY') === '1') {
    $pdo->exec("UPDATE ticket_buyers SET consent_version='synthetic',consent_recorded_at=UTC_TIMESTAMP(6)");
}
// Staff identities only; every ticket and credential still originates from checkout.
$staff=$pdo->prepare('INSERT INTO admins(email,password_hash,name,staff_role,is_active) VALUES(?,?,?,?,1)');
$roles=['door-a'=>'door','door-b'=>'door','no-door'=>'viewer'];
if (getenv('BRVTAL_T31_RECOVERY') === '1') $roles+=['support-a'=>'superadmin','support-b'=>'superadmin'];
foreach ($roles as $name=>$role) {
    $staff->execute([$name.'@example.test',password_hash('T31 staff fixture only!',PASSWORD_DEFAULT),$name,$role]);
}
PHP
openssl req -x509 -newkey rsa:2048 -sha256 -nodes -days 1 -subj '/CN=T31 Ephemeral CA' -keyout /fixture/ca.key -out /fixture/ca.pem >/dev/null 2>&1
openssl req -newkey rsa:2048 -sha256 -nodes -subj '/CN=sandbox.wompi.co' -keyout /fixture/server.key -out /fixture/server.csr >/dev/null 2>&1
printf 'subjectAltName=DNS:sandbox.wompi.co,DNS:localhost,IP:127.0.0.1\n' > /fixture/san.ext
openssl x509 -req -in /fixture/server.csr -CA /fixture/ca.pem -CAkey /fixture/ca.key -CAcreateserial \
  -days 1 -sha256 -extfile /fixture/san.ext -out /fixture/server.pem >/dev/null 2>&1
# Trust only the ephemeral CA in the isolated browser profile; never ignore TLS errors.
export HOME=/tmp/t31-browser-home
mkdir -p "$HOME/.pki/nssdb"
certutil -N --empty-password -d "sql:$HOME/.pki/nssdb"
certutil -A -d "sql:$HOME/.pki/nssdb" -n T31-fixture-CA -t 'C,,' -i /fixture/ca.pem
cat > /fixture/router.php <<'PHP'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($_SERVER['REMOTE_ADDR'] !== '127.0.0.1' || ($_SERVER['HTTP_X_FIXTURE_TLS'] ?? '') !== '1') {
    http_response_code(403); exit;
}
$_SERVER['HTTPS']='on';
if ($path === '/t31-door-fixture') {
    header('Content-Type: text/html; charset=utf-8');
    echo <<<'HTML'
<!doctype html><html lang="es"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/discadmin/ticketing-door.css"><main id="fixture"></main>
<script type="module">
import {mount} from '/discadmin/ticketing-door.js';
const fragment = await fetch('/discadmin/ticketing-door.php', {headers:{'X-Brvtal-Admin-Fragment':'1'}});
if (!fragment.ok) throw new Error('door fixture unavailable');
document.getElementById('fixture').innerHTML = await fragment.text();
mount(document.querySelector('[data-door]'));
</script></html>
HTML;
    exit;
}
$assets=['/discadmin/ticketing-door.js'=>'text/javascript','/discadmin/ticketing-door.css'=>'text/css'];
if (isset($assets[$path])) {
    header('Content-Type: '.$assets[$path]);
    readfile('/tmp/app'.$path); exit;
}
$routes=['/index.php','/api/buyer/auth.php','/api/buyer/account.php','/api/ticketing/webhook-wompi.php',
    '/api/index.php/auth','/api/ticketing/checkin.php','/discadmin/ticketing-door.php'];
if (getenv('BRVTAL_T31_RECOVERY') === '1') $routes=array_merge($routes,
    ['/api/ticketing/admin-actions.php','/api/ticketing/admin-entries.php']);
if (!in_array($path,$routes,true)) {
    http_response_code(404); exit;
}
$script=$path==='/api/index.php/auth'?'/api/index.php':$path;
$_SERVER['SCRIPT_NAME']=$script;
$_SERVER['SCRIPT_FILENAME']='/tmp/app'.$script;
require $_SERVER['SCRIPT_FILENAME'];
PHP
# Real cycle and real mail adapter, with only its outbound transport captured.
cat > /fixture/worker.php <<'PHP'
<?php
require_once '/tmp/app/config/bootstrap.php';
require_once '/tmp/app/ops/ticketing-mail-worker.php';
require_once '/tmp/app/config/ticketing_mail_outbox.php';
require_once '/tmp/app/config/ticketing_wompi_reconcile.php';
require_once '/tmp/app/config/ticketing_issue_delivery.php';
$cycle=brvtal_ticket_mail_worker_cycle(
    static fn()=>['pdo'=>db(),'gate'=>brvtal_ticket_mail_worker_gate(brvtal_secret_value(...))],
    static fn($pdo)=>brvtal_wompi_reconcile_next($pdo),
    static fn($pdo)=>brvtal_ticket_issue_process_next($pdo,'https://brvtal.invalid'),
    static fn($pdo)=>brvtal_ticket_mail_deliver_one($pdo,static function($payload,$headers) {
        $keys=array_values(array_filter($headers, static fn($line)=>str_starts_with($line,'Idempotency-Key: ')));
        if (count($keys)!==1) throw new RuntimeException('FIXTURE_MAIL_HEADER');
        $key=substr($keys[0],strlen('Idempotency-Key: '));
        file_put_contents('/fixture/mail.jsonl',json_encode(['key'=>$key,'payload'=>$payload],JSON_THROW_ON_ERROR)."\n",FILE_APPEND|LOCK_EX);
        return ['status'=>200,'body'=>json_encode(['id'=>'fixture-'.hash('sha256',$key)])];
    })
);
echo json_encode($cycle['output'],JSON_THROW_ON_ERROR);
exit($cycle['exit_code']);
PHP
PHP_CLI_SERVER_WORKERS=4 php -d curl.cainfo=/fixture/ca.pem -d session.save_path=/tmp/sessions -S 127.0.0.1:8080 -t /tmp/app /fixture/router.php >/fixture/php.log 2>&1 &
php_pid=$!
node tests/e2e/wompi-provider-fixture.mjs >/fixture/provider.log 2>&1 &
provider_pid=$!
# Keep raw TAP and stderr inside the disposable container (no QR or cookies in CI).
if ! node --test --test-reporter=tap "$spec" >/fixture/test.tap 2>&1; then
  echo "T31_SCENARIO_FAILED: $2" >&2
  exit 1
fi
# A file with zero registered tests, or a skipped test, can exit zero.
if ! grep -Fxq "ok 1 - $test_name" /fixture/test.tap; then
  echo "T31_SCENARIO_RECEIPT_MISSING: $2" >&2
  exit 1
fi
if [[ "$2" == checkout ]]; then
  grep -Fxq '# T31_CHECKIN_STACK_PASS: issued QR, real door, concurrent audit, mobile negatives' /fixture/test.tap || {
    echo 'T31_CHECKIN_RECEIPT_MISSING' >&2; exit 1;
  }
  echo 'T31_CHECKIN_STACK_PASS: issued QR, real door, concurrent audit, mobile negatives'
fi
if [[ "$2" == resend ]]; then
  grep -Fxq '# T31_RESEND_STACK_PASS: authenticated recovery, concurrent rotation, immutable mail and single admission' /fixture/test.tap || exit 1
  echo 'T31_RESEND_STACK_PASS: authenticated recovery, concurrent rotation, immutable mail and single admission'
fi
echo "T31_SCENARIO_PASS: $2"
