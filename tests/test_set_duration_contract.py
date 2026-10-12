"""Behavioral and structural acceptance for BRVTAL #989 (synthetic inputs only)."""
import json
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
        self.assertIn("information_schema.COLUMNS", ddl)
        self.assertIn("COLUMN_NAME = 'duration_seconds'", ddl)
        self.assertIn("@brvtal_set_duration_present = 0", ddl)
        self.assertIn("ADD COLUMN duration_seconds INT UNSIGNED NULL", ddl)
        self.assertIn("'SELECT 1'", ddl)
        self.assertNotRegex(ddl, r"(?im)^\s*(?:DROP|TRUNCATE|DELETE|UPDATE)\b")

    def test_public_api_returns_only_real_published_duration(self):
        public = source("api/public.php")
        query = public.split("FROM sets_media s", 1)[1].split(")->fetchAll();", 1)[0]
        self.assertIn("CASE WHEN s.duration_seconds BETWEEN 1 AND 86400", public)
        self.assertIn("ELSE NULL END AS duration_seconds", public)
        self.assertIn("WHERE s.status='published'", query)
        self.assertIn("brvtal_public_sanitize_set_relations($sets", public)
        self.assertNotIn("duration_seconds = 180", public)

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

    def test_migration_registry_and_reconciliation_contract(self):
        reconciliation = source("config/migration_reconcile.php")
        tests = source("tests/migrations-contract.php")
        self.assertIn("'migration_set_duration_01.sql' => brvtalMigrationObjectRequirements(", reconciliation)
        self.assertIn("'column-unsigned-nullable:sets_media.duration_seconds'", reconciliation)
        self.assertIn("IS_NULLABLE FROM information_schema.COLUMNS", reconciliation)
        self.assertIn("brvtalMigrationDurationColumnMatches", tests)
        self.assertIn("every historical migration must have an explicit proof specification", tests)
        self.assertIn("brvtalMigrationProofDisposition('migration_set_duration_01.sql'", tests)
        self.assertIn("brvtalMigrationAssertAdditiveSql", tests)


if __name__ == "__main__":
    unittest.main()
