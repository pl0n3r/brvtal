# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- Content Core valida y normaliza fechas de Events, Ticket Types y lifecycle del colectivo antes de escribir en MariaDB; input malformado o fechas imposibles devuelven HTTP 422 con el campo afectado.
- Ticket Types rechaza ventanas de disponibilidad invertidas cuando `available_until` precede a `available_from`, incluyendo PUT parciales combinados con el estado existente.
- Pages exige identidad editorial válida en POST/PUT: título no vacío y slug no vacío; en creación el slug puede generarse de forma segura desde un título válido.
- El editor de Pages marca Title y Slug como campos obligatorios y conserva el locale público inglés por defecto.
- Se añadieron contratos PHP y una regresión real-stack autenticada contra PHP/MariaDB para probar fechas, identidad y ventanas temporales.

## Archivos modificados en este deploy

- `README.md` — snapshot operativo exacto de este deploy.
- `api/content-validation.php` — contrato compartido para fechas, ventanas de tickets e identidad de Pages.
- `api/index.php` — aplica las validaciones compartidas antes de INSERT/UPDATE y sobre el estado final de PUT parciales.
- `discadmin/pages-publication-contract.js` — expone Title/Slug como obligatorios en el editor de Pages.
- `tests/content-validation-contract.php` — cubre normalización temporal, fechas imposibles, ventanas y Page identity.
- `tests/e2e/content-validation-real-stack.spec.mjs` — prueba respuestas 422 y persistencia válida contra el stack real de CI.

## Validación

- Base exacta: `main` `2fa698a8198ef61ff1d6d3fc246dd03ce22cb998`, con `BRVTAL CI / validate` verde.
- No había PRs abiertos al crear `fix/content-validation-quick-wins`.
- Issues cubiertos: `#163`, `#225`, `#247`.
- No hay migración de base de datos, cambios de schema, borrado, restore ni mutación de datos de producción.
- `datetime-local` válido se normaliza a formato SQL sin perder compatibilidad con los formularios actuales.
- Campos temporales opcionales vacíos se normalizan a `NULL`; drafts incompletos siguen permitidos.
- La validación de ventanas de tickets en PUT usa `array_replace($before, $p)` para no perder el extremo ya persistido.
- Las regresiones quedan dentro de PHP 8.5 y real-stack; el clasificador de CI decidirá los gates adicionales por los archivos modificados.
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
- Issues abordados: `#163`, `#225`, `#247`.
