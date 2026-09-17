# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README es un **snapshot operativo de solo el deploy actual**. Por decisión de producto vigente incluye también un panorama compacto de pendientes para no reconstruir prioridades en el siguiente ciclo. El contexto durable sigue en `AGENTS.md`, `docs/` y GitHub Issues.

## Qué se hizo

- Se corrige la configuración de **SonarQube Cloud Automatic Analysis** que fallaba con `Source and test paths overlap`.
- `sonar.sources` deja de apuntar a todo el repositorio (`.`) y pasa a declarar únicamente las raíces reales de código/producto, separadas de `tests`.
- `sonar.tests=tests` queda como raíz exclusiva de pruebas; no se añade scanner duplicado en GitHub Actions porque BRVTAL sigue usando Automatic Analysis.
- Se conserva fuera del análisis el bundle vendorizado `discadmin/qrcode.min.js`.
- Se añade un contrato PHP que falla si `sonar.sources` vuelve a incluir `.` o si alguna raíz source/test se solapa en el futuro.
- Se actualiza el contrato de scope ya existente para que valide la configuración Sonar corregida en vez de exigir el antiguo `sonar.sources=.` que provocaba el conflicto.

## Archivos modificados en este deploy

- `.sonarcloud.properties` — 🟡 MOD · separa de forma explícita source roots y test root para Automatic Analysis.
- `tests/ci-scope-contract.php` — 🟡 MOD · reemplaza la expectativa obsoleta del root completo por el contrato de sources explícitas y tests disjuntos.
- `tests/sonarqube-scope-contract.php` — 🟢 NEW · regresión específica que protege la separación source/test.
- `README.md` — 🟡 MOD · snapshot exacto del deploy + panorama compacto de pendientes.

## Validación

- Base exacta: `main` `46f3562219c1e7f56fd3031b931602eaf28f467c`.
- Esa base tiene **BRVTAL CI #671** verde; Production Performance del mismo SHA también terminó correctamente.
- **BRVTAL CI #672** detectó correctamente una expectativa obsoleta en `tests/ci-scope-contract.php` que todavía exigía `sonar.sources=.`; se corrigió el contrato sin debilitar la validación.
- La configuración mantiene `sonar.sources` y `sonar.tests` como conjuntos explícitos y separados y conserva Automatic Analysis sin scanner duplicado en CI.
- No hay cambios en runtime público, DISCADMIN, API, base de datos, migrations ni producción.
- Pendiente: nuevo **BRVTAL CI / validate**, CodeRabbit y la siguiente ejecución automática de SonarQube Cloud sobre el head actualizado.
- CI verde significará **VALIDATED IN CODE**; Sonar verde confirmará que el análisis externo acepta el scope. Ninguno de los dos equivale por sí solo a **VALIDATED IN PRODUCTION**.

## Panorama general de lo pendiente

### P0 — bugs funcionales públicos
1. **#419 Menú mobile / header** — MENU no abre de forma fiable en mobile; corregir apertura/cierre, foco, Escape, scroll lock, targets y composición de header conservando SOUND.
2. **#420 Resize / scroll / Events** — resize puede romper el scroll y Events secuestra el wheel vertical; hacer el runtime resize-safe y devolver scroll vertical nativo.

### P1 — quick wins globales
3. **#421 Legibilidad y sistema UI** — quitar `LIVE / CMS CONNECTED`, retirar contadores de escena fuera del Hero, aumentar microtexto funcional, reducir headings excesivos y normalizar botones/CTAs.
4. Revisar footer y Contact con la misma escala tipográfica y contraste funcional.

### P2 — dirección visual
5. **#422 Home visual** — diferenciar secciones con skins/texturas/profundidad, glitch controlado en títulos, Hero ambiental y Events con cards coherentes; sin reintroducir scroll hijacking ni motion pesado en mobile.

### P3 — Memories
6. **#415 Memories administrable** — Media Library = almacenamiento; Memories = curaduría.
7. Añadir `MEDIA → Memories` en DISCADMIN para seleccionar media existente, título, orden y publicación sin duplicar/borrar el asset original.
8. Public Memories: grid editorial/asimétrico, mobile 2 columnas con ritmo y viewer inmersivo PREV/NEXT/cierre.
9. No reutilizar ciegamente el enfoque relation-first del PR cerrado `#417`; quedó superseded por el modelo de galería curada.

### P4 — backlog posterior
10. Soundscape BRVTAL para **SOUND**, siempre opt-in.
11. Continuar simplificación de DISCADMIN sobre fricciones concretas; después Hero Slider, SEO centralizado, backups programados/off-site y demás backlog según prioridad/evidencia.
12. Smokes autenticados de producción solo cuando exista acceso autorizado; CI no sustituye validación real.

## Qué sigue

1. Dejar **BRVTAL CI / validate** y CodeRabbit verdes en este PR, squash merge y verificar el SHA exacto de `main`.
2. Confirmar que SonarQube Cloud ya no reporta el solapamiento source/test en su siguiente Automatic Analysis.
3. Continuar inmediatamente con **#419** y después **#420**, antes del batch visual #421/#422.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Visión pública: `#398`.
- Memories curado: `#415`.
- Quick wins nuevos: `#419`, `#420`, `#421`, `#422`.
- Testing/validación: `docs/TESTING.md`.
- GitHub Issues es la fuente de verdad para cada tarea individual y su estado.
