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
| Responsive | Pendiente de captura y medición real a 390 y 1440 px, con navegación abierta/cerrada y editor de banners |

## Capturas exigidas

El criterio AC-02 exige los cuatro archivos de captura real del navegador: `before-390.webp`, `after-390.webp`, `before-1440.webp`, `after-1440.webp`. **No hay todavía capturas verificadas de DISCADMIN** ni acceso autenticado a su interfaz en este entorno. No declarar AC-02 aprobado, ni solicitar CodeRabbit FINAL o fusionar, hasta obtener evidencia real y adjuntarla al PR.

**Calidad técnica separada:** el contrato `tests/discadmin-spanish-labels-contract.php` y las pruebas existentes aseguran que los valores de ruta, acceso y configuración sigan estables. La evidencia visual no se reemplaza por la aprobación del CI.
