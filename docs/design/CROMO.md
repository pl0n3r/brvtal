# Cromo — brief de diseño del sitio público de BRVTAL

Decisión del dueño (2026-11-04): el boceto **Cromo** (`docs/design/cromo-prototype.html`) se lleva a producción completo, con paleta roja, blanca y negra, con sonido y con Broken Glass en los títulos. El texto del sitio es solo en español.

## Idea
Metal líquido sobre un fondo casi negro. Un fondo WebGL de metaballs con reflejo cromado reacciona al puntero y, si el sonido está activo, al pulso del bombo. Todo el texto se apoya en placas oscuras o en contorno grueso para leerse sobre ese fondo.

## Paleta
- Negro `#050505` (fondo y placas, con `rgba(3,3,3,.9)` sobre el cromo).
- Blanco hueso `#f4f1e8` (texto y relleno de títulos).
- Rojo de acento (franja del cromo, sombra de títulos, botones, estados activos). Debe dar al menos 4,5:1 contra `#050505` cuando se use como texto; en botones, tinta blanca o negra según contraste.
- El acento sigue siendo administrable desde Theme Studio (`--red`); no se crea un sistema de color paralelo.

## Tipografía
- Títulos (wordmark y `h2` de sección): **Broken Glass** (`assets/fonts/BrokenGlass.ttf`), relleno blanco, contorno oscuro (`-webkit-text-stroke` con `paint-order: stroke fill`) y sombra roja desplazada.
- Texto corrido y etiquetas: Barlow Condensed.
- Nunca usar `mix-blend-mode: difference` para texto: no es legible sobre el cromo.

## Legibilidad
- Todo bloque de texto va sobre una placa oscura o con contorno grueso.
- Contraste mínimo de 4,5:1; objetivos táctiles de 44 px; foco visible.
- Movimiento reducido: un solo fotograma del fondo, sin bucle; el título nunca se oculta (las animaciones solo usan `transform`).
- Sin WebGL, con ahorro de datos o en equipos limitados: se ve el diseño actual sin reglas nuevas (todo bajo `html.cromo`).

## Sonido
Web Audio sintetizado a unos 130 BPM: bombo (seno con barrido de 170 a 42 Hz), hi-hat de ruido filtrado (abierto y cerrado) y bajo de sierra con filtro. Solo arranca tras un clic, está apagado por defecto, tiene interruptor accesible con `aria-pressed` y etiquetas en español ("Activar sonido", "Apagar el pulso"), y se detiene al ocultar la pestaña. El pulso del bombo alimenta el brillo del fondo (`uBeat`).


## Home Cromo completa (decisión del dueño: 2026-11-04, #1496)

Fuente de verdad ejecutable: `js/public-cromo-home.js` y `css/public-cromo-home.css`. El prototipo `docs/design/cromo-prototype.html` es solo una referencia estética, no contenido editorial real. Cromo se monta después de recibir `/api/public.php`; ante fallo de API o montaje se conserva la home Concept 05. Sin WebGL sigue habiendo fondos negros y texto legible.

Orden real del DOM (no sustituir por un page builder):

1. **Barra superior + Hero**: navegación a Noches, Artistas, Sonido, Memorias, Journal y Contacto; marca, tickets y pulso opt-in. Wordmark en Broken Glass, lema y tarjeta de la próxima noche si existe, con fecha y lugar; sin próxima noche se omite solo la tarjeta. Franja editorial después del hero.
2. **Destacados** (opcional): banners/pósters de DISCADMIN entre franja y Noches, en duotono rojo y negro, descritos abajo.
3. **Noches**: hasta seis noches próximas con fecha, ciudad, local, hora, estado de tickets y ruta `/events/{slug}`. Siempre incluye «Aún sin fecha» hacia Contacto. **Últimas noches** es un subapartado aquí, nunca una sección principal independiente.
4. **Artistas**: hasta cinco artistas públicos, primero miembros actuales del colectivo, con nombres grandes, breve descripción y ficha, más invitación a contactar.
5. **Experiencia**: próxima noche destacada con póster opcional, fecha/hora/lugar, descripción y cartel extraído de relaciones publicadas; CTA de tickets únicamente con URL segura y vínculo a la ficha. Sin noche se omite Experiencia; sin imagen se utiliza composición de texto a una columna.
6. **Sonido**: control accesible para activar voluntariamente el pulso; no autoplay.
7. **Sets**: último set publicado como protagonista y hasta cuatro adicionales con enlaces oficiales o ficha interna; sin sets, mensaje de que aparecerán cuando estén publicados.
8. **Memorias**: hasta nueve medios publicados en mosaicos (primero destacado), filtros Todo/Imágenes/Video/Audio. Sin medios, mensaje explícito y ninguna imagen inventada.
9. **Journal**: última crónica si hay post público y tarjeta editorial «Archivo» que enlaza a Memorias; sin post, solo la tarjeta editorial.
10. **Conectado**: explorador real de vínculos entre noches, artistas, sets y discos (abajo).
11. **Contacto / Hablemos**: formulario con nombre, correo, mensaje, reto anti-bot y estados de error/éxito desde `/api/contact.php`; pie con privacidad y preferencias de cookies cuando estén disponibles.

