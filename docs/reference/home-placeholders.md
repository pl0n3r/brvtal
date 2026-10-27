# Concepto 05 — medios sintéticos originales

> Preparación editorial de BRVTAL #975. **Fuentes versionadas no equivalen a medios publicados en el CMS.** No cargar a producción, no usar credenciales reales ni datos de usuarios para pruebas.

## Cinco categorías

| Categoría | Archivo fuente WebP (Git) | Campo editorial deseado |
| --- | --- | --- |
| Rostro / hero | `assets/placeholders/concept05/hero-face.webp` | `settings.site.c05_media_hero_face` |
| Público | `assets/placeholders/concept05/crowd.webp` | `settings.site.c05_media_crowd` |
| Artistas | `assets/placeholders/concept05/artists.webp` | `settings.site.c05_media_artists` |
| Noches | `assets/placeholders/concept05/nights.webp` | `settings.site.c05_media_nights` |
| Memorias | `assets/placeholders/concept05/memories.webp` | `settings.site.c05_media_memories` |

Los cinco archivos son materiales **sintéticos y de autoría original**: composiciones en blanco/negro con grano y acentos rojos, sin rostros reales, marcas ajenas ni material extraído de terceros. Las rutas de Git sirven de origen reproducible; la URL pública aprobada debe ser siempre la devuelta por Media Library después de una carga física válida.

## Registro y reemplazo seguro

1. El importador `scripts/seed_concept05_media_placeholders.php` calcula SHA-256 de cada archivo y solo admite ejecución contra el CMS aislado, bajo una sesión autenticada, protección CSRF y un host local explícito. Por defecto es inspección sin escritura.
2. En `api/media-library.php?action=upload`, enviar cada WebP como archivo multipart real (no llamar `register` con `assets/`, ni inventar `/uploads/media`). El servidor registra inicialmente el medio como `draft`, y el flujo autenticado puede cambiarlo a `published` mediante `PUT?action=update&id=N` después de comprobar la subida.
3. La deduplicación por hash de contenido es responsabilidad del endpoint. Ante la misma entrada se conserva el ID existente; cinco categorías con contenido distinto deben conservar cinco identidades.
4. DISCADMIN muestra un selector para cada categoría a través del picker canónico. Al guardar, se persisten solamente las cinco claves editoriales dentro de `settings.site`, preservando todas las otras claves y las relaciones de otras secciones.
5. El home usa únicamente rutas HTTPS o rutas locales `/uploads/media/...` correspondientes a medios publicados/autorizados; si falta medio, conserva el contenido editorial previo sin romper imágenes. Nunca se presenta una fuente Git como asset servido por el CMS.

## Evidencia exigida

- **AC-01**: fixture de subida autenticada/CSRF aislada para las cinco categorías, identidad/hash, estado `draft→published` y rechazo de rutas Git disfrazadas.
- **AC-02**: roundtrip `DISCADMIN → settings.site → nueva lectura pública` con sustitución de URL/ID; ningún campo de secciones vecinas debe cambiar.
- **AC-03**: Chromium/Playwright real a 390 y 1440, sin URL de imagen vacía/rota, screenshots junto a `docs/reference/home-concept05-owner-reference-v2.png` y diferencias justificadas. La revisión estética personal del dueño se difiere al cierre del concepto; no se inventa paridad visual.

## Limitaciones operativas

Esta documentación no acredita que las fuentes, pruebas o la carga CMS estén terminadas. No es permiso para publicar, gastar, ejecutar migraciones o tocar contenido real. Todo merge requiere V3, `validate` sobre HEAD exacto, seguridad y reglas de `PLAN-AGENTES.md`.

## Selección CMS integrada

Las cinco claves `settings.site.c05_media_*` conservan una relación `{id,url,status:'published'}` elegida desde Media Library. El picker solo presenta imágenes publicadas, el guardado preserva claves hermanas de `settings.site` y el home no asigna un `src` mientras no reciba una relación CMS publicable y una URL permitida. Un error HTTP de imagen la vuelve a ocultar.
