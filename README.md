# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. Se reemplaza en cada deploy. El contexto durable vive en `AGENTS.md` y, cuando aplica, en `docs/`.

## Qué se hizo

- Se corrigió la reapertura de fechas de Events: los valores de MariaDB se normalizan al formato que exige `datetime-local`.
- Sets ahora carga conjuntamente Sets, Artists y Events antes de renderizar el workspace, por lo que New Set dispone de sus relaciones aunque se entre directamente al módulo.
- Se añadió un contrato específico para impedir regresiones de ambos estados de formulario.

## Archivos modificados en este deploy

- `README.md` — snapshot operativo de este deploy.
- `discadmin/admin-relational-forms.js` — normalización de fechas e hidratación de relaciones de Sets.
- `discadmin/index.php` — carga la nueva capa dentro del shell canónico de DISCADMIN.
- `scripts/php85-compatibility.sh` — incorpora el contrato nuevo a la suite PHP 8.5.
- `tests/admin-relational-forms-contract.php` — cobertura contractual para Issues #123 y #124.

## Validación

- El PR debe pasar `README Deploy Snapshot · PR / verify`, `BRVTAL CI / validate` y `PHP 8.5 Compatibility / php85` antes del merge.
- No hay cambios de esquema ni mutaciones de datos de producción.
- Producción canónica: `https://www.brvtal.com.co`.

## Qué sigue

1. Validar el SHA exacto de `main` después del merge y comprobar el despliegue automático.
2. Resolver el bloqueo intermitente del Hero Slider reportado en #125 con timeout/Retry explícitos.
3. Sustituir el bloque Next Experience hard-coded de Genesis reportado en #126 por contenido derivado del CMS.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Especificaciones profundas: `docs/`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
