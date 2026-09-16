# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- Se implementó la **Fase A de la evolución pública de BRVTAL** (#399): el Home comunica desde el primer viewport que BRVTAL es una plataforma/colectivo de cultura electrónica underground nacida en Pereira, no solo una colección de secciones.
- Se preservó y reforzó la estética existente: negro profundo, blanco sucio, tipografía brutalista, grano/micro-ruido, interferencia y glitch; el verde ácido funciona como señal/acento y no como fondo dominante.
- `Next Experience` ahora se alimenta de las fuentes ya existentes del backend: lifecycle público de Events, `event_artists`, `ticket_url` y `event_ticket_types`. No se creó lógica editorial paralela ni schema nuevo.
- La experiencia activa muestra título, fecha, hora, ciudad/venue, lineup estructurado y acceso a la página canónica del evento.
- El CTA **TICKETS** solo aparece cuando existe una URL pública HTTP/HTTPS válida y el evento todavía admite ticketing según las reglas canónicas actuales. Si el Event no tiene `ticket_url`, se puede reutilizar el primer ticket type activo/disponible con `external_url` válida.
- En móvil, fecha/localización, lineup y acciones reciben prioridad; los CTAs mantienen tamaño táctil suficiente y la composición evita overflow horizontal.
- La nueva capa visual es CSS propio y liviano: no añade librerías, no modifica el runtime adaptativo de motion y respeta `prefers-reduced-motion`.
- Las métricas de producción previas quedan como baseline histórico; el deploy nuevo deberá volver a medirse en mobile/desktop antes de considerar sus métricas como evidencia actual.

## Archivos modificados en este deploy

- `README.md` — snapshot operativo exacto de este deploy.
- `config/public_home.php` — compone la declaración cultural del Home y enriquece Next Experience con lineup/ticket CTA usando contratos de backend existentes y fail-safe.
- `css/public-home-phase-a.css` — tratamiento brutalista/grano/glitch de Fase A, jerarquía de Next Experience y responsive móvil.
- `tests/public-home-phase-a-contract.php` — contratos de selección, URL segura, declaración cultural, lineup y Tickets CTA.
- `tests/e2e/public-home-phase-a.spec.mjs` — regresión visual/estructural desktop y mobile, touch targets y overflow.

## Validación

- Base exacta: `main` `d07a1ea067ddda66e6c3c75fc27b91819c2521f3`.
- La base quedó con `BRVTAL CI / validate` verde en el run #588.
- Issue cubierto: `#399`, Fase A de `#398`.
- No hay migración, cambio de schema, restore, bulk delete ni mutación de datos de producción.
- `api/public.php` sigue siendo el API público canónico y no se creó un segundo contrato público.
- La implementación reutiliza `config/public_visibility.php` para lifecycle/ticketing y las relaciones existentes `event_artists` / `event_ticket_types`.
- Pendiente en este snapshot: `BRVTAL CI / validate`, revisión automática y cualquier corrección válida sobre el head de la rama.
- CI verde significará **VALIDATED IN CODE**. No se declarará **VALIDATED IN PRODUCTION** sin comprobar el deploy real.
- Tras deploy deberá ejecutarse nueva medición de Production Performance sobre el SHA exacto publicado; las métricas anteriores solo son baseline.

## Qué sigue

1. Resolver en esta misma rama cualquier fallo o finding válido de BRVTAL CI/revisión automática.
2. Hacer squash merge solo con `BRVTAL CI / validate` verde.
3. Verificar `BRVTAL CI / validate` del SHA exacto resultante en `main`.
4. Volver a medir producción del nuevo Home y comparar LCP/waterfall/mobile/desktop contra el baseline anterior.
5. Implementar de forma separada `#400`: Settings tipados, IA lógica de configuración y wordmark SVG configurable en Theme Studio.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de validación: `docs/TESTING.md`.
- Visión pública: `#398`.
- Issue abordado: `#399`.
- Configuración/admin follow-up: `#400`.
