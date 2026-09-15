# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- Las relaciones públicas de Blog ahora se filtran contra los mismos pools finales que ya alimentan el API público.
- Una relación hacia un Event solo sobrevive si el Event está en el conjunto público activo o histórico permitido por la política canónica.
- Relaciones hacia Artists, Sets y Releases solo sobreviven si el destino existe en su colección pública final.
- IDs de destinos draft, privados, eliminados o inexistentes se omiten completamente del payload público.
- Tipos de relación no soportados también se eliminan, evitando exponer referencias internas que el frontend no puede resolver.
- La sanitización vive en `api/public-related.php`; no introduce una segunda política de publicación ni duplica reglas de lifecycle.

## Archivos modificados en este deploy

- `api/public-related.php` — añade sanitización de relaciones Blog contra pools públicos finales.
- `api/public.php` — aplica la sanitización después de particionar Events y resolver Artists, Sets y Releases públicos.
- `tests/related-content-contract.php` — cubre relaciones válidas, privadas/inexistentes, tipos no soportados y preservación del orden.
- `README.md` — snapshot operativo de este deploy.

## Validación

- Rama creada desde `main` `f1d1406eacec86813fafbf2e2290776430f1c8b4`.
- Ese SHA exacto tenía BRVTAL CI completo, PHP 8.5 Compatibility y Backup Recovery Rehearsal en success.
- No hay cambios de esquema, migraciones ni mutaciones de datos de producción.
- La implementación reutiliza las colecciones públicas ya resueltas; no añade una segunda definición de qué contenido es público.
- Pendiente de **BRVTAL CI / validate** y **PHP 8.5 Compatibility / php85** del PR.
- Tras el merge se verificará la matriz completa sobre el SHA exacto de `main`.
- CI verde significa **VALIDATED IN CODE**; no implica **VALIDATED IN PRODUCTION**.

## Qué sigue

1. Ejecutar los gates del PR y corregir en esta misma rama cualquier fallo detectado.
2. Con CI verde, hacer squash merge.
3. Verificar BRVTAL CI completo, PHP 8.5 y Backup Recovery sobre el SHA exacto resultante de `main`.
4. Cerrar #284 y continuar con el siguiente issue público prioritario que siga vigente.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
