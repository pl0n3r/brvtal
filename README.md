# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- Se rediseñó la sección pública **MEMORIES** para que deje de sentirse como una grilla genérica de imágenes iguales y funcione como un archivo visual curado.
- La composición dinámica usa una retícula editorial ordenada: una memoria dominante y piezas secundarias con distintos pesos, sin alterar el orden real de los medios entregados por el API.
- La versión estática/fallback de Memories adopta la misma lógica visual y deja de depender de imágenes flotantes/absolutas dispersas.
- La cabecera del archivo incorpora una jerarquía más clara, contexto editorial y controles de búsqueda/filtro integrados al sistema visual BRVTAL.
- Las tarjetas reciben numeración de archivo, metadata más legible y transición grayscale → color contenida, manteniendo las imágenes como protagonista.
- Tablet simplifica la retícula a seis columnas; móvil vuelve a una secuencia estable de una columna, con targets táctiles de 44 px y sin overflow horizontal incluso antes de que cargue la webfont condensada.
- Se conservan sin cambios el API de Media, responsive image delivery, lazy loading, búsqueda, filtros, empty state y navegación accesible del visor.
- Playwright ahora comprueba la jerarquía desktop, el orden móvil, targets táctiles y ausencia de overflow además de la funcionalidad existente de filtros y viewer.

## Archivos modificados en este deploy

- `README.md` — snapshot operativo exacto de este deploy.
- `index.html` — refuerza la cabecera y semántica de Memories con framing editorial y título accesible.
- `css/public-media.css` — sustituye la grilla uniforme por la retícula editorial responsive, estiliza controles/tarjetas/fallback, protege el ancho móvil durante fallback tipográfico y conserva viewer/reduced-motion.
- `tests/e2e/public-media.spec.mjs` — añade regresión de layout desktop/mobile, touch targets y overflow sin perder las pruebas existentes de discovery y viewer.

## Validación

- Base exacta: `main` `825357089cab33f1b1b5cebac8181160adb04deb`.
- La base quedó con `BRVTAL CI / validate` verde en el run #584.
- Issue cubierto: `#394`.
- No hay migración, cambio de schema, restore, bulk delete ni mutación de datos de producción.
- `js/public-media.js`, APIs públicos y Media Engine no se modificaron; el contrato funcional y la entrega responsive permanecen en su frontera existente.
- Run #585: `fast` verde; Chromium ejecutó 167 pruebas, con 161 pasadas y 5 omitidas. La única falla aplicable fue la nueva regresión de overflow móvil de Memories; la jerarquía desktop y la funcionalidad existente de Public Media pasaron.
- El overflow se corrigió sin relajar el test: el contenedor corta desbordamiento horizontal no intencional, el título usa fallback tipográfico explícito y una escala móvil que cabe aun antes de cargar Barlow Condensed, y los controles de audio respetan el ancho disponible.
- Pendiente en este snapshot: nuevo `BRVTAL CI / validate` y revisión automática sobre el head corregido.
- CI verde significará **VALIDATED IN CODE**. No se declarará **VALIDATED IN PRODUCTION** sin comprobar el deploy real.

## Qué sigue

1. Resolver en esta misma rama cualquier fallo o finding válido de BRVTAL CI/revisión automática.
2. Hacer squash merge solo con `BRVTAL CI / validate` verde y revisión final limpia.
3. Verificar `BRVTAL CI / validate` del SHA exacto resultante en `main`.
4. Retomar `#388` en su rama aislada `feature/system-status-reset-log` sin mezclar ambos cambios.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de validación: `docs/TESTING.md`.
- Issue abordado: `#394`.
