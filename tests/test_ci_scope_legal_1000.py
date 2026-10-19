"""CI scope contract for the legal lawyer-handoff folder (#1000 / #1004)."""
from __future__ import annotations

import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SCOPE = ROOT / "scripts/ci-scope.sh"
DOCUMENTS = (
    "revision-juridica/00-LEEME.md",
    "revision-juridica/aviso-privacidad.md",
    "revision-juridica/canal-derechos.md",
    "revision-juridica/paquete-completo.md",
    "revision-juridica/politica-tratamiento.md",
    "revision-juridica/registro-tratamientos.md",
    "revision-juridica/retencion.md",
    "revision-juridica/terminos-condiciones.md",
)
SUPPORT = (
    "scripts/generar_revision_juridica.py",
    "tests/test_revision_juridica.py",
    ".github/workflows/revision-juridica-sync.yml",
    "scripts/ci-scope.sh",
    "tests/test_ci_scope_legal_1000.py",
)


def classify(files: tuple[str, ...]) -> dict[str, str]:
    """Use the real CI classifier rather than a model of its allowlist."""
    shell = 'source "$1"; brvtal_ci_classify_files "$2" pull_request; brvtal_ci_scope_print'
    result = subprocess.run(
        ["bash", "-c", shell, "_", str(SCOPE), "\n".join(files)],
        cwd=ROOT, text=True, capture_output=True, check=True, timeout=20,
    )
    return dict(line.split("=", 1) for line in result.stdout.splitlines() if "=" in line)


class CiScopeLegal1000Tests(unittest.TestCase):
    def test_legal_markdown_only_not_deploy_bound(self) -> None:
        """AC-02: exact PR file list and each legal markdown are maintenance only."""
        files = DOCUMENTS + SUPPORT
        self.assertEqual(len(files), 13)
        self.assertEqual(classify(files)["deploy_bound"], "false")
        for path in DOCUMENTS:
            with self.subTest(path=path):
                self.assertEqual(classify((path,))["deploy_bound"], "false")

    def test_runtime_and_unknown_paths_still_deploy_bound(self) -> None:
        """AC-03: mixed runtime and unknown/executable files fail closed."""
        for bad in (
            "index.php",
            "config/version.php",
            "revision-juridica/injected.php",
            "revision-juridica/unknown.bin",
            "revision-juridica/subdir/file.bin",
            "revision-juridica/subdir/unknown.md",  # A nested Markdown file is not a root legal document.
            "revision-juridica/unknown.md",  # New root Markdown is not an approved lawyer document.
            "revision-juridica/.hidden.md",  # Hidden Markdown must not bypass scope.
            "revision-juridica/00-LEEME.md.bak",
        ):
            with self.subTest(bad=bad):
                self.assertEqual(classify(DOCUMENTS + SUPPORT + (bad,))["deploy_bound"], "true")
                self.assertEqual(classify((bad,))["deploy_bound"], "true")

    def test_existing_ci_scope_contract_runs(self) -> None:
        """AC-04: existing PHP scope invariants must not regress."""
        result = subprocess.run(
            ["php", "tests/ci-scope-contract.php"], cwd=ROOT,
            text=True, capture_output=True, timeout=45,
        )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)


if __name__ == "__main__":
    unittest.main()
