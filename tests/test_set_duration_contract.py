"""Behavioral and structural acceptance for BRVTAL #989 (synthetic inputs only)."""
import json
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
            r"\$setDurationProjection\s*=\s*brvtal_public_column_exists\("
            r"\$pdo,\s*'sets_media',\s*'duration_seconds'\)\s*"
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

    def test_admin_edit_validates_range_and_permissions(self):
        api = source("api/index.php")
        self.assertIn("if ($resource === 'sets' && array_key_exists('duration_seconds', $d))", api)
        self.assertIn("'duration_seconds','status','sort_order'", api)
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


if __name__ == "__main__":
    unittest.main()
