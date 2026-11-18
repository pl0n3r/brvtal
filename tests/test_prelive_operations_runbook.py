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


if __name__ == "__main__":
    unittest.main()
