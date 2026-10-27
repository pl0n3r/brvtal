# DeepL Free: transporte HTTP real, sin activar

El issue #1034 implementa únicamente código, bajo la decisión auténtica #1033/A. El transporte sigue **apagado y sin wiring productivo**. No se configura Hostinger, no se reciben claves reales, no se envía contenido ni se despliega desde este PR.

La factoría brvtalPublicDeepLRealAdapter() comprueba simultáneamente BRVTAL_DEEPL_ENABLED=1 y DEEPL_AUTH_KEY no vacía. Cualquier ausencia o error retorna provider_not_configured, sin HTTP; la versión de caché del adaptador apagado difiere de la versión real para que un caché previo nunca habilite resultados con el interruptor apagado.

La solicitud autorizada es un POST JSON a https://api-free.deepl.com/v2/translate con Authorization: DeepL-Auth-Key, Content-Type application/json, source_lang ES, target_lang EN-US y un solo texto plano UTF-8 de hasta 5.000 bytes. Curl usa TLS verificado, timeout total de 3 segundos y de conexión de 2; sin redirects, retries ni trazas de cuerpos o clave.

BRVTAL_DEEPL_MONTHLY_BUDGET define 1–500.000 caracteres/mes; valor por defecto 300.000. Antes de llamar a HTTP se reserva la cuota en un contador local persistido por mes UTC con bloqueo exclusivo. No almacena contenido, IDs ni secretos. Si no puede persistir o el contador se agota, no hay llamada externa y el resolver devuelve español. En despliegues multihost o con directorio temporal no persistente debe mantenerse OFF hasta contar con un contador compartido fiable y autorización independiente.

Respuestas inválidas generan provider_invalid_response; HTTP fallido, timeout o cuota agotada generan provider_unavailable; jamás se cachean fallos. La elegibilidad pública editorial brvtalPublicEditorialOverlayEligible permanece intacta. Ningún punto de entrada instancia la factoría, por lo que esta entrega no activa el proveedor.

Pruebas sin red ni credenciales reales: php tests/deepl_real_transport_scenarios.php off|request|errors|contract y python3 -m unittest tests/test_deepl_real_transport.py. Reversión futura: retirar BRVTAL_DEEPL_ENABLED y volver al texto español; revertir el squash remueve el transporte.

---

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
