"""Legal handoff: exact copies, reproducible bundle and minimum workflow permissions."""
import importlib.util
import json
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path
from unittest.mock import patch

ROOT = Path(__file__).resolve().parents[1]
SCRIPT = ROOT / "scripts/generar_revision_juridica.py"
spec = importlib.util.spec_from_file_location("generar_revision_juridica", SCRIPT)
mod = importlib.util.module_from_spec(spec)
spec.loader.exec_module(mod)


class RevisionJuridicaTests(unittest.TestCase):
    def test_exact_copies_bundle_and_sync_workflow(self):
        result = subprocess.run([sys.executable, str(SCRIPT), "--check"],
                                cwd=ROOT, capture_output=True, text=True, timeout=35)
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
        sha, day = mod.provenance(ROOT)
        expected = mod.expected(ROOT, sha, day)
        self.assertEqual(len(expected), 8)
        index = expected["00-LEEME.md"].decode("utf-8")
        self.assertTrue(index.startswith(
            f"Última actualización automática de las fuentes: {day} · SHA de origen: `{sha}`"))
        self.assertIn("documented_not_legally_approved", index)
        for provider in ("google_analytics", "smtp_mail_provider"):
            self.assertIn(provider, index)
        for filename, payload in expected.items():
            self.assertEqual((ROOT / "revision-juridica" / filename).read_bytes(), payload, filename)
        for filename, _ in mod.NAMES:
            self.assertEqual((ROOT / "revision-juridica" / filename).read_bytes(),
                             (ROOT / "docs/privacidad" / filename).read_bytes())
            self.assertIn("## docs/privacidad/" + filename, expected["paquete-completo.md"].decode())
        workflow = (ROOT / ".github/workflows/revision-juridica-sync.yml").read_text(encoding="utf-8")
        for required in ("branches: [main]", "schedule:", "contents: read", "issues: write",
                         "python3 scripts/generar_revision_juridica.py --check",
                         "revision-juridica-desfase", "gh issue create"):
            self.assertIn(required, workflow)
        self.assertNotIn("contents: write", workflow)
        self.assertNotIn("pull_request_target:", workflow)

    def test_check_does_not_write_stale_documents(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            (root / "docs/privacidad").mkdir(parents=True)
            (root / "config").mkdir()
            for name, _ in mod.NAMES:
                (root / "docs/privacidad" / name).write_bytes(name.encode("utf-8"))
            (root / "datos.yml").write_text(json.dumps({"treatments":[{"providers":[]}]}))
            (root / "config/version.php").write_text("<?php const BRVTAL_APP_VERSION = '0.0.1';")
            with patch.object(mod, "provenance", return_value=("a"*40, "2026-10-18")):
                self.assertTrue(mod.synchronize(root, check=True))
                self.assertFalse((root / "revision-juridica").exists())
                mod.synchronize(root, check=False)
                self.assertEqual(mod.synchronize(root, check=True), [])
                path = root / "revision-juridica" / mod.NAMES[0][0]
                path.write_bytes(b"corrupt")
                self.assertIn(mod.NAMES[0][0], mod.synchronize(root, check=True))
                self.assertEqual(path.read_bytes(), b"corrupt")


if __name__ == "__main__":
    unittest.main()
