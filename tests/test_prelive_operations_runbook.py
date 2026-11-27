"""BRVTAL #1040: simulacro offline, sin red ni acciones de producción."""
from __future__ import annotations

import json
from pathlib import Path
import re
import unittest

ROOT = Path(__file__).resolve().parents[1]
DOC = ROOT / "docs/prelive-operations-runbook.md"
PATHS = (
    "api/health.php",
    "api/deployment.php",
    ".github/workflows/production-deploy-observer.yml",
    ".github/workflows/production-authenticated-smoke.yml",
    "tests/production-smoke-contract.php",
    "docs/PRODUCTION-HEALTH-INSPECTION.md",
)
MARKER = re.compile(r"<!-- factory-unattended-kill-switch (\{[^\n<>]*\}) -->")
ROLES = {"OPERADOR", "QA", "SOPORTE", "SEGURIDAD", "OWNER"}


def classify_signal(signal: object) -> str:
    """Evaluador puro: no realiza request HTTP."""
    required = {"http_status", "ok", "deployment_exact", "sha_match",
                "schema_up_to_date", "failures_5m", "smoke"}
    if type(signal) is not dict or set(signal) != required:
        return "UNKNOWN"
    if type(signal["failures_5m"]) is not int or not 0 <= signal["failures_5m"] <= 100:
        return "UNKNOWN"
    if type(signal["http_status"]) is not int or type(signal["ok"]) is not bool:
        return "UNKNOWN"
    if any(type(signal[k]) is not bool for k in
           ("deployment_exact", "sha_match", "schema_up_to_date")):
        return "UNKNOWN"
    if signal["smoke"] not in ("SUCCESS", "FAILED", "NOT_RUN"):
        return "UNKNOWN"
    if (signal["http_status"] == 503 or signal["ok"] is False or
            signal["deployment_exact"] is False or signal["sha_match"] is False or
            signal["schema_up_to_date"] is False or signal["smoke"] == "FAILED" or
            signal["failures_5m"] >= 3):
        return "CRITICAL"
    if signal["http_status"] != 200 or signal["smoke"] == "NOT_RUN":
        return "UNKNOWN"
    return "WARN" if signal["failures_5m"] in (1, 2) else "GREEN"


def unique_pairs(pairs):
    result = {}
    for key, val in pairs:
        if key in result:
            raise ValueError("duplicate key")
        result[key] = val
    return result


def simulate_switch(body: object, closed: object, trusted: object,
                    freeze: object) -> str:
    """Interpreta exclusivamente un snapshot falso. No cambia el issue."""
    if (type(body) is not str or type(closed) is not bool or
            type(trusted) is not bool or type(freeze) is not bool):
        return "DENY"
    markers = MARKER.findall(body)
    if len(markers) != 1 or not closed or not trusted or freeze:
        return "DENY"
    try:
        payload = json.loads(markers[0], object_pairs_hook=unique_pairs)
    except (ValueError, json.JSONDecodeError):
        return "DENY"
    if type(payload) is not dict or set(payload) != {"version", "state", "owner"}:
        return "DENY"
    if (type(payload["version"]) is not int or payload["version"] != 1
            or payload["state"] != "RUNNING" or payload["owner"] != "pl0n3r"):
        return "DENY"
    return "ALLOW_EVALUATION"


def sanitize_ticket(record: object) -> dict:
    required = {"folio", "kind", "severity", "owner_role", "acknowledged"}
    if type(record) is not dict or set(record) != required:
        raise ValueError("fields")
    if (record["folio"] != "DEMO-0001" or record["kind"] not in
            {"HEALTH", "DEPLOY", "SMOKE", "SUPPORT", "SECURITY"} or
            record["severity"] not in {"INFO", "WARN", "CRITICAL", "UNKNOWN"} or
            record["owner_role"] not in ROLES or
            type(record["acknowledged"]) is not bool):
        raise ValueError("values")
    return dict(record)


