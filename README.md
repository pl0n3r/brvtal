# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aquí, además del snapshot exacto de lo que cambió, un **panorama general actualizado de lo que sigue pendiente por hacer/desarrollar**.

## Qué se hizo

- Continúa #451 con un PR aislado de Reliability para el cuarto y último finding Sonar `javascript:S8786` identificado en el bloque actual.
- `discadmin/theme-studio-v2.js` deja de recortar guiones de los slugs con la regex de bordes reportada y usa un helper lineal con `startsWith()` / `endsWith()` e índices.
- Se conserva el contrato funcional de `safeSlug`: minúsculas, reemplazo de caracteres no permitidos por `-`, eliminación de guiones en los extremos, `_` permitido, límite de 60 caracteres y fallback `theme` cuando el resultado queda vacío.
- Se añade una prueba Playwright específica que ejecuta `currentTheme()` con slugs adversariales y valida bordes, underscores, longitud y fallback.
- El contrato fuente `S8786` se amplía para impedir que reaparezca la regex de Theme Studio y exigir el recorrido lineal.
- No se modifica el modelo de Theme Studio, persistencia, activación, Media picker, estilos ni runtime público.

## Archivos modificados en este deploy

- `README.md` — snapshot exacto del deploy y panorama pendiente actualizado.
- `discadmin/theme-studio-v2.js` — limpieza lineal de guiones de borde para `safeSlug`.
- `tests/e2e/discadmin-theme-studio-slug.spec.mjs` — cobertura ejecutable del comportamiento del slug de Theme Studio.
- `tests/sonar-regex-reliability-contract.php` — contrato fuente ampliado al cuarto `S8786`.

## Validación

- Base exacta: `main` `c5c713ec94e344e753600d2315bff2bde7debbc5`.
- Ese SHA exacto de `main` pasó BRVTAL CI #771 con `fast`, Chromium y `validate` verdes; los gates no aplicables quedaron omitidos según el selector de scope.
- La rama debe pasar BRVTAL CI, SonarQube Cloud y revisión automatizada sobre el SHA exacto del PR antes del squash merge.
- La validación dirigida incluye el nuevo Playwright de Theme Studio y el contrato fuente que cubre los cuatro patrones `S8786` tratados.
- CI verde significará **VALIDATED IN CODE**, no validación de producción.

## Qué sigue

- Resolver cualquier finding válido de CI, Sonar o CodeRabbit sobre este PR; hacer squash merge solo con los gates aplicables verdes y verificar BRVTAL CI del SHA exacto resultante de `main`.
- Continuar #451 por riesgo con los siguientes findings HIGH de Reliability, priorizando problemas con impacto funcional antes de deuda puramente estilística.
- Revalidar cada finding del reporte contra el nuevo `main` antes de tocar código para evitar arreglar rutas o firmas que ya hayan cambiado.
- Mantener #434 (Memories) separado hasta resolver su propio Quality Gate y tratar su migración de producción por separado.

## Panorama general pendiente

Este panorama debe mantenerse actualizado en **cada deploy** y resumir trabajo relevante todavía abierto, aunque no forme parte del deploy actual.

- **Memories administrable:** #415 / PR #434 — galería curada desde DISCADMIN usando Media Library existente, con publicación, orden y viewer editorial; falta cerrar Quality Gate y ejecutar la migración de producción por separado.
- **Sonar / calidad:** #451 — los BLOCKER de seguridad/globals, `S2871`, `S7727` y los cuatro `S8786` identificados ya se trabajaron; continúan findings HIGH/MEDIUM de Reliability y deuda Maintainability a resolver por riesgo y por área.
- **Archivo cultural:** #398 / #403 — profundizar relaciones explícitas Event ↔ Artist ↔ Set ↔ Release ↔ Memory sin inferencias falsas.
- **Analytics / GA4:** #427 — completar mapeo `brvtal_*` en GTM/GA4 y preparar lectura segura futura en Dashboard.
- **SEO:** #390, #391 y #272 — workspace SEO, alineación del bloque actual y structured data por entidad.
- **DISCADMIN:** #348, #351 y #365 — simplificación de navegación, Theme Studio y consolidación de Dashboard.
- **Operación / historial:** #232, #207 y #388 — Activity completo, System Status fiable y RESET LOG seguro.
- **Media / mobile:** #260 — inspector accesible inmediatamente tras seleccionar un asset en móvil.
- **Seguridad editorial / UX:** #257 — proteger cambios no guardados en editores legacy.
- **Backups:** #389 — scheduling seguro y copia opcional a Google Drive cuando existan autorización/credenciales.
- **Bulk Actions:** #275 — alcanzar registros más allá del recorte local de 500 sin presentar búsquedas parciales como exhaustivas.
- **Idioma:** #212 — futura experiencia ES/EN manteniendo español canónico.
- **Performance / recovery:** conservar evidencia y optimizar solo ante regresiones o cuellos de botella medidos.
