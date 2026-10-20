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

[PR #1007](https://github.com/pl0n3r/brvtal/pull/1007) is **merged** into `main@1bd47c931ffa324fd43acc399440efc6eff978e7` with product version `0.1.145`; its previous V3 claim on `config/version.php` and `package.json` is inactive. After recovering #967 and formally renewing its V3 contract to include those two metadata files, branch `work/issue-967` was reconciled with that exact main via a non-force merge, and both version manifests were bumped to **`0.1.146`** in commit `7e189f9ffd2217f3a7c3ac9786618551590c7689`. This does not activate the DeepL provider or add production wiring. The latest CI `preflight` on that SHA has succeeded; `validate` and the full CI must be assessed on the eventual final HEAD rather than inferred from earlier runs.

The only six claimed paths are the four fake-adapter/test/doc files, plus these two product-version metadata manifests. Before a final squash, re-check the authentic active V3 UUID, exact HEAD, six-path diff, sequential version, complete tests and security evidence. Ask CodeRabbit for **one final review only after final CI is green**, never during intermediate commits. Do not interpret a green CI or a merged source patch as a production DeepL smoke.

The repo's project metadata/README identify phase `live`, while `datos.yml` identifies `construccion`. Under Factory D-069, the author must not assume autonomous merge authority in `live` from this conflict, and D-043/D-059 deployment gates remain independent. #920 still controls any real provider account, tokens, privacy/commercial consent, networking, spending and production activation.
