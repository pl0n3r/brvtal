# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. Se reemplaza en cada deploy. El contexto durable vive en `AGENTS.md` y, cuando aplica, en `docs/`.

## Qué se hizo

- Se añade una validación de producción controlada para #122, separada del smoke read-only de #123–#125.
- El nuevo workflow es exclusivamente manual, solo admite `main`, exige el token explícito `WRITE_AND_DELETE_TEMP_PAGE`, verifica el SHA exacto desplegado y usa las mismas credenciales aisladas del environment `production-smoke`.
- La prueba reproduce el caso original con una única CMS Page temporal publicada y `content_json={"text":"Manifiesto"}`.
- La Page usa un slug único `production-smoke-122-...`, se relee para comprobar persistencia y se elimina en cleanup usando únicamente el ID creado por esa ejecución.
- Si la respuesta de creación fuera ambigua, el cleanup busca exclusivamente el slug único de esa ejecución antes de intentar eliminarlo.
- La prueba exige confirmar después que el ID temporal devuelve HTTP 404; un cleanup no verificado hace fallar el run.
- El contrato de seguridad se amplía para impedir triggers automáticos, credenciales embebidas, PUT/PATCH de contenido existente o borrados fuera del registro temporal.

## Archivos modificados en este deploy

- `.github/workflows/production-page-write-smoke.yml` — workflow manual y explícitamente confirmado para reproducir #122 y limpiar la Page temporal.
- `.github/workflows/production-smoke-contract.yml` — incorpora el nuevo workflow/probe al gate estático y valida sintaxis Node.
- `tests/e2e/production-page-write-smoke.mjs` — autenticación, verificación de deploy, creación/lectura y cleanup verificado de la Page temporal.
- `tests/production-smoke-contract.php` — protege los límites read-only de #123–#125 y los límites controlados de escritura de #122.
- `docs/TESTING.md` — documenta el procedimiento, riesgo acotado, criterio de cleanup y definición de VALIDATED IN PRODUCTION para #122.
- `README.md` — snapshot operativo de este deploy.

## Validación

- El PR debe pasar `BRVTAL CI / validate`, `PHP 8.5 Compatibility / php85` y `Production Smoke Contract / contract` antes del merge.
- El contrato exige que ambos smokes de producción sigan siendo manual-only, usen el origen canónico y secretos, y estén ligados al SHA exacto de `main`.
- El smoke de #123–#125 continúa siendo content-read-only.
- El smoke de #122 puede escribir únicamente una Page temporal creada por esa misma ejecución y debe verificar su eliminación.
- Este deploy **no equivale todavía a VALIDATED IN PRODUCTION**: los workflows manuales deben ejecutarse después del merge contra el SHA desplegado.
- Producción canónica: `https://www.brvtal.com.co`.

## Qué sigue

1. Tras merge + CI verde de `main`, configurar/verificar `BRVTAL_PROD_ADMIN_EMAIL`, `BRVTAL_PROD_ADMIN_PASSWORD` y, si aplica, `BRVTAL_PROD_TOTP_SECRET` en el environment `production-smoke`.
2. Ejecutar `Authenticated Production Smoke` para #123, #124 y #125 y revisar `production-authenticated-smoke.json`.
3. Ejecutar `Controlled Production Page Write Smoke` con `WRITE_AND_DELETE_TEMP_PAGE` para #122 y confirmar `cleanup.verifiedAbsent=true` en `production-page-write-smoke.json`.
4. Registrar la evidencia correspondiente y cerrar únicamente los issues que hayan quedado **VALIDATED IN PRODUCTION**.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Especificaciones profundas: `docs/`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
