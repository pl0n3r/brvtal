#!/usr/bin/env python3
"""BRVTAL #1095: cinco contratos sintéticos S06-R001..R005.

Ejercita la política PHP real, el catálogo y la proyección editorial ES/EN;
no certifica persistencia, navegación HTTP, despliegue ni producción.
Las filas de la matriz siguen NO_VERIFICABLE hasta reconciliación independiente.
"""
from __future__ import annotations

from copy import deepcopy
from functools import lru_cache
import json
from pathlib import Path
import re
import shutil
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]
MATRIX = ROOT / "docs/matriz-cobertura.md"
PATHS = {
    "spec": "docs/BRVTAL-SPEC.md",
    "policy": "config/public_i18n.php",
    "translation": "config/public_translation.php",
    "public_api": "api/public.php",
    "browser": "js/app.js",
}
IDS = ("S06-R001", "S06-R002", "S06-R003", "S06-R004", "S06-R005")
SUMMARY = {
    "S06-R001": "Spanish (**es**) is the canonical editorial language and default public locale.",
    "S06-R002": "English (**en**) is the supported alternate locale.",
    "S06-R003": "Public locale settings are effective only through the allowlisted contract default_locale + available_locales; unsupported values fail closed to the supported ES/EN policy.",
    "S06-R004": "Critical public microcopy uses a small versioned catalog in code.",
    "S06-R005": "BRVTAL brand terms, artist names and music taxonomy are protected from automatic translation by an explicit glossary/field policy.",
}
UNKNOWN = "UNKNOWN"
LOCAL = "LOCAL_PARTIAL"

# Sin HTTP, DB ni servicios externos. La implementación invocada proviene de
# config/public_i18n.php y config/public_translation.php en el propio checkout.
PHP_PROBE = r"""
require 'config/public_i18n.php';
require 'config/public_translation.php';

final class SyntheticLocaleAdapter implements BrvtalPublicTranslationAdapter {
    public function version(): string { return 'synthetic-v1'; }
    public function translate(string $source, string $from, string $to): string {
        if ($from !== 'es' || $to !== 'en') throw new RuntimeException('UNEXPECTED_LOCALE');
        return 'EN::' . $source;
    }
}
$adapter = new SyntheticLocaleAdapter();
$writes = [];
$read = static fn(array $identity): ?string => null;
$write = static function(array $identity, string $value) use (&$writes): void {
    $writes[] = [$identity['source_locale'], $identity['target_locale'], $value];
};
$event = [
    'id'=>7, 'slug'=>'genesis-synthetic', 'title'=>'GENESIS',
    'description'=>'Noche sintética', 'genre'=>'HARD TECHNO', 'status'=>'published',
];
$artist = [
    'id'=>8, 'slug'=>'random-kore-synthetic', 'name'=>'RANDOM KORE',
    'bio'=>'Artista sintético', 'genre'=>'INDUSTRIAL',
];
$invalid = [...$event, 'description'=>['unsafe'=>'object']];
$result = [
    'supported'=>brvtalPublicI18nSupportedLocales(),
    'normalize'=>[
        brvtalPublicI18nNormalizeLocale(' EN '),
        brvtalPublicI18nNormalizeLocale('eS'),
        brvtalPublicI18nNormalizeLocale('fr'),
        brvtalPublicI18nNormalizeLocale('../en'),
        brvtalPublicI18nNormalizeLocale(123),
    ],
    'base'=>brvtalPublicI18nPayload([]),
    'configured'=>brvtalPublicI18nPayload([
        'default_locale'=>'en', 'available_locales'=>['en','es','fr','en',null],
        'internal_secret'=>'must-never-be-exposed',
    ]),
    'unsupported'=>brvtalPublicI18nPayload([
        'default_locale'=>'fr', 'available_locales'=>['fr','xx',false],
    ]),
    'only_es'=>brvtalPublicI18nPayload(['available_locales'=>['es']]),
    'only_en'=>brvtalPublicI18nPayload(['available_locales'=>['en']]),
    'malformed'=>brvtalPublicI18nPayload(['available_locales'=>'en']),
    'event'=>brvtalPublicTranslationProjectRecord(
        $event, 'events', 'en', $adapter, $read, $write),
    'artist'=>brvtalPublicTranslationProjectRecord(
        $artist, 'artists', 'en', $adapter, $read, $write),
    'bad_record'=>brvtalPublicTranslationProjectRecord(
        $invalid, 'events', 'en', $adapter, $read, $write),
    'unknown_locale'=>brvtalPublicTranslationProjectRecord(
        $event, 'events', 'fr', $adapter, $read, $write),
    'writes'=>$writes,
];
echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
"""


