# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- Follow-up de revisión para el batch de validación de Content Core integrado en #379.
- Los campos temporales del API rechazan arrays/objetos como `INVALID_DATE` antes de cualquier conversión a string, evitando warnings PHP y respuestas JSON contaminadas.
- La regresión PHP cubre explícitamente valores temporales JSON no escalares.
- La regresión real-stack usa identidades únicas por ejecución y limpia en `finally` todos los Ticket Types, Pages y Events que alcanza a crear.
- El real-stack prueba directamente un PUT parcial de Ticket Type donde el nuevo `available_from` entra en conflicto con el `available_until` persistido, protegiendo la validación del estado final combinado.

## Archivos modificados en este deploy

- `README.md` — snapshot operativo exacto de este deploy.
- `api/content-validation.php` — rechazo temprano y limpio de valores temporales no escalares.
- `tests/content-validation-contract.php` — regresión PHP para fecha JSON no escalar.
- `tests/e2e/content-validation-real-stack.spec.mjs` — namespace único, cleanup garantizado y cobertura real del PUT parcial de ventana de tickets.

## Validación

- Base exacta: `main` `cea4a357e518fac4bf39abed1acb9742b726280e`, con `BRVTAL CI / validate` verde.
- No había PRs abiertos al crear `fix/content-validation-review-hardening`.
- Este deploy responde a tres findings sustantivos de CodeRabbit recibidos después de que #379 ya hubiera sido squash-mergeado.
- No hay migración de base de datos, cambios de schema, restore, borrado de producción ni mutación de datos de producción.
- El cleanup añadido existe solo dentro del stack efímero de pruebas y elimina únicamente IDs creados y registrados por esa misma ejecución.
- Pendiente en este snapshot: `BRVTAL CI / validate`, revisión advisory de CodeRabbit y análisis automático de SonarQube Cloud sobre el head final.
- CI verde significará **VALIDATED IN CODE**. No se declarará **VALIDATED IN PRODUCTION** sin comprobar el deploy real y la superficie correspondiente.

## Qué sigue

1. Resolver en esta misma rama cualquier finding válido de BRVTAL CI, CodeRabbit o SonarQube Cloud.
2. Hacer squash merge solo con `BRVTAL CI / validate` verde y sin findings sustantivos de revisión pendientes.
3. Verificar `BRVTAL CI / validate` del SHA exacto resultante en `main`.
4. Retomar la reducción autónoma de issues abiertos desde el nuevo `main` verde.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de validación: `docs/TESTING.md`.
- Follow-up de revisión: PR #379.
