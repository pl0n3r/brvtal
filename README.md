# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- Hero Slider público deja de convertir excepciones internas en una configuración válida `enabled:false`; ahora responde HTTP 503 con `ok:false`, `HERO_SLIDER_UNAVAILABLE`, `Retry-After` y `Cache-Control: no-store`.
- Los fallos del endpoint público de Hero Slider quedan registrados mediante `PUBLIC_HERO_SLIDER_ERROR`, preservando el payload/cache existente en respuestas sanas.
- Sitemap deja de omitir silenciosamente una familia cuando falla su query; cualquier fallo estructural durante la generación aborta el documento parcial y devuelve HTTP 503 `SITEMAP_UNAVAILABLE`.
- Los fallos de Sitemap quedan registrados con la familia afectada y la respuesta degradada se marca `no-store`, `Retry-After` y `X-Robots-Tag: noindex, follow`.
- Se añadió una regresión PHP que protege las semánticas de error y confirma que los caminos sanos conservan sus contratos de cache/XML.

## Archivos modificados en este deploy

- `README.md` — snapshot operativo exacto de este deploy.
- `api/hero-slider.php` — separa respuestas exitosas de errores internos observables.
- `sitemap.php` — falla de forma explícita en lugar de servir un sitemap parcial como HTTP 200.
- `tests/public-failure-semantics-contract.php` — protege los contratos de error público del Hero Slider y Sitemap.

## Validación

- Base exacta: `main` `03bae7eb0ae70105616328f0fa40092919df38a9`, con `BRVTAL CI / validate` verde.
- No había PRs abiertos al crear `fix/public-failure-semantics-quick-wins`.
- Issues cubiertos: `#220`, `#254`.
- No hay migración de base de datos, cambios de schema, borrado, restore ni mutación de datos de producción.
- Las respuestas sanas mantienen los contratos públicos existentes: Hero Slider conserva su cache público y Sitemap conserva XML + `max-age=900`.
- Solo los fallos internos cambian de semántica: pasan de éxito silencioso/parcial a 503 observable y no-cacheable.
- La regresión nueva queda dentro de la suite PHP 8.5 auto-discovered; el clasificador de CI decidirá browser/real-stack por los archivos modificados.
- Pendiente en este snapshot: `BRVTAL CI / validate`, revisión advisory de CodeRabbit y análisis automático de SonarQube Cloud sobre el head final.
- CI verde significará **VALIDATED IN CODE**. No se declarará **VALIDATED IN PRODUCTION** sin comprobar el deploy real y la superficie correspondiente.

## Qué sigue

1. Resolver en esta misma rama cualquier finding válido de BRVTAL CI, CodeRabbit o SonarQube Cloud.
2. Hacer squash merge solo con `BRVTAL CI / validate` verde.
3. Verificar `BRVTAL CI / validate` del SHA exacto resultante en `main`.
4. Continuar con otro lote de varios Quick Wins compatibles antes de entrar en fixes de mayor profundidad.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de validación: `docs/TESTING.md`.
- Issues abordados: `#220`, `#254`.
