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

BRVTAL CI classifies the new `config/public_*.php` source as deploy-bound, so **this PR cannot pass its release-version preflight or be merged without a sequential product-version transition**. The original proposed 0.1.144 → 0.1.145 bump was removed from PR #1006 at commit `4f2c607aa25c0b187c9aa020ab5d90c200a9af83`: `config/version.php` and `package.json` were restored byte-exactly to `main@dd778fc0bdc00f6dce4690a0540f98766998d931`. Those two paths are **not** in the current four-path V3 lease for #967.

The version manifests are reserved by #969/PR #1007. Keep #1006 DRAFT and do not fake a green preflight, change CI policy, or edit another session's claims. After #1007 is legitimately merged and its reservation released, extend #967's task paths via Coordinación, obtain a new authentic V3 lease, reconcile the branch with the new `main`, set the **next** valid patch version in both manifests, and run `validate` against the resulting exact HEAD. Only then request CodeRabbit review **once** on the final green HEAD. That eventual source-version bump is not permission to activate DeepL; #920 still controls accounts, tokens, costs, network access and production.
