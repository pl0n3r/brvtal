# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aquí, además del snapshot exacto de lo que cambió, un **panorama general actualizado de lo que sigue pendiente por hacer/desarrollar**.

## Qué se hizo

- Continúa #451 con un PR de Reliability para el cuarto y último finding Sonar `javascript:S8786` identificado en el bloque actual y el contrato de integridad de slugs de Theme Studio asociado.
- `discadmin/theme-studio-v2.js` deja de recortar guiones de los slugs con la regex de bordes reportada y usa un helper lineal con `startsWith()` / `endsWith()` e índices.
- Se conserva el contrato funcional de `safeSlug`: minúsculas, reemplazo de caracteres no permitidos por `-`, eliminación de guiones en los extremos incluso después del límite, `_` permitido, máximo 60 caracteres y fallback `theme` cuando el resultado queda vacío.
- CodeRabbit detectó que un caller directo de `/settings` podía saltarse la normalización del cliente; `api/content-validation.php` define ahora el contrato canónico `^[a-z0-9_-]{1,60}$` para claves `theme.<slug>` y el valor de `theme.active`, y `api/index.php` lo aplica antes de persistir.
- Las pruebas cubren slugs adversariales en navegador y validan de forma ejecutable claves/valores de settings válidos, inválidos, overlong y no canónicos.
- El contrato fuente `S8786` impide que reaparezca la regex de Theme Studio y exige el recorrido lineal.
- No se modifica el modelo de datos, Media picker, estilos ni runtime público; la persistencia existente solo gana validación previa para identidad de Theme Studio.

## Archivos modificados en este deploy

- `README.md` — snapshot exacto del deploy y panorama pendiente actualizado.
- `api/content-validation.php` — contrato compartido para identidad de settings de Theme Studio.
- `api/index.php` — aplica el contrato antes de escribir `theme.<slug>` o `theme.active`.
- `discadmin/theme-studio-v2.js` — limpieza lineal de guiones de borde para `safeSlug`.
- `tests/content-validation-contract.php` — casos ejecutables de validación de claves y valores Theme.
- `tests/e2e/discadmin-theme-studio-slug.spec.mjs` — cobertura ejecutable del comportamiento del slug de Theme Studio.
- `tests/sonar-regex-reliability-contract.php` — contrato fuente ampliado al cuarto `S8786`.

## Validación

- Base exacta: `main` `c5c713ec94e344e753600d2315bff2bde7debbc5`.
- Ese SHA exacto de `main` pasó BRVTAL CI #771 con `fast`, Chromium y `validate` verdes; los gates no aplicables quedaron omitidos según el selector de scope.
- Antes del ajuste backend, el head de #456 pasó BRVTAL CI completo y CodeRabbit; el finding de integridad de settings fue validado contra `api/index.php` y `api/public.php` y se corrigió antes del merge.
- El head actualizado debe volver a pasar BRVTAL CI, SonarQube Cloud y CodeRabbit antes del squash merge; no se reutilizan gates de un SHA anterior.
- CI verde significará **VALIDATED IN CODE**, no validación de producción.

## Qué sigue

- Cerrar los gates del SHA exacto actualizado de #456, resolver cualquier finding válido, hacer squash merge y verificar BRVTAL CI del SHA exacto resultante de `main`.
- Recontrastar PR #457 contra ese nuevo `main`, refrescar su README y volver a correr sus gates antes de integrarlo.
- Promover después `quality/content-core-form-accessibility`, reutilizando los labels visualmente ocultos de #457 y corrigiendo sus asociaciones estáticas sin mezclar deuda visual no comprobada.
- Continuar #451 por riesgo con los siguientes findings de Reliability antes de deuda puramente estilística.
- Mantener #434 (Memories) separado hasta resolver su propio Quality Gate y tratar su migración de producción por separado.

## Panorama general pendiente

Este panorama debe mantenerse actualizado en **cada deploy** y resumir trabajo relevante todavía abierto, aunque no forme parte del deploy actual.

- **Admin accessibility:** PR #457 — Blog, Media y Releases están en el segundo carril paralelo; Sonar obligó a pasar de `aria-label` a asociaciones `<label for>` reales y el head corregido está en revalidación.
- **Content Core accessibility:** rama `quality/content-core-form-accessibility` — 14 labels visibles ya asociados; las dos búsquedas se ajustarán para reutilizar la utilidad accesible de #457 antes de abrir el PR.
- **Memories administrable:** #415 / PR #434 — galería curada desde DISCADMIN usando Media Library existente, con publicación, orden y viewer editorial; falta cerrar Quality Gate y ejecutar la migración de producción por separado.
- **Sonar / calidad:** #451 — los BLOCKER de seguridad/globals, `S2871`, `S7727` y los cuatro `S8786` identificados están trabajados en código; continúan findings Reliability y deuda Maintainability a resolver por riesgo y por área.
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
