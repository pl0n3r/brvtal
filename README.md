# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aquí, además del snapshot exacto de lo que cambió, un **panorama general actualizado de lo que sigue pendiente por hacer/desarrollar**.

## Qué se hizo

- Continúa #451 atacando los cuatro BLOCKER `javascript:S2703` restantes del baseline Sonar sin mezclar refactors de Maintainability de otras áreas.
- La ruta canónica `/discadmin` expone `csrf` y `state` como propiedades explícitas de `window` en lugar de depender de bindings globales léxicos que los módulos externos modificaban implícitamente.
- `admin-auth-boundary.js`, `admin-reliability.js` y `totp-login.js` actualizan el mismo estado/CSRF canónico mediante `window.state` y `window.csrf`.
- El antiguo Hero Slider state bridge deja de crear un setter con asignación implícita y pasa a verificar la superficie explícita ya creada por el shell.
- `index-core.php` conserva su fallback interno: el override TOTP solo adopta el estado explícito cuando se ejecuta desde la ruta canónica envuelta por `discadmin/index.php`.
- Se añade un contrato auto-descubierto que impide reintroducir asignaciones implícitas de `csrf` o `state`, y la prueba Playwright del boundary usa la nueva superficie explícita.

## Archivos modificados en este deploy

- `README.md` — snapshot exacto del deploy y panorama pendiente actualizado.
- `discadmin/index.php` — convierte la declaración runtime del shell canónico a `window.csrf` / `window.state` y falla si el patrón esperado no aparece exactamente una vez.
- `discadmin/admin-auth-boundary.js` — limpia sesión/CSRF usando propiedades explícitas.
- `discadmin/admin-reliability.js` — login, logout y relaciones usan el estado explícito del shell.
- `discadmin/hero-slider-state-bridge.js` — retira el setter implícito y valida la disponibilidad del estado canónico.
- `discadmin/totp-login.js` — restore de sesión usa `window.csrf` / `window.state` solo cuando la superficie canónica existe.
- `tests/admin-runtime-globals-contract.php` — contrato contra regresiones S2703 en los cuatro módulos.
- `tests/e2e/admin-reliability-quick-wins.spec.mjs` — verifica que un 401 limpie el estado/CSRF explícito y vuelva al login.

## Validación

- Base exacta: `main` `104b20daaf8db077acd59924f24168392b784f8f`.
- Ese SHA exacto de `main` pasó BRVTAL CI: `fast`, Chromium y `validate` verdes; los gates no aplicables quedaron omitidos según el selector de scope.
- La rama debe pasar BRVTAL CI, SonarQube Cloud y revisión automatizada aplicable sobre el SHA exacto del PR antes del squash merge.
- Por tocar TOTP/sesión, el selector de CI debe mantener la cobertura aplicable de autenticación, incluido WebKit cuando corresponda.
- CI verde significará **VALIDATED IN CODE**, no validación de producción.

## Qué sigue

- Resolver cualquier finding válido de CI, Sonar o CodeRabbit sobre este PR; hacer squash merge solo con los gates aplicables verdes y verificar BRVTAL CI del SHA exacto resultante de `main`.
- Reconsultar Sonar tras el merge para confirmar que los cuatro BLOCKER `S2703` desaparecieron del baseline.
- Continuar #451 con Reliability de mayor impacto: `discadmin/media-library.js` `S2871`, `js/related-content.js` `S7727` y regex `S8786`, cada uno en PRs pequeños con cobertura proporcional.
- Mantener #434 (Memories) separado hasta resolver su propio Quality Gate y tratar su migración de producción por separado.

## Panorama general pendiente

Este panorama debe mantenerse actualizado en **cada deploy** y resumir trabajo relevante todavía abierto, aunque no forme parte del deploy actual.

- **Memories administrable:** #415 / PR #434 — galería curada desde DISCADMIN usando Media Library existente, con publicación, orden y viewer editorial; falta cerrar Quality Gate y ejecutar la migración de producción por separado.
- **Sonar / calidad:** #451 — después de este bloque quedan findings HIGH/MEDIUM de Reliability y deuda Maintainability; continuar por riesgo, no por volumen.
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
