# Auditoría de producción — home Cromo e interiores (base v0.1.195)

**Estado: PARCIAL / NO VALIDADO EN PRODUCCIÓN EN SU TOTALIDAD.** No se cierra #1507 ni se afirma `VALIDATED_IN_PRODUCTION` sin los puntos pendientes de autoridad y dispositivo real.

- **Fecha:** 2026-11-04 (UTC; navegador registrado ~13:24Z).
- **Origen:** #1507; entrega #1496 / PR #1497 (v0.1.195). La web pública observada referencia assets `?v=bb78ab8` y el `main` leído al comienzo fue `bb78ab86f7a8ca59f86e90d2b8dc5fa836b70679`; **esto no prueba identidad de despliegue ni versión exacta del backend**.
- **Entorno:** Mac conectado (macOS/Darwin), Chromium headless `154.0.8037.57`, escritorio 1440×900 y **emulación** de viewport móvil 390×844, `deviceScaleFactor=1`, touch emulado y `prefers-reduced-motion: reduce` donde se indica. No hubo teléfono físico ni equipo de gama baja real.
- **Alcance y límites:** solo GET público, clicks de UI y observación DOM; sin credenciales, sin envío del formulario, sin escritura a datos de producción. Se observó HTTP 404 en `/health` por la ruta probada; no se deduce que no exista otro endpoint canónico.

## Evidencia visual capturada

Capturas PNG reales generadas con Puppeteer en el equipo autorizado. Están disponibles **localmente**, no se adjuntan al repositorio porque el claim V3 de #1507 permite solo este Markdown (no hay autoridad para añadir nuevos paths de captura):

| Captura local bajo `/Users/pl0n3r/Downloads/brvtal-1507-evidencia/` | HTTP | SHA-256 de los bytes PNG |
| --- | ---: | --- |
| `home-desktop.png` | 200 | `28d734f7714b9b2c06bf31ee3e2c3f30e88a4919589b81c6ab1c88d9d64d2961` |
| `home-390px.png` | 200 | `37caa13adf7ca631ea36b3f476619c13382eea6123ca554aff4c16c3f37f5a1a` |
| `releases-404.png` | 404 | `91b63999fc1566fa21028f6ba1d1319435592a5e911531bae591e88b81db810a` |
| `contacto-390px.png` | 200 | `b80abb73882ded55b2818b1578a10961c22b390f04a0e06d855c3e7173d5e3a1` |

Las rutas `/Users/...` **no son URLs de GitHub**: la evidencia visual aún debe incorporarse de modo permitido a un artefacto compartible antes de declarar AC-01 plenamente satisfecho.

## Matriz de resultados

