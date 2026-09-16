# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- El rate limiter público de Contact deja de truncar y reescribir su JSON de estado in-place.
- Contact reutiliza el lock estable compartido de rate limits y persiste mediante archivo temporal + escritura completa + `fflush`/`fsync` + rename atómico.
- Un estado corrupto o un fallo de persistencia ahora falla cerrado con `RATE_LIMIT_UNAVAILABLE` en lugar de aceptar la solicitud o resetear silenciosamente el contador.
- El helper atómico se extrae como primitiva reusable en `config/rate_limit_store.php`, preservando el comportamiento de Password/TOTP.
- Se añade una regresión ejecutable para estado válido, lock estable, JSON corrupto y fallo de reemplazo sin destruir el último estado válido.

## Archivos modificados en este deploy

- `README.md` — snapshot operativo exacto de este deploy.
- `config/public_contact.php` — Contact usa lock estable, lectura fail-closed y reemplazo atómico del estado.
- `config/rate_limit_store.php` — expone el reemplazo atómico reusable con verificación de escritura/flush/fsync.
- `tests/contact-rate-limit-persistence-contract.php` — regresión de persistencia y fallos del limiter de Contact.

## Validación

- Base exacta: `main` `b51122778599df340b87e6e174b8d27fb920c9ef`.
- No había PRs abiertos al crear `security/contact-rate-limit-persistence`.
- Issue cubierto: `#373`.
- No hay migración de base de datos ni mutación de datos de producción.
- Se preservan las garantías existentes de trusted proxies y storage privado para Contact.
- `tests/contact-rate-limit-persistence-contract.php` queda auto-descubierto por la suite PHP 8.5 de contratos.
- Pendiente en este snapshot: `BRVTAL CI / validate`, revisión advisory de CodeRabbit y análisis automático de SonarQube Cloud sobre el head final.
- CI verde significará **VALIDATED IN CODE**. No se declarará **VALIDATED IN PRODUCTION** sin comprobar el deploy real y la superficie correspondiente.

## Qué sigue

1. Resolver en esta misma rama cualquier finding válido de CodeRabbit, SonarQube Cloud o BRVTAL CI.
2. Hacer squash merge solo con `BRVTAL CI / validate` verde.
3. Verificar `BRVTAL CI / validate` del SHA exacto resultante en `main`.
4. Continuar con el siguiente hallazgo priorizado de la auditoría global.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de validación: `docs/TESTING.md`.
- Issue abordado: `#373`.
