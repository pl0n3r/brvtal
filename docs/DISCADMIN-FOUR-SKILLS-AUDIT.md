# BRVTAL / DISCADMIN — auditoría transversal de cuatro skills

**Estado:** auditoría de código y contratos completada; **validación visual autenticada de producción pendiente**. Issue [#1652](https://github.com/pl0n3r/brvtal/issues/1652). Fecha de corte: 2026-11-07/08. Commit base: `4da5846ac7d2a81b3ceea35216109dfaa2886b6a` (BRVTAL 0.1.235). No equivale a aprobación estética del propietario ni certificación de todos los flujos del CMS.

## Alcance y procedencia de las cuatro skills

| Skill | Material consultado realmente | Aplicación en auditoría | Límite |
|---|---|---|---|
| **Impeccable** | `~/.claude/skills/impeccable/SKILL.md`, `reference/adapt.md`, `reference/craft-floor.md` | Jerarquía tipográfica, densidad, zonas vacías, composición y adaptación móvil, contraste y estados | El lanzador `scripts/impeccable` no estaba disponible; lectura manual, **no se afirma que se ejecutó** |
| **Make Interfaces Feel Better** | `~/.claude/skills/make-interfaces-feel-better/SKILL.md`, `surfaces.md` | Tamaños táctiles, nombres de acciones, foco óptico, relaciones de superficies, mensajes de guardado/error | Guía aplicada sobre código, no equivale a test táctil real |
| **transitions.dev** | Instalación local autorizada del dueño; `SKILL.md`, `05-menu-dropdown.md`, `07-panel-reveal.md` | Origen de menú, tiempos de apertura/cierre, reversibilidad e interrupción, reducción de movimiento | **No se vendorizó ni redistribuyó** código sujeto a licencia; no se asegura animación añadida donde no aporta |
| **GSAP** | `~/.claude/skills/gsap-core/SKILL.md`, `gsap-performance/SKILL.md` | Opt-in, cleanup, context, `prefers-reduced-motion`, coste CPU, overlays sin fugas | Revisar uso justificado; no forzar GSAP en tablas/formularios que deben ser instantáneos |

**Evidencia**: árbol actual `discadmin/`, `tests/e2e/discadmin-*.spec.mjs`, `docs/DISCADMIN-UX-AUDIT.md` y `docs/rediseno-skills-brvtal.md`. La auditoría de septiembre contiene elementos **ya corregidos**; no se reabre por inercia lo fusionado en #1363–#1365, #1638 y #1651.

## Inventario de navegación y recorridos (fuente → acción → estado)

| Superficie | Fuente primaria | Recorrido/estado a inspeccionar | Resultado del análisis de fuente |
|---|---|---|---|
| Shell y acceso | `discadmin/index-core.php`, `admin-shell.css`, `admin-information-architecture.js` | Login, sesión, deep link, scroll lateral, menú móvil y navegación por teclado | Responsive y foco parciales; pendiente inspección de render Safari autenticado |
| Dashboard | `dashboard-v2.js/css` | Datos/atajos, carga, vacío, 503, personalización | Estados dinámicos; contrastar tipografía y densidad |
| Eventos | `content-core.js`, `event-workflow.js`, `admin-data-grid.js` | Crear, asociar DJs, guardar, publicar, editar | Flujo cubierto por real-stack; prueba visual pendiente |
| Artistas | `index-core.php`, `artist-hero-preview.js/css`, `admin-data-grid.js` | Foto, Instagram vinculado, miembro/invitado, encuadre, guardar | **P1**: faltan carátulas y preview de perfil real, #1657 |
| Sets | `soundcloud-artwork.js`, `admin-data-grid.js/css`, `content-ordering.js/css` | Auto-relleno, portada, orden 01, drag/teclado, PUT | #1638 **fusionado** en 0.1.234; verificar con contenido real, no duplicar implementación |
| Releases | `releases.js/css`, `admin-data-grid.js` | Crear, publicar, relacionar artistas, SEO y vacío | Revisar estados/espacios si no hay registros |
| Media | `media-library.js/css`, `admin-modules.js` | Preview, upload, errores, reemplazo, tamaños y focal point | **P1**: controles y feedback densos, responder 4xx/5xx sin duplicar |
| Pages | `pages-publication-contract.js`, `admin-modules.js` | Crear, borrador, publicación y previsualización | Contratos de publicación presentes; confirmar jerarquía |
| Blog | `blog.js/css`, `seo-metadata.js` | Guardar, publicar/borrador, error de DB, recuperar draft | SQLSTATE 1267 registrado históricamente; #1562 ya fusionado |
| Memorias | `memories.js/css` | Crear, asociar medios y relaciones, estados de contenido | Revisar jerarquía/preview en móvil |
| Hero / Banners | `hero-slider.js`, `hero-slider-v2.css` | Ordenar y previsualizar a 390/1440, play/pause | **P1**: texto de controles muy pequeño en fuente |
| Theme Studio | `theme-studio-configuration.js/css`, `admin-appearance.js/css` | Previsualizar, guardar, deshacer, persistir | Verificar estados guardado, contraste, fallback |
| SEO | `seo-workspace.js/css`, `seo-editorial-defaults.js` | Meta del CMS, defaults, errores, validación | Fuente tiene reglas heredadas; revisar consistencia |
| Settings / Security | `settings-v2.js/css`, `security.js/css` | Password, 2FA, SMTP, CSRF, fallos de red | Evitar efectos visuales que oculten controles críticos |
| System Status / Logs | `system-status-v2.js/css`, `technical.php`, `logs.php` | Healthy/degraded/error, red, reset log, historial | Tests específicos de degradación existentes; lectura de logs de producción requiere acceso protegido |
| Backups | `backups.js/css`, `backups.php` | Crear/descargar/error, recuperación, foco | Reportados warnings de limpieza .part; [#1655](https://github.com/pl0n3r/brvtal/issues/1655) |
| Búsqueda global | `global-search.js`, `admin-shell.js` | Abrir por teclado, resultados sin duplicados, vacío | Revisar overlay, foco, contraste y Escape |

## Medición reproducible de fuente (NO medición de CSS computado)

Medición realizada sobre `main` 0.1.235 con expresiones sobre archivos rastreados en Git; los conteos describen **declaraciones en código**, no número final de controles que el navegador presenta.

| Hoja | Líneas | `@media` | `:focus-visible` | `prefers-reduced-motion` | `!important` | Posibles tamaños 8–11 px |
|---|---:|---:|---:|---:|---:|---:|
| `discadmin/index-core.php` | 1248 | 7 | 1 | 0 | 11 | 56 |
| `discadmin/admin-shell.css` | 81 | 4 | 5 | 2 | 6 | 1 |
| `discadmin/admin-data-grid.css` | 142 | 4 | 4 | 2 | 5 | 1 |
| `discadmin/content-ordering.css` | 36 | 4 | 3 | 2 | 9 | 1 |
| `discadmin/media-library.css` | 29 | 3 | 4 | 1 | 7 | 4 |
| `discadmin/artist-hero-preview.css` | 53 | 1 | 0 | 0 | 0 | 2 |
| `discadmin/hero-slider-v2.css` | 13 | 3 | 0 | 1 | 1 | 8 |
| `discadmin/system-status-v2.css` | 51 | 5 | 11 | 0 | 1 | 3 |

**Reproducción:** contar reglas CSS en esos archivos; observar que CSS inline está empaquetado en `index-core.php`. Las cifras de fuente deben contrastarse con DevTools (especificidad, cascada, pixels computados y contraste), no convertirlas en un supuesto fallo WCAG confirmado.

## Matriz de evaluación de las cuatro skills (hallazgo / prioridad)

| Módulo | Impeccable | Interface Feel | transitions.dev | GSAP | Prioridad de remediación |
|---|---|---|---|---|---|
| Shell/navegación | Comprobar tamaño/jerarquía y drawer por viewport | A11y de Tab, Escape y targets de menú | Interrupción y reversibilidad de drawer | Evitar timeline persistente entre rutas | **P1** |
| Dashboard | Densidad y jerarquía de datos | Carga/vacío/degradado y acciones claras | No animar métricas que puedan saltar | Cero costo en tareas rutinarias | P2 |
| Eventos, Blog, Pages | Lectura y agrupación de formularios | Guardado/estado/error conservando borrador | Cambio de vista sin pérdida de foco | No requerir GSAP para editar | **P1** |
| Artistas | Imágenes/encuadres, miembro/invitado | Preview antes de guardar y fallback claro | Preview opcional suave sin ocultar original | Revertir animaciones de preview | **P1 #1657** |
| Sets | Columna de acciones, fotos, orden | Un solo grab-handle, teclado y éxito/error | Reordenamiento reversible sin falsas duraciones | No animar datos fuera de orden | #1638 fusionado; **P1 validar** |
| Releases / Media | Tarjetas y miniaturas consistentes | Upload, estados de procesamiento, 4xx/5xx | Crossfade solo tras éxito | Cleanup de previews | **P1** |
| Memorias / Hero | Composición y espacios, lectura | Toggle y preview de media sin scroll raro | Ajustar apertura de visor/slide | No dejar ScrollTrigger activos | P2 |
| Theme / SEO / Settings | Jerarquía y controles previsibles | Guardar/Deshacer y mensajes de validación | Transición solo entre paneles necesarios | Sin efectos en seguridad ni saves | P2 |
| Seguridad / Backups / Logs | Alto contraste y una acción principal | Confirmaciones, errores no engañosos | Evitar ocultar información crítica | Cero dependencia de GSAP | **P1** |

## Hallazgos priorizados y no duplicados

| Severidad | Hallazgo verificable o reportado | Acción | Dependencia / fuente |
|---|---|---|---|
| **P0** | No se ha reproducido un fallo destructivo de datos en esta auditoría estática. Errores previos de actualización Sets 1062 requieren **re-test en producción 0.1.234+** antes de cerrarlos como operativamente resueltos | Smoke de edición de registro existente con id y slug original | #1638 |
| **P1** | Campos/acciones de densidad excesiva en CSS inline, varias declaraciones tipográficas inferiores a 12 px | Consolidar tokens admin y controles en un frente de corrección visual medido | `index-core.php`; abrir PR focalizado, no reescritura |
| **P1** | Fotos Artistas ausentes, recorte de tarjeta y pertenencia «Colectivo.» ambigua en captura del dueño | Foto autorizada Instagram, encuadre editable, membresía visible, fallback | [#1657](https://github.com/pl0n3r/brvtal/issues/1657) |
| **P1** | Jerarquía y estados visuales fragmentados entre grid, legacy editors, media, slider | Comparar estilos computados, reusar contratos accesibles | `admin-data-grid.css`, `admin-form-dialogs.css`, `hero-slider-v2.css` |
| **P1** | Compatibilidad PHP CLI 8.2 / web 8.5, backups .part y noise de logs; no es defecto visual pero distorsiona QA | Corregir tras pruebas de backend y observador | [#1655](https://github.com/pl0n3r/brvtal/issues/1655) |
| **P2** | Motion sin prueba global sobre cierres, reentrada y `prefers-reduced-motion` para todos los módulos | Matriz interactiva GSAP/transitions.dev por módulo y cancelación | `admin-appearance.js`, `hero-slider.js`, `admin-shell.js` |
| **P2** | Mobile auth/post-login, formularios y búsqueda sin capturas homogéneas en 360/390/430/768/1024/1440 | E2E con screenshots anonimizada y prueba de overflow, foco/teclado | Bloque de evidencia pendiente |
| **P3** | Variación de labels y formato entre modules | Normalizar copy y estados sin romper datos | Diseño del sistema |

### Registro de problemas ya resueltos (no reabrir sin regresión)
- #1363 shell, #1364 datos, #1365 módulos y #1366 motion — intervenciones anteriores; no constituyen certificado global de cuatro skills.
- #1562 SQLSTATE 1267 al guardar Blog/Bulk — fusionado, entradas del log son anteriores al fix.
- #1638 Sets — fusionado a 0.1.234: nueva posición 01, SoundCloud, modal y actualización PUT; falta evidencia de uso productivo.
- #1651 navegación móvil pública — fusionado a 0.1.235, fuera del alcance DISCADMIN.
- #1656 Cromo Conectado/Contacto — frontend **público**, no confundir con DISCADMIN.

## Protocolo de evaluación visual pendiente

Estos son los **6 viewports requeridos** por el dueño. Los registros debajo son matriz de ejecución, **no capturas simuladas**:

| Viewport CSS | Dispositivo de referencia | Rutas priorizadas | Estado de evidencia |
|---:|---|---|---|
| 360 px | teléfono estrecho | Login, Dashboard, Sets, Artistas | **PENDIENTE**: screenshot autenticada y medición real |
| 390 px | iPhone estándar | Sidebar, modal Sets, Artistas | **PENDIENTE** |
| 430 px | teléfono grande | Artistas, Media, formularios | **PENDIENTE** |
| 768 px | tableta vertical | Layout admin, media y tablas | **PENDIENTE** |
| 1024 px | tableta horizontal | Dashboard, tabla Sets, Modales | **PENDIENTE** |
| 1440 px | escritorio | Todos los módulos, contraste y overflow | **PENDIENTE** |

**Criterio de cierre:** cada captura debe ser reproducible desde browser test con identidad y datos anonimizados, URL/commit y viewport; comparativa antes/después solo después de implementar el cambio correspondiente. En esta fase no se ha ejecutado una prueba visual autenticada contra Hostinger. Las capturas proporcionadas por el dueño evidencian fricción, pero no prueban el resultado de cambios futuros.

### Secuencia para remediación

1. Validar la entrega #1638 en producción con registros reales sin modificar datos fuera de un smoke controlado.
2. Inventario de screenshots y métricas de layout por viewport; etiquetar contraste/color, focus/Tab/Escape, reducción de movimiento, 4xx/5xx y vacío.
3. Ejecutar P1 por componentes pequeños: controles/tokens admin; Artistas y miniaturas; Media/Preview; guardado/error.
4. Ejecutar P2: transiciones reversibles y GSAP con cleanup solo en los flujos que lo necesitan.
5. Repetir las **mismas capturas** tras cada fix y enlazar PR + CI exact-HEAD + aprobación estética explícita del propietario.

### Relación con backlog activo
[#1638](https://github.com/pl0n3r/brvtal/issues/1638) (completado) · [#1651](https://github.com/pl0n3r/brvtal/issues/1651) (completado) · [#1655](https://github.com/pl0n3r/brvtal/issues/1655) (pendiente) · [#1656](https://github.com/pl0n3r/brvtal/issues/1656) (pendiente) · [#1657](https://github.com/pl0n3r/brvtal/issues/1657) (pendiente).

**Este documento no certifica aprobación visual ni el cierre de #1652:** faltan la fase visual autenticada y la remediación asociada. Nunca presentar «4 skills ejecutadas» como sustituto de evidencia de interfaz.