| Área | Prueba / observación exacta | Resultado | Evidencia / defecto |
| --- | --- | --- | --- |
| Home desktop | `GET /`, título «BRVTAL — Rave till Grave» y secciones Cromo `#noches`, `#conectado` visibles | **CORRECTO (carga)** | HTTP 200, DOM renderizado, `home-desktop.png` |
| Sonido home | `#snd` inicialmente `aria-pressed=false`; click → `true`, «Apagar el pulso»; segundo click → `false`, «Activar sonido» | **CORRECTO (estado UI)** | Reproducción acústica en altavoz **NO VERIFICADA**; la página no expone elementos `audio` (puede usar Web Audio) |
| Sonido interiores | `GET /contact` responde 200, pero no hay `[data-cromo-sound]`, `#snd` ni botón aria de sonido | **DEFECTO** | #1514; `contacto-390px.png`. Otras rutas de interiores no se pueden probar porque devuelven 404 |
| Formulario home | Campos `name`, `email`, `message`, honeypot `website`, `captcha_token` oculto y `captcha_answer` obligatorio | **NO VERIFICADO (envío)** | No se probó anti-robot real, error español ni éxito; #1507 requiere mensaje de prueba acordado explícitamente con el dueño |
| 390px emulado | `innerWidth=390`, `scrollWidth=390`, secciones `#noches` y `#conectado` con ancho 390 | **CORRECTO (sin overflow horizontal)** | `home-390px.png`; la geometría de todas las tarjetas y banners requiere inspección visual y dispositivo real |
| Móvil físico / gama baja | Dispositivo físico, tacto, render cromado y rendimiento | **NO VERIFICADO** | No había dispositivo físico móvil accesible; Chrome headless 390px no equivale a hardware real |
| Menú interior a 390px | `/contact` solo presenta «INICIO ↙» y branding; `header nav a` = 0 | **DEFECTO** | #1515; no se visualiza menú Cromo de dos filas |
| Páginas interiores | `/events`, `/artists`, `/releases`, `/sets`, `/media`, `/journal` | **DEFECTO de navegación publicada** | HTTP 404 en cada URL probada; `/releases` es destino real del enlace «RECORDS» de home, registrado en #1512. Otros paths se probaron como candidatos, no se afirma que todos fueran rutas canónicas |
| Movimiento reducido | En viewport emulado se aplicó `prefers-reduced-motion: reduce`; `matchMedia` devolvió `true` | **PARCIAL** | No se midieron transiciones, animación WebGL ni consumo en hardware real |
| Foco por teclado | Se recorrieron 12 controles mediante Tab en viewport emulado 390px; todos reportaron `:focus-visible=true`, `outline-style:solid` y `outline-width` de 3–4px | **CORRECTO (muestra de 12 controles)** | No equivale a auditoría completa de orden de tabulación ni contraste visual en dispositivo físico |
| Conectado | Seleccionar «TENSION MEDIANOCHE» produce `aria-pressed=true`; con esa selección activa, Escape deja **cero** botones activos; click sobre nodo activo también lo deselecciona | **CORRECTO (Escape y selección)** | Ensayo Chromium 154 en emulación 390px, sin hardware físico |
| Pausa de banners | Botón con `aria-label='Pausar los banners'` → clic → `aria-label='Reanudar los banners'`, `aria-pressed=true` | **CORRECTO (estado UI)** | No se observó duración completa para probar detención del autoplay |
| Cookies en home e interiores | Home y `/contact` presentan controles «Rechazar», «Aceptar» y preferencias. En contexto aislado se pulsó «Rechazar»: el banner dejó de mostrarse y siguió oculto tras recargar | **CORRECTO (presencia y persistencia local)** | Se comprobó home y `/contact`; las rutas interiores 404 no admiten comprobación |
| «Últimas noches» | La sección legacy `#events` existe pero queda oculta (alto 0); la visible está en `#noches`. `#archive` no existe; visitar ambos hashes no lleva al bloque visible esperado | **DEFECTO** | #1513. La lista legacy indica «NO PUBLISHED NIGHTS YET»; no hubo noche pasada publicada comprobable para validar ese escenario |

## Defectos confirmados y tickets

- [#1512](https://github.com/pl0n3r/brvtal/issues/1512) — enlace de home «RECORDS» conduce a `/releases`, HTTP 404 en producción.
- [#1513](https://github.com/pl0n3r/brvtal/issues/1513) — los hashes `#events` / `#archive` no llevan al archivo Cromo visible.
- [#1514](https://github.com/pl0n3r/brvtal/issues/1514) — `/contact` carece de interruptor de sonido.
- [#1515](https://github.com/pl0n3r/brvtal/issues/1515) — `/contact` carece del menú interior de dos filas a 390px.

No se han registrado como defectos las observaciones inconclusas, la falta de dispositivo real ni las rutas supuestas sin enlace canónico; tampoco se ha alterado contenido del producto.

## Cierre de aceptación / autoridad pendiente

- **AC-01:** parcial. Existen resultados de UI y defectos reales; faltan audio audible, envío anti-bot, mensaje de error/éxito, móvil físico de gama baja, pausa temporal de banners y noche pasada publicada. Las capturas aún están fuera del repositorio.
- **AC-02:** este archivo corresponde a la ruta reclamada.
- **AC-03:** cuatro defectos reproducidos tienen Issue enlazado.
- **AC-04:** cumplido en esta ejecución; solo GET públicos, interacción UI, sin credenciales ni envío de formulario.
- **AC-05:** pendiente de `factory-ci / Validar` terminal `success` sobre HEAD definitivo y revisión final permitida.

**Puerta humana explícita:** antes de enviar el formulario de producción, el dueño debe acordar el texto de la prueba y autorizar el envío. Sin esa autorización no se marca el criterio como probado. La prueba en un **móvil físico real** tampoco se sustituye por emulación ni se declara cumplida por falta de equipamiento.

**Continuación segura:** completar pruebas permitidas, incorporar evidencia visual mediante un claim legítimo sin invadir rutas, resolver autorización/hardware, y solo entonces abrir/finalizar el PR y satisfacer gates exactos para fusionar; no falsificar un resultado por el color de CI.
