# DeepL translation: offline build-ahead only

Issue BRVTAL #967 | parent #212 | separate activation gate #920.

## Status and boundaries

This is a reversible, **unwired** PHP adapter for synthetic public Spanish-to-English text. It is **not** an active DeepL service integration. No SDK, HTTP calls, provider account, secret provisioning, billing, production wiring or CMS/database change is included. The GitHub-to-Hostinger release path does not activate the adapter because no production entry point instantiates it. Do not interpret a successful CI run as permission to use DeepL in production.

## Contract

- PHP class BrvtalDeepLTranslationAdapter implements the existing BrvtalPublicTranslationAdapter interface; adapter version deepl-fake-es-en-v1 is used by the standard SHA-based cache identity.
- Constructor receives an explicitly injected callable that can only be a **fake transport for this issue** and an optional, abstract environment-configuration reader. Without a reader, or when DEEPL_AUTH_KEY is missing/empty/unreadable, the provider raises the sanitized reason provider_not_configured **before** invoking transport.
- Any value returned by the configuration reader is converted to a boolean configured state; it is not retained, logged, echoed or sent to the fake transport. Tests use a synthetic fixture token, never a real key.
- Only exact es to en requests are accepted. One plain-text source, maximum 5,000 valid UTF-8 bytes. HTML, controls, blank text, malformed UTF-8 and unsupported locales are rejected before transport. A fake response must contain exactly one translation with plain text, at most 10,000 valid UTF-8 bytes. Oversize responses are rejected before normalization; malformed UTF-8 and invalid provider results fail closed.
- Transport errors are sanitized to provider_unavailable and invalid responses to provider_invalid_response; neither includes remote payloads, exceptions nor secrets. The provider-neutral brvtalPublicTranslationResolve falls back to the original canonical Spanish string without writing failed responses to cache.
- Public/trusted eligibility remains exclusively guarded by brvtalPublicEditorialOverlayEligible and related existing editorial overlay functions. This low-level adapter cannot establish that content is public; callers must never pass private records to it.

## Offline verification

Run Python unittest tests/test_deepl_adapter.py, and PHP tests/deepl_translation_adapter_scenarios.php with scenario names missing, valid, errors or cache. Tests use injected callables only, with no network, real credentials or private data. Cross-check roles: architecture, QA and security. CI validate and source-only merge are necessary but **not** evidence of a production DeepL smoke.

## Future gate

BRVTAL #920 retains legal/privacy and DPA consent, any allowed cost decision, credential provisioning, human approval of real provider/network operations, safe rollout/rollback and production observation. Do not change those authorities in #967. Reversion: remove the four new files; no migration or runtime config needs reversal.

## Release metadata only

BRVTAL CI classifies `config/public_translation_deepl.php` as deploy-bound, so the release-version preflight requires a sequential product-version transition even though the adapter remains unwired.

## Real transport (BRVTAL #1034, authorized in #1033)

`config/public_translation_deepl_transport.php` adds `BrvtalDeepLHttpTransport`, a real HTTPS transport to `https://api-free.deepl.com/v2/translate`. It is **OFF by default**: `BrvtalDeepLHttpTransport::adapterFromEnvironment()` returns `null` (the site keeps showing canonical Spanish) unless the server environment has both `BRVTAL_DEEPL_ENABLED=1` and a non-empty `DEEPL_AUTH_KEY`. No production entry point instantiates it yet, so merging this change does not call DeepL.

- Request: one plain-text source (at most 5,000 bytes), `source_lang=ES`, `target_lang=EN-US`, header `Authorization: DeepL-Auth-Key <key>`, 3-second timeout, no automatic retries, HTTPS only, no redirects.
- Budget: a monthly character counter in `storage/deepl-usage.json` (default 300,000, configurable with `BRVTAL_DEEPL_MONTHLY_CHARS`). When it would be exceeded, no call is made and the site shows Spanish.
- Failure: any transport error, non-200 status, malformed JSON or exhausted budget becomes `provider_unavailable` or `provider_invalid_response`; nothing is written to the translation cache and the key never appears in errors, logs or results.
- Cache identity: the adapter reports `deepl-api-free-es-en-v1`, separate from the fake `deepl-fake-es-en-v1`.
- Tests: `tests/test_deepl_real_transport.py` and `tests/deepl_real_transport_scenarios.php` use an injected fake HTTP callable; no network and no real key.

Future owner-approved activation (not part of this change): first complete separately approved production wiring into the public translation flow and legal/DPA gates. Only after that wiring is reviewed may the owner authorize provisioning `DEEPL_AUTH_KEY` and `BRVTAL_DEEPL_ENABLED=1` in the Hostinger environment and a limited public-page test. Merely setting the two variables does not activate DeepL without production wiring. Turning the switch off (`BRVTAL_DEEPL_ENABLED` removed or `0`) disables DeepL immediately. Legal/DPA review and protection of `main` (#1009) remain open gates before real traffic.
