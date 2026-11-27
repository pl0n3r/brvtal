#!/usr/bin/env bash
set -euo pipefail
umask 077
[[ "$GITHUB_ACTIONS" == true && "$TARGET_HEAD" =~ ^[a-f0-9]{40}$ && "$TARGET_BASE" =~ ^[a-f0-9]{40}$ ]]
[[ "$(git rev-parse HEAD)" == "$TARGET_HEAD" && ! -e config/config.php && ! -L config/config.php ]]
fixture=tests/e2e/finance-expenses-http-fixture.spec.mjs
[[ ! -e "$fixture" && ! -L "$fixture" ]]
BASELINE_SHA="$(sed -n "s/^const baseline='\([a-f0-9]\{40\}\)';$/\1/p" tests/e2e/discadmin-ticketing-finance-expenses.spec.mjs)"
[[ "$BASELINE_SHA" =~ ^[a-f0-9]{40}$ ]]
git merge-base --is-ancestor "$BASELINE_SHA" "$TARGET_HEAD"
export BASELINE_SHA
export PLAYWRIGHT_JSON_OUTPUT_FILE="$RUNNER_TEMP/finance-2191-report.json"
npx --no-install playwright test tests/e2e/discadmin-ticketing-finance-expenses.spec.mjs --project=chromium --workers=1 --reporter=list,json
mkdir -p artifacts/issue-2191
python3 - <<'PY'
import base64, hashlib, json, os, struct
from pathlib import Path
root = Path.cwd()
out = root / 'artifacts/issue-2191'
report = json.loads(Path(os.environ['PLAYWRIGHT_JSON_OUTPUT_FILE']).read_text())
def specs(node):
    yield from node.get('specs', [])
    for child in node.get('suites', []):
        yield from specs(child)
all_specs = list(specs(report))
manifest = {'issue': 2191, 'pr': 2305, 'head': os.environ['TARGET_HEAD'],
            'target_base': os.environ['TARGET_BASE'], 'before': os.environ['BASELINE_SHA'],
            'run': os.environ['GITHUB_RUN_ID'], 'screenshots': []}
for width in (390, 1440):
    matches = [s for s in all_specs if s['title'] == f'2191 libro de gastos y presupuesto {width} px']
    assert len(matches) == 1 and len(matches[0]['tests']) == 1
    result = matches[0]['tests'][0]['results'][-1]
    assert result['status'] == 'passed'
    for phase in ('before', 'after'):
        name = f'2191-{phase}-{width}.png'
        attachments = [a for a in result['attachments'] if a['name'] == name]
        assert len(attachments) == 1 and attachments[0]['contentType'] == 'image/png'
        a = attachments[0]
        if 'body' in a:
            data = base64.b64decode(a['body'], validate=True)
        else:
            path = Path(a['path']).resolve(strict=True)
            assert path.is_relative_to(root) and path.is_file()
            data = path.read_bytes()
        assert 24 <= len(data) <= 10_000_000 and data[:8] == b'\x89PNG\r\n\x1a\n'
        w, h = struct.unpack('>II', data[16:24])
        assert w == width and h >= 400
        (out / name).write_bytes(data)
        manifest['screenshots'].append({'name': name, 'width': w, 'height': h,
                                        'sha256': hashlib.sha256(data).hexdigest()})
