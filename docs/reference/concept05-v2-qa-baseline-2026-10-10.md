# Concepto 05 v2 — QA de huellas del fixture sintético (2026-10-10)

## Autoridad, fuentes y límite del resultado

- Instrucción del dueño en [BRVTAL #965, comentario 6094235414](https://github.com/pl0n3r/brvtal/issues/965#issuecomment-6094235414): durante construcción los agentes pueden refrescar las huellas tras inspección A/B; no se exige nueva aprobación visual del propietario. **No exime de fidelidad, QA, revisión independiente ni gates de merge.**
- Referencia canónica: `docs/reference/home-concept05-owner-reference-v2.png` (blob Git `f3d8434a605cee56d84d97307c0e3f5a6409b84d`, prefijo SHA-256 `62041aa6`), composición de escritorio y móvil en una sola imagen de **1312 × 1199 píxeles**.
- Evidencia reproducible, HEAD previo `1910cb4d682e8fe5e04a3e3a7652a14f3451bbdd`: [CI Chromium run 38034231994](https://github.com/pl0n3r/brvtal/actions/runs/38034231994), [artefacto visual 11663094260](https://github.com/pl0n3r/brvtal/actions/runs/38034231994/artifacts/11663094260), con `concept05-390-fullpage.png` (**390 × 6861**) y `concept05-1440-fullpage.png` (**1440 × 5978**) más la referencia original. Es incorrecto decir que el ZIP carece de las capturas; se verificaron los doce archivos del artefacto real.
- En esa ejecución: **521 PASS, 29 SKIP, dos FAIL**; ambos fallos eran exclusivamente `canonical … screenshot fingerprint stays approved`. Se conservan pruebas de orden 01–07, composición, controles, clipping, targets móviles y estados de medios rotos.

## Recalibración de la regresión sintética

Las cuatro huellas agregadas son SHA-256 sobre señales de estructura dHash 17×16 y color 12×12 de nueve regiones, **no** hashes de la lámina v2 ni fotografías del CMS. Se obtuvieron del `Received` de Chromium, no de valores inventados ni de `PENDING_CALIBRATION`.

| Ancho | Nueva huella `structure` | Nueva huella `color` |
| --- | --- | --- |
| 390 px | `0e22155ed8bedcd6a2ce5b995d95cddf5125c15d4c0a5481e4af0e58ae4a8ea9` | `b0757d4cc5ea9209533351994b8c437ce8ccbdcc7c2f1d8763e820d42dc810e1` |
| 1440 px | `0ca474d4581303cf1e8c8f7bdd826ead1eb4754d7347ecf85b8b40d07646317f` | `133a12fe27127708da420145fd60877727bdddd4ecacd895330fb9a44ed7792f` |

Se conservan las aserciones `.toEqual(expected)` y todas las reglas de geometría, medios y accesibilidad. Cambiar de nuevo la composición o paleta del fixture volverá a romper el test. **La recalibración solamente acredita el fixture de estrés actual; no implica que el home público tenga fidelidad visual aprobada.**

## Comparación A/B profesional y desviaciones visibles

| Zona | Referencia owner v2 | Captura sintética CI | Diagnóstico |
| --- | --- | --- | --- |
| Hero | Fotografía documental de alto contraste, textura roja, marca BR/VT/AL, CTA y cita | Marca, lema y CTA; la fotografía falta y quedan zonas oscuras | Fixture sin imagen editorial; **paridad de recorte, foco y contraste no verificada** |
| 01 Next Experience | Foto de evento y panel compactado con nombre, fecha, venue, precio y tickets | Nombre sintético extremadamente largo ocupa un panel muy alto, imagen vacía | Estrés deliberado de texto. **Densidad de contenido con CMS real pendiente** |
| 02/03 Nights + Artists | Filas visuales con tarjetas de eventos y retratos, densidad de poster | Paneles casi vacíos, titulares y medios sustitutos | La geometría está cubierta por E2E; **medios y recortes reales no comprobados** |
| 04/05 Sound + Memories | Cubiertas, waveform y mosaico fotográfico | Listas de texto y placeholders, gran altura de página | Mantener fallback sin roturas; **contraste y composición del CMS aún por verificar** |
| 06/07 Journal + Connected | Historias con imagen y grafo rojo/verde, footer compacto | Titulares largos y nodos de datos sintéticos | Están probadas navegación, no-overflow y fallbacks; **equivalencia visual pendiente** |
| Móvil 390 | Poster fotográfico apilado, CTA dominante y barra inferior | Columna larga con placeholders; barra/CTA permanecen | Priorizar targets ≥44px, teclado, lectura y safe area; **fotografía/cropping pendiente** |

La lámina es un collage, no una captura full-page en el mismo escenario: **no se deben comparar directamente sus tamaños de imagen como si fueran diferencia CSS cuantitativa**. Los placeholders y cadenas largas tienen propósito de QA (vacíos/errores/overflow), no son licencias para que el producto final ignore la fotografía y composición de referencia.

## Comp puertas que siguen vigentes

La siguiente revisión debe probar una renderización representativa del CMS con medios permitidos o fixtures editoriales realistas, inspeccionar 390 y 1440 contra la referencia, listar desviaciones solamente por usabilidad/accesibilidad/datos dinámicos y validar tests + CI terminal exact-HEAD. No se afirma `VALIDATED_IN_PRODUCTION`, GREEN, paridad CMS ni permiso de publicación a partir de hashes sintéticos. No merge, go-live, gasto, secretos, datos reales o despliegue en este despacho.

Reversión de este slice: revertir el único commit de huellas/documentación, sin afectar datos ni permisos.

## Inspección A/B y huellas genuinas 390/1440 (21-oct-2026)
<!-- BR973-C05-AB-35C9B3A9 -->

**Proveniencia exacta:** PR #1012 en 35c9b3a96b59bf6f60f6b4e3c0725e3329929797, BRVTAL CI #39521130498, artefacto visual #12311375066. Se descargaron e inspeccionaron las capturas contra la lámina owner-v2 del mismo artefacto antes de recalibrar. No se han inventado valores ni alterado automáticamente huellas en CI.

| Imagen | Resolución | SHA-256 del PNG |
| --- | --- | --- |
| owner-v2-reference | 1312×1199 | 62041aa6017ffa10b8085ce920de38b021dbc1c77b1d8170e66196f74b8efdf3 |
| concept05-390-fullpage | 390×6489 | 53ce6c908d56ffc35d2989117f86af4256fde0952bc0d5647e5eef626cac9846 |
| concept05-1440-fullpage | 1440×5406 | 04e0bc1ff7e9d24b074c8a13c1acd07ff802383d2cc749161f14de2557282056 |

**Huellas de estructura/color procedentes exclusivamente del Received auténtico de Chromium:**

| Ancho | structure | color |
| --- | --- | --- |
| 390 | 7f1537fcb58333eadcbd6b2517ab385f29a53de59f1ac0fb7e650472b480c387 | 3bc5bf4cb50e852472ae3daecc946cab00c844f0efefe40f62302a988db327d2 |
| 1440 | 8b6f932906121e083479f455f691ecb507efdaaa699a6d2b7e0decf96e92c4c6 | 35e207190412c9b129b55bb6a7c373ef25edd00ca7533a951598d454c0d98fda |

**Inspección A/B:** el bloque 06 ahora incorpora un panel fotográfico con título superpuesto y una lista de transmisiones más compacta a la derecha. El fixture no dispone de imagen publicada válida: aparece IMAGE SIGNAL UNAVAILABLE, no se ha fabricado fotografía CMS. El 07 reduce espacio vacío, mejora contraste ambiental rojo/verde, y mantiene solo las líneas de relaciones verificadas. El móvil vuelve a una columna, con 44px mínimos para controles; la regresión de geometría y aristas reales pasó en el HEAD exacto. Aun hay diferencias importantes en medios, densidad y composición frente a la referencia del dueño. La lámina original es un collage y no se comparan alturas full-page como si fueran una métrica de paridad.

**Estado de aceptación:** AC-03 permanece PENDING_HUMAN_AB_COMPARISON, NO aprobada. Las huellas anteriores son históricas; las nuevas protegen solamente el fixture sintético con aserciones fail-closed. No autorizan READY/squash, producción, go-live ni cambios del CMS. La reserva #973 se amplió oficialmente a este documento: V3 eb8a5cfe-c6de-5f51-a1f5-705e8e254d7c. Siguen pendientes la protección de main (#1009), la reconciliación de fase construccion/live y los demás gates.
