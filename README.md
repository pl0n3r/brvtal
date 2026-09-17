# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aquí el snapshot exacto de lo que cambió y un panorama general actualizado de lo pendiente.

## Qué se hizo

- Corrige #391: el bloque compartido de SEO vuelve a respetar el gutter visual de los editores.
- `.brvtal-seo-section` usa el mismo padding horizontal de 17 px que las secciones canónicas de Content Core.
- El componente ocupa el ancho disponible sin escapar del contenedor ni generar overflow horizontal.
- `grid-column:1/-1` evita que el SEO quede atrapado en una sola columna cuando se monta dentro de formularios grid, como Releases y editores legacy.
- Se conserva la lógica existente de carga, preview y persistencia SEO; el cambio es exclusivamente de layout.
- La regresión Playwright compara geometría real contra la sección canónica en desktop y verifica que mobile permanezca dentro del viewport.

## Archivos modificados en este deploy

- `README.md` — snapshot exacto del deploy y panorama pendiente.
- `discadmin/seo-metadata.js` — contrato visual reutilizable del bloque SEO.
- `tests/e2e/discadmin-seo-gutter.spec.mjs` — regresión desktop/mobile de gutter y overflow.

## Validación

- Base exacta: `main` `ce91a61bb677dae4fdede7501438e92e9ccf2744`.
- Ese SHA exacto pasó BRVTAL CI #859 y queda **VALIDATED IN CODE**.
- El head del PR debe pasar BRVTAL CI, SonarCloud y CodeRabbit sobre su SHA exacto antes del squash merge.
- Después del merge se verificará BRVTAL CI sobre el SHA exacto resultante de `main`; CI verde significa **VALIDATED IN CODE**, no validación de producción.

## Qué sigue

- Cerrar gates exactos de #391, corregir findings válidos, hacer squash merge y verificar el nuevo `main`.
- Corregir #260 para que el inspector de Media Library sea accesible inmediatamente al seleccionar un asset en móvil.
- Continuar después con #388 y #365 como siguientes bloques pequeños/medianos independientes.
- Mantener #451 activo por riesgo con findings revalidados contra el código actual.

## Panorama general pendiente

- **Sonar / calidad:** #451 — continuar por riesgo y área, con PRs pequeños y comportamiento preservado.
- **Media / mobile:** #260 — acercar el inspector al asset seleccionado en móvil.
- **System Status:** #388 — RESET LOG seguro reutilizando el boundary existente con POST + auth + CSRF.
- **Dashboard / DISCADMIN:** #365, #348 y #351 — consolidación del Dashboard, IA y Theme Studio.
- **SEO editorial:** #182, #214, #204 y #272 deben sanearse antes de construir #390.
- **Seguridad editorial / navegación:** #257, #216, #174 y #193 — dirty state, modal lifecycle y navegación transaccional.
- **Hero Slider:** #237 y #221 — breakpoint responsive e integridad de media.
- **Content / Media integrity:** #191, #224 y #252 — referencias válidas y edición coherente con el modelo.
- **Activity / operaciones:** #232 y #195 — historial navegable y cobertura de audit log. #207 ya fue revalidado y cerrado.
- **Bulk Actions:** #275 — eliminar el truncado silencioso de catálogos >500.
- **Archivo cultural:** #398 / #403 — seguir con relaciones estructuradas reales y Event Records.
- **Memories:** #415 / PR #434 requiere reconciliación cuidadosa con `main` y mantiene la migración de producción separada.
- **Idioma:** #212 — futura experiencia ES/EN con español canónico y traducción automática por fases.
- **Backups:** #389 — scheduling seguro y copia opcional a Google Drive con autorización externa.
- **Performance / recovery:** optimizar solo ante regresiones o cuellos medidos y mantener producción separada de la validación CI.
