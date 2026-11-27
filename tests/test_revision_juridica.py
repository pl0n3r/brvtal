"""Legal handoff: exact copies, reproducible bundle and minimum workflow permissions."""
import importlib.util
import json
import subprocess
import sys
import tempfile
from types import SimpleNamespace
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
            f"Última actualización automática de las fuentes: {day} · SHA-256 de fuentes: `{sha}`"))
        self.assertIn("documented_not_legally_approved", index)
        self.assertNotIn("versión declarada", index)
        self.assertNotIn(mod.version(ROOT), index)
        self.assertEqual(json.loads((ROOT / "package.json").read_text(encoding="utf-8"))["version"],
                         mod.version(ROOT))
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
                         "revision-juridica-desfase", "gh issue create",
                         "GH_TOKEN: ${{ github.token }}",
                         "GH_REPO: ${{ github.repository }}",
                         "group: revision-juridica-desfase-${{ github.repository }}",
                         "cancel-in-progress: false",
                         "from generar_revision_juridica import ROOT, version",
                         'v${legal_version}',
                         "- tests/test_revision_juridica.py",
                         '"paths":["revision-juridica/","tests/test_revision_juridica.py"]'):
            self.assertIn(required, workflow)
        self.assertNotIn("@DLR@", workflow)
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
            with patch.object(mod, "provenance", return_value=("a"*64, "2026-10-18")):
                self.assertTrue(mod.synchronize(root, check=True))
                self.assertFalse((root / "revision-juridica").exists())
                mod.synchronize(root, check=False)
                self.assertEqual(mod.synchronize(root, check=True), [])
                path = root / "revision-juridica" / mod.NAMES[0][0]
                path.write_bytes(b"corrupt")
                self.assertIn(mod.NAMES[0][0], mod.synchronize(root, check=True))
                self.assertEqual(path.read_bytes(), b"corrupt")


    def test_version_bump_alone_does_not_stale_index(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            (root / "docs/privacidad").mkdir(parents=True)
            (root / "config").mkdir()
            for name, _ in mod.NAMES:
                (root / "docs/privacidad" / name).write_bytes(name.encode("utf-8"))
            (root / "datos.yml").write_text(json.dumps({"treatments": [{"providers": []}]}))
            version_file = root / "config/version.php"
            version_file.write_text("<?php const BRVTAL_APP_VERSION = '0.0.1';")
            with patch.object(mod, "provenance", return_value=("a" * 64, "2026-10-18")):
                mod.synchronize(root, check=False)
                version_file.write_text("<?php const BRVTAL_APP_VERSION = '9.9.9';")
                self.assertEqual(mod.synchronize(root, check=True), [])
                index = (root / "revision-juridica/00-LEEME.md").read_text(encoding="utf-8")
                self.assertNotIn("9.9.9", index)
                self.assertNotIn("0.0.1", index)


    def test_rejects_unknown_entries_and_symlinks_without_writing(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            (root / "docs/privacidad").mkdir(parents=True)
            (root / "config").mkdir()
            for name, _ in mod.NAMES:
                (root / "docs/privacidad" / name).write_bytes(name.encode("utf-8"))
            (root / "datos.yml").write_text(json.dumps({"treatments": [{"providers": []}]}))
            (root / "config/version.php").write_text(
                "<?php const BRVTAL_APP_VERSION = '0.0.1';")
            with patch.object(mod, "provenance", return_value=("a" * 64, "2026-10-18")):
                mod.synchronize(root, check=False)
                folder = root / "revision-juridica"
                unexpected = folder / "unexpected.php"
                unexpected.write_bytes(b"preserve-me")
                with self.assertRaisesRegex(ValueError, "Entradas no previstas"):
                    mod.synchronize(root, check=True)
                with self.assertRaisesRegex(ValueError, "Entradas no previstas"):
                    mod.synchronize(root, check=False)
                self.assertEqual(unexpected.read_bytes(), b"preserve-me")
                unexpected.unlink()

                outside = root / "outside.md"
                outside.write_bytes(b"untouched")
                legal = folder / mod.NAMES[0][0]
                legal.unlink()
                legal.symlink_to(outside)
                with self.assertRaisesRegex(ValueError, "Entradas no previstas"):
                    mod.synchronize(root, check=True)
                with self.assertRaisesRegex(ValueError, "Entradas no previstas"):
                    mod.synchronize(root, check=False)
                self.assertEqual(outside.read_bytes(), b"untouched")



    def test_content_fingerprint_survives_squash_and_detects_source_drift(self):
        with tempfile.TemporaryDirectory() as temp:
            root = Path(temp)
            (root / "docs/privacidad").mkdir(parents=True)
            (root / "config").mkdir()
            (root / "revision-juridica").mkdir()
            for name, _ in mod.NAMES:
                (root / "docs/privacidad" / name).write_text(f"Contenido: {name}\n", encoding="utf-8")
            (root / "datos.yml").write_text(json.dumps({"treatments": [{"providers": []}]}))
            (root / "config/version.php").write_text("<?php const BRVTAL_APP_VERSION = '0.0.1';")

            def before_squash(args, **_kwargs):
                return SimpleNamespace(stdout="false" if args[1] == "rev-parse" else "2026-11-24")

            with patch.object(mod.subprocess, "run", side_effect=before_squash):
                fingerprint, day = mod.provenance(root)
            self.assertEqual(len(fingerprint), 64)
            self.assertEqual(day, "2026-11-24")
            (root / "revision-juridica/00-LEEME.md").write_bytes(
                mod.expected(root, fingerprint, day)["00-LEEME.md"])

            def after_squash(args, **_kwargs):
                return SimpleNamespace(stdout="false" if args[1] == "rev-parse" else "2026-11-25")

            with patch.object(mod.subprocess, "run", side_effect=after_squash):
                self.assertEqual(mod.provenance(root), (fingerprint, day))
                source = root / "docs/privacidad/canal-derechos.md"
                source.write_text(source.read_text() + "Cambio verificable\n", encoding="utf-8")
                changed_sha, changed_day = mod.provenance(root)
                self.assertNotEqual(changed_sha, fingerprint)
                self.assertEqual(changed_day, "2026-11-25")
                self.assertIn("00-LEEME.md", mod.synchronize(root, check=True))


if __name__ == "__main__":
    unittest.main()
