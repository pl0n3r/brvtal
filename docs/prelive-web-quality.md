# BRVTAL — aceptación pre-live de accesibilidad y rendimiento 1440/390

**Corte:** 27-oct-2026. Hoja [#1043](https://github.com/pl0n3r/brvtal/issues/1043), dependencia #1036 cerrada. **No autoriza go-live** y no sustituye la aprobación estética OWNER. Esta entrega es **sintética/offline**, no un ensayo de producción, PageSpeed/Lighthouse, axe ni una captura de navegador auténtica.

## Procedimiento repetible

1. Fijar un único SHA del código y timestamp UTC. Con el mismo contenido **sintético**, preparar muestras desktop **1440** y móvil **390**. El ensayador del test construye PNGs válidos de esos anchos, HTML, CSS y recursos ficticios sin solicitar red o información personal.
2. **Accesibilidad (WCAG 2.2 AA):** validar matemáticamente contraste sRGB mínimo 4.5:1 para texto normal (3:1 para texto grande en evaluación futura); confirmar etiquetas semánticas `main`, `nav`, `h1`, `alt` informativo, nombres accesibles de controles, CSS `:focus-visible` con outline y `prefers-reduced-motion: reduce` con `animation: none`. Registrar una traza sintética de `Tab`, `Shift+Tab`, `Enter` y `Escape`. Este contrato estático no sustituye probar el orden de foco, lector de pantalla y activación de controles con navegador real.
3. **Rendimiento:** por cada viewport exigir FCP ≤ **1800 ms**, LCP ≤ **2500 ms**, TBT ≤ **200 ms**, CLS ≤ **0.10**. Los valores provienen únicamente de fixtures sintéticos para esta hoja y no se pueden presentar como tiempos medidos de BRVTAL. Repetir con Lighthouse real, red/CPU controlados y origen autenticado cuando el owner autorice la puerta de go-live.
4. **Presupuesto de imágenes:** tamaño de cada recurso ≤ **300 KiB** y suma ≤ **750 KiB** por viewport, calculados desde bytes del fixture, con SHA-256. En un ensayo posterior medir las imágenes realmente transferidas, no el peso de la captura de pantalla.
5. **Comparativa 1440/390:** exigir PNG íntegro por viewport, firma, CRC e IHDR válido, ancho exacto, SHA-256 por archivo, HTML/CSS sintéticos y una comparación que vincule **ambos** SHA. Prohibido declarar `PASS_SYNTHETIC` si falta una captura o prueba; generar `UNKNOWN` para evidencia ausente/corrupta o `FAIL` para una medición que excede presupuesto.
6. **Aprobación visual:** la composición y similitud visual A/B 1440/390 con medios finales se presentan al dueño. La decisión seguirá `PENDING_OWNER`; ninguna prueba automatizada puede declarar `APPROVED`. Validar posteriormente contra capturas reales revisadas por el owner y cualquier desviación.
7. Ejecutar `python3 -m unittest discover -s tests -p 'test_prelive_web_quality.py' -v`. Antes de fusionar, verificar `validate` exact-HEAD en `success`, SonarCloud, CodeQL y revisión FINAL de CodeRabbit conforme al plan. No inferir éxito por runs de otro SHA.

## Contrato de evidencia fail-closed

`audit(report, root)` valida el siguiente inventario exacto: `version=1`, `source=synthetic`, `source_sha` hexadecimal de 40 caracteres, `observed_at` ISO UTC, `visual_decision=PENDING_OWNER`, `viewports` con claves `1440` y `390`, y `comparison` con las dos huellas del PNG. Cada viewport aporta `capture`, `html`, `css`, `images`, `keyboard_trace`, `normal_text_fg`, `normal_text_bg`, `metrics`. Todos los assets están restringidos a `synthetic/`, con hash, sin URL ni symlink.

| Puerta | Estado | Significado |
| --- | --- | --- |
| Evidencia completa, bajo presupuestos | `PASS_SYNTHETIC` | Solo prueba técnica offline |
| Criterio medible incumplido | `FAIL` | Corregir antes de otra evaluación |
| Evidencia inexistente o no autenticable | `UNKNOWN` | Nunca convertir en PASS |
| Aprobación estética final | `PENDING_OWNER` | Solo titular decide |
| Producción y go-live | `UNKNOWN` | No verificados por este trabajo |

La [línea base histórica](PERFORMANCE-BASELINE.md) documenta mediciones antiguas sobre producción en septiembre de 2026, pero no equivale a observación actual. La decisión visual pendiente de #965/#1036 y el contenido final están **DEL_DUEÑO**, sin cuentas, gastos, proveedores ni autorización de despliegue.

**Alcance de la aceptación técnica:** el test demuestra que los controles detectan fallos en fixtures, incluidos contraste, semántica, teclado, alt, reduced-motion, presupuesto y ausencia de capturas; no afirma que el portal actual haya aprobado una prueba completa de accesibilidad, fidelidad o rendimiento. Ningún estado obtenido en este slice es `VALIDATED_IN_PRODUCTION`.