### Últimas noches y enlaces de archivo

Se alimenta de `data.archive.events`: únicamente eventos históricos con slug y fecha válidos, ordenados del más nuevo al más antiguo. Los pósters muestran portada si existe, título, fecha y ciudad; enlazan a `/events/{slug}`. Se ven ocho inicialmente, con «Ver todas» para desplegar el resto. Sin historial válido se omite este subapartado. El enlace histórico `#events` conduce a `#noches`, mientras `#archive` conduce a `#archivo`, ancla interna de Últimas noches cuando existe. El archivo **vive dentro de Noches** por decisión del dueño.

### Destacados: Slider Manager de DISCADMIN

Origen: `GET /api/hero-slider.php`. Si el endpoint falla, el slider está desactivado o no hay slides con `desktopSrc` válido, se omite Destacados **sin bloquear Cromo**. Cada slide representa un póster a todo el ancho: imagen o vídeo silenciado, variante móvil, filtro gris/negro con tinte rojo, overlay oscuro y retorno a los colores del medio en hover/foco.

Respeta los parámetros editoriales reales: capas de texto, imagen, logo o CTA; posiciones `x/y/width`, overrides y ocultación móvil, alineación, `fade`/`slide-up`/`slide-left`/`zoom`; sin capas se usa la composición heredada de entradilla, título, texto y CTA. Las transiciones entre slides son `fade`/`slide`/`zoom`. Con varias slides hay contador, barras de progreso, anterior/siguiente y pausa; con una sola no se muestran controles innecesarios. Autoplay e intervalo vienen del CMS, pero la rotación se detiene en pausa, hover/foco, pestaña oculta o movimiento reducido. URLs validadas y texto CMS insertado como texto, no HTML arbitrario.

### Conectado: relaciones publicadas, sin saturación (#1656)

El explorador recibe únicamente relaciones reales desde `/api/public.php`. Muestra columnas Noches, Artistas, Sonido y, cuando existen, Memorias. Se presentan hasta seis elementos por columna, con expansión voluntaria. **No selecciona automáticamente** ninguno: el estado inicial explica cómo navegar. Al seleccionar una entidad, se destacan sus vecinos y el resumen presenta conteos por categoría (Noches, Artistas, Sets, Releases y Memorias) y hasta dos enlaces canónicos reales por categoría, con «Ver todos» progresivo. No inventar vínculos ni enumerar 20+ sets en un párrafo. Se eliminan las líneas SVG cruzadas; Escape o «Quitar selección» restaura el estado inicial. En móvil, teclado y reduced-motion, la información y los enlaces siguen disponibles.

Contacto conserva `/api/contact.php`, honeypot y el reto anti-bot, con tamaños táctiles y etiquetas asociadas a errores. Si el reto no carga, se bloquea el envío y se ofrece reintento manual, sin simular envío exitoso. Sonido y Contacto tienen altura basada en contenido, no espacios forzados; las secciones vacías se ocultan de acuerdo con #1651.

## Páginas interiores Cromo

`config/public_cromo_chrome.php` comparte barra superior, menú de anclas de la home, botón de pulso con `aria-pressed` y enlace «Volver». Las páginas de entidades (eventos, artistas, sets, discos, blog y CMS), privacidad, 404 y 503 usan `css/public-cromo-pages.css`: póster hero duotono, título gigante Broken Glass con glitch rojo, tarjetas cromadas y Barlow Condensed para los detalles.

Las fichas muestran únicamente entidad pública, datos/acciones disponibles y relaciones publicadas. Una tarjeta sin imagen usa la marca BRVTAL; sin descripción se usa un texto de marca; los bloques relacionados vacíos no aparecen. Los eventos diferencian activo/histórico con banda de ciclo de vida. Privacidad conserva datos legales configurados o marcadores explícitos de pendiente. Las páginas 404/503 mantienen la identidad, pero comunican falta/indisponibilidad de datos, no registros inventados. Fondo WebGL opcional: sin él permanece base negra legible.

## Tokens de movimiento y glitch

