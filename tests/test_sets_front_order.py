"""Tests for first-position Set creation and update identity."""
import re
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
API = (ROOT / "api/index.php").read_text()
ADMIN = (ROOT / "discadmin/admin-modules.js").read_text()
CORE = (ROOT / "discadmin/index-core.php").read_text()


class SetsFrontOrderTests(unittest.TestCase):
    def test_new_set_prepend_transaction(self):
        self.assertIn("'brvtal.sets.create'", API)
        self.assertIn("SELECT GET_LOCK(?, 5)", API)
        self.assertIn("SELECT RELEASE_LOCK('brvtal.sets.create')", API)
        self.assertIn("SELECT COALESCE(MIN(sort_order), 0) - 1 FROM sets", API)
        self.assertIn("$p['sort_order'] = (int)", API)
        self.assertIn("$pdo->beginTransaction()", API)
        self.assertIn("$pdo->commit()", API)
        self.assertIn("brvtal_available_set_slug", API)
        self.assertIn("if ($resource !== 'sets' && isset($p['slug'])", API)

    def test_update_preserves_identity(self):
        self.assertIn("if($method==='PUT'&&$id!==null)", API)
        self.assertIn("if ($resource === 'sets') {\n                unset($p['sort_order']);", API)
        self.assertIn("(string)($before['slug'] ?? '')", API)
        self.assertIn("DUPLICATE_VALUE", API)
        self.assertIn("result?.id ?? id", ADMIN)
        self.assertIn("state.editing = Number(savedId)", ADMIN)
        self.assertIn("method:id !== null && id !== '' ? 'PUT' : 'POST'", ADMIN)
        self.assertIn("type === 'artists' ? visualOrderValue", ADMIN)
        self.assertIn("window.save('sets',state.editing,{close:false})", CORE)
        self.assertIn("window.save('sets',state.editing,{close:true})", CORE)

    def test_php_is_syntactically_valid(self):
        for file in ["api/index.php", "api/soundcloud-artwork.php", "discadmin/index.php"]:
            with self.subTest(file=file):
                result = subprocess.run(["php", "-l", file], cwd=ROOT,
                                        capture_output=True, text=True)
                self.assertEqual(result.returncode, 0, result.stderr + result.stdout)


if __name__ == "__main__":
    unittest.main()
