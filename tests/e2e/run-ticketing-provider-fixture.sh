#!/usr/bin/env bash
set -euo pipefail

if [[ "${1:-}" != '--inside' ]]; then
  [[ "${GITHUB_ACTIONS:-}" == 'true' || "${BRVTAL_T31_FIXTURE_LOCAL:-}" == '1' ]] || {
    echo 'T31 fixture requires CI or explicit isolated local opt-in' >&2; exit 1;
  }
  command -v docker >/dev/null || { echo 'Docker unavailable: fail closed' >&2; exit 1; }
  docker info >/dev/null || { echo 'Docker daemon unavailable: fail closed' >&2; exit 1; }
  [[ -f config/ticketing_wompi_webhook.php ]] || exit 1
  scratch="$(mktemp -d)"
  image="brvtal-t31-fixture-${GITHUB_RUN_ID:-local}-$$"
  cleanup() { docker image rm "$image" >/dev/null 2>&1 || true; rm -rf "$scratch"; }
  trap cleanup EXIT
  cat > "$scratch/Dockerfile" <<'DOCKERFILE'
FROM php:8.5-cli-bookworm
RUN apt-get update && apt-get install -y --no-install-recommends nodejs openssl libcurl4-openssl-dev ca-certificates \
    && docker-php-ext-install curl && rm -rf /var/lib/apt/lists/*
DOCKERFILE
  # Downloads happen only in image preparation, before any fixture test starts.
  docker build --pull -q -t "$image" "$scratch" >/dev/null
  docker run --rm --network none --read-only --security-opt no-new-privileges \
    --pids-limit 128 --memory 512m --cpus 2 \
    --add-host sandbox.wompi.co:127.0.0.1 --add-host wrong-host.invalid:127.0.0.1 \
    -e HTTPS_PROXY= -e HTTP_PROXY= -e ALL_PROXY= -e NO_PROXY='*' \
    -e https_proxy= -e http_proxy= -e all_proxy= -e no_proxy='*' \
    --mount "type=bind,source=$PWD,target=/app,readonly" \
    --mount "type=bind,source=$scratch,target=/fixture" \
    --tmpfs /tmp:rw,mode=1777 -w /app "$image" \
    bash /app/tests/e2e/run-ticketing-provider-fixture.sh --inside
  exit 0
fi

# Inside a dedicated network namespace with loopback only. No Docker socket,
# network gateway, host DNS, writable source tree or trusted host CA is mounted.
if awk 'NR>1 && $2=="00000000" {found=1} END {exit !found}' /proc/net/route; then
  echo 'T31: unexpected default network route' >&2; exit 1
fi
umask 077
openssl req -x509 -newkey rsa:2048 -sha256 -nodes -days 1 \
  -subj '/CN=T31 Ephemeral CA' -keyout /fixture/ca.key -out /fixture/ca.pem >/dev/null 2>&1
openssl req -newkey rsa:2048 -sha256 -nodes -subj '/CN=sandbox.wompi.co' \
  -keyout /fixture/server.key -out /fixture/server.csr >/dev/null 2>&1
printf 'subjectAltName=DNS:sandbox.wompi.co,DNS:localhost,IP:127.0.0.1\n' > /fixture/san.ext
openssl x509 -req -in /fixture/server.csr -CA /fixture/ca.pem -CAkey /fixture/ca.key \
  -CAcreateserial -days 1 -sha256 -extfile /fixture/san.ext -out /fixture/server.pem >/dev/null 2>&1
openssl req -x509 -newkey rsa:2048 -nodes -days 1 -subj '/CN=Wrong CA' \
  -keyout /fixture/wrong.key -out /fixture/wrong-ca.pem >/dev/null 2>&1
cat > /fixture/config.php <<'PHP'
<?php
return ['app' => ['base_url' => 'https://localhost:4443', 'timezone' => 'America/Bogota'],
        'db' => ['host' => '127.0.0.1', 'port' => 3306, 'name' => 'brvtal_test_t31',
                 'user' => 'fixture', 'pass' => 'fixture']];
PHP
cat > /fixture/router.php <<'PHP'
<?php
// Test-only bootstrap config; no repository or production config is modified.
define('BRVTAL_SENTRY_TESTING', true);
$GLOBALS['brvtalBootstrapConfigPath'] = '/fixture/config.php';
// Ephemeral TLS terminator router; only TLS requests acquire HTTPS=on.
$_SERVER['HTTPS'] = ($_SERVER['HTTP_X_FIXTURE_TLS'] ?? '') === '1' ? 'on' : 'off';
if (parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) === '/api/ticketing/webhook-wompi.php') {
    $_SERVER['SCRIPT_FILENAME'] = '/app/api/ticketing/webhook-wompi.php';
    require $_SERVER['SCRIPT_FILENAME'];
    return true;
}
http_response_code(404);
PHP
cat > /fixture/assert.php <<'PHP'
<?php
declare(strict_types=1);
require '/app/config/ticketing_wompi_webhook.php';
$mode = getenv('FIXTURE_CASE');
$id = getenv('FIXTURE_ID');
$settings = [
    'apiBase' => getenv('FIXTURE_BASE') ?: 'https://sandbox.wompi.co/v1',
    'private' => getenv('FIXTURE_PRIVATE') ?: 'prv_test_fixture_only_123456',
    'mode' => 'sandbox',
];
if ($mode === 'valid') {
    $value = brvtal_wompi_hook_lookup($settings, $id);
    if ($value['id'] !== 'tx-valid' || $value['status'] !== 'APPROVED'
        || $value['currency'] !== 'COP' || $value['amount_in_cents'] !== 12300
        || $value['reference'] !== 'BW7-1-CARD-' . str_repeat('a', 24)) {
        throw new LogicException('Provider record was not independent and canonical');
    }
} elseif ($mode === 'mismatch') {
    $value = brvtal_wompi_hook_lookup($settings, $id);
    // Real application verifier checks provider fields before touching PDO.
    $signed = [
        'id' => 'tx-conflict', 'status' => 'APPROVED', 'currency' => 'COP',
        'amount_in_cents' => 12300,
        'reference' => 'BW7-1-CARD-' . str_repeat('a', 24),
    ];
    class NoDatabase extends PDO { public function __construct() {} }
    try {
        brvtal_wompi_hook_apply(new NoDatabase(), $signed, $value);
        throw new LogicException('Conflicting provider record accepted');
    } catch (DomainException $exception) {
        if ($exception->getMessage() !== 'WEBHOOK_PROVIDER_MISMATCH') throw $exception;
    }
} else {
    try {
        brvtal_wompi_hook_lookup($settings, $id);
        throw new LogicException('Provider was unexpectedly accepted');
    } catch (RuntimeException $exception) {
        if ($exception->getMessage() !== 'WEBHOOK_PROVIDER_UNAVAILABLE') throw $exception;
    }
}
echo "T31_CASE_PASS:$mode\n";
PHP
php -S 127.0.0.1:8080 -t /app /fixture/router.php >/fixture/php.log 2>&1 & php_pid=$!
node /app/tests/e2e/wompi-provider-fixture.mjs >/fixture/provider.log 2>&1 & node_pid=$!
cleanup_inside() { kill "$node_pid" "$php_pid" 2>/dev/null || true; }
trap cleanup_inside EXIT
ready=0
for _ in $(seq 1 30); do
  code="$(curl --noproxy '*' -sS --connect-timeout 1 --cacert /fixture/ca.pem \
    -o /dev/null -w '%{http_code}' 'https://localhost:4443/api/ticketing/webhook-wompi.php' 2>/dev/null || true)"
  [[ "$code" == 405 ]] && { ready=1; break; }
  sleep 0.2
done
[[ "$ready" == 1 ]] || { echo 'T31: local HTTPS application unavailable' >&2; exit 1; }
code="$(curl --noproxy '*' -sS --cacert /fixture/ca.pem -o /dev/null -w '%{http_code}' \
  -X POST -H 'Content-Type: text/plain' 'https://localhost:4443/api/ticketing/webhook-wompi.php')"
[[ "$code" == 400 ]] || { echo 'T31: HTTPS webhook guard not exercised' >&2; exit 1; }
run_case() { local mode="$1" id="$2" ca="${3:-/fixture/ca.pem}" base="${4:-https://sandbox.wompi.co/v1}" private="${5:-prv_test_fixture_only_123456}";
  FIXTURE_CASE="$mode" FIXTURE_ID="$id" FIXTURE_BASE="$base" FIXTURE_PRIVATE="$private" \
    php -d "curl.cainfo=$ca" /fixture/assert.php;
}
run_case valid tx-valid
run_case mismatch tx-conflict
run_case reject tx-absent
run_case reject tx-malformed
run_case reject tx-unavailable
run_case reject tx-timeout
run_case reject tx-valid /fixture/wrong-ca.pem
run_case reject tx-valid /fixture/ca.pem https://wrong-host.invalid/v1
run_case reject tx-valid /fixture/ca.pem https://192.0.2.1/v1
run_case reject tx-valid /fixture/ca.pem https://sandbox.wompi.co/v1 invalid_test_auth
[[ -s /fixture/audit.jsonl ]] || { echo 'T31: no provider evidence' >&2; exit 1; }
grep -q '"id":"tx-valid","status":200' /fixture/audit.jsonl || exit 1
grep -q '"id":"tx-conflict","status":200' /fixture/audit.jsonl || exit 1
if grep -Eq 'Authorization|Bearer|prv_test_|test_events_|INTEGRITY_SECRET' /fixture/audit.jsonl; then
  echo 'T31: credential leaked to audit' >&2; exit 1
fi
echo 'T31_PROVIDER_FIXTURE_PASS: isolated network, verified TLS and hostname, independent provider records, real PHP lookup, local HTTPS endpoint, negative cases; zero external egress'
