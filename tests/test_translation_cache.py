from __future__ import annotations

import json
import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def php_json(expression: str) -> dict:
    script = (
        "chdir($argv[1]); "
        "require 'config/public_translation.php'; "
        f"$result={expression}; "
        "echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);"
    )
    result = subprocess.run(
        ["php", "-r", script, str(ROOT)],
        cwd=ROOT,
        check=True,
        capture_output=True,
        text=True,
    )
    return json.loads(result.stdout)


class TranslationCacheTests(unittest.TestCase):
    def test_source_hash_locale_and_translator_version_invalidate_cache(self) -> None:
        base = php_json(
            "brvtalPublicTranslationCacheIdentity('Hola mundo','es','en','adapter-v1')"
        )
        same = php_json(
            "brvtalPublicTranslationCacheIdentity('Hola mundo','es','en','adapter-v1')"
        )
        changed_source = php_json(
            "brvtalPublicTranslationCacheIdentity('Hola mundo!','es','en','adapter-v1')"
        )
        changed_locale = php_json(
            "brvtalPublicTranslationCacheIdentity('Hola mundo','es','es','adapter-v1')"
        )
        changed_version = php_json(
            "brvtalPublicTranslationCacheIdentity('Hola mundo','es','en','adapter-v2')"
        )

        self.assertEqual(base["cache_key"], same["cache_key"])
        self.assertEqual(len(base["source_hash"]), 64)
        self.assertNotEqual(base["cache_key"], changed_source["cache_key"])
        self.assertNotEqual(base["cache_key"], changed_locale["cache_key"])
        self.assertNotEqual(base["cache_key"], changed_version["cache_key"])

        migration = (
            ROOT / "database/migration_public_translation_cache_01.sql"
        ).read_text(encoding="utf-8")
        self.assertIn("source_hash", migration)
        self.assertIn("source_locale", migration)
        self.assertIn("target_locale", migration)
        self.assertIn("translator_version", migration)
        self.assertIn("uq_public_translation_cache_identity", migration)

    def test_provider_failure_falls_back_to_spanish_without_leaking_secrets(self) -> None:
        expression = """(function(){
            $adapter=new class implements BrvtalPublicTranslationAdapter {
                public function version(): string { return 'adapter-v1'; }
                public function translate(string $source,string $sourceLocale,string $targetLocale): string {
                    throw new RuntimeException('provider-secret=sk_live_never_expose');
                }
            };
            return brvtalPublicTranslationResolve(
                'Texto canónico',
                'en',
                $adapter,
                static fn(array $identity): ?string => null,
                static function(array $identity,string $translated): void {
                    throw new RuntimeException('cache should not be written');
                }
            );
        })()"""
        result = php_json(expression)
        serialized = json.dumps(result, ensure_ascii=False)

        self.assertEqual(result["text"], "Texto canónico")
        self.assertEqual(result["locale"], "es")
        self.assertEqual(result["source"], "fallback")
        self.assertFalse(result["translated"])
        self.assertNotIn("sk_live_never_expose", serialized)

        migration = (
            ROOT / "database/migration_public_translation_cache_01.sql"
        ).read_text(encoding="utf-8").lower()
        self.assertNotIn("api_key", migration)
        self.assertNotIn("provider_key", migration)


if __name__ == "__main__":
    unittest.main()
