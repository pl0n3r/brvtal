# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

> **Regla permanente del proyecto:** cada deploy debe dejar aquí el snapshot exacto de lo que cambió y un panorama general actualizado de lo pendiente.

## Qué se hizo

- Continúa #451 con dos quick wins DOM revalidados en Media Library.
- `handleImageError()` elimina el fallback consumido mediante `delete img.dataset.fallback`, preservando la semántica de un solo reintento antes de degradar a placeholder.
- `decoratePickerInputs()` usa `input.after(button)` para mantener el botón `SELECT MEDIA` inmediatamente después del input decorado.
- La regresión Playwright verifica la adyacencia del botón y que `data-fallback` desaparece antes de un segundo fallo.
- Se añade un contrato auto-descubierto que exige las APIs DOM modernas y bloquea el regreso de las expresiones legacy equivalentes.
- No se modifican APIs, base de datos, rutas, permisos, estilos ni contratos públicos.

## Archivos modificados en este deploy

- `README.md` — snapshot exacto del deploy y panorama pendiente.
- `discadmin/media-library.js` — modernización equivalente de eliminación de dataset e inserción DOM.
- `tests/media-library-dom-contract.php` — contrato fuente para las dos APIs DOM.
- `tests/e2e/discadmin-media.spec.mjs` — regresiones de adyacencia y fallback de un solo uso.

## Validación

- Base exacta: `main` `8dfabab609e2db087f5d81b95031e895da7e8e93`.
- Ese SHA exacto pasó BRVTAL CI #857 y queda **VALIDATED IN CODE**.
- La rama permanece limitada a los dos cambios funcionales de Media Library y su cobertura.
- El head del PR debe pasar BRVTAL CI, SonarCloud y CodeRabbit sobre su SHA exacto antes del squash merge.
- Después del merge se verificará BRVTAL CI sobre el SHA exacto resultante de `main`; CI verde significa **VALIDATED IN CODE**, no validación de producción.

## Qué sigue

- Cerrar gates exactos de este bloque, corregir findings válidos, hacer squash merge y verificar el nuevo `main`.
- Atacar #391 como siguiente quick win: alinear el bloque SEO con el gutter canónico de los editores.
- Después corregir #260 para que el inspector de Media Library sea accesible inmediatamente al seleccionar un asset en móvil.
- Mantener #451 activo por riesgo con findings revalidados contra el código actual.

## Panorama general pendiente

- **Sonar / calidad:** #451 — continuar por riesgo y área, con PRs pequeños y comportamiento preservado.
- **SEO editorial:** #391 es el siguiente quick win; #182, #214, #204 y #272 deben sanearse antes de construir #390.
- **Media / mobile:** #260 — acercar el inspector al asset seleccionado en móvil.
- **Dashboard / DISCADMIN:** #365, #348 y #351 — consolidación del Dashboard, IA y Theme Studio.
- **Seguridad editorial / navegación:** #257, #216, #174 y #193 — dirty state, modal lifecycle y navegación transaccional.
- **Hero Slider:** #237 y #221 — breakpoint responsive e integridad de media.
- **Content / Media integrity:** #191, #224 y #252 — referencias válidas y edición coherente con el modelo.
- **Activity / operaciones:** #232, #195 y #388 — historial navegable, cobertura de audit log y RESET LOG seguro. #207 ya fue revalidado y cerrado.
- **Bulk Actions:** #275 — eliminar el truncado silencioso de catálogos >500.
- **Archivo cultural:** #398 / #403 — seguir con relaciones estructuradas reales y Event Records.
- **Memories:** #415 / PR #434 requiere reconciliación cuidadosa con `main` y mantiene la migración de producción separada.
- **Idioma:** #212 — futura experiencia ES/EN con español canónico y traducción automática por fases.
- **Backups:** #389 — scheduling seguro y copia opcional a Google Drive con autorización externa.
- **Performance / recovery:** optimizar solo ante regresiones o cuellos medidos y mantener producción separada de la validación CI.
