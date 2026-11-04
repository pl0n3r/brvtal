# Auditoría de producción — home Cromo e interiores (base v0.1.195)

**Estado: PARCIAL / NO VALIDADO EN PRODUCCIÓN EN SU TOTALIDAD.** No se cierra #1507 ni se afirma `VALIDATED_IN_PRODUCTION` sin los puntos pendientes de autoridad y dispositivo real.

- **Fecha:** 2026-11-04 (UTC; navegador registrado ~13:24Z).
- **Origen:** #1507; entrega #1496 / PR #1497 (v0.1.195). La primera lectura referenció assets `?v=bb78ab8` y `main@bb78ab86f7a8ca59f86e90d2b8dc5fa836b70679`. Tras otro merge concurrente, el **GET público de `/api/health.php`** devolvió `ok=true`, `version=0.1.197`, `release_sha=95e1ae469d69e47702cd2f0893780cad96872c7d`, `deployment.exact=true` y `source=git_checkout`; `main` fue reconsultado y coincide con ese SHA. La segunda lectura de la home referencia assets `?v=95e1ae4`. Los defectos #1512–#1515 persistieron al revalidar sobre esa versión.
- **Entorno:** Mac conectado (macOS/Darwin), Chromium headless `154.0.8037.57`, escritorio 1440×900 y **emulación** de viewport móvil 390×844, `deviceScaleFactor=1`, touch emulado y `prefers-reduced-motion: reduce` donde se indica. No hubo teléfono físico ni equipo de gama baja real.
- **Alcance y límites:** solo GET público, clicks de UI y observación DOM; sin credenciales, sin envío del formulario, sin escritura a datos de producción. La ruta inicial `/health` devolvió 404; el contrato de salud público aplicable es `/api/health.php`, accesible y coherente con `main@95e1ae4`.

## Evidencia visual capturada

Capturas PNG reales generadas con Puppeteer en el equipo autorizado. Están disponibles **localmente**, no se adjuntan al repositorio porque el claim V3 de #1507 permite solo este Markdown (no hay autoridad para añadir nuevos paths de captura):

| Captura local bajo `/Users/pl0n3r/Downloads/brvtal-1507-evidencia/` | HTTP | SHA-256 de los bytes PNG |
| --- | ---: | --- |
| `home-desktop.png` | 200 | `6ea68970a25709bc607c0a6266267dc3af06f17ffc11aa9eb451df55ed26cd5e` |
| `home-390px.png` | 200 | `84a4b303e4f4909aec68da190beda7941b472d6c8f79ac269e826f7b97f55b22` |
| `releases-404.png` | 404 | `91b63999fc1566fa21028f6ba1d1319435592a5e911531bae591e88b81db810a` |
| `contacto-390px.png` | 200 | `9bf62c7bfd437ced819b3d7338915fc3ae0a084e95d71021454194ac6ecf6721` |
| `evento-390px.png` | 200 | `5159f7f068c337eff3df30b68e0a5aafc7ef45bf2408e6e08d02845327cad913` |

Las rutas `/Users/...` **no son URLs de GitHub**: la evidencia visual aún debe incorporarse de modo permitido a un artefacto compartible antes de declarar AC-01 plenamente satisfecho.

## Matriz de resultados