La escala **transitions.dev** está definida tanto en home como en interiores: `--duration-stagger:40ms`, `--duration-micro:80ms`, `--duration-quick:150ms`, `--duration-fast:250ms`, `--duration-medium:350ms`, `--duration-slow:400ms`, `--duration-very-slow:500ms`; suavizado `--ease-smooth-out:cubic-bezier(.22,1,.36,1)`, `--ease-in-out`/`--ease-out`, distancia `--distance-medium:12px` y blur de `2px`/`3px`. El texto «soft swap» de Conectado cruza blur/opacidad en `200ms` y reajusta anchura en `240ms`.

El **glitch rojo** duplica el título en dos pseudocapas con `data-text`, recortes y desplazamientos cortos; ejecuta una ráfaga breve cada **5,4 s**. **Nunca sacudir, ocultar ni desplazar el texto base**: solo se animan las capas. Con `prefers-reduced-motion: reduce` se suprimen glitch, entradas, rotación automática de banners, dibujo de líneas, movimiento del wordmark y transiciones no esenciales; los controles y el texto siguen visibles y operables. Mantener foco visible, mínimo táctil de 44 px y contraste de 4,5:1 para texto.

## Decisiones del dueño (2026-11-04)

- **Broken Glass** para títulos y **Barlow Condensed** para texto; **rojo/blanco/negro**, sin variante verde. La configuración de tema no debe sacrificar contraste.
- Sitio público **solo en español**, sin selector de idioma ni traducciones editoriales automáticas.
- Cromo completo en home e interiores, sonido solo opt-in y archivo de eventos **dentro de Noches** como «Últimas noches».
- Los banners salen de DISCADMIN y las relaciones de datos publicados; datos ausentes se presentan como ausencia, nunca como contenido simulado.

## Lo que no cambia
Contenido y datos del CMS, rutas, formularios, comprobaciones anti-robots, estados vacíos, enlaces canónicos y funciones existentes. El boceto es una referencia visual: sus textos y tarjetas de ejemplo no son contenido real.


## BRVTAL Audio Deck (diseño anterior, reemplazado por #1649)

En el Home Cromo, al elegir **ESCUCHAR** el widget oficial SoundCloud deja de estar dentro de las tarjetas. Se crea una sola superficie de escucha compartida, flotante, negro/rojo y con información auténtica del set: carátula SoundCloud con respaldo CMS, título, artistas, atribución y enlace externo al recurso oficial. Las tarjetas del destacado y del catálogo nunca incorporan iframes ni alteran su altura cuando se escucha, cambia o cierra un set. Solo se crea el iframe tras la interacción explícita del visitante; cerrar o cambiar de set desmonta el anterior y detiene el audio.

El Audio Deck puede minimizarse/expandirse sin sustituir el iframe activo, y cerrarse completamente (también con Escape) restaurando el foco al disparador. El pulso sintetizado y el audio del set se excluyen mutuamente. SoundCloud controla la forma de onda y el audio desde su reproductor nativo; la presentación del iframe se oscurece visualmente con un filtro CSS, sin modificar contenido cross-origin. Si el proveedor o su reproductor no están disponibles, permanece el enlace original. No se simulan ondas de audio, duración o estados de reproducción que la integración no pueda demostrar.

El diseño usa las variables existentes `--red`, `--bone`, Broken Glass y Barlow Condensed; GSAP es una mejora optativa en la entrada de panel con alternativa sin animación y respeto a `prefers-reduced-motion`. No afecta el CMS, el orden editorial, el mosaico de otros sets, VER MÁS SETS ni Concept 05.

## Decisión vigente: SoundCloud integrado en ESCUCHAR (#1649)

El dueño reemplazó expresamente el *Audio Deck* flotante de #1647: **no debe
abrirse ningún popup, dock fijo, modal o ventana superpuesta**. Al pulsar
ESCUCHAR, la zona de botones de esa misma tarjeta muestra un reproductor
compacto oficial de SoundCloud, con base negro/rojo. La portada/título/DJs
permanecen visibles y no se duplican; el botón pasa a CERRAR y el enlace
ABRIR EN SOUNDCLOUD se conserva. Los demás sets no montan reproductores.

El iframe se crea solo con un clic y se desmonta al cerrar, al cambiar de
set o al comenzar la música ambiente. La cuadrícula mantiene el orden
editorial; el set activo puede aumentar únicamente lo necesario para sus
controles nativos. En escritorio ancho, las acciones de una tarjeta activa
usan su propia fila para dar ancho útil al reproductor; en móvil lo muestran
bajo los botones dentro de la misma tarjeta. Reducir movimiento no altera
controles y GSAP es progresivo.

La interfaz del iframe pertenece a SoundCloud (cross-origin): las teclas
pulsadas dentro de ese frame no llegan al contenedor BRVTAL, por lo que
el botón CERRAR se mantiene accesible por Tab. En caso de falta de
disponibilidad se informa cómo abrir el set directamente en SoundCloud;
un 404 tardío conserva el enlace y restaura allí el foco si procede.
