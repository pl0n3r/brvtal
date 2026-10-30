"""BRVTAL #1353: inventario sin valores y rotación ficticia 100 % offline."""
from __future__ import annotations
import json
import re
import tempfile
import unittest
from pathlib import Path

DOC = Path(__file__).resolve().parents[1] / "docs" / "inventario-cuentas-y-rotacion.md"
REQUIRED = ("GitHub", "Hostinger", "SFTP", "MariaDB", "SMTP", "DNS", "TLS",
            "DeepL", "Analítica", "ControlBot")
KEY_FORMATS = (
    re.compile(r"\bAKIA[0-9A-Z]{16}\b"),
    re.compile(r"\bgh[pousr]_[A-Za-z0-9]{20,}\b"),
    re.compile(r"\bgithub_pat_[A-Za-z0-9_]{20,}\b"),
    re.compile(r"\bxox[baprs]-[A-Za-z0-9-]{20,}\b"),
    re.compile(r"\bAIza[A-Za-z0-9_-]{30,}\b"),
    re.compile(r"\bsk_(?:live|test)_[A-Za-z0-9]{16,}\b"),
    re.compile(r"\bsk-[A-Za-z0-9]{32,}\b"),
    re.compile(r"-----BEGIN (?:RSA |EC |OPENSSH )?PRIVATE KEY-----"),
    re.compile(r"\beyJ[A-Za-z0-9_-]{10,}\.eyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]{10,}\b"),
    re.compile(r"\b(?:password|passwd|api[_-]?key|secret|token|private[_-]?key)\s*[:=]\s*['\"]?[A-Za-z0-9+/_=-]{12,}", re.I),
    re.compile(r"\b[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}\b"),
)

def sensitive_match(text: str) -> str | None:
    """Nunca devolver ni imprimir el valor coincidente."""
    for index, pattern in enumerate(KEY_FORMATS):
        if pattern.search(text):
            return f"formato-{index + 1}"
    return None

def inventory_rows(doc: str) -> list[list[str]]:
    table = doc.split("## Registro sin valores, identidades ni rutas privadas", 1)[1]
    table = table.split("\n## Ensayo aislado de rotación", 1)[0]
    rows = []
    for line in table.splitlines():
        if not line.startswith("| "):
            continue
        cells = [cell.strip() for cell in line.strip().strip("|").split("|")]
        if len(cells) != 7:
            raise AssertionError("Matriz con columnas incorrectas")
        if cells[0] == "Sistema a comprobar" or all(set(c) <= set("-: ") for c in cells):
            continue
        rows.append(cells)
    return rows

class FakeProvider:
    """Proveedor de laboratorio: no autentica ante ningún servicio real."""
    def __init__(self):
        self.previous = "FICTICIO_ANTERIOR_1353"
        self.current = self.previous
        self.events = []

    def valid(self, marker: str) -> bool:
        return marker == self.current

    def revoke(self):
        self.current = "REVOCADO"
        self.events.append("revocar")

    def generate(self) -> str:
        self.events.append("generar")
        return "FICTICIO_NUEVO_1353"

    def activate(self, marker: str):
        if marker == self.previous or not marker.startswith("FICTICIO_"):
            raise ValueError("Marcador de laboratorio inválido")
        self.current = marker

def rehearsal() -> tuple[list[str], bool, bool, bool]:
    provider = FakeProvider()
    with tempfile.TemporaryDirectory(prefix="brvtal1353-fake-") as tmp:
        simulated_private = Path(tmp) / "fake-private.json"
        provider.revoke()
        old_revoked = not provider.valid(provider.previous)
        new_marker = provider.generate()
        simulated_private.write_text(json.dumps({"simulated": new_marker}), encoding="utf-8")
        simulated_private.chmod(0o600)
        provider.events.append("actualizar")
        provider.activate(new_marker)
        observed = json.loads(simulated_private.read_text(encoding="utf-8"))["simulated"]
        new_accepted = provider.valid(observed)
        old_denied = not provider.valid(provider.previous)
        provider.events.append("verificar")
        return provider.events, old_revoked, new_accepted, old_denied

class InventarioCuentasTests(unittest.TestCase):
    def test_criterio_principal(self):
        self.assertTrue(DOC.is_file())
        doc = DOC.read_text(encoding="utf-8")
        self.assertGreater(len(doc), 2000)
        self.assertIsNone(sensitive_match(doc), "Formato de secreto en documento")
        rows = inventory_rows(doc)
        self.assertGreaterEqual(len(rows), len(REQUIRED))
        for required in REQUIRED:
            with self.subTest(system=required):
                self.assertTrue(any(required in r[0] for r in rows))
        for system, kind, existence, twofactor, custody, last_review, owner in rows:
            with self.subTest(system=system):
                self.assertTrue(kind)
                self.assertEqual(existence, "POR_COMPROBAR")
                self.assertEqual(twofactor, "NO_VERIFICADO")
                self.assertIn("registro privado", custody.lower())
                self.assertEqual(last_review, "SIN_REVISAR")
                self.assertEqual(owner, "DUEÑO")
        for fragment in ("Revocar", "Generar", "Actualizar archivo privado",
                         "Verificar", "NO_VERIFICABLE", "DEL_DUEÑO", "ENSAYO_SINTETICO"):
            self.assertIn(fragment.lower(), doc.lower())
        events, old_revoked, new_accepted, old_denied = rehearsal()
        self.assertEqual(events, ["revocar", "generar", "actualizar", "verificar"])
        self.assertTrue(old_revoked)
        self.assertTrue(new_accepted)
        self.assertTrue(old_denied)

    def test_detecta_patrones_de_credenciales(self):
        probes = ("ghp_" + "A" * 24, "AKIA" + "B" * 16,
                  "-----BEGIN PRIVATE KEY-----", "password=" + "C" * 18,
                  "user@example.invalid")
        for candidate in probes:
            with self.subTest(label=candidate[:4]):
                self.assertIsNotNone(sensitive_match(candidate))
        for allowed in ("2FA", "NO_VERIFICABLE", "token", "clave"):
            self.assertIsNone(sensitive_match(allowed))

    def test_ensayo_repetible_y_sin_valores_en_documento(self):
        self.assertEqual(rehearsal(), rehearsal())
        self.assertNotIn("FICTICIO_NUEVO_1353", DOC.read_text(encoding="utf-8"))

    def test_no_declara_cuentas_verificadas(self):
        for row in inventory_rows(DOC.read_text(encoding="utf-8")):
            self.assertEqual(row[2], "POR_COMPROBAR")
            self.assertEqual(row[3], "NO_VERIFICADO")
            self.assertEqual(row[5], "SIN_REVISAR")

if __name__ == "__main__":
    unittest.main()
