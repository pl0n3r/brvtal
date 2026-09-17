# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aquí el snapshot exacto de lo que cambió y un panorama general actualizado de lo pendiente.

## Qué se hizo

- El sitemap público queda alimentado directamente por el contenido público/indexable actual de BRVTAL, sin XML estático.
- Se centralizan `events`, `artists`, `sets`, `releases`, `blog` y `pages` en un registro compartido por SEO y sitemap.
- Publicar, despublicar o actualizar contenido se refleja en la siguiente lectura; borradores, DISCADMIN, APIs y recursos privados quedan fuera.
- `/sitemap.xml` es la URL canónica; `/sitemap.php` redirige permanentemente al XML y PHP queda como renderer interno.
- El sitemap exige revalidación en vez del cache público anterior de 15 minutos.
- El renderer XML se separa en una función pura y se endurece según el protocolo soportado por Google: UTF-8, `urlset` con namespace estándar, `url > loc`, URLs HTTPS absolutas del host canónico, `<loc>` menor de 2048 caracteres, entity escaping y `lastmod` válido `YYYY-MM-DD` cuando exista.
- Un contrato ejecutable genera y parsea el XML con `DOMDocument`; rechaza XML mal formado, URLs relativas/off-host, fechas inválidas y regresiones de namespace/estructura.
- `robots.txt` continúa anunciando únicamente `https://www.brvtal.com.co/sitemap.xml`.
- Los contratos existentes de SEO, Contact y semántica de fallos se alinean al registro/cache nuevos sin reducir garantías.
- `AGENTS.md` incorpora la regla canónica de paralelismo seguro; los merges a `main` siguen serializados.
- No hay cambios de base de datos ni migraciones.

## Archivos modificados en este deploy

- `.htaccess` — canonicalización pública `sitemap.php` → `sitemap.xml` y render interno.
- `AGENTS.md` — paralelismo seguro y merges serializados como regla canónica.
- `README.md` — snapshot exacto del deploy y panorama pendiente.
- `config/public_routes.php` — registro canónico de familias, structured data y rutas indexables.
- `config/public_seo.php` — consumo del registro común para entidades SEO.
- `config/public_sitemap.php` — renderer XML compatible con el protocolo de Sitemaps y guardas de URL/fecha.
- `sitemap.php` — sitemap dinámico, revalidado y renderizado mediante el helper validado.
- `tests/public-contact-contract.php` — Contact usa la fuente canónica del sitemap.
- `tests/public-failure-semantics-contract.php` — semántica de error y cache sano actualizados.
- `tests/public-seo-delivery-contract.php` — structured data validado desde el registro compartido.
- `tests/public-sitemap-contract.php` — sincronización de registro, routing, renderer XML y `robots.txt`.
- `tests/public-sitemap-xml-contract.php` — parseo real y contrato estructural compatible con Google/Sitemaps.
- `tests/seo-defaults-contract.php` — fallback `content_json` de Pages desde el registro común.

## Validación

- Base exacta recontrastada: `main` `49a1bfe7490ed98daf183801ac9f6e200fb99f57`; BRVTAL CI #820 quedó verde y #468 está **VALIDATED IN CODE**.
- Los heads previos del PR detectaron y corrigieron dos contratos SEO textuales obsoletos; `e06aa25d9737640bce73613b934b4cbf205c45a3` pasó BRVTAL CI #835 y Sonar Quality Gate.
- El head `6efd3f9b94e5f6baec70dd3cbdbaf3e482f647f4` pasó BRVTAL CI #838 (`fast`, `database`, `real-stack`, `chromium`, `validate`) y Sonar Quality Gate; CodeRabbit permanecía pendiente.
- La validación XML añadida cambia nuevamente el head: BRVTAL CI, SonarQube Cloud y CodeRabbit deben ejecutarse sobre el SHA exacto nuevo antes del squash merge.
- El contrato XML comprueba de forma ejecutable la estructura publicada por Google/Sitemaps; no se declara validación de producción por pasar CI.
- Después del merge se verificará BRVTAL CI sobre el SHA exacto resultante de `main`; verde significará **VALIDATED IN CODE**.

## Qué sigue

- Cerrar gates del head exacto, corregir findings válidos, squash merge y verificar el nuevo `main`.
- Retomar #451 con el HIGH `javascript:S7761` de `discadmin/content-core-nav.js`, preservando la presencia de `data-health-open` incluso cuando su valor sea vacío y cubriéndolo en navegador.
- Revalidar luego los quick wins vigentes de `discadmin/media-library.js` sin mezclar el refactor de complejidad.
- Mantener #434 (Memories) separado; no ejecutar automáticamente su migración de producción.

## Panorama general pendiente

- **Sitemap / SEO técnico:** tras integrar, verificar `/sitemap.xml` en producción/Search Console y mantener el registro canónico al añadir nuevas familias públicas.
- **DOM API quick wins:** #461, #462, #466 y #468 integrados y **VALIDATED IN CODE**; continuar solo con findings vigentes.
- **Content Core navigation:** HIGH `S7761` revalidado y preflightado como siguiente bloque.
- **Media Library DOM:** quick wins `dataset`/`Element.after()` revalidados; tratarlos separados de complejidad.
- **Memories administrable:** #415 / PR #434 — cerrar Quality Gate y tratar su migración de producción aparte.
- **Sonar / calidad:** #451 — continuar por riesgo y área con PRs pequeños.
- **Archivo cultural:** #398 / #403 — relaciones explícitas Event ↔ Artist ↔ Set ↔ Release ↔ Memory.
- **Analytics / GA4:** #427 — completar mapeo `brvtal_*` en GTM/GA4.
- **SEO editorial:** #390, #391 y #272 — workspace SEO y structured data por entidad.
- **DISCADMIN:** #348, #351 y #365 — navegación, Theme Studio y Dashboard.
- **Operación / historial:** #232, #207 y #388 — Activity, System Status y RESET LOG seguro.
- **Media / mobile:** #260 — inspector accesible tras seleccionar un asset en móvil.
- **Seguridad editorial / UX:** #257 — proteger cambios no guardados.
- **Backups:** #389 — scheduling seguro y copia opcional a Google Drive con autorización.
- **Bulk Actions:** #275 — superar el recorte local de 500 sin búsquedas parciales engañosas.
- **Idioma:** #212 — experiencia ES/EN manteniendo español canónico.
- **Performance / recovery:** optimizar solo con evidencia de regresiones o cuellos medidos.