class PreliveOperationsRunbookTests(unittest.TestCase):
    def test_senales_alertas_umbrales_y_escalamiento(self):
        content = DOC.read_text(encoding="utf-8")
        for path in PATHS:
            with self.subTest(path=path):
                self.assertTrue((ROOT / path).is_file(), path)
                self.assertIn(path, content)
        for token in ("AC-01", "HEALTH", "DEPLOY", "SMOKE", "WARN", "CRITICAL",
                      "UNKNOWN", "5 min", "15 min", "ESCALAR AL OWNER",
                      "503", "NO CERTIFICA GO-LIVE"):
            self.assertIn(token, content)
        good = {"http_status": 200, "ok": True, "deployment_exact": True,
                "sha_match": True, "schema_up_to_date": True,
                "failures_5m": 0, "smoke": "SUCCESS"}
        self.assertEqual("GREEN", classify_signal(good))
        for changes, expected in (
            ({"failures_5m": 1}, "WARN"),
            ({"failures_5m": 2}, "WARN"),
            ({"failures_5m": 3}, "CRITICAL"),
            ({"http_status": 503}, "CRITICAL"),
            ({"sha_match": False}, "CRITICAL"),
            ({"deployment_exact": False}, "CRITICAL"),
            ({"schema_up_to_date": False}, "CRITICAL"),
            ({"smoke": "FAILED"}, "CRITICAL"),
            ({"smoke": "NOT_RUN"}, "UNKNOWN"),
            ({"ok": None}, "UNKNOWN"),
            ({"failures_5m": True}, "UNKNOWN"),
            ({"http_status": 502}, "UNKNOWN"),
        ):
            with self.subTest(changes=changes):
                self.assertEqual(expected, classify_signal({**good, **changes}))
        self.assertEqual("UNKNOWN", classify_signal({"status": 200}))

    def test_rutina_diaria_y_contactos_por_rol(self):
        text = DOC.read_text(encoding="utf-8")
        for item in ("AC-02", "Apertura de turno", "Atencion entrante",
                     "handoff", "owner_role", "next_review", "evidence_ref",
                     "OPERADOR", "SOPORTE", "QA", "SEGURIDAD", "OWNER",
                     "NO PROGRAMADA", "no inventar contactos"):
            self.assertIn(item.casefold(), text.casefold())
        safe = {"folio": "DEMO-0001", "kind": "HEALTH",
                "severity": "WARN", "owner_role": "OPERADOR",
                "acknowledged": False}
        self.assertEqual(safe, sanitize_ticket(safe))
        for bad in ({**safe, "email": "synthetic@example.test"},
                    {**safe, "token": "FAKE"},
                    {**safe, "owner_role": "persona"},
                    {**safe, "folio": "email"},
                    {**safe, "acknowledged": "yes"}):
            with self.assertRaises(ValueError):
                sanitize_ticket(bad)

    def test_simulacro_apagado_sin_efectos(self):
        content = DOC.read_text(encoding="utf-8")
        for item in ("AC-03", "PAUSED", "UNKNOWN", "RUNNING", "DENY",
                     "MANTENER_PAUSA", "efectos externos", "0",
                     "sin red", "NO ejecutar", "no modifica"):
            self.assertIn(item.casefold(), content.casefold())
        marker = '<!-- factory-unattended-kill-switch {"version":1,"state":"RUNNING","owner":"pl0n3r"} -->'
        samples = (
            (marker, True, True, False, "ALLOW_EVALUATION"),
            (marker.replace("RUNNING", "PAUSED"), True, True, False, "DENY"),
            (marker.replace("RUNNING", "UNKNOWN"), True, True, False, "DENY"),
            (marker, False, True, False, "DENY"),
            (marker, True, False, False, "DENY"),
            (marker, True, True, True, "DENY"),
            (marker + "\n" + marker, True, True, False, "DENY"),
            (marker.replace('"version":1', '"version":2'), True, True, False, "DENY"),
            (marker.replace('"owner":"pl0n3r"', '"owner":"otro"'), True, True, False, "DENY"),
            ('<!-- factory-unattended-kill-switch {"version":1,"state":"RUNNING","state":"RUNNING","owner":"pl0n3r"} -->',
             True, True, False, "DENY"),
            ("", True, True, False, "DENY"),
        )
        effects = []
        for body, closed, trusted, frozen, result in samples:
            with self.subTest(result=result):
                self.assertEqual(result, simulate_switch(body, closed, trusted, frozen))
        self.assertEqual([], effects, "El simulacro no puede mutar recursos")


    def test_golive_runbook_secciones_responsables_y_no_activacion(self):
        content = DOC.read_text(encoding="utf-8")
        section = content.split("## Go-live de boletería (#2193)", 1)[1]
        section = section.split("\n## Validacion, evidencia y reversibilidad", 1)[0]
        for heading in ("Entornos, credenciales y flags",
                        "Monitoreo, respaldo y retorno",
                        "Ensayos de contingencia sintéticos",
                        "Puerta humana de encendido"):
            self.assertIn("### " + heading, section)
        for token in ("BRVTAL_TICKET_WOMPI_MODE=off",
                      "BRVTAL_TICKET_WOMPI_PROD_*", "BRVTAL_TICKET_WOMPI_*",
                      "BRVTAL_TICKET_MAIL_MODE=disabled",
                      "BRVTAL_TICKET_MAIL_ALLOW_LIVE=0",
                      "BRVTAL_TICKET_MAIL_DNS_VERIFIED",
                      "BRVTAL_TICKET_MAIL_PRIVACY_APPROVED", "SPF/DKIM/DMARC",
                      "HEALTH", "DEPLOY", "SMOKE", "CRITICAL/UNKNOWN",
                      "backup", "restore", "SHA-256", "RPO/RTO",
                      "D-059", "sin dinero", "no certificar"):
            with self.subTest(token=token):
                self.assertIn(token, section)
        def rows(fragment):
            return [tuple(cell.strip() for cell in line.strip("|").split("|"))
                    for line in fragment.splitlines() if line.startswith("|")]
        drills = section.split("### Ensayos de contingencia sintéticos", 1)[1]
        drills, gates = drills.split("### Puerta humana de encendido", 1)
        expected_drills = {"Proveedor caído": "SRE + QA",
                           "Webhook duplicado": "SRE + QA",
                           "Correo fallido": "OPERADOR + QA",
                           "BD a medio migrar": "SRE + OWNER",
                           "Puerta sin red": "OPERADOR + QA"}
        drill_rows = rows(drills)[2:]
        self.assertEqual(set(expected_drills), {row[0] for row in drill_rows})
        self.assertEqual(len(expected_drills), len(drill_rows))
        for scenario, signal, recovery, role in drill_rows:
            with self.subTest(scenario=scenario):
                self.assertTrue(signal and recovery)
                self.assertEqual(expected_drills[scenario], role)
        expected_gates = {"Titularidad", "Impuestos", "Datos personales #1671",
                          "Medios de pago", "Reembolsos", "Encendido"}
        def assert_closed_gates(fragment):
            gate_rows = rows(fragment)[2:]
            self.assertEqual(expected_gates, {row[0] for row in gate_rows})
            self.assertEqual(len(expected_gates), len(gate_rows))
            for gate, evidence, role, status in gate_rows:
                self.assertTrue(evidence, gate)
                self.assertIn("OWNER", role, gate)
                self.assertEqual("NO ACTIVAR", status, gate)
        assert_closed_gates(gates)
        for gate in expected_gates:
            with self.subTest(unsafe_gate=gate):
                changed = re.sub(r"(?m)^(\| " + re.escape(gate) +
                                 r" \|.*\| )NO ACTIVAR( \|)$", r"\1ACTIVAR\2", gates)
                self.assertNotEqual(gates, changed)
                with self.assertRaises(AssertionError):
                    assert_closed_gates(changed)



    def test_payment_incident_runbook(self):
        content = DOC.read_text(encoding="utf-8")
        heading = "## Incidencias de pago (#2196)"
        self.assertEqual(1, content.count(heading))
        content = heading + content.split(heading, 1)[1].split(
            "\n## Go-live de boletería (#2193)", 1
        )[0]
        sections = {
            "Verificación y evidencia común": (
                "SOPORTE/OPERADOR", "SRE", "QA", "UNKNOWN", "ESCALAR AL OWNER",
                "GET /v1/transactions/{transaction_id}", "transaction.updated",
                "firma válida", "journal firmado", "conciliación verificada por API",
            ),
            "Pago duplicado (`duplicate_payment`, #2194)": (
                "mismo ID de transacción", "dos transacciones distintas",
                "ticket_payment_incidents", "evidencia del journal",
                "no emitir boletas extra", "No devolver", "OWNER",
            ),
            "Reversión y disputa / contracargo": (
                "APPROVED, DECLINED, VOIDED, ERROR y PENDING",
                "VOIDED es\n  anulación de tarjeta", "modelo Agregador",
                "Gateway", "plazo", "dictamen auténtico", "Evitar doble reembolso",
            ),
            "Pendiente vencido (`stale_pending`, #2195)": (
                "48 h", "168 h", "señal operativa", "backoff",
                "no liberar aforo ante cobro incierto", "APPROVED tardío",
                "approved_unfulfilled", "OWNER",
            ),
            "Decisión y cierre con evidencia": (
                "evidence_ref", "Sin datos reales", "OWNER deja una decisión específica",
                "sin aprobación, no ejecutar", "recibo terminal del proveedor",
                "CERRADO solo con evidencia terminal", "decisión OWNER registrada",
                "mantener ABIERTO", "SQL directo",
                "https://docs.wompi.co/docs/colombia/transacciones/",
                "https://docs.wompi.co/docs/colombia/eventos/",
                "https://soporte.wompi.co/hc/es-419/articles/360046916653",
                "https://soporte.wompi.co/hc/es-419/articles/360054848754",
                "https://soporte.wompi.co/hc/es-419/articles/360020767034",
            ),
        }

        def assert_contract(fragment):
            self.assertEqual(1, fragment.count(heading))
            intro, *parts = fragment.split("\n### ")
            for token in ("NO ACTIVAR", "aprueba explícitamente", "no mover dinero"):
                self.assertIn(token, intro)
            parsed = {part.split("\n", 1)[0]: part for part in parts}
            self.assertEqual(len(sections), len(parts))
            self.assertEqual(set(sections), set(parsed))
            for name, tokens in sections.items():
                for token in tokens:
                    self.assertIn(token, parsed[name], f"{name}: {token}")

        assert_contract(content)
        for original, unsafe in (
            ("sin aprobación, no ejecutar", "sin aprobación, ejecutar"),
            ("CERRADO solo con evidencia terminal", "CERRADO sin evidencia terminal"),
            ("no emitir boletas extra", "emitir boletas extra"),
            ("no liberar aforo ante cobro incierto", "liberar aforo ante cobro incierto"),
            ("### Reversión y disputa / contracargo", "### Reversión"),
        ):
            with self.subTest(unsafe=unsafe):
                changed = content.replace(original, unsafe, 1)
                self.assertNotEqual(content, changed)
                with self.assertRaises(AssertionError):
                    assert_contract(changed)

if __name__ == "__main__":
    unittest.main()
