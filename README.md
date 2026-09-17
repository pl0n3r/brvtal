# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aquí, además del snapshot exacto de lo que cambió, un **panorama general actualizado de lo que sigue pendiente por hacer/desarrollar**.

## Qué se hizo

- Continúa #451 con un bloque acotado de Reliability para tres findings Sonar `javascript:S8786`.
- `discadmin/seo-editorial-defaults.js` reemplaza dos regex de truncación potencialmente super-lineales por búsqueda del último espacio y limpieza lineal del sufijo, conservando el límite y el corte por palabra.
- `js/public-measurement.js` reemplaza la regex de barras finales por un recorrido lineal que conserva la normalización de rutas públicas.
- Se amplían las pruebas Playwright existentes con texto SEO adversarial y rutas con múltiples `/` finales.
- Se añade un contrato auto-descubierto que impide reintroducir los tres patrones `S8786` tratados en este deploy.
- El finding `S8786` de `discadmin/theme-studio-v2.js` queda deliberadamente separado para el siguiente PR y no se declara resuelto aquí.

## Archivos modificados en este deploy

- `README.md` — snapshot exacto del deploy y panorama pendiente actualizado.
- `discadmin/seo-editorial-defaults.js` — truncación SEO lineal sin los dos regex `S8786` reportados.
- `js/public-measurement.js` — normalización lineal de barras finales en rutas públicas.
- `tests/e2e/discadmin-seo-defaults.spec.mjs` — cobertura ejecutable de truncación larga y limpieza de sufijo.
- `tests/e2e/public-measurement.spec.mjs` — cobertura de contexto de entidad con barras finales repetidas.
- `tests/sonar-regex-reliability-contract.php` — contrato fuente para los tres patrones `S8786` resueltos.

## Validación

- Base exacta: `main` `e3e2bf6a8acae0366c30bf372c51f2d910b18de9`.
- Ese SHA exacto de `main` pasó BRVTAL CI con `fast`, Chromium y `validate` verdes; los gates no aplicables quedaron omitidos según el selector de scope.
- La rama debe pasar BRVTAL CI, SonarQube Cloud y revisión automatizada sobre el SHA exacto del PR antes del squash merge.
- La validación dirigida incluye las suites Playwright existentes de SEO defaults y public measurement, además del contrato fuente nuevo.
- CI verde significará **VALIDATED IN CODE**, no validación de producción.

## Qué sigue

- Resolver cualquier finding válido de CI, Sonar o CodeRabbit sobre este PR; hacer squash merge solo con los gates aplicables verdes y verificar BRVTAL CI del SHA exacto resultante de `main`.
- Resolver el `S8786` restante de `discadmin/theme-studio-v2.js` en un PR separado con cobertura del slug, sin reescribir el editor completo.
- Continuar #451 por riesgo con los siguientes findings Reliability de DISCADMIN, priorizando problemas funcionales/accesibilidad antes de deuda puramente estilística.
- Mantener #434 (Memories) separado hasta resolver su propio Quality Gate y tratar su migración de producción por separado.

## Panorama general pendiente

Este panorama debe mantenerse actualizado en **cada deploy** y resumir trabajo relevante todavía abierto, aunque no forme parte del deploy actual.

- **Memories administrable:** #415 / PR #434 — galería curada desde DISCADMIN usando Media Library existente, con publicación, orden y viewer editorial; falta cerrar Quality Gate y ejecutar la migración de producción por separado.
- **Sonar / calidad:** #451 — los BLOCKER de seguridad/globals, `S2871` y `S7727` ya se trabajaron; este deploy resuelve 3 de los 4 `S8786` identificados y deja Theme Studio aislado como siguiente bloque; después siguen Reliability y deuda Maintainability por riesgo y área.
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