def coverage_rows(document: object) -> dict[str, tuple[str, ...]]:
    """Exigir cinco filas exactas, presentes una sola vez y sin promoción."""
    if not isinstance(document, str) or not document:
        raise ValueError("UNKNOWN: matriz ausente")
    found: dict[str, tuple[str, ...]] = {}
    for line in document.splitlines():
        match = re.match(r"^\| (S06-R\d{3}) · ", line)
        if not match or match.group(1) not in IDS:
            continue
        key = match.group(1)
        if key in found:
            raise ValueError("UNKNOWN: fila duplicada " + key)
        cells = tuple(x.strip() for x in line.strip("|").split("|"))
        if (len(cells) != 5 or not cells[0].startswith(key + " · ")
                or cells[1] != SUMMARY[key]
                or cells[2] != "NO_VERIFICABLE"
                or not cells[3].startswith("Sin aserción contrastada")
                or not cells[4]):
            raise ValueError("UNKNOWN: fila inválida " + key)
        found[key] = cells
    if set(found) != set(IDS):
        raise ValueError("UNKNOWN: falta una fila")
    return found


def source_texts() -> dict[str, str]:
    return {name: (ROOT / path).read_text(encoding="utf-8")
            for name, path in PATHS.items()}


@lru_cache(maxsize=1)
def observe() -> dict[str, object]:
    php = shutil.which("php")
    if not php:
        raise AssertionError("UNKNOWN: CLI PHP ausente")
    p = subprocess.run([php, "-r", PHP_PROBE], cwd=ROOT, check=False,
                       text=True, capture_output=True, timeout=25)
    if p.returncode != 0 or p.stderr.strip():
        raise AssertionError("UNKNOWN: fixture PHP: " + p.stderr[-800:])
    data = json.loads(p.stdout)
    if not isinstance(data, dict):
        raise AssertionError("UNKNOWN: respuesta PHP no estructurada")
    return data


