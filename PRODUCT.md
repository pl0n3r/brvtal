# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users
Gente de la escena de música electrónica underground de Pereira y Colombia: asistentes a las noches, artistas y colectivos, y quien llega por una referencia a un set o a una foto. Entran sobre todo desde el celular, con poca luz y con prisa: quieren saber qué pasa, cuándo, dónde y poder escuchar o contactar. El equipo de BRVTAL usa DISCADMIN para publicar (sistema aparte, no cubierto aquí).

## Product Purpose
Plataforma de música y archivo cultural: publica eventos ("noches"), artistas, sets de sonido, memorias (fotos/video), un journal y un contacto. El éxito es que una persona nueva entienda en segundos qué es BRVTAL y llegue a la próxima noche, a un set o a un artista, y que el archivo se sienta como una publicación cuidada y no como una cartelera.

## Positioning
BRVTAL no es una agenda de fiestas: es el archivo vivo de una escena concreta (Pereira, Colombia). El mecanismo propio es la relación estructurada entre eventos, artistas, sets y memorias (los datos están relacionados, no copiados como texto).

## Operating Context
Sitio PHP + HTML/CSS/JS sin framework, con contenido administrable desde DISCADMIN (CMS propio) y la paleta administrable desde Theme Studio. Despliegue GitHub `main` → Hostinger. Idiomas ES (canónico) y EN. Fase actual: construcción.

## Capabilities and Constraints
- El contenido real lo carga el equipo en DISCADMIN; hoy hay contenido de prueba visible ("DUMMY SET", "sample-set-one") que no debe fabricarse ni sustituirse con datos inventados.
- La paleta (`--bg`, `--fg`, `--muted`, `--red`, `--acid`, `--line`) la controla Theme Studio: ningún rediseño puede crear un sistema de color paralelo ni fijar colores que impidan reteñirlo.
- Cualquier cambio visible exige refrescar las huellas y baselines de CSS de CI tras revisión del dueño (#1417/#1418).
- Sin cookies no esenciales por defecto; texto legal pendiente de aprobación profesional.
- Pendiente de decidir: asignación de los medios reales del hero y de la sección Genesis.

## Brand Commitments
Nombre BRVTAL, lema "Rave till Grave", identidad Concepto 05: negro profundo, papel sucio, verde señal y rojo señal, tipografía condensada de gran tamaño y mono para metadatos, reglas finas, textura de grano/scanline y registro/glitch usados con medida. El dueño pidió evolucionar esta identidad, no sustituirla.

## Evidence on Hand
Logo (`assets/brvtal-logo-*.webp`), flyers de eventos en `assets/flyers/`, imágenes Concepto 05 generadas (#1348). No hay testimonios, cifras ni métricas de audiencia: no inventarlas.

## Product Principles
1. El archivo manda: eventos, artistas, sets y memorias se muestran como piezas de una misma publicación.
2. Primero lo accionable: próxima noche, escuchar, contactar, en el primer viewport móvil.
3. Un sistema, no parches: tokens y componentes únicos, sin reglas que se pisen entre hojas.
4. Accesible por defecto: contraste AA, objetivos táctiles de 44 px, movimiento reducido respetado.
5. Honestidad de contenido: los estados vacíos se diseñan; el contenido de prueba no se disfraza de real.

## Accessibility & Inclusion
WCAG 2.2 AA como piso. Navegación por teclado, `prefers-reduced-motion`, texto mínimo legible (hoy hay metadatos de 8 a 12 px), ES/EN.
