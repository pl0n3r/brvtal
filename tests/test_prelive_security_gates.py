"""BRVTAL #1042: pruebas offline de gates pre-live; no hacen red ni autorizan live."""
from __future__ import annotations
import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs" / "prelive-security-gates.md"
CI = ROOT / ".github" / "workflows" / "update-release-metadata.yml"
REQUIRED = (
    "Identidades y accesos", "Least privilege y segregación",
    "2FA y recuperación", "Secretos y rotación", "TLS y dominios",
    "WAF y hosting edge", "CodeQL", "SonarCloud", "CodeRabbit",
    "CI `validate`", "Incidentes y hallazgos",
)
STATUS = "NO_VERIFICABLE"


def rows(text: str) -> dict[str, list[str]]:
    """Leer la matriz sin consultar GitHub ni el navegador."""
    table = text.split("## Matriz obligatoria", 1)[1].split(
        "\n## Paquete de evidencias", 1
    )[0]
    parsed = {}
    for line in table.splitlines():
        if not line.startswith("| "):
            continue
        cells = [item.strip() for item in line.strip().strip("|").split("|")]
        if len(cells) != 5 or cells[0] in ("Control", "---"):
            continue
        if all(set(item) <= set("-: ") for item in cells):
            continue
        if cells[0] in parsed:
            raise ValueError("Control duplicado")
        parsed[cells[0]] = cells
    return parsed


def synthetic_receipts(sha: str) -> dict[str, dict]:
    """Todos los valores son ficticios, no son datos de seguridad actuales."""
    return {
        name: {
            "sha": sha, "status": "completed", "conclusion": "success",
            "verified": True, "fresh": True, "high_critical_open": 0,
        }
        for name in REQUIRED
    }


def can_mark_ready(receipts: object, expected_sha: object, owner_ok: object,
                   tls_valid: object, mfa_enforced: object,
                   final_review_done: object) -> str:
    """Oráculo SOLO de pruebas; nunca habilita infraestructura real."""
    if not isinstance(expected_sha, str) or not re.fullmatch(
        r"[0-9a-f]{40}", expected_sha
    ):
        return STATUS
    if any(flag is not True for flag in (
        owner_ok, tls_valid, mfa_enforced, final_review_done
    )):
        return STATUS
    if not isinstance(receipts, dict) or set(receipts) != set(REQUIRED):
        return STATUS
    for name in REQUIRED:
        evidence = receipts[name]
        if not isinstance(evidence, dict):
            return STATUS
        if evidence.get("sha") != expected_sha:
            return STATUS
        if evidence.get("status") != "completed":
            return STATUS
        if evidence.get("conclusion") != "success":
            return STATUS
        if evidence.get("verified") is not True or evidence.get("fresh") is not True:
            return STATUS
        if type(evidence.get("high_critical_open")) is not int:
            return STATUS
        if evidence["high_critical_open"] != 0:
            return STATUS
    return "LISTO"  # solo simulación, NO decisión real de live


class SecurityGatesTests(unittest.TestCase):
    def test_identidad_privilegios_y_secretos(self):
        text = DOC.read_text(encoding="utf-8")
        matrix = rows(text)
        self.assertEqual(set(matrix), set(REQUIRED))
        for name in REQUIRED:
            with self.subTest(control=name):
                cells = matrix[name]
                self.assertEqual(len(cells), 5)
                self.assertGreater(len(cells[2]), 65)
                self.assertEqual(cells[3], STATUS)
                self.assertTrue(cells[4])
        for fragment in (
            "2FA", "Least privilege", "cross-tenant", "CSRF", "rotación",
            "OWNER", "DEL_DUEÑO", "revoc", "recuperación",
            "roles", "secretos", "mínimo privilegio",
        ):
            self.assertIn(fragment.casefold(), text.casefold())
        github_token_pattern = "ghp" + "_" + "[A-Za-z0-9_]{20,}"
        self.assertNotRegex(text, github_token_pattern)
        self.assertNotRegex(text, r"AKIA[0-9A-Z]{16}")
        self.assertNotIn("PRIVATE KEY-----", text)

    def test_gates_tls_sast_review(self):
        text = DOC.read_text(encoding="utf-8")
        for name in ("TLS y dominios", "CodeQL", "SonarCloud",
                     "CodeRabbit", "CI `validate`", "Incidentes y hallazgos"):
            self.assertIn(name, rows(text))
        for keyword in (
            "HTTPS", "hostname", "SAN", "HSTS", "cadena", "expiración",
            "mixed content", "SHA exacto", "CodeQL", "SonarCloud",
            "CodeRabbit", "high/critical", "rate limited",
            "CHANGES_REQUESTED", "FINAL", "D-070", "NO_VERIFICABLE",
            "issues", "certificado", "WAF", "SAST",
        ):
            with self.subTest(keyword=keyword):
                self.assertIn(keyword.casefold(), text.casefold())
        self.assertIn("una revisión manual", text)
        self.assertIn("no equivale a una revisión aprobada", text)

    def test_no_listo_sin_evidencia_exact_sha(self):
        text = DOC.read_text(encoding="utf-8")
        for keyword in (
            "pending", "skipped", "failure", "stale", "rate limited",
            "NO_VERIFICABLE", "candidate_sha", "release_sha",
            "alta o crítica", "firma/procedencia",
        ):
            self.assertIn(keyword.casefold(), text.casefold())
        sha = "a" * 40
        flags = (True, True, True, True)
        self.assertEqual(can_mark_ready(synthetic_receipts(sha), sha, *flags), "LISTO")
        self.assertEqual(can_mark_ready(synthetic_receipts(sha), "INVALID", *flags), STATUS)
        for i in range(len(flags)):
            missing = list(flags)
            missing[i] = False
            with self.subTest(flag=i):
                self.assertEqual(can_mark_ready(synthetic_receipts(sha), sha, *missing), STATUS)
        for name in REQUIRED:
            with self.subTest(missing=name):
                proof = synthetic_receipts(sha)
                del proof[name]
                self.assertEqual(can_mark_ready(proof, sha, *flags), STATUS)
        for mutation in (
            {"sha": "b" * 40},
            {"status": "pending"},
            {"status": "skipped"},
            {"conclusion": "failure"},
            {"conclusion": "neutral"},
            {"verified": False},
            {"verified": "true"},
            {"fresh": False},
            {"high_critical_open": 1},
            {"high_critical_open": "0"},
        ):
            with self.subTest(mutation=mutation):
                proof = synthetic_receipts(sha)
                proof["CodeRabbit"].update(mutation)
                self.assertEqual(can_mark_ready(proof, sha, *flags), STATUS)
        self.assertEqual(can_mark_ready(synthetic_receipts(sha), sha,
                                        True, True, True, False), STATUS)

    def test_validate_es_gate_requerido(self):
        ci = CI.read_text(encoding="utf-8")
        text = DOC.read_text(encoding="utf-8")
        self.assertRegex(ci, r"(?m)^  validate:\s*$")
        self.assertRegex(ci, r"(?m)^\s+name: validate\s*$")
        self.assertIn("candidate_sha", text)
        self.assertIn("main", text)
        self.assertIn("V3", text)
        self.assertIn("No declarar", text)
        self.assertTrue(DOC.is_file())
        self.assertTrue(CI.is_file())


if __name__ == "__main__":
    unittest.main()
