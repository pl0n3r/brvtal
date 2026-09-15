# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- Blog aplica política latest-intent-wins al abrir posts: cancela la lectura anterior y una respuesta obsoleta no puede reemplazar el editor ni su `save(id)`.
- Media Library aplica la misma política al inspector: una lectura de detalle vieja no puede cambiar el asset seleccionado después de una selección más reciente.
- Bulk Actions aísla cada carga por módulo, invalida cargas al cerrar/cambiar de recurso y solo permite APPLY cuando los IDs seleccionados pertenecen al catálogo vigente.
- Admin Activity comparte una única secuencia latest-intent-wins entre DETAIL e HISTORY; respuestas anteriores no pueden reemplazar el último modal solicitado.
- Playwright fuerza respuestas fuera de orden incluso neutralizando `abort()` en el harness para demostrar que los tokens de intención también protegen el estado.
- No hay cambios de API, esquema, migraciones, permisos ni datos de producción.

## Archivos modificados en este deploy

- `discadmin/blog.js` — cancelación + token de intención para detalle editorial.
- `discadmin/media-library.js` — cancelación + token de intención para selección del inspector.
- `discadmin/bulk-actions.js` — aislamiento de catálogo por módulo, invalidación de cargas e IDs fail-closed antes de mutar.
- `discadmin/admin-activity.js` — DETAIL/HISTORY comparten cancelación y latest-wins.
- `tests/e2e/discadmin-latest-wins-quick-wins.spec.mjs` — regresiones de respuestas fuera de orden para #176, #278, #287 y #289.
- `README.md` — snapshot operativo de este deploy.

## Validación

- Rama creada desde `main` `a7b654b4b8f0fdc6b012a2b706537dd4f9891d23`.
- Ese SHA exacto tenía BRVTAL CI completo —fast, database, Chromium, WebKit, real-stack y validate—, PHP 8.5 Compatibility y Backup Recovery Rehearsal en success.
- Pendiente de gates del PR y, tras el merge, matriz completa sobre el SHA exacto nuevo de `main`.
- CI verde significa **VALIDATED IN CODE**; no implica **VALIDATED IN PRODUCTION**.

## Qué sigue

1. Ejecutar los gates del PR y corregir cualquier fallo en esta misma rama.
2. Si CI exige cambios, volver a compactar la rama a un único commit antes del merge.
3. Con CI verde, hacer squash merge y verificar BRVTAL CI completo, PHP 8.5 y Backup Recovery sobre el nuevo SHA exacto de `main`.
4. Continuar con el siguiente batch de Quick Wins relacionados.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