def evidence_for(key: object, rows: object, files: object,
                 data: object) -> dict[str, str]:
    result = {"local": UNKNOWN, "integrated": UNKNOWN,
              "ci": UNKNOWN, "production": UNKNOWN}
    if (not isinstance(key, str) or key not in IDS
            or not isinstance(rows, dict) or key not in rows
            or not isinstance(files, dict) or set(files) != set(PATHS)
            or not isinstance(data, dict)
            or any(not isinstance(x, str) or not x.strip()
                   for x in files.values())):
        return result
    row = rows[key]
    if (not isinstance(row, tuple) or len(row) != 5
            or row[1] != SUMMARY[key] or row[2] != "NO_VERIFICABLE"
            or SUMMARY[key] not in files["spec"]):
        return result
    base, configured = data.get("base"), data.get("configured")
    unsupported = data.get("unsupported")
    if not all(isinstance(x, dict) for x in (base, configured, unsupported)):
        return result

    canonical = (
        data.get("supported") == ["es", "en"]
        and data.get("normalize") == ["en", "es", None, None, None]
        and base.get("canonical_locale") == "es"
        and base.get("default_locale") == "es"
        and configured.get("canonical_locale") == "es"
        and configured.get("default_locale") == "es"
        and unsupported.get("canonical_locale") == "es"
        and unsupported.get("default_locale") == "es"
    )
    available = (
        base.get("available_locales") == ["es", "en"]
        and configured.get("available_locales") == ["en", "es"]
        and unsupported.get("available_locales") == ["es", "en"]
        and data.get("only_es", {}).get("available_locales") == ["es"]
        and data.get("only_en", {}).get("available_locales") == ["es", "en"]
        and data.get("malformed", {}).get("available_locales") == ["es", "en"]
    )
    allowlisted = (
        set(base) == {"version", "canonical_locale", "default_locale",
                      "available_locales", "catalog", "protected_terms"}
        and set(configured) == set(base)
        and "internal_secret" not in configured
        and "'i18n'] = brvtalPublicI18nPayload($site)" in files["public_api"]
        and "require_once __DIR__ . '/../config/public_i18n.php'" in files["public_api"]
        and "availableLocales.every(locale => renderedLocales.has(locale))" in files["browser"]
    )
    catalog = base.get("catalog")
    catalog_ok = (
        base.get("version") == 1
        and isinstance(catalog, dict)
        and set(catalog) == {"es", "en"}
        and isinstance(catalog.get("es"), dict)
        and isinstance(catalog.get("en"), dict)
        and set(catalog["es"]) == set(catalog["en"])
        and len(catalog["es"]) >= 5
        and all(isinstance(v, str) and bool(v.strip())
                for locale in ("es", "en") for v in catalog[locale].values())
        and catalog["es"].get("nav.events") == "EVENTOS"
        and catalog["en"].get("nav.events") == "EVENTS"
        and catalog["es"].get("status.empty") == "SIN RESULTADOS"
        and catalog["en"].get("status.empty") == "NO RESULTS"
        and "applyCatalog(locale, config)" in files["browser"]
    )
    protected = base.get("protected_terms")
    event, artist = data.get("event"), data.get("artist")
    glossary_ok = (
        isinstance(protected, dict)
        and {"exact", "music_taxonomy", "entity_fields"} <= set(protected)
        and all(isinstance(protected[x], list) for x in
                ("exact", "music_taxonomy", "entity_fields"))
        and {"BRVTAL", "RAVE TILL GRAVE", "GENESIS", "RANDOM KORE"} <= set(protected["exact"])
        and {"HARD TECHNO", "INDUSTRIAL"} <= set(protected["music_taxonomy"])
        and {"artists[].name", "events[].title", "sets[].title",
             "releases[].title"} <= set(protected["entity_fields"])
        and isinstance(event, dict) and isinstance(artist, dict)
        and event.get("resolved_locale") == "en" and event.get("translated") is True
        and artist.get("resolved_locale") == "en" and artist.get("translated") is True
        and event.get("translated_fields") == ["description"]
        and artist.get("translated_fields") == ["bio"]
        and event.get("record", {}).get("title") == "GENESIS"
        and event.get("record", {}).get("genre") == "HARD TECHNO"
        and event.get("record", {}).get("slug") == "genesis-synthetic"
        and event.get("record", {}).get("description") == "EN::Noche sintética"
        and artist.get("record", {}).get("name") == "RANDOM KORE"
        and artist.get("record", {}).get("genre") == "INDUSTRIAL"
        and artist.get("record", {}).get("bio") == "EN::Artista sintético"
        and data.get("bad_record", {}).get("resolved_locale") == "es"
        and data.get("bad_record", {}).get("source") == "fallback"
        and data.get("unknown_locale", {}).get("resolved_locale") == "es"
        and all(pair[:2] == ["es", "en"] for pair in data.get("writes", []))
    )
    checks = {
        "S06-R001": canonical,
        "S06-R002": canonical and available,
        "S06-R003": canonical and available and allowlisted,
        "S06-R004": catalog_ok and allowlisted,
        "S06-R005": glossary_ok and allowlisted,
    }
    if checks[key] is True:
        result["local"] = LOCAL
    return result


