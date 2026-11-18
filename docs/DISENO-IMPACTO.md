# BRVTAL · capa de impacto sobre Concept 05

Decisión del dueño (@pl0n3r, 2026-10-29): el home y el panel deben destacar por pulidos y llamativos, sin atarse a la composición anterior si el criterio de diseño ve algo mejorable. Se conservan los contratos de contenido, las plantillas base, las rutas, el CMS y los controles de Theme Studio; el HTML **renderizado sí cambia**. `config/public_home.php` inyecta hojas, script, declaración del hero, figura documental y ajustes de la próxima experiencia. `discadmin/index.php` carga el estilo del panel después de `admin-design-system.css`.

## Qué hay

| Capa | Archivos | Qué hace |
| --- | --- | --- |
| Impacto del hero | `css/public-brvtal-impact.css`, `js/public-brvtal-impact.js` | Wordmark de borde a borde con su glitch, figura documental a sangre detrás del hero, brillo ácido, declaración en una fila, cinta de texto ácida, entrada del título con GSAP, ráfaga de glitch, paralaje de la foto, barra de progreso de lectura y reacción al pulsar. |
| Secciones | `css/public-brvtal-sections.css` | Etiquetas de sección en la señal ácida, título de «próxima experiencia» legible, pie con filete ácido sin grano, Noches y Artistas a ancho completo con rejillas adaptables, textos secundarios del Archivo y de medios con contraste AA. |
| Panel DISCADMIN | `discadmin/admin-frecuencia.css` | Esquinas rectas, sin sombras, señal ácida en la opción activa del menú y filete en las tarjetas del tablero. Se carga tras `admin-design-system.css`. |

## Reglas que no se rompen

- **Aditivo y reversible.** Las nuevas hojas no modifican las hojas base de Concept 05. Para retirar completamente las capas públicas hay que quitar de `config/public_home.php` **ambas hojas** (`css/public-brvtal-impact.css` y `css/public-brvtal-sections.css`) y la inyección **independiente** del script `js/public-brvtal-impact.js`; quitar solo el CSS deja activo el ticker y el JS. Para retirar el estilo del panel, quitar la inclusión de `discadmin/admin-frecuencia.css` en `discadmin/index.php`. No eliminar los assets base de Concept 05.
- **Colores administrables.** Las capas públicas usan tokens `--c5-*` de la paleta de Theme Studio. El panel usa `--admin-signal: var(--acid, #b8ff00)`: sigue el acento `--acid` cuando existe y emplea el verde ácido fijo como respaldo. El texto sobre el acento usa `--admin-signal-ink: #050505`. No afirmar que `--c5-*` gobierna directamente el panel.
- **Contenido visible por defecto.** Las animaciones de entrada limpian sus estilos al terminar y nada queda oculto si GSAP no carga.
- **Movimiento con límites diferenciados.** El JS desactiva la entrada GSAP, el glitch temporizado y el paralaje cuando `prefers-reduced-motion: reduce`, `data-theme-motion="reduced"`/`"minimal"` o `html.c5-visual-test`; `data-theme-glitch="0"` inhibe específicamente la ráfaga de glitch. **El ticker CSS solo se detiene con `prefers-reduced-motion: reduce`**: actualmente no atiende por sí mismo `data-theme-motion` ni `html.c5-visual-test`. La cinta es decorativa (`aria-hidden`). Cualquier apagado global del movimiento requerirá otra intervención de CSS fuera de este documento.
- **Contraste a comprobar por paleta.** El texto del Archivo y de medios pasó de grises fijos de 8–9 px al token `--c5-paper-muted` a 11 px. Esto mejora consistencia y tamaño, **pero no prueba por sí solo WCAG AA**: validar la pareja real token/fondo en cada tema administrable. No extrapolar al token atenuado las comprobaciones de contraste realizadas para otra pareja de colores.
- **Sin `!important` en las tres hojas añadidas** (`css/public-brvtal-impact.css`, `css/public-brvtal-sections.css` y `discadmin/admin-frecuencia.css`); esta regla no describe las hojas base de Concept 05, que pueden usar esa prioridad para modos especiales. No se introduce otra dependencia de terceros (GSAP ya se cargaba).

## Fotografía

El hero reserva la figura documental (`[data-c5-hero-documentary]`) con recorte a sangre y `object-fit: cover`; **el contenedor responsivo no garantiza una relación 16:9**. El formato 16:9 puede usarse como orientación editorial de la **imagen fuente**, no como contrato de layout. El arte del hero y de la próxima experiencia se selecciona desde la Biblioteca de medios de DISCADMIN. Las rutas y prompts de imágenes pendientes deben registrarse en un manifiesto versionado cuando exista, sin remitir a uno no comprobado. Mientras no hay medio, el hero muestra el brillo ácido y la rejilla.

## Theme Studio

Los controles de grano, scanlines y glitch siguen existiendo. El pie ya no dibuja el grano (`public-brvtal-sections.css`), así que ese interruptor solo afecta a las demás superficies.

## Pruebas

`tests/test_public_brvtal_impact.py`, `tests/test_public_brvtal_sections.py` y `tests/test_discadmin_frecuencia.py` fijan estas reglas. `tests/test_public_concept05_hero.py` (Playwright) conserva la regresión visual; una ejecución histórica no sustituye la evidencia del CI exacto del PR actual.

## Pendiente

- Revisión con lector de pantalla y prueba en móvil real.
- Listas de noches y artistas con muchos registros reales.
- Fotografía real en el hero y en la próxima experiencia.
- Pantallas de DISCADMIN distintas del tablero y sus temas claro y de vidrio.