(out / 'manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
print('Four PNG attachments verified against exact historical and candidate sources.')
PY
# The only database accepted is the disposable service in this workflow.
DB_NAME=brvtal_test_finance_2191
export DB_NAME
db_cli=$(command -v mariadb || command -v mysql)
db=("$db_cli" --no-defaults --protocol=TCP -h127.0.0.1 -P3306 -uroot -pbrvtal_root)
cleanup() {
  [[ -z "${php_pid:-}" ]] || kill "$php_pid" 2>/dev/null || true
  rm -f config/config.php "$fixture"
}
trap cleanup EXIT
sed -e "s/^CREATE DATABASE IF NOT EXISTS brvtal /CREATE DATABASE IF NOT EXISTS $DB_NAME /" \
    -e "s/^USE brvtal;/USE $DB_NAME;/" database/schema.sql | "${db[@]}"
for migration in content_core_01 releases_01 seo_01 integration_secrets_01 totp_foundation admin_activity_01 ticketing_orders_01 ticketing_finance_expenses_01; do
  "${db[@]}" "$DB_NAME" < "database/migration_$migration.sql"
done
mkdir -p storage/logs storage/rate_limits "$RUNNER_TEMP/finance-sessions"
cat > config/config.php <<'PHP'
<?php
return [
 'app'=>['name'=>'BRVTAL','base_url'=>'http://127.0.0.1:4174','timezone'=>'America/Bogota','debug'=>false],
 'db'=>['host'=>'127.0.0.1','port'=>3306,'name'=>'brvtal_test_finance_2191','user'=>'root','pass'=>'brvtal_root','charset'=>'utf8mb4'],
 'security'=>['session_name'=>'BRVTAL_ADMIN_FINANCE_CI','csrf_key'=>str_repeat('c',32),'encryption_key'=>str_repeat('e',32)],
];
PHP
php <<'PHP'
<?php
require 'config/bootstrap.php';
$pdo=db();
$q=$pdo->prepare('INSERT INTO admins(id,email,password_hash,name,staff_role,is_active) VALUES(?,?,?,?,?,1)');
foreach ([6101=>'admin',6102=>'door'] as $id=>$role) {
    $q->execute([$id,$role.'-2191@example.test',password_hash('brvtal-fixture-2191',PASSWORD_DEFAULT),'Fixture '.$role,$role]);
}
$pdo->exec("INSERT INTO events(id,title,slug,status) VALUES(6101,'Evento fixture 2191','finance-2191','draft'),(6102,'Otro fixture','finance-2191-other','draft')");
PHP
export BRVTAL_TEST_DB_NAME="$DB_NAME" BRVTAL_MAIL_TESTING=1
export BRVTAL_MAIL_CAPTURE_FILE="$RUNNER_TEMP/finance-mail.json"
export BRVTAL_TICKET_WOMPI_MODE=off BRVTAL_TICKET_MAIL_MODE=disabled
php -d session.save_path="$RUNNER_TEMP/finance-sessions" -S 127.0.0.1:4174 -t . > "$RUNNER_TEMP/finance-php.log" 2>&1 &
php_pid=$!
for _ in $(seq 1 40); do
  if curl -fsS http://127.0.0.1:4174/api/index.php/auth >/dev/null; then break; fi
  sleep 0.25
done
curl -fsS http://127.0.0.1:4174/api/index.php/auth >/dev/null
cp ../wiring/tests/e2e/finance-expenses-http-fixture.mjs "$fixture"
unset PLAYWRIGHT_JSON_OUTPUT_FILE
if ! BRVTAL_FINANCE_2191_FIXTURE=1 npx playwright test "$fixture" --project=chromium --workers=1 --reporter=list; then
  tail -n 80 "$RUNNER_TEMP/finance-php.log"
  exit 1
fi
php <<'PHP'
<?php
require 'config/bootstrap.php';
$pdo=db();
function requireSame($actual,$expected) {
    if ($actual !== $expected) throw new RuntimeException('Persisted ledger/audit mismatch: '.json_encode($actual));
}
$expenses=$pdo->query('SELECT id,event_id,amount_cop,status,supersedes_id FROM event_expenses ORDER BY id')->fetchAll(PDO::FETCH_NUM);
requireSame(array_map(fn($r)=>array_map(fn($v)=>$v===null?null:(string)$v,$r),$expenses),[
 ['1','6101','45000','committed',null],['2','6101','50000','paid','1'],['3','6101','50000','void','2']]);
$budgets=$pdo->query('SELECT event_id,category,amount_cop,status FROM event_budgets')->fetchAll(PDO::FETCH_NUM);
requireSame(array_map(fn($r)=>array_map('strval',$r),$budgets),[['6101','produccion','90000','active']]);
$audit=$pdo->query("SELECT admin_id,action,resource,resource_id,meta_json FROM admin_activity_log WHERE resource IN ('event_expenses','event_budgets') ORDER BY id")->fetchAll(PDO::FETCH_NUM);
$actual=array_map(fn($r)=>[(int)$r[0],$r[1],$r[2],(int)$r[3],json_decode($r[4],true,512,JSON_THROW_ON_ERROR)],$audit);
requireSame($actual,[
 [6101,'create','event_expenses',1,['reason'=>'Registro fixture','previous_id'=>null]],
 [6101,'adjust','event_expenses',2,['reason'=>'Ajuste fixture','previous_id'=>1]],
 [6101,'create','event_budgets',1,['reason'=>'Presupuesto fixture','previous_id'=>null]],
 [6101,'void','event_expenses',3,['reason'=>'Anulación fixture','previous_id'=>2]]]);
echo "2191 REAL HTTP + DB + AUDIT PASS\n";
file_put_contents('artifacts/issue-2191/persistence.json',json_encode(['expenses'=>3,'budgets'=>1,'audit'=>4,'head'=>getenv('TARGET_HEAD'),'result'=>'passed'],JSON_THROW_ON_ERROR)."\n");
PHP