class BrechasBrvtal05Tests(unittest.TestCase):
    def test_contrato_principal(self) -> None:
        table = coverage_rows(MATRIX.read_text(encoding="utf-8"))
        files, data = source_texts(), observe()
        self.assertEqual(set(IDS), set(table))
        for key in IDS:
            with self.subTest(brecha=key):
                self.assertIn(SUMMARY[key], files["spec"])
                self.assertEqual(LOCAL, evidence_for(key, table, files, data)["local"])
        self.assertEqual(1, data["base"]["version"])
        self.assertEqual("EN::Noche sintética", data["event"]["record"]["description"])

    def test_falla_cerrado(self) -> None:
        raw = MATRIX.read_text(encoding="utf-8")
        table, files, data = coverage_rows(raw), source_texts(), observe()
        first = next(x for x in raw.splitlines() if x.startswith("| S06-R001 · "))
        with self.assertRaisesRegex(ValueError, "duplicada"):
            coverage_rows(raw + "\n" + first)
        with self.assertRaisesRegex(ValueError, "falta"):
            coverage_rows("\n".join(x for x in raw.splitlines()
                                   if not x.startswith("| S06-R005 · ")))
        with self.assertRaisesRegex(ValueError, "inválida"):
            coverage_rows(raw.replace(first, first.replace(
                "NO_VERIFICABLE", "CUBIERTO")))
        for key in IDS:
            with self.subTest(brecha=key):
                self.assertEqual(UNKNOWN, evidence_for(key, {}, files, data)["local"])
                self.assertEqual(UNKNOWN, evidence_for(key, table, {}, data)["local"])
                self.assertEqual(UNKNOWN, evidence_for(key, table, files, None)["local"])
                changed = dict(table)
                item = list(table[key])
                item[2] = "CUBIERTO"
                changed[key] = tuple(item)
                self.assertEqual(UNKNOWN,
                                 evidence_for(key, changed, files, data)["local"])
                self.assertEqual(UNKNOWN,
                                 evidence_for(key, table, files, {})["local"])
        changed = deepcopy(data)
        changed["configured"]["available_locales"] = ["en", "fr"]
        self.assertEqual(UNKNOWN, evidence_for(
            "S06-R003", table, files, changed)["local"])
        changed = deepcopy(data)
        changed["event"]["record"]["title"] = "EN::GENESIS"
        self.assertEqual(UNKNOWN, evidence_for(
            "S06-R005", table, files, changed)["local"])
        changed = deepcopy(data)
        changed["base"]["catalog"]["en"].pop("nav.events")
        self.assertEqual(UNKNOWN, evidence_for(
            "S06-R004", table, files, changed)["local"])
        self.assertEqual(UNKNOWN, evidence_for(
            "S06-R999", table, files, data)["local"])

    def test_sin_datos_reales(self) -> None:
        data = observe()
        self.assertEqual("genesis-synthetic", data["event"]["record"]["slug"])
        self.assertEqual("random-kore-synthetic", data["artist"]["record"]["slug"])
        self.assertNotIn("internal_secret", data["configured"])
        self.assertTrue(all(item[:2] == ["es", "en"] for item in data["writes"]))
        own = Path(__file__).read_text(encoding="utf-8")
        self.assertNotRegex(
            own, r"(?m)^(?:from|import)\s+(?:requests|httpx|socket|urllib)\b")
        self.assertNotIn("BEGIN " + "PRIVATE KEY", own)
        self.assertNotIn("curl_exec(", PHP_PROBE)
        self.assertNotIn("file_get_contents('http", PHP_PROBE)

    def test_estados_unknown_explicitos(self) -> None:
        table = coverage_rows(MATRIX.read_text(encoding="utf-8"))
        files, data = source_texts(), observe()
        for key in IDS:
            with self.subTest(brecha=key):
                state = evidence_for(key, table, files, data)
                self.assertEqual(UNKNOWN, state["integrated"])
                self.assertEqual(UNKNOWN, state["ci"])
                self.assertEqual(UNKNOWN, state["production"])
                self.assertEqual(UNKNOWN,
                                 evidence_for(key, table, files, None)["local"])
        missing = deepcopy(data)
        missing["base"].pop("version")
        self.assertEqual(UNKNOWN, evidence_for(
            "S06-R004", table, files, missing)["local"])
        missing = deepcopy(data)
        missing["event"]["record"]["genre"] = "TRANSLATED"
        self.assertEqual(UNKNOWN, evidence_for(
            "S06-R005", table, files, missing)["local"])


if __name__ == "__main__":
    unittest.main()
