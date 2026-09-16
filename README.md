# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- Se consolidó la política de mutaciones Media en `api/media-library.php`: las rutas legacy de upload y CRUD genérico ya no son alcanzables para escribir o borrar Media.
- Los paths locales `/uploads/...` ya no pueden registrarse dos veces; duplicados históricos cuentan como ownership compartido y bloquean el borrado canónico.
- El delete canónico adquiere el mutex de referencias cuando está disponible, mueve original/sidecar/variantes al árbol web-denied `.private` antes de borrar la fila DB y restaura el staging si la transacción no puede completarse.
- Un fallo de limpieza posterior al commit ya no deja archivos públicamente accesibles; queda como deuda privada reportada y registrada para limpieza.
- Se añadieron regresiones PHP, MariaDB y real-stack para rutas legacy, ownership duplicado, staging/rollback y el flujo canónico upload→register duplicate→delete.

## Archivos modificados en este deploy

- `README.md` — snapshot operativo exacto de este deploy.
- `api/media-library.php` — aplica ownership compartido, registro local único y borrado DB↔filesystem con staging privado/rollback.
- `api/route.php` — cierra las mutaciones legacy de `upload` y `media` manteniendo GET Media compatible.
- `config/media_integrity.php` — centraliza ownership duplicado, mutex opcional y staging/restauración/finalización privada del borrado.
- `tests/media-integrity-contract.php` — prueba fail-closed de rutas legacy y comportamiento filesystem del staging/restauración.
- `tests/integration/media-reference-atomicity.php` — valida en MariaDB que dos registros con el mismo path local se tratan como ownership compartido.
- `tests/e2e/media-integrity-real-stack.spec.mjs` — prueba con PHP/MariaDB reales que solo Media Library puede mutar y que el flujo canónico conserva `draft`, rechaza duplicados y borra de forma segura.

## Validación

- Base exacta: `main` `2fe6ec647d5178b37cebc5535dd94674a47f8d33`, con `BRVTAL CI / validate` verde (run #553).
- El PR residual #381 fue cerrado antes de iniciar esta rama porque su trabajo quedó absorbido y ampliado por #382.
- Issues cubiertos: `#159`, `#227`, `#228`, `#371`.
- No hay migración de base de datos, cambio de schema, restore, bulk delete ni mutación de datos de producción.
- La nueva política reutiliza la migración de guardia/mutex ya existente cuando está instalada y mantiene compatibilidad fail-safe con instalaciones donde aún no exista esa tabla auxiliar.
- La prueba real-stack crea un asset CI mediante el endpoint canónico y lo elimina en `finally` si la prueba se interrumpe antes del delete esperado.
- Pendiente en este snapshot: `BRVTAL CI / validate`, revisión CodeRabbit y análisis automático de SonarQube Cloud sobre el head final.
- CI verde significará **VALIDATED IN CODE**. No se declarará **VALIDATED IN PRODUCTION** sin comprobar el deploy real y la superficie correspondiente.

## Qué sigue

1. Resolver en esta misma rama cualquier finding válido de BRVTAL CI, CodeRabbit o SonarQube Cloud.
2. Hacer squash merge solo con `BRVTAL CI / validate` verde y revisar los threads finales de CodeRabbit.
3. Verificar `BRVTAL CI / validate` del SHA exacto resultante en `main`.
4. Repriorizar el siguiente deploy por riesgo/impacto técnico entre revocación de sesiones (#167), invariantes de Content Core y deuda estructural restante de Media/DISCADMIN.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de validación: `docs/TESTING.md`.
- Issues abordados: `#159`, `#227`, `#228`, `#371`.
