"""Behavioral and structural acceptance for BRVTAL #989 (synthetic inputs only)."""
import json
import os
import re
import sqlite3
from pathlib import Path
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]


def source(path):
    return (ROOT / path).read_text(encoding="utf-8")


class SetDurationContractTests(unittest.TestCase):
    def test_optional_duration_migration_and_schema_are_additive(self):
        schema = source("database/schema.sql")
        ddl = source("database/migration_set_duration_01.sql")
        sets_ddl = schema.split("CREATE TABLE sets_media (", 1)[1].split(") ENGINE=InnoDB;", 1)[0]
        self.assertIn("duration_seconds INT UNSIGNED NULL", sets_ddl)
        statements = [line.strip() for line in ddl.splitlines()
                      if line.strip() and not line.lstrip().startswith("--")]
        self.assertEqual(
            statements,
            ["ALTER TABLE sets_media ADD COLUMN duration_seconds INT UNSIGNED NULL AFTER description;"],
        )
        self.assertNotRegex(ddl, r"(?im)^\s*(?:DROP|TRUNCATE|DELETE|UPDATE)\b")
        self.assertNotRegex(ddl, r"(?i)\b(?:PREPARE|EXECUTE|DEALLOCATE)\b")
        reconciliation = source("config/migration_reconcile.php")
        self.assertIn("'column-unsigned-nullable:sets_media.duration_seconds'", reconciliation)
        self.assertIn("brvtalMigrationProofDisposition($name, $executionProof) !== 'apply'", reconciliation)
        self.assertIn("brvtalMigrationAssertReconciliationSql($name, $sql, $executionProof)", reconciliation)

    def test_public_api_returns_only_real_published_duration(self):
        public = source("api/public.php")
        query = public.split("FROM sets_media s", 1)[1].split(")->fetchAll();", 1)[0]
        self.assertIn("CASE WHEN s.duration_seconds BETWEEN 1 AND 86400", public)
        self.assertIn("ELSE NULL END AS duration_seconds", public)
        self.assertIn("WHERE s.status='published'", query)
        self.assertIn("brvtal_public_sanitize_set_relations($sets", public)
        self.assertNotIn("duration_seconds = 180", public)

        # Evaluate the production SQL projection on a synthetic in-memory fixture.
        # This verifies disclosure/range semantics, not MariaDB production readiness.
        projection = re.search(
            r"CASE WHEN s\.duration_seconds BETWEEN 1 AND 86400\s+"
            r"THEN s\.duration_seconds ELSE NULL END AS duration_seconds",
            public,
        )
        self.assertIsNotNone(projection)
        with sqlite3.connect(":memory:") as fixture:
            fixture.execute(
                "CREATE TABLE sets_media (id INTEGER PRIMARY KEY, status TEXT, duration_seconds INTEGER)"
            )
            fixture.executemany(
                "INSERT INTO sets_media(id, status, duration_seconds) VALUES(?, ?, ?)",
                [
                    (1, "published", None),
                    (2, "published", 59),
                    (3, "published", 86400),
                    (4, "published", 0),
                    (5, "published", 86401),
                    (6, "draft", 120),
                    (7, "published", -1),
                ],
            )
            rows = fixture.execute(
                f"SELECT {projection.group(0)} FROM sets_media s "
                "WHERE s.status='published' ORDER BY s.id"
            ).fetchall()
        self.assertEqual(rows, [(None,), (59,), (86400,), (None,), (None,), (None,)])

    def test_public_sets_survive_duration_migration_transition(self):
        public = source("api/public.php")
        branches = re.search(
            r"\$setDurationProjection\s*=\s*brvtal_public_set_duration_schema_ready\(\$pdo\)\s*"
            r"\?\s*'(?P<modern>[^']+)'\s*:\s*'(?P<legacy>[^']+)';",
            public,
        )
        self.assertIsNotNone(branches)
        self.assertIn("{$setDurationProjection},", public)
        self.assertEqual(branches.group("legacy"), "NULL AS duration_seconds")
        self.assertEqual(
            branches.group("modern"),
            "CASE WHEN s.duration_seconds BETWEEN 1 AND 86400 "
            "THEN s.duration_seconds ELSE NULL END AS duration_seconds",
        )
        # Synthetic pre-migration and post-migration database: no draft leak.
        with sqlite3.connect(":memory:") as fixture:
            fixture.execute("CREATE TABLE sets_media (id INTEGER PRIMARY KEY, status TEXT)")
            fixture.executemany(
                "INSERT INTO sets_media(id,status) VALUES (?,?)",
                [(1, "published"), (2, "draft")],
            )
            before = fixture.execute(
                f"SELECT {branches.group('legacy')} FROM sets_media s "
                "WHERE s.status='published' ORDER BY s.id"
            ).fetchall()
            self.assertEqual(before, [(None,)])
            fixture.execute("ALTER TABLE sets_media ADD COLUMN duration_seconds INTEGER")
            fixture.executemany(
                "UPDATE sets_media SET duration_seconds=? WHERE id=?",
                [(45, 1), (95, 2)],
            )
            after = fixture.execute(
                f"SELECT {branches.group('modern')} FROM sets_media s "
                "WHERE s.status='published' ORDER BY s.id"
            ).fetchall()
        self.assertEqual(after, [(45,)])


    def test_public_sets_reject_wrong_physical_duration_column(self):
        """A public Sets response must ignore absent or incompatible columns."""
        public = source("api/public.php")
        helper = "function brvtal_public_set_duration_schema_ready(" + public.split(
            "function brvtal_public_set_duration_schema_ready(", 1
        )[1].split("function brvtal_public_memories(", 1)[0]
        self.assertIn("information_schema.COLUMNS", helper)
        self.assertIn("brvtalMigrationDurationColumnMatches($column)", helper)
        if shutil.which("php") is None:
            self.skipTest("PHP CLI unavailable; exact CI must run this test")
        harness = r"""
class DurationPublicStatement extends PDOStatement {
    public function __construct(private mixed $column) {}
    public function execute(?array $params = null): bool {
        if ($params !== ['sets_media', 'duration_seconds']) {
            throw new RuntimeException('Unexpected column lookup');
        }
        return true;
    }
    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0
    ): mixed { return $this->column; }
}
class DurationPublicPDO extends PDO {
    public function __construct(private mixed $column) {}
    public function prepare(string $query, array $options = []): PDOStatement|false {
        if (!str_contains($query, 'information_schema.COLUMNS')) {
            throw new RuntimeException('Unexpected public query');
        }
        return new DurationPublicStatement($this->column);
    }
}
$cases = [
    ['absent', false, false],
    ['correct', ['DATA_TYPE'=>'int', 'COLUMN_TYPE'=>'int(10) unsigned', 'IS_NULLABLE'=>'YES'], true],
    ['text', ['DATA_TYPE'=>'varchar', 'COLUMN_TYPE'=>'varchar(40)', 'IS_NULLABLE'=>'YES'], false],
    ['signed', ['DATA_TYPE'=>'int', 'COLUMN_TYPE'=>'int(11)', 'IS_NULLABLE'=>'YES'], false],
    ['required', ['DATA_TYPE'=>'int', 'COLUMN_TYPE'=>'int unsigned', 'IS_NULLABLE'=>'NO'], false],
];
foreach ($cases as [$name, $column, $expected]) {
    $actual = brvtal_public_set_duration_schema_ready(new DurationPublicPDO($column));
    if ($actual !== $expected) {
        fwrite(STDERR, 'DURATION_SCHEMA_WRONG:' . $name);
        exit(1);
    }
}
echo "PUBLIC_DURATION_SCHEMA_OK";
"""
        shared = source("config/migration_reconcile.php")
        matcher = "function brvtalMigrationDurationColumnMatches(" + shared.split(
            "function brvtalMigrationDurationColumnMatches(", 1
        )[1].split("function brvtalMigrationProbeRequirement(", 1)[0]
        result = subprocess.run(
            ["php", "-r", matcher + helper + harness],
            cwd=ROOT, capture_output=True, text=True, timeout=15,
        )
        self.assertEqual(result.returncode, 0, result.stderr[-3000:])
        self.assertIn("PUBLIC_DURATION_SCHEMA_OK", result.stdout)

    def test_admin_duration_writes_wait_for_migration(self):
        api = source("api/index.php")
        self.assertEqual(api.count("brvtal_set_duration_require_schema($pdo, $resource, $p);"), 2)
        self.assertIn("'SET_DURATION_SCHEMA_PENDING'", api)
        if shutil.which("php") is None:
            self.skipTest("PHP CLI unavailable; exact CI must run this test")

        # Exercise real PHP guard using synthetic metadata, without a database.
        guard = "function brvtal_set_duration_schema_ready(" + api.split(
            "function brvtal_set_duration_schema_ready(", 1
        )[1].split("function sanitize_payload(", 1)[0]
        self.assertIn("brvtalMigrationDurationColumnMatches($row)", guard)
        harness = r"""
function json_response(array $body, int $status = 200): never {
    throw new DomainException('HTTP_' . $status);
}
class SetDurationStatement extends PDOStatement {
    public function __construct(public mixed $row) {}
    public function execute(?array $params = null): bool {
        if ($params !== ['sets_media', 'duration_seconds']) {
            throw new RuntimeException('Unexpected parameters');
        }
        return true;
    }
    public function fetch(
        int $mode = PDO::FETCH_DEFAULT,
        int $cursorOrientation = PDO::FETCH_ORI_NEXT,
        int $cursorOffset = 0
    ): mixed { return $this->row; }
}
class SetDurationPDO extends PDO {
    public function __construct(public mixed $row) {}
    public function prepare(string $query, array $options = []): PDOStatement|false {
        if (!str_contains($query, 'information_schema.COLUMNS')) {
            throw new RuntimeException('Unexpected query');
        }
        return new SetDurationStatement($this->row);
    }
}
$valid = ['DATA_TYPE'=>'int', 'COLUMN_TYPE'=>'int(10) unsigned', 'IS_NULLABLE'=>'YES'];
$wrong = ['DATA_TYPE'=>'varchar', 'COLUMN_TYPE'=>'varchar(10)', 'IS_NULLABLE'=>'YES'];
$cases = [
    [false,'sets',[], 'OK'],
    [false,'artists',['duration_seconds'=>30], 'OK'],
    [false,'sets',['duration_seconds'=>30], 'HTTP_503'],
    [false,'sets',['duration_seconds'=>null], 'HTTP_503'],
    [$valid,'sets',['duration_seconds'=>30], 'OK'],
    [$valid,'sets',['duration_seconds'=>null], 'OK'],
    [$wrong,'sets',['duration_seconds'=>30], 'HTTP_503'],
];
$results = [];
foreach ($cases as [$schema,$resource,$payload,$expected]) {
    try {
        brvtal_set_duration_require_schema(new SetDurationPDO($schema),$resource,$payload);
        $results[] = 'OK';
    } catch (DomainException $error) {
        $results[] = $error->getMessage();
    }
}
echo json_encode($results);
"""
        shared = source("config/migration_reconcile.php")
        matcher = "function brvtalMigrationDurationColumnMatches(" + shared.split(
            "function brvtalMigrationDurationColumnMatches(", 1
        )[1].split("function brvtalMigrationProbeRequirement(", 1)[0]
        executed = subprocess.run(
            ["php", "-r", matcher + harness + guard],
            check=True, capture_output=True, text=True
        )
        self.assertEqual(
            json.loads(executed.stdout),
            ["OK", "OK", "HTTP_503", "HTTP_503", "OK", "OK", "HTTP_503"],
        )

    def test_admin_edit_validates_range_and_permissions(self):
        api = source("api/index.php")
        self.assertIn("if ($resource === 'sets' && array_key_exists('duration_seconds', $d))", api)
        self.assertIn("'duration_seconds','status','sort_order'", api)
        admin = source("discadmin/index-core.php")
        modules = source("discadmin/admin-modules.js")
        self.assertIn('id="f_duration_seconds" type="number" min="1" max="86400"', admin)
        self.assertIn("r.duration_seconds??''", admin)
        self.assertIn("duration_seconds:fieldValue('duration_seconds') === '' ? null : fieldValue('duration_seconds')", modules)
        self.assertIn("function validOptionalSetDuration()", modules)
        self.assertIn("if (type === 'sets' && !validOptionalSetDuration()) return;", modules)
        self.assertIn(r"const canonical = value === '' || /^[1-9]\d{0,4}$/.test(value);", modules)
        self.assertIn("if (!input) {", modules)
        self.assertIn("Duration field unavailable; reopen the Sets editor.", modules)
        self.assertIn("input.checkValidity() && canonical", modules)
        self.assertIn("input.reportValidity();", modules)
        self.assertIn("Feedback.error('Duration must be whole seconds", modules)
        self.assertIn("brvtal_admin_require_csrf();", api)
        self.assertIn("brvtal_activity_record(", api)
        if shutil.which("php") is None:
            self.skipTest("PHP CLI not installed locally; CI validates PHP")
        fn = api.split("function brvtal_set_duration_normalize(", 1)[1].split("function sanitize_payload(", 1)[0]
        helper = "function brvtal_set_duration_normalize(" + fn
        cases = [None, 1, 86400, "12", "01", " 1 ", "1e2", 0, 86401, -1, 1.5, True, [], {}]
        expected = [None, 1, 86400, 12] + ["INVALID"] * 10
        code = helper + """
$inputs = json_decode(base64_decode($argv[1]), true);
$results = [];
foreach ($inputs as $input) {
    try { $results[] = brvtal_set_duration_normalize($input); }
    catch (InvalidArgumentException $e) { $results[] = 'INVALID'; }
}
echo json_encode($results);
"""
        import base64
        encoded = base64.b64encode(json.dumps(cases).encode()).decode()
        output = subprocess.run(["php", "-r", code, encoded], check=True, capture_output=True, text=True)
        self.assertEqual(json.loads(output.stdout), expected)

        # Exercise the real Sets payload sanitizer, not just the normalization helper.
        # Only unrelated CMS/temporal dependencies are stubbed; duration code is unchanged.
        sanitizer = "function sanitize_payload(" + api.split("function sanitize_payload(", 1)[1].split("function allowed_fields(", 1)[0]
        stubs = """
function json_response($body, $status = 200): never { throw new DomainException('HTTP_' . $status); }
function ensure_string(array &$d, string $key, int $max): void {}
function brvtal_content_temporal_normalize(string $resource, array $d): array {
    return ['error' => null, 'payload' => $d];
}
function brvtalContentVisualShapeError(string $resource, array $d): ?array { return null; }
"""
        request_cases = [
            {}, {"duration_seconds": None}, {"duration_seconds": 1},
            {"duration_seconds": "12"}, {"duration_seconds": 86400},
            {"duration_seconds": 0}, {"duration_seconds": -1},
            {"duration_seconds": 86401}, {"duration_seconds": 1.5},
            {"duration_seconds": True}, {"duration_seconds": "01"},
            {"duration_seconds": "1e2"}, {"duration_seconds": " 1 "},
            {"duration_seconds": []}, {"duration_seconds": {}},
        ]
        request_script = stubs + helper + sanitizer + """
$payloads = json_decode(base64_decode($argv[1]), true);
$results = [];
foreach ($payloads as $payload) {
    try {
        $clean = sanitize_payload('sets', $payload);
        $results[] = [
            'status' => 200,
            'present' => array_key_exists('duration_seconds', $clean),
            'duration' => $clean['duration_seconds'] ?? null,
        ];
    } catch (DomainException $e) {
        $results[] = ['status' => (int)substr($e->getMessage(), 5)];
    }
}
echo json_encode($results);
"""
        request_encoded = base64.b64encode(json.dumps(request_cases).encode()).decode()
        observed = subprocess.run(
            ["php", "-r", request_script, request_encoded],
            check=True, capture_output=True, text=True,
        )
        expected_valid = [
            {"status": 200, "present": False, "duration": None},
            {"status": 200, "present": True, "duration": None},
            {"status": 200, "present": True, "duration": 1},
            {"status": 200, "present": True, "duration": 12},
            {"status": 200, "present": True, "duration": 86400},
        ]
        self.assertEqual(json.loads(observed.stdout), expected_valid + [{"status": 422}] * 10)


    def test_set_list_formats_known_duration_only(self):
        renderer = source("js/public-sets-library.js")
        e2e = source("tests/e2e/public-sets-library-phase-c.spec.mjs")
        self.assertIn("const durationLabel = value =>", renderer)
        self.assertIn("Number.isSafeInteger(seconds)", renderer)
        self.assertIn("seconds < 1 || seconds > 86400", renderer)
        self.assertIn("data-set-duration aria-label", renderer)
        self.assertIn("duration ? `<span", renderer)
        self.assertNotIn("duration_seconds: 180", renderer)
        self.assertIn("known CMS durations", e2e)
        self.assertIn("!Object.prototype.hasOwnProperty.call(item, 'status') || item.status === 'published'", renderer)
        self.assertIn("Missing/malformed Sets is not equivalent", renderer)
        self.assertIn("missing or malformed Sets data does not erase the static fallback", e2e)
        self.assertIn("an explicitly unpublished CMS Set never leaks its duration", e2e)
        self.assertIn("for (const width of [390, 1440])", e2e)
        self.assertIn("await page.setViewportSize({width, height:width === 390 ? 844 : 900});", e2e)


    def test_editor_duration_guard_runtime_synthetic(self):
        """Exercise the production editor guard without network or real records."""
        modules = source("discadmin/admin-modules.js")
        self.assertIn("if (type === 'sets' && !validOptionalSetDuration()) return;", modules)
        if shutil.which("node") is None:
            self.skipTest("Node unavailable for isolated editor guard execution")
        guard = "function validOptionalSetDuration() {" + modules.split(
            "function validOptionalSetDuration() {", 1
        )[1].split("  window.save = async function", 1)[0]
        harness = r"""
let current;
let errorCount = 0;
const document = {getElementById: key => key === 'f_duration_seconds' ? current : null};
const Feedback = {error() {errorCount++;}};
""" + guard + r"""
const inputs = JSON.parse(process.argv[1]);
const results = inputs.map(value => {
  let reported = 0;
  let focused = 0;
  const prevErrors = errorCount;
  current = {
    value,
    checkValidity() {
      if (value === '') return true;
      const num = Number(value);
      return value.trim() === value && Number.isInteger(num) && num >= 1 && num <= 86400;
    },
    reportValidity() {reported++;},
    focus() {focused++;}
  };
  const allowed = validOptionalSetDuration();
  return [allowed, reported, focused, errorCount - prevErrors];
});
const priorErrors = errorCount;
current = null;
const missingAllowed = validOptionalSetDuration();
console.log(JSON.stringify({results, missingAllowed, missingErrors: errorCount - priorErrors}));
"""
        inputs = ["", "1", "3599", "86400", "0", "-1", "86401",
                  "01", "1e2", "1.5", "3.0", "abc", " 1 "]
        output = subprocess.run(
            ["node", "-e", harness, json.dumps(inputs)],
            check=True, capture_output=True, text=True
        )
        expected = [[True, 0, 0, 0]] * 4 + [[False, 1, 1, 1]] * 9
        result = json.loads(output.stdout)
        self.assertEqual(result["results"], expected)
        self.assertFalse(result["missingAllowed"])
        self.assertEqual(result["missingErrors"], 1)

    def test_migration_registry_and_reconciliation_contract(self):
        reconciliation = source("config/migration_reconcile.php")
        tests = source("tests/migrations-contract.php")
        migration_proof = reconciliation.split("'migration_set_duration_01.sql' => [", 1)[1].split("],", 1)[0]
        self.assertIn("'column:sets_media.duration_seconds'", migration_proof)
        self.assertIn("'column-unsigned-nullable:sets_media.duration_seconds'", migration_proof)
        self.assertIn("IS_NULLABLE FROM information_schema.COLUMNS", reconciliation)
        self.assertIn("brvtalMigrationDurationColumnMatches", tests)
        self.assertIn("every historical migration must have an explicit proof specification", tests)
        self.assertIn("brvtalMigrationProofDisposition('migration_set_duration_01.sql'", tests)
        self.assertIn("brvtalMigrationAssertAdditiveSql", tests)


    def test_mariadb_duration_migration_roundtrip(self):
        """Exercise the canonical reconciler in an isolated MariaDB test schema."""
        if os.getenv("BRVTAL_INTEGRATION_TESTS") != "1":
            self.skipTest("MariaDB integration environment not enabled")
        if shutil.which("php") is None:
            self.fail("PHP CLI required for MariaDB integration")
        php = r'''
declare(strict_types=1);
require_once 'config/migrations.php';
require_once 'config/migration_reconcile.php';
function duration_check(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException('SET_DURATION_TEST: ' . $message);
}
$testDb = (string)getenv('BRVTAL_TEST_DB_NAME');
duration_check((string)getenv('BRVTAL_INTEGRATION_TESTS') === '1', 'explicit integration opt-in required');
duration_check(preg_match('/^brvtal_test[a-zA-Z0-9_]*$/D', $testDb) === 1, 'test database name required');
$host = (string)(getenv('BRVTAL_TEST_DB_HOST') ?: '');
$portText = (string)(getenv('BRVTAL_TEST_DB_PORT') ?: '');
duration_check($host === '127.0.0.1', 'only local MariaDB is permitted');
duration_check($portText === '3306', 'unexpected MariaDB test port');
$port = 3306;
$user = (string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root');
$pass = (string)(getenv('BRVTAL_TEST_DB_PASS') ?: '');
$schema = 'brvtal_test_duration_' . bin2hex(random_bytes(6));
$dir = sys_get_temp_dir() . '/brvtal-set-duration-' . bin2hex(random_bytes(6));
duration_check(mkdir($dir, 0700), 'temporary SQL fixture directory');
$created = false;
$admin = null;
$pdo = null;
try {
    foreach (['migration_schema_migrations_01.sql', 'migration_set_duration_01.sql'] as $file) {
        duration_check(copy('database/' . $file, $dir . '/' . $file), 'copy canonical SQL fixture');
    }
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
    $admin = new PDO("mysql:host={$host};port={$port};charset=utf8mb4", $user, $pass, $options);
    $admin->exec('CREATE DATABASE ' . $schema . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $created = true;
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$schema};charset=utf8mb4", $user, $pass, $options);
    $pdo->exec('CREATE TABLE sets_media (id INT UNSIGNED PRIMARY KEY, description TEXT NULL) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO sets_media(id, description) VALUES(7, 'existing set')");
    $plan = brvtalMigrationReconciliationPlan($pdo, $dir);
    duration_check($plan['blocked'] === [] && $plan['apply'] === ['migration_set_duration_01.sql'], 'schema absence must plan one additive migration');
    $first = brvtalMigrationReconcileHistorical($pdo, $dir, 'ci', 'abcdef1');
    duration_check($first['verified'] === true && $first['applied'] === ['migration_set_duration_01.sql'], 'canonical reconciler must apply duration');
    $column = $pdo->query("SELECT DATA_TYPE,COLUMN_TYPE,IS_NULLABLE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='sets_media' AND COLUMN_NAME='duration_seconds'")->fetch();
    duration_check(brvtalMigrationDurationColumnMatches($column), 'MariaDB column must be nullable unsigned INT');
    $old = $pdo->query('SELECT description, duration_seconds FROM sets_media WHERE id=7')->fetch();
    duration_check($old['description'] === 'existing set' && $old['duration_seconds'] === null, 'existing data must survive with NULL duration');
    $pdo->exec('UPDATE sets_media SET duration_seconds=123 WHERE id=7');
    $second = brvtalMigrationReconcileHistorical($pdo, $dir, 'ci', 'abcdef1');
    duration_check($second['applied'] === [] && $second['baselined'] === [], 'second reconcile must not reapply ALTER');
    duration_check((int)$pdo->query('SELECT duration_seconds FROM sets_media WHERE id=7')->fetchColumn() === 123, 'second reconcile must preserve value');
    // Already-migrated deployments must be baselined, never have the ALTER replayed.
    $pdo->exec('DROP TABLE schema_migrations');
    $pdo->exec('DROP TABLE sets_media');
    $pdo->exec('CREATE TABLE sets_media (id INT UNSIGNED PRIMARY KEY, description TEXT NULL, duration_seconds INT UNSIGNED NULL) ENGINE=InnoDB');
    $pdo->exec("INSERT INTO sets_media(id, description, duration_seconds) VALUES(7, 'pre-existing duration', 456)");
    $baselinePlan = brvtalMigrationReconciliationPlan($pdo, $dir);
    duration_check($baselinePlan['apply'] === [] && $baselinePlan['baseline'] === ['migration_set_duration_01.sql'] && $baselinePlan['blocked'] === [], 'matching pre-existing column must plan baseline');
    $baseline = brvtalMigrationReconcileHistorical($pdo, $dir, 'ci', 'abcdef1');
    duration_check($baseline['applied'] === [] && $baseline['baselined'] === ['migration_set_duration_01.sql'], 'matching pre-existing column must baseline without ALTER');
    $baselineAgain = brvtalMigrationReconcileHistorical($pdo, $dir, 'ci', 'abcdef1');
    duration_check($baselineAgain['applied'] === [] && $baselineAgain['baselined'] === [], 'baselined column must remain a no-op');
    duration_check((int)$pdo->query('SELECT duration_seconds FROM sets_media WHERE id=7')->fetchColumn() === 456, 'baseline must preserve an existing duration');
    $pdo->exec('DROP TABLE schema_migrations');
    $pdo->exec("ALTER TABLE sets_media MODIFY duration_seconds VARCHAR(40) NOT NULL DEFAULT 'invalid'");
    $badPlan = brvtalMigrationReconciliationPlan($pdo, $dir);
    duration_check($badPlan['blocked'] === ['migration_set_duration_01.sql'], 'wrong physical type must block migration');
    $rejected = false;
    try { brvtalMigrationReconcileHistorical($pdo, $dir, 'ci', 'abcdef1'); }
    catch (RuntimeException $e) { $rejected = str_starts_with($e->getMessage(), 'MIGRATION_RECONCILIATION_BLOCKED:'); }
    duration_check($rejected && !brvtal_migration_registry_exists($pdo), 'schema drift must fail closed without writes');
    echo "SET_DURATION_MARIADB_OK\n";
} finally {
    $pdo = null;
    if ($created && $admin instanceof PDO) $admin->exec('DROP DATABASE ' . $schema);
    foreach (glob($dir . '/*') ?: [] as $file) unlink($file);
    if (is_dir($dir)) rmdir($dir);
}
'''
        # These fail before PDO and do not attempt any remote/local database connection.
        for overrides, expected_error in [
            ({"BRVTAL_TEST_DB_HOST": "example.invalid"}, "only local MariaDB is permitted"),
            ({"BRVTAL_TEST_DB_PORT": "3306junk"}, "unexpected MariaDB test port"),
        ]:
            with self.subTest(overrides=overrides):
                denied_env = os.environ.copy()
                denied_env.update(overrides)
                denied = subprocess.run(
                    ["php", "-r", php], cwd=ROOT, env=denied_env,
                    capture_output=True, text=True, timeout=15
                )
                self.assertNotEqual(denied.returncode, 0)
                self.assertIn(expected_error, denied.stderr)
        result = subprocess.run(
            ["php", "-r", php], cwd=ROOT, capture_output=True, text=True, timeout=90
        )
        self.assertEqual(result.returncode, 0, result.stderr[-3000:])
        self.assertIn("SET_DURATION_MARIADB_OK", result.stdout)

if __name__ == "__main__":
    unittest.main()