| Área | Prueba / observación exacta | Resultado | Evidencia / defecto |
| --- | --- | --- | --- |
| Home desktop | `GET /`, título «BRVTAL — Rave till Grave» y secciones Cromo `#noches`, `#conectado` visibles | **CORRECTO (carga)** | HTTP 200, DOM renderizado, `home-desktop.png` |
| Sonido home | `#snd` inicialmente `aria-pressed=false`; clic → `true`, «Apagar el pulso»; segundo clic → `false`, «Activar sonido». Instrumentación de `AudioContext` antes de cargar mostró un contexto nuevo `state=running`, `sampleRate=44100` tras el gesto | **CORRECTO (UI y contexto técnico)** | Audición acústica en altavoz **NO VERIFICADA**; estado `AudioContext` no demuestra volumen audible ni silencio efectivo tras apagar |
| Sonido interiores | `GET /contact` responde 200, pero no hay `[data-cromo-sound]`, `#snd` ni botón aria de sonido | **DEFECTO** | #1514; `contacto-390px.png`. Otras rutas de interiores no se pueden probar porque devuelven 404 |
| Formulario home | Campos `name`, `email`, `message`, honeypot `website`, `captcha_token` oculto y `captcha_answer` obligatorio | **NO VERIFICADO (envío)** | No se probó anti-robot real, error español ni éxito; #1507 requiere mensaje de prueba acordado explícitamente con el dueño |
| 390px emulado | `innerWidth=390`, `scrollWidth=390`, secciones `#noches` y `#conectado` con ancho 390 | **CORRECTO (sin overflow horizontal)** | `home-390px.png`; la geometría de todas las tarjetas y banners requiere inspección visual y dispositivo real |
| Móvil físico / gama baja | Dispositivo físico, tacto, render cromado y rendimiento | **NO VERIFICADO** | No había dispositivo físico móvil accesible; Chrome headless 390px no equivale a hardware real |
| Menú interior a 390px | `/contact` solo presenta «INICIO ↙» y branding; `header nav a` = 0 | **DEFECTO** | #1515; no se visualiza menú Cromo de dos filas |
| Páginas interiores y rutas | `/events`, `/artists`, `/releases`, `/sets`, `/media`, `/journal` dieron 404; los detalles **canónicos** `/events/umbral-03` y `/events/tension-medianoche` respondieron 200, con menú y botón `[data-cromo-sound]` | **MIXTO** | El defecto probado es el enlace real «RECORDS» → `/releases` 404 (#1512). No se afirma que los demás listados fueran rutas canónicas. Captura `evento-390px.png` |
| Movimiento reducido | En viewport emulado se aplicó `prefers-reduced-motion: reduce`; `matchMedia` devolvió `true` | **PARCIAL** | No se midieron transiciones, animación WebGL ni consumo en hardware real |
| Foco por teclado | Se recorrieron 12 controles mediante Tab en viewport emulado 390px; todos reportaron `:focus-visible=true`, `outline-style:solid` y `outline-width` de 3–4px | **CORRECTO (muestra de 12 controles)** | No equivale a auditoría completa de orden de tabulación ni contraste visual en dispositivo físico |
| Conectado | Seleccionar «TENSION MEDIANOCHE» produce `aria-pressed=true`; con esa selección activa, Escape deja **cero** botones activos; click sobre nodo activo también lo deselecciona | **CORRECTO (Escape y selección)** | Ensayo Chromium 154 en emulación 390px, sin hardware físico |
| Pausa de banners | Había dos banners; activo índice 0, clic en Pausa; tras **8,3 s** continuó activo índice 0. Al reanudar, transcurridos otros **8,3 s**, cambió a índice 1 | **CORRECTO (comportamiento temporal)** | Ensayo en Chromium 154, con ciclo CSS anunciado de 7 s |
| Cookies en home e interiores | Home y `/contact` presentan controles «Rechazar», «Aceptar» y preferencias. En contexto aislado se pulsó «Rechazar»: el banner dejó de mostrarse y siguió oculto tras recargar | **CORRECTO (presencia y persistencia local)** | Se comprobó home y `/contact`; las rutas interiores 404 no admiten comprobación |
| «Últimas noches» | La sección legacy `#events` existe pero queda oculta (alto 0); la visible está en `#noches`. `#archive` no existe; visitar ambos hashes no lleva al bloque visible esperado | **DEFECTO** | #1513. La lista legacy indica «NO PUBLISHED NIGHTS YET»; no hubo noche pasada publicada comprobable para validar ese escenario |

## Defectos confirmados y tickets

- [#1512](https://github.com/pl0n3r/brvtal/issues/1512) — enlace de home «RECORDS» conduce a `/releases`, HTTP 404 en producción.
- [#1513](https://github.com/pl0n3r/brvtal/issues/1513) — los hashes `#events` / `#archive` no llevan al archivo Cromo visible.
- [#1514](https://github.com/pl0n3r/brvtal/issues/1514) — `/contact` carece de interruptor de sonido.
- [#1515](https://github.com/pl0n3r/brvtal/issues/1515) — `/contact` carece del menú interior de dos filas a 390px.

No se han registrado como defectos las observaciones inconclusas, la falta de dispositivo real ni las rutas supuestas sin enlace canónico; tampoco se ha alterado contenido del producto.

## Cierre de aceptación / autoridad pendiente

- **AC-01:** parcial. Existen resultados de UI y defectos reales; faltan audición real, envío anti-bot y sus mensajes de error/éxito, móvil físico de gama baja, verificación de movimiento reducido en hardware y noche pasada publicada. Las capturas aún están fuera del repositorio.
- **AC-02:** este archivo corresponde a la ruta reclamada.
- **AC-03:** cuatro defectos reproducidos tienen Issue enlazado.
- **AC-04:** cumplido en esta ejecución; solo GET públicos, interacción UI, sin credenciales ni envío de formulario.
- **AC-05:** el check `factory-ci / Validar` llegó a **success** para el HEAD reconciliado `085bd7a9613ce6350ee89b02972704fde726817b` ([run 41842079211](https://github.com/pl0n3r/brvtal/actions/runs/41842079211)). Para la revisión y eventual fusión se debe verificar de nuevo el `success` terminal contra el **HEAD vigente del PR**, que puede cambiar cuando se actualice este mismo informe. AC-05 no sustituye AC-01.

**Puerta humana explícita:** antes de enviar el formulario de producción, el dueño debe acordar el texto de la prueba y autorizar el envío. Sin esa autorización no se marca el criterio como probado. La prueba en un **móvil físico real** tampoco se sustituye por emulación ni se declara cumplida por falta de equipamiento.

**Continuación segura:** completar pruebas permitidas, incorporar evidencia visual mediante un claim legítimo sin invadir rutas, resolver autorización/hardware, y solo entonces marcar el PR #1517 como definitivo y satisfacer gates exactos para fusionar; no falsificar un resultado por el color de CI.


## Revalidación de coordinación y puertas de QA (2026-11-04)

- **PR existente:** [#1517](https://github.com/pl0n3r/brvtal/pull/1517), en estado **DRAFT**, reservado por la lease V3 `e3f7400e-4e13-42bc-ab3b-54299239ad3a`. Al consultar el HEAD `085bd7a9613ce6350ee89b02972704fde726817b`, la rama no estaba rezagada respecto de `main@56ec4fa6cdbeca4308d080bcb9f4e3dd2d8f69b2`, y su diff se limitaba al presente Markdown.
- **Gates técnicos del HEAD previo:** `BRVTAL CI / validate` success, `factory-ci / Validar` success, SonarCloud success, CodeQL success y Factory Policy success. CodeRabbit check **skipped** por tratarse de un PR DRAFT, sin review FINAL. Deben repetirse o acreditarse sobre el HEAD posterior a cualquier edición; no se heredan automáticamente.
- **Mensaje de prueba propuesto (SIN envío):** `Prueba técnica controlada BRVTAL, issue #1507. Verificación del envío de contacto, protección anti-robots y confirmación de éxito en producción. Mensaje de QA; no requiere respuesta comercial.` La propuesta y la solicitud de autorización están en el [comentario #6632556563](https://github.com/pl0n3r/brvtal/issues/1507#issuecomment-6632556563). No hay aprobación explícita de texto, nombre y correo de prueba. **No completar ni enviar el formulario sin esa aprobación**.
- **Equipo físico:** las comprobaciones del Mac autorizado (`system_profiler SPUSBDataType` y disponibilidad de `adb`) no aportaron ningún teléfono disponible para esta QA. No se deben reclasificar la emulación 390 px, el contexto WebAudio o el CSS de movimiento reducido como validación en hardware físico.
- **Confirmación independiente:** el [comentario de CodeRabbit en el issue](https://github.com/pl0n3r/brvtal/issues/1507#issuecomment-6632567905) señaló que la solicitud del mensaje no es una autorización y que falta móvil real, audio audible y una noche pasada publicada. Ese comentario no constituye una revisión FINAL del PR #1517.
- **Cierre condicionado:** AC-01 permanece **parcial**. Ni CI verde ni una lista de `UNKNOWN` sustituyen resultados de pruebas no ejecutadas; #1507 no se cierra hasta obtener la evidencia faltante o una decisión explícita y legítima del dueño que revise el alcance.
