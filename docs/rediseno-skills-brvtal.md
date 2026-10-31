# BRVTAL — plan verificable de rediseño UX/UI (#1359)

> Documento de seguimiento de la épica, no autorización de despliegue ni sustituto del criterio visual del dueño. La fuente viva es [la épica #1359](https://github.com/pl0n3r/brvtal/issues/1359) y sus hojas enlazadas.

## Dirección y límites

Mantener **BRVTAL Concepto 05** y su composición editorial, preservando CMS, textos existentes, privacidad, derechos de imagen, accesibilidad y funcionamiento de DISCADMIN. Cubrir sitio público y administración; diferenciar la **implementación verificable** de la **aprobación estética**, que corresponde al dueño. No usar credenciales, datos reales, accesos a Hostinger ni publicación para acreditar este documento.

La selección de imágenes y la aprobación visual se registran por separado en [#1357](https://github.com/pl0n3r/brvtal/issues/1357) y [#1358](https://github.com/pl0n3r/brvtal/issues/1358). En #1358 deben contrastarse capturas de 1440 px y 390 px; nunca deducir un «aprobado» de CI en verde.

## Hojas de implementación

| Hoja | Área | Evidencia requerida |
| --- | --- | --- |
| [#1360](https://github.com/pl0n3r/brvtal/issues/1360) | Tokens, tipografía, color, espaciado y estados públicos | CSS + `tests/test_rediseno_base.py` |
| [#1361](https://github.com/pl0n3r/brvtal/issues/1361) | Home, hero, secciones, slider | CSS + `tests/test_rediseno_home.py` |
| [#1362](https://github.com/pl0n3r/brvtal/issues/1362) | Noches, artistas, journal, sets, transmisiones, memorias, contacto | CSS + `tests/test_rediseno_public_pages.py` |
| [#1363](https://github.com/pl0n3r/brvtal/issues/1363) | Arquitectura, navegación y sistema de diseño DISCADMIN | CSS + `tests/test_rediseno_admin_shell.py` |
| [#1364](https://github.com/pl0n3r/brvtal/issues/1364) | Tablas, formularios y estados DISCADMIN | CSS + `tests/test_rediseno_admin_data.py` |
| [#1365](https://github.com/pl0n3r/brvtal/issues/1365) | Theme Studio y módulos de contenido DISCADMIN | CSS + `tests/test_rediseno_admin_modules.py` |
| [#1366](https://github.com/pl0n3r/brvtal/issues/1366) | Movimiento GSAP y reducción de movimiento | JS + `tests/test_rediseno_gsap.py` |

Cada hoja tiene su **propia reserva V3, alcance y CI exact-HEAD**. La épica solo verifica su estado; no reescribe ni reclama los archivos de las hojas. Para cerrar AC-01, comprobar que **las siete hojas están cerradas por PR fusionado**, no por cierre administrativo, y que no hay hallazgos graves pendientes. Para AC-02, exigir `CI Factory v1 / Validar` terminal verde del PR final de la épica.

## Checklist transversal (Impeccable, Interface Feel, GSAP)

- Jerarquía y legibilidad: texto funcional ≥12 px; contraste WCAG AA (4.5:1 para texto normal, 3:1 para UI); espaciados consistentes; titulares balanceados, párrafos legibles y cifras tabulares donde corresponda.
- Interacción: dianas táctiles ≥44 px, foco `:focus-visible` inequívoco, estados activo/vacío/carga/error distinguibles y `transition` con propiedades explícitas (no `transition:all`).
- Reducción de movimiento: respetar `prefers-reduced-motion`; no ocultar contenido esencial detrás de animaciones. En GSAP usar `matchMedia`, limpieza y reversibilidad; no introducir fugas de ScrollTrigger.
- Diseño e identidad: conservar Concepto 05, Theme Studio y el CMS; no cambiar copy legal ni contenido existente por conveniencia visual.
- Evidencia: pruebas estáticas de cada hoja, revisión con las skills de Claude Code donde estén disponibles y capturas visuales a 1440/390 px para la revisión final del dueño.

**Alcance de las skills:** la validación final mediante Impeccable y Make Interfaces Feel Better corresponde a Claude Code; los agentes que no disponen de ellas aplican el checklist expresamente copiado en cada Issue, sin atribuirse una ejecución de esas skills.

## Puertas y trazabilidad

1. Confirmar Factory #767 en `RUNNING`, inexistencia de freeze activo y fase `construccion`; nunca inferir producción segura.
2. Verificar que cada hoja tenga PR efectivamente fusionado y reserva/claims válidos, sin colisiones.
3. Evaluar el PR de esta épica únicamente tras satisfacer AC-01 y obtener `Validar`, SonarCloud, CodeQL y evidencia CodeRabbit/restricción D-070 válida sobre el HEAD exacto conforme a PLAN-AGENTES.
4. Mantener pendiente la aprobación estética en [#1358](https://github.com/pl0n3r/brvtal/issues/1358) hasta constancia inequívoca del dueño; este documento no la concede.
5. Cualquier hallazgo grave de seguridad o dependencia abierta conserva su propio veto; no cambiar estados de Issues para forzar el gate.

## Estado de referencia al crear este documento

Al 31-oct-2026, #1363, #1364 y #1366 constaban cerradas; #1360, #1361, #1362 y #1365 seguían abiertas. **Este corte es informativo, no un caché autoritativo**: para fusionar la épica hay que releer sus estados y merges en GitHub. La dependencia mecánica #1349 figura cerrada. No se certifican capturas ni aprobación estética en este archivo.
