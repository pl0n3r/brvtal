# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- Pages tiene ahora un contrato público explícito para `content_json` en vez de tratar cualquier JSON válido como contenido renderizable.
- Se mantienen compatibles los formatos simples top-level `text`, `body` y `content`.
- El formato de bloques soporta únicamente `text`, `paragraph` y `heading`, con contenido textual escapado por el renderer existente.
- Una Page publicada rechaza estructuras válidas de JSON que el frontend público no sabe representar; los drafts pueden seguir guardando estructuras incompletas para edición futura.
- El contenido de Pages se normaliza a texto seguro antes de construir SEO/JSON-LD y antes de llegar al renderer canónico, por lo que `content_json` crudo ya no puede aparecer como descripción pública.
- DISCADMIN documenta los formatos realmente soportados en el editor de Pages y deja de prometer un Page Builder indefinido.
- No hay cambios de esquema, migraciones ni mutaciones de datos de producción.

## Archivos modificados en este deploy

- `config/page_content.php` — contrato canónico, validación estructural y extracción segura de texto para Pages.
- `api/pages-contract.php` — exige contenido renderizable al publicar, manteniendo drafts flexibles.
- `config/public_seo.php` — normaliza `content_json` de Pages antes de SEO, JSON-LD y entrega pública.
- `discadmin/pages-publication-contract.js` — documenta los JSON simples y tipos de bloque soportados.
- `tests/public-entity-pages-contract.php` — cubre bloques soportados, compatibilidad legacy, rechazo de estructuras desconocidas y ausencia de fuga de JSON/markup.
- `README.md` — snapshot operativo de este deploy.

## Validación

- Rama creada desde `main` `d001bb32f858fe6a9d6672fe23a60f7971eb037d`.
- Ese SHA exacto tenía BRVTAL CI completo, PHP 8.5 Compatibility y Backup Recovery Rehearsal en success.
- No hay cambios de esquema ni migraciones; la corrección es de contrato, validación y render público.
- No se publicará ni modificará una Page real de producción para validar este issue.
- Pendiente de **BRVTAL CI / validate**, **PHP 8.5 Compatibility / php85**, **README Deploy Snapshot · PR** y **Backup Recovery Rehearsal** del PR.
- Tras el merge se verificará la matriz completa sobre el SHA exacto de `main`.
- CI verde significa **VALIDATED IN CODE**; no implica **VALIDATED IN PRODUCTION**.

## Qué sigue

1. Ejecutar los gates del PR y corregir en esta misma rama cualquier fallo detectado.
2. Con CI verde, hacer squash merge.
3. Verificar BRVTAL CI completo, PHP 8.5 y Backup Recovery sobre el SHA exacto resultante de `main`.
4. Confirmar #206 cerrado y continuar con el siguiente issue público prioritario vigente.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
