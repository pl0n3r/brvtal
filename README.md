# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- Sets en `draft` pueden seguir incompletos, pero un Set no puede entrar a `published` sin una **Listening URL** válida.
- La política de publicación exige URL `http(s)` y vive en una función canónica compartida, en vez de depender solo del formulario de DISCADMIN.
- POST y PUT del API genérico validan el estado resultante completo; un PUT parcial que intente publicar un Set cuyo URL persistido está vacío también responde `422` sin modificar la fila.
- Bulk Actions reutiliza la misma regla: antes de publicar Sets bloquea y revisa `external_url` de todos los IDs seleccionados dentro de la misma transacción. Si uno no está listo, el lote completo se rechaza.
- El editor de Sets marca dinámicamente **Listening URL \*** al elegir `Published`, aplica `required`/`aria-required`, exige `http(s)` y enfoca el campo con feedback antes de enviar una publicación inválida.
- Se mantiene una sola semántica entre Content Health y publicación real: un Set públicamente visible siempre tiene destino de escucha.

## Archivos modificados en este deploy

- `README.md` — snapshot operativo exacto de este deploy.
- `api/bulk-actions-lib.php` — valida Listening URL antes de una publicación masiva de Sets.
- `api/index.php` — aplica el contrato de publicación en POST y PUT, incluidos updates parciales.
- `config/set_publication.php` — política canónica `draft` vs `published` para Sets.
- `discadmin/index.php` — carga versionada del guard editorial de Sets dentro del shell canónico.
- `discadmin/set-publication-contract.js` — feedback inmediato y required state en el editor.
- `tests/set-publication-contract.php` — regresión de política, API, Bulk Actions y UI.

## Validación

- Base de trabajo: `main` `0e65905de4ff483714120d883ba6f4371baceda9`.
- Ese SHA tenía BRVTAL CI exacto en verde y Production Performance exitoso, incluido `Wait for exact Hostinger deploy`.
- No había PRs abiertos al iniciar este bloque.
- Issue contrastado inmediatamente antes de desarrollar: `#234` = **Sets can be published without a listening URL**.
- Se preserva ONE SHELL / ONE SIDEBAR / ONE SESSION / ONE CENTRAL WORKSPACE.
- No se publicaron ni alteraron Sets reales para validar esta corrección.
- Pendiente: CI del head final del PR y, después del squash merge, CI + Production Performance del SHA exacto de `main`.
- CI verde significará **VALIDATED IN CODE**. Una edición/publicación manual autenticada en producción seguirá siendo validación de producción separada.

## Qué sigue

1. Ejecutar CI del PR, corregir cualquier regresión y hacer squash merge si `validate` queda verde.
2. Confirmar deploy exacto en Hostinger para el nuevo SHA de `main`.
3. Continuar la auditoría diagnóstica sobre el `main` desplegado, priorizando integridad editorial, seguridad y operaciones.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Bug abordado por este bloque: `#234`.
