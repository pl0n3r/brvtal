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

## Secciones del boceto
1. **Hero**: wordmark que entra letra por letra, lema sobre placa, tarjeta de la próxima noche (fecha, lugar, lineup, entradas) y botones.
2. **Noches**: tarjetas oscuras con fecha, lugar, lineup, estado de entradas y TICKETS.
3. **Artistas**: nombres grandes contorneados con ficha corta.
4. **Sonido**: invitación a encender el pulso con el interruptor.
5. **Sets**: filas con título, artista y enlace.
6. **Memorias**: filtros (Todo, Imágenes, Video, Audio) y cuadrícula de mosaicos con uno grande y títulos en la esquina.
7. **Journal**: tarjetas oscuras (Crónica, Archivo) con título en Broken Glass y botón; **Conectado** resume los vínculos verificados.
8. **Contacto** ("Hablemos"): placa oscura con formulario (nombre, correo, mensaje, comprobación y envío) y pie de página.

## Lo que no cambia
Contenido y datos del CMS, rutas, formularios, comprobaciones anti-robots, estados vacíos, enlaces canónicos y funciones existentes. El boceto es una referencia visual: sus textos y tarjetas de ejemplo no son contenido real.
