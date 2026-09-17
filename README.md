# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aquí, además del snapshot exacto de lo que cambió, un **panorama general actualizado de lo que sigue pendiente por hacer/desarrollar**.

## Qué se hizo

- El sitemap público queda alimentado directamente por el estado actual del contenido público/indexable de BRVTAL, sin generar ni mantener un archivo XML estático.
- Se centralizan las familias públicas `events`, `artists`, `sets`, `releases`, `blog` y `pages` en un único registro compartido por SEO y sitemap.
- Publicar, despublicar o actualizar contenido se refleja automáticamente en la siguiente lectura del sitemap; los borradores, DISCADMIN, APIs y recursos privados siguen fuera del índice.
- Se elimina el cache público de 15 minutos del sitemap y se exige revalidación para no servir snapshots SEO obsoletos.
- La URL pública canónica queda en `/sitemap.xml`; una solicitud directa a `/sitemap.php` redirige permanentemente al XML mientras PHP sigue siendo solo el renderer interno.
- `robots.txt` ya apuntaba correctamente a `https://www.brvtal.com.co/sitemap.xml` y no requiere modificación.
- Se añade un contrato que mantiene sincronizados el registro de contenido, el routing público, el renderer XML y la URL anunciada a crawlers.
- Los contratos existentes de SEO se alinean al nuevo registro canónico: structured data y fallback de Pages se verifican desde la nueva fuente de verdad, no desde definiciones duplicadas.
- Los contratos existentes de Contact y fallos públicos se adaptan al registro/cache nuevos sin reducir sus garantías.
- `AGENTS.md` incorpora como regla canónica de ejecución el paralelismo seguro del trabajo independiente; los merges a `main` permanecen serializados.
- No hay cambios de base de datos ni migraciones.

## Archivos modificados en este deploy

- `.htaccess` — canonicalización pública `sitemap.php` → `sitemap.xml` y render interno del XML.
- `AGENTS.md` — regla canónica de paralelismo seguro y merges serializados.
- `README.md` — snapshot exacto del deploy y panorama pendiente actualizado.
- `config/public_routes.php` — registro canónico de familias, structured data y rutas públicas indexables.
- `config/public_seo.php` — consumo del registro común para resolver entidades SEO sin duplicar definiciones.
- `sitemap.php` — sitemap dinámico basado en el registro común y revalidado en cada consulta.
- `tests/public-contact-contract.php` — garantía de Contact actualizada para leer la fuente canónica del sitemap.
- `tests/public-failure-semantics-contract.php` — semántica de error conservada y cache sano alineado a revalidación.
- `tests/public-seo-delivery-contract.php` — contrato SEO actualizado para validar los tipos estructurados desde el registro canónico compartido.
- `tests/public-sitemap-contract.php` — contrato de sincronización entre contenido público, routing, XML y `robots.txt`.
- `tests/seo-defaults-contract.php` — contrato de defaults actualizado para comprobar el fallback `content_json` de Pages desde el registro común.

## Validación

- Base exacta recontrastada: `main` `49a1bfe7490ed98daf183801ac9f6e200fb99f57`.
- Ese SHA exacto pasó BRVTAL CI #820 con `fast`, `chromium` y `validate` en `success`; #468 queda **VALIDATED IN CODE** en `main`.
- BRVTAL CI #830 detectó que el contrato SEO todavía buscaba los tipos Schema.org dentro de `public_seo.php`; se alineó con `config/public_routes.php` sin cambiar comportamiento público.
- BRVTAL CI #833 confirmó ese primer ajuste y detectó el segundo contrato textual obsoleto: Pages todavía esperaba `content_json` definido dentro de `public_seo.php`. Se cambió a una aserción ejecutable sobre `brvtal_public_content_definitions()`.
- El head previo `e06aa25d9737640bce73613b934b4cbf205c45a3` pasó BRVTAL CI #835 (`fast`, `database`, `real-stack`, `chromium`, `validate`) y SonarQube Cloud Quality Gate, con 0 Security Hotspots.
- La actualización de `AGENTS.md`/README cambia el head; el SHA exacto nuevo debe volver a pasar BRVTAL CI, SonarQube Cloud y CodeRabbit antes del squash merge.
- Este cambio no requiere migración de base de datos.
- Después del merge se verificará BRVTAL CI sobre el SHA exacto resultante de `main`; CI verde significará **VALIDATED IN CODE**, no validación de producción.

## Qué sigue

- Completar los gates exactos del sitemap dinámico, corregir findings válidos, hacer squash merge y verificar el nuevo SHA exacto de `main`.
- Después retomar #451 con el HIGH `javascript:S7761` revalidado en `discadmin/content-core-nav.js`, preservando la semántica de `data-health-open` y su cobertura de navegador.
- Revalidar después los quick wins todavía vigentes en `discadmin/media-library.js` sin mezclar su refactor de complejidad más amplio.
- Mantener #434 (Memories) separado hasta resolver su propio Quality Gate y tratar su migración de producción por separado.

## Panorama general pendiente

Este panorama debe mantenerse actualizado en **cada deploy** y resumir trabajo relevante todavía abierto, aunque no forme parte del deploy actual.

- **Sitemap / SEO técnico:** este deploy centraliza y automatiza el sitemap público; tras integrar, verificar el XML en producción y mantener el registro canónico como única fuente al añadir futuras familias públicas.
- **DOM API quick wins:** #461, #462, #466 y #468 integrados y **VALIDATED IN CODE**; continuar solo con findings vigentes recontrastados.
- **Content Core navigation:** `discadmin/content-core-nav.js` conserva un HIGH `S7761` revalidado; tratarlo en un bloque separado.
- **Media Library DOM:** permanecen revalidados quick wins `dataset`/`Element.after()`; tratarlos aparte de findings de complejidad para mantener el riesgo acotado.
- **Memories administrable:** #415 / PR #434 — galería curada desde DISCADMIN usando Media Library existente, con publicación, orden y viewer editorial; falta cerrar Quality Gate y ejecutar la migración de producción por separado.
- **Sonar / calidad:** #451 — continuar la cola por riesgo y área, evitando refactors masivos y revalidando cada finding contra el código vigente.
- **Archivo cultural:** #398 / #403 — profundizar relaciones explícitas Event ↔ Artist ↔ Set ↔ Release ↔ Memory sin inferencias falsas.
- **Analytics / GA4:** #427 — completar mapeo `brvtal_*` en GTM/GA4 y preparar lectura segura futura en Dashboard.
- **SEO editorial:** #390, #391 y #272 — workspace SEO, alineación editorial y structured data por entidad.
- **DISCADMIN:** #348, #351 y #365 — simplificación de navegación, Theme Studio y consolidación de Dashboard.
- **Operación / historial:** #232, #207 y #388 — Activity completo, System Status fiable y RESET LOG seguro.
- **Media / mobile:** #260 — inspector accesible inmediatamente tras seleccionar un asset en móvil.
- **Seguridad editorial / UX:** #257 — proteger cambios no guardados en editores legacy.
- **Backups:** #389 — scheduling seguro y copia opcional a Google Drive cuando existan autorización/credenciales.
- **Bulk Actions:** #275 — alcanzar registros más allá del recorte local de 500 sin presentar búsquedas parciales como exhaustivas.
- **Idioma:** #212 — futura experiencia ES/EN manteniendo español canónico.
- **Performance / recovery:** conservar evidencia y optimizar solo ante regresiones o cuellos de botella medidos.
