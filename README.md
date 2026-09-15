# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- La entrega pública de Ticket Types ahora respeta `available_from` y `available_until` además del status.
- La política queda centralizada en `brvtal_public_ticket_type_is_available()` para evitar divergencias entre superficies.
- Los límites temporales son inclusivos: un ticket puede aparecer exactamente en `available_from` y permanece visible hasta `available_until` inclusive.
- Tickets `active` o `sold_out` antes de su apertura o después de su cierre dejan de entregarse públicamente.
- Una ventana no definida permanece abierta; una fecha no vacía pero inválida falla cerrada y no expone la oferta.
- `api/public.php` filtra Ticket Types antes de agruparlos dentro de cada Event.
- Las páginas canónicas `/events/{slug}` aplican la misma política antes de renderizar tarjetas de tickets.
- La política de #197 para Events históricos/pasados se conserva: si el Event completo ya no admite ticketing, ni siquiera se publica su CTA comercial.

## Archivos modificados en este deploy

- `config/public_visibility.php` — predicado canónico de disponibilidad temporal de Ticket Types.
- `api/public.php` — filtra Ticket Types por la política compartida antes de construir el payload público.
- `config/public_page.php` — filtra la sección TICKETS de la página canónica con la misma política.
- `tests/api-contract.php` — contrato que exige hidratación de ventanas y aplicación del predicado en el API.
- `tests/public-entity-pages-contract.php` — regresiones deterministas de status, ventanas, límites inclusivos y fail-closed.
- `README.md` — snapshot operativo de este deploy.

## Validación

- Rama creada desde `main` `21720460a4c771c80f7ed3387e063d00d91e3e9a`.
- Ese SHA exacto tenía BRVTAL CI completo, PHP 8.5 Compatibility, Backup Recovery Rehearsal y Production Performance en success.
- No hay cambios de esquema ni migraciones: `available_from` y `available_until` ya existen como `DATETIME NULL` en `event_ticket_types`.
- La implementación solo cambia la política de entrega pública; no borra ni modifica datos comerciales almacenados.
- Pendiente de **BRVTAL CI / validate** y **PHP 8.5 Compatibility / php85** del PR.
- Tras el merge se verificará la matriz completa sobre el SHA exacto de `main`.
- CI verde significa **VALIDATED IN CODE**; no implica **VALIDATED IN PRODUCTION**.

## Qué sigue

1. Ejecutar los gates del PR y corregir en esta misma rama cualquier fallo detectado.
2. Con CI verde, hacer squash merge.
3. Verificar BRVTAL CI completo, PHP 8.5 y Backup Recovery sobre el SHA exacto resultante de `main`.
4. Cerrar #198 y continuar con el siguiente issue público prioritario que siga vigente.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
