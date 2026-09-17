# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README cubre **solo el deploy actual** y se reemplaza en el siguiente deploy.

## Qué se hizo

- Se devuelve el indicador de escena del header (`CORE /// 01`, etc.) al centro geométrico real del viewport en desktop.
- Se evita que el ancho desigual entre logo y controles de la derecha desplace visualmente el indicador hacia la izquierda.
- Mobile conserva el comportamiento actual: el indicador central sigue oculto por debajo de 900 px.

## Archivos modificados en este deploy

- `README.md` — snapshot exacto del deploy.
- `css/public-header-alignment.css` — centra de forma absoluta el indicador de escena del header y evita interferencia con clics.
- `index.php` — carga el ajuste de alineación del header dentro del Home público.

## Validación

- Base: `main` `3eb99fa21e60cfe831974245f7ad82f990ff18e5`, con BRVTAL CI #695 verde.
- El cambio debe pasar BRVTAL CI y revisión automatizada antes del squash merge.
- CI verde significa **VALIDATED IN CODE**, no validación de producción.

## Qué sigue

- Verificar el centrado en desktop después del deploy y continuar con los quick wins visuales priorizados del Home.
