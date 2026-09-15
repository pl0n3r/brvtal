# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- Se consolidó el ciclo automático de validación en un único workflow: `BRVTAL CI`.
- `fast` ahora calcula el alcance del diff y ejecuta PHP 8.5, todos los contratos PHP de nivel superior, sintaxis JavaScript y la validación del snapshot README en PRs.
- Pull requests y pushes exactos a `main` usan la misma selección path-aware; `workflow_dispatch` conserva la matriz completa.
- Los cambios JS/CSS de DISCADMIN ya no fuerzan por defecto MariaDB + real-stack; los cambios PHP/API/config/database siguen seleccionando las capas de integración que corresponden.
- El recovery rehearsal sigue existiendo como job aislado, pero solo se activa por superficies de backups/recovery o por ejecución manual completa.
- Se eliminaron cuatro workflows automáticos redundantes para reducir checkout/setup duplicado y competencia por runners.
- Los smokes reales de producción autenticado y Page-write permanecen manuales y separados.
- No hay cambios de esquema, datos, permisos ni comportamiento de producción de la aplicación.

## Archivos modificados en este deploy

- `.github/workflows/backup-recovery-rehearsal.yml` — eliminado; el rehearsal pasa al job path-aware `recovery` de BRVTAL CI.
- `.github/workflows/php85-compatibility.yml` — eliminado; PHP 8.5 se valida dentro de `fast`.
- `.github/workflows/production-smoke-contract.yml` — eliminado; su contrato PHP se ejecuta automáticamente dentro de `fast`.
- `.github/workflows/readme-deploy-snapshot.yml` — eliminado; la verificación exacta del README se ejecuta dentro de `fast` en PRs.
- `.github/workflows/update-release-metadata.yml` — consolida planificación + fast gate, selección path-aware para PR/main, recovery opcional y `validate` estable.
- `AGENTS.md` — actualiza el contrato canónico del ciclo de entrega y elimina la dependencia del workflow PHP 8.5 separado.
- `docs/TESTING.md` — documenta el CI consolidado, la selección por paths y el nuevo lugar del recovery rehearsal.
- `scripts/php85-compatibility.sh` — auto-descubre y ejecuta todos los contratos PHP top-level además de lint/compatibilidad PHP 8.5.
- `tests/backup-recovery-rehearsal-contract.php` — protege el recovery integrado en BRVTAL CI en lugar de exigir un workflow separado.
- `tests/project-operations-contract.php` — protege la topología consolidada y evita que reaparezcan los workflows retirados.
- `README.md` — snapshot operativo exacto de este deploy.

## Validación

- Base de trabajo: `main` `f591e85b43cb7b58bd317be85c271d36ccb8463a`, con BRVTAL CI exacto en verde antes de abrir este batch.
- El diseño conserva `branch → tests → PR → CI → squash merge → exact-main-CI`; la optimización elimina gates duplicados y hace path-aware también el push de `main`.
- `validate` continúa siendo el único resultado agregado estable del pipeline.
- El propio cambio de `.github/workflows/update-release-metadata.yml` fuerza todos los jobs, incluido recovery, en este PR y en su primer push a `main`, para validar la nueva topología completa antes de beneficiarse del recorte en cambios posteriores.
- Pendiente: ejecución de los gates del PR y, tras squash merge, validación del SHA exacto nuevo de `main`.
- CI verde significa **VALIDATED IN CODE**; no implica **VALIDATED IN PRODUCTION**.

## Qué sigue

1. Abrir el PR y validar la topología consolidada completa.
2. Corregir cualquier fallo en esta misma rama y refrescar este README si cambia el file set.
3. Con `validate` verde, hacer squash merge y comprobar el nuevo SHA exacto de `main`.
4. Medir los siguientes ciclos reales para confirmar reducción de cola/setup sin perder cobertura aplicable.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
