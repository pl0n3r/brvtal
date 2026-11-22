# Evidencia visual de DISCADMIN #1864

**Alcance:** shell principal de DISCADMIN y editor de banners. Modo de diseño `impeccable: Operate`; referencias de Factory `impeccable/reference/craft-floor.md` y `make-interfaces-feel-better/SKILL.md`.

## Autorrevisión de diseño

| Revisión | Estado y fundamento |
| --- | --- |
| Lenguaje de navegación | Traducciones de destinos, cabeceras, botones y estados a español, sin cambios en los IDs de rutas |
| Jerarquía | Se preserva la estructura original: menú lateral, título de módulo y espacio central |
| Legibilidad | No se añaden colores, fuentes ni efectos nuevos. Se mantienen los estilos CSS y las clases preexistentes |
| Controles y foco | Se mantienen botones, `aria-*`, `data-*`, manejadores, estados de carga y botones de recuperación |
| Acceso y errores | Mensajes de login, carga de banners y guardado con instrucciones en español |
| Datos y seguridad | No se traducen claves de API ni enums almacenados; el SHA de despliegue sigue en atributo secundario no visible |
| Responsive | Capturas reales de Chromium a 390 y 1440 px sobre fixture sintética derivada del shell/editor Git, con manifiesto exact-HEAD y SHA256 |

## Capturas exigidas

El workflow `DISCADMIN Spanish visual evidence` ejecuta Playwright Chromium sobre el HEAD exacto del PR y publica un artefacto que contiene `before-390.webp`, `after-390.webp`, `before-1440.webp`, `after-1440.webp` y `manifest.json` con origen, viewport, SHA256 y procedencia. La fuente es una **fixture sintética extraída de código Git**, no una sesión autenticada ni una verificación de producción.

La ejecución [#45033247531](https://github.com/pl0n3r/brvtal/actions/runs/45033247531) confirmó las cuatro capturas reales de navegador sobre `d4643daa99f5ad18e87dafe753b2b3c521e26125`. Después de reconciliar la versión con `main`, el mismo workflow debe confirmar el HEAD definitivo antes de fusionar.

**Calidad separada:** `tests/discadmin-spanish-labels-contract.php` y Playwright validan etiquetas y controles sin alterar persistencia, autenticación ni rutas. Un test verde no equivale a validación de despliegue ni autorización jurídica.
