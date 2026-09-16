# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- Se cerró la integridad de relaciones polimórficas de Blog: una relación `event`, `artist`, `set` o `release` solo puede persistirse si el destino existe realmente.
- La validación ocurre dentro de la misma transacción que crea/actualiza el Blog Post y bloquea los destinos válidos con `FOR UPDATE`, evitando que una eliminación concurrente deje una relación colgante entre validación y commit.
- Un POST/PUT con destino inexistente devuelve `422 BLOG_RELATION_NOT_FOUND` antes de modificar el post o sus relaciones existentes.
- Un PUT rechazado conserva tanto los campos anteriores del Blog Post como sus relaciones anteriores; no deja un estado parcial.
- Se añadieron contrato PHP, regresión MariaDB y smoke autenticado PHP/MariaDB/Chromium para probar destinos válidos/inválidos y rollback de actualización.

## Archivos modificados en este deploy

- `README.md` — snapshot operativo exacto de este deploy.
- `api/blog-relations.php` — frontera canónica para validar/bloquear destinos polimórficos de Blog dentro de la transacción.
- `api/blog.php` — aplica la validación de relaciones antes de INSERT/UPDATE y antes de sincronizar relaciones.
- `package.json` — incorpora la regresión MariaDB de Blog al gate de integración canónico.
- `tests/blog-contract.php` — contrato rápido del helper y del wiring transaccional del endpoint.
- `tests/e2e/blog-relation-integrity-real-stack.spec.mjs` — comprueba por HTTP autenticado que una relación inexistente devuelve 422 y no altera el post ni sus relaciones previas.
- `tests/e2e/run-content-core-real-stack.sh` — incorpora el smoke real-stack de integridad de relaciones de Blog.
- `tests/integration/blog-relations.php` — valida contra MariaDB los cuatro tipos de relación y el rechazo de destinos inexistentes.

## Validación

- Base exacta: `main` `ac614a48e66ebcdeb18d3f624a5243ffe962db71`, con `BRVTAL CI / validate` verde (run #569).
- Issue cubierto: `#158`.
- No hay migración, cambio de schema, restore, bulk delete ni mutación de datos de producción.
- Las pruebas MariaDB y real-stack usan únicamente la base `brvtal_test...` y fixtures CI.
- El smoke real-stack confirma explícitamente rollback/no-mutación tras el 422.
- Pendiente en este snapshot: `BRVTAL CI / validate`, revisión CodeRabbit y análisis automático de SonarQube Cloud sobre el head final.
- CI verde significará **VALIDATED IN CODE**. No se declarará **VALIDATED IN PRODUCTION** sin comprobar el deploy real y una operación autenticada controlada.

## Qué sigue

1. Resolver en esta misma rama cualquier finding válido de BRVTAL CI, CodeRabbit o SonarQube Cloud.
2. Hacer squash merge solo con `BRVTAL CI / validate` verde y revisar los threads finales de CodeRabbit.
3. Verificar `BRVTAL CI / validate` del SHA exacto resultante en `main`.
4. Recalcular el backlog y priorizar los requerimientos nuevos registrados (`#387`–`#391`) junto con los defects abiertos de mayor impacto, sin mezclar dominios arbitrariamente.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de validación: `docs/TESTING.md`.
- Issue abordado: `#158`.
