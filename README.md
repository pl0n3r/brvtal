# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aquí el snapshot exacto de lo que cambió y un panorama general actualizado de lo pendiente.

## Qué se hizo

- El sitemap público se genera desde el contenido público/indexable actual de BRVTAL, sin XML estático.
- `events`, `artists`, `sets`, `releases`, `blog` y `pages` comparten un registro canónico usado por SEO y sitemap.
- Publicar, despublicar o actualizar contenido se refleja en la siguiente lectura; borradores, DISCADMIN, APIs y recursos privados quedan fuera.
- `/sitemap.xml` es la URL canónica; `/sitemap.php` redirige permanentemente al XML y PHP queda como renderer interno.
- El sitemap exige revalidación en vez del cache público anterior de 15 minutos.
- El origen queda fijado estrictamente a `https://www.brvtal.com.co`: no se confía en hosts alternos configurados ni en puertos HTTPS no estándar.
- El renderer XML cumple el contrato de Sitemaps usado por Google: UTF-8, `urlset` con namespace estándar, `url > loc`, URLs HTTPS absolutas, `<loc>` menor de 2048 caracteres, entity escaping y `lastmod` válido `YYYY-MM-DD` cuando exista.
- Un contrato ejecutable genera y parsea XML con `DOMDocument`, y cubre URLs relativas/off-host, puerto no estándar, base HTTPS alternativa, fechas inválidas y regresiones de namespace/estructura.
- Cache/headers sanos, rutas estáticas, Contact y tipos Schema.org se validan mediante boundaries ejecutables en vez de depender de búsquedas textuales frágiles.
- `robots.txt` continúa anunciando únicamente `https://www.brvtal.com.co/sitemap.xml`.
- `AGENTS.md` incorpora la regla canónica de paralelismo seguro; los merges a `main` siguen serializados.
- No hay cambios de base de datos ni migraciones.

## Archivos modificados en este deploy

- `.htaccess` — canonicalización pública `sitemap.php` → `sitemap.xml` y render interno.
- `AGENTS.md` — paralelismo seguro y merges serializados como regla canónica.
- `README.md` — snapshot exacto del deploy y panorama pendiente.
- `config/public_routes.php` — registro canónico de familias, structured data y rutas indexables.
- `config/public_seo.php` — consumo del registro común para entidades SEO.
- `config/public_sitemap.php` — origen canónico fijo, renderer XML, URL collection y response policy ejecutables.
- `sitemap.php` — sitemap dinámico que consume las boundaries validadas.
- `tests/public-contact-contract.php` — Contact se comprueba dentro del XML renderizado.
- `tests/public-failure-semantics-contract.php` — semántica de error y cache sano actualizados.
- `tests/public-seo-delivery-contract.php` — tipos Schema.org y XML verificados mediante configuración/renderer ejecutables.
- `tests/public-sitemap-contract.php` — registro, response policy, routing y `robots.txt`.
- `tests/public-sitemap-xml-contract.php` — parseo real y contrato estructural compatible con Google/Sitemaps.
- `tests/seo-defaults-contract.php` — fallback `content_json` de Pages desde el registro común.

## Validación

- Base exacta: `main` `49a1bfe7490ed98daf183801ac9f6e200fb99f57`; BRVTAL CI #820 quedó verde y #468 está **VALIDATED IN CODE**.
- El head `ed1a98caa96e99d277fd2509d8f8ff61b399f3ec` pasó BRVTAL CI #845 completo (`fast`, `database`, `real-stack`, `chromium`, `validate`) y SonarQube Cloud Quality Gate.
- En ese head, el contrato Google/Sitemaps fue ejecutado dentro de la suite PHP 8.5 y pasó con parseo DOM real.
- CodeRabbit cerró la revisión de `ed1a98c...` con dos findings válidos: fijar el origen canónico y reemplazar aserciones textuales del sitemap por pruebas ejecutables. Ambos quedan corregidos en este deploy.
- Esas correcciones cambian nuevamente el head; BRVTAL CI, SonarQube Cloud y CodeRabbit deben volver a pasar sobre el SHA exacto final antes del squash merge.
- CI verde significa **VALIDATED IN CODE**, no validación de producción.
- Después del merge se verificará BRVTAL CI sobre el SHA exacto resultante de `main`.

## Qué sigue

- Cerrar gates del head exacto final, resolver cualquier finding válido restante, squash merge y verificar el nuevo `main`.
- Retomar #451 con el HIGH `javascript:S7761` de `discadmin/content-core-nav.js`, preservando la presencia de `data-health-open` incluso con valor vacío y cubriéndolo en navegador.
- Revalidar luego los quick wins vigentes de `discadmin/media-library.js` sin mezclar el refactor de complejidad.
- Mantener #434 (Memories) separado; no ejecutar automáticamente su migración de producción.

## Panorama general pendiente

- **Sitemap / SEO técnico:** tras integrar, verificar `/sitemap.xml` en producción/Search Console y mantener el registro canónico al añadir nuevas familias públicas.
- **DOM API quick wins:** #461, #462, #466 y #468 integrados y **VALIDATED IN CODE**; continuar solo con findings vigentes.
- **Content Core navigation:** HIGH `S7761` revalidado; implementación y regresión del siguiente bloque ya están preparadas en una rama independiente, sin merge paralelo.
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
