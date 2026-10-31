# BRVTAL #1349 — QA A/B de accesibilidad CSS y huellas Concepto 05

## Alcance, autoridad y límites
- Hoja [#1349](https://github.com/pl0n3r/brvtal/issues/1349), PR [#1371](https://github.com/pl0n3r/brvtal/pull/1371), fase `construccion`. Los 43 CSS reclamados aumentan tamaños explícitos inferiores a 11 px (tanto `font-size` como `font:`) y restauran foco visible. La prueba `tests/test_css_legibility.py::CssLegibilityTests::test_minimum_declared_font_size` corre en `fast`.
- `docs/reference/concept05-v2-qa-baseline-2026-10-10.md` reproduce la decisión del dueño en #965 (comentario #6094235414): durante construcción los agentes pueden recalibrar las huellas sintéticas después de inspección A/B. **Esto no es aprobación de fidelidad visual del CMS real ni de publicación.**
- Inspección manual de artefactos auténticos descargados, sin reemplazar capturas ni suavizar las aserciones de la prueba. La referencia es un collage editorial; el fixture de regresión es un escenario de estrés con texto extremadamente largo y medios ausentes: no es válido comparar alturas absolutas de ambas imágenes como si fueran el mismo CMS.

## Origen exacto y archivos inspeccionados
| Fuente | SHA / Run / Artefacto | Dimensiones | SHA-256 PNG |
| --- | --- | --- | --- |
| Referencia original owner v2 | `docs/reference/home-concept05-owner-reference-v2.png`, incluida en ambos ZIP | 1312 × 1199 | `62041aa6017ffa10b8085ce920de38b021dbc1c77b1d8170e66196f74b8efdf3` |
| Main, captura pública móvil | `main@5a92560dfdf1d7e280e45059b10527520b780544`, BRVTAL CI #41141399793, artefacto #13018649764 | 390 × 6429 | `92591de7150c5e29d93c7727da7e68e839b73be14f447f559b2f4574990ee9b2` |
| Main, captura pública desktop | mismo run/artefacto; escenario diferente al fixture de estrés | 1440 × 4772 | `74ab91399b51082760d629f672da173c90b89dfc12a6ac8c89da8b437d263665` |
| Pre-reparación móvil | `3fbe2400bc0dd58abf9150f40972f9b0330b2cd3`, BRVTAL CI #41144592617, artefacto #13019334309 | 390 × 7059 | `c8dc1ccd1bc8085989932ea29644dbcad41cbb4286eb1387f1b17fe1796440b7` |
| Pre-reparación desktop | mismo run/artefacto | 1440 × 5411 | `aa6e0528d6b183441cdd9af5c10125642f22f2a1dd881c0a00c39d8866f082e8` |
| Reparación móvil, fixture sintético | `1e2d40483c886900c6e0a3b361693d92152fd1d8`, BRVTAL CI [#41146150013](https://github.com/pl0n3r/brvtal/actions/runs/41146150013), [artefacto #13021741086](https://github.com/pl0n3r/brvtal/actions/runs/41146150013/artifacts/13021741086) | 390 × 7006 | `dd549b441585ce196f47eefab7683e71710620c2edc9d18e9ee51532b411fee3` |
| Reparación desktop, fixture sintético | mismo run/artefacto | 1440 × 5411 | `31c63e1574fefd89021cb1b39c6809c2ea936df8ca22e9aeb0fb8bd47f642a89` |

## Contraste A/B por área, fuente auténtica
| Área | Inspección de imágenes y tests | Resultado / limitación |
| --- | --- | --- |
| Hero 1440 | La ampliación de etiquetas llevaba `statementBottom=595.03` fuera del límite `<582`. La reparación compactó el padding vertical, mantuvo el panel y su CTA. | El test `Concept 05 desktop Hero keeps authored 1440 composition inside the safe viewport` ahora pasa; no se elimina la aserción. |
| Sound 390 | El título largo y el tamaño aumentado llevaban `feature.height=199.75` frente al límite `<180`. El CSS final mantiene los tracks 28/72/52 y cover 72, reduce gaps/padding y usa un título aún legible de 16px. | El E2E móvil y el contrato MariaDB de geometría canónica ahora pasan, sin ocultar contenido ni quitar targets de 44px. |
| Huellas sintéticas 390 y 1440 | La referencia editorial incorpora fotos; las capturas de estrés siguen mostrando placeholders oscuros, textos largos, secuencia de módulos 01–07, señales rojo/verde y navegación. | El cambio real de tipografía/composición altera ambos hashes, por lo que se recalibran manualmente desde `Received` del run exacto; **no** se afirma paridad con owner v2/CMS ni producción. |
| Regresión general | BRVTAL CI #41146150013: `614 passed`, `2 failed` exclusivamente en ambas huellas antes de recalibrar. `fast`, `database`, `real-stack`, `webkit-totp`, SonarCloud, CodeQL y coordinación completaron `success` sobre ese HEAD. | Se conservan `expect(...).toEqual(expected)`, las geometrías, los controles y las condiciones de no-overflow. |

## Huellas recalibradas exclusivamente desde la salida Chromium #41146150013
| Ancho | structure | color |
| --- | --- | --- |
| 390 | `f7465291e4a17c5913c762230d283712574532763cb26eb4543fb322b4ad7aff` | `b48d809c69d2f76fb499f2237381d5aa31ae16297dee3d03773cd0f78b0e8ef9` |
| 1440 | `a53bee6e0d4ceb6078fcfdb7c2feb4af9b0833235b693b4bdcec318d9059bb24` | `df124088ead685f443378051c5b9b4145f7d95272e1991e8f2e2733e97f23bd2` |

**QA cruzado:** frontend verificó los componentes sin rediseño ni JS; QA releyó las pruebas previas y rechazó sustituir el contrato de grid por una geometría no aprobada. El diff de CSS presenta foco visible; las declaraciones explícitas de fuentes menores a 11px están cubiertas por test. La siguiente corrida sobre el HEAD con estas huellas debe producir `validate=success`; cualquier mismatch adicional vuelve a bloquear, no se ignora.

## Exclusiones
No se aprueba go-live, autenticación de DISCADMIN, paridad exacta de CMS/medios, backup/restore ni D-043 `VALIDATED_IN_PRODUCTION`. No se cambian datos reales, licencias, proveedores, secretos, publicación manual o las capturas originales. Revertir el squash restaura los estilos/huellas anteriores.