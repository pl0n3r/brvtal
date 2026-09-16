# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md`, `docs/` y los issues de producto.

## Qué se hizo

- Se implementa **#409** para que `DISCADMIN → System Status → Attention Required` refleje también el backlog abierto del repositorio GitHub.
- Se corrige el significado ambiguo de `NO ACTIVE ISSUES`: el estado sano ahora dice **NO ACTIVE PLATFORM SIGNALS**, limitado explícitamente a salud operativa.
- `discadmin/technical.php` reutiliza la integración server-side existente con la GitHub Public API; no se añade token, secreto, servicio nuevo ni request directo desde el navegador.
- La consulta de backlog usa `is:issue is:open`, por lo que excluye Pull Requests, y devuelve el total de Issues abiertos más una lista acotada de los seis actualizados más recientemente.
- El payload GitHub incorpora `open_issues`, `recent_issues` y `backlog_state` con estados `fresh`, `stale` o `unavailable`.
- La caché previa sin campos de backlog no puede presentarse como si conociera el número de Issues: si no puede refrescarse, el backlog se marca **UNAVAILABLE** en lugar de mostrar un falso cero.
- `Attention Required` separa dos grupos: **PLATFORM SIGNALS** y **GITHUB BACKLOG**. Un Issue abierto de producto/desarrollo no se convierte en incidente de producción ni reduce el health score.
- Repository añade el contador **OPEN ISSUES** y el backlog muestra links canónicos a cada Issue más `VIEW ALL` hacia GitHub.
- Los títulos/labels de GitHub se escapan al renderizar y las URLs individuales se reconstruyen desde el número de Issue del repositorio conocido.
- La UI mantiene tratamiento brutalista, estados fresh/stale/unavailable, focus visible, targets táctiles y layout sin overflow en mobile.
- `AGENTS.md` conserva la decisión durable de no confundir backlog de desarrollo con salud operativa.

## Archivos modificados en este deploy

**Diff funcional:** `7 archivos` · **+263** líneas · **−45** líneas *(sin contar README, porque este snapshot modifica su propio diff al actualizarse).*  
Leyenda: 🟡 modificado · `+ / −` líneas frente al `main` base de este deploy.

### SYSTEM STATUS / GITHUB

- `discadmin/technical.php` — 🟡 MOD · **+57 / −7** · extiende la caché GitHub con total/lista de Issues abiertos, freshness explícita y fallback honesto para caché legacy o API no disponible.
- `discadmin/system-status-v2.js` — 🟡 MOD · **+50 / −8** · separa señales de plataforma del backlog GitHub, añade OPEN ISSUES, links canónicos, VIEW ALL y copy no ambiguo.
- `discadmin/system-status-v2.css` — 🟡 MOD · **+8 / −7** · presentación diferenciada del backlog, focus/touch targets y comportamiento responsive.

### TESTS

- `tests/system-status-contract.php` — 🟡 MOD · **+15 / −1** · exige query `is:issue is:open`, payload acotado, caché compatible, ausencia de token y separación semántica entre plataforma/backlog.
- `tests/e2e/discadmin-system-status-v2.spec.mjs` — 🟡 MOD · **+63 / −11** · valida contador/listado GitHub, links, health independiente y mobile/touch/overflow usando el CSS real del módulo.
- `tests/e2e/discadmin-system-status-degraded.spec.mjs` — 🟡 MOD · **+68 / −10** · valida fallos de fuentes auxiliares y los tres estados del backlog: fresh, stale/cached y unavailable sin falso cero ni impacto en el health score.

### CONTEXTO DURABLE

- `AGENTS.md` — 🟡 MOD · **+2 / −1** · registra que System Status muestra backlog GitHub de forma read-only, sin incorporarlo al health score y sin falsear cero cuando la fuente no está disponible.

### SNAPSHOT

- `README.md` — 🟡 MOD · **AUTO** · este mismo snapshot; su conteo se excluye para evitar referencia circular.

## Validación

- Base exacta: `main` `83b6dc8e240010dc3f760daf6477000ace7a24fa`.
- Esa base quedó completamente verde en **BRVTAL CI #636** después del squash merge de #408.
- PR: **#410** · issue: **#409**.
- No hay migration, cambio de schema, credenciales nuevas ni mutación de producción.
- El backlog GitHub es metadata pública read-only y permanece fuera del cálculo de salud operativa.
- BRVTAL CI **#639** quedó completamente verde sobre el head previo a añadir la regresión explícita de estado `stale`; al cambiar el head, ese run queda como evidencia intermedia y no como gate final de merge.
- El head actual añade cobertura ejecutable de `stale/cached`; requiere un nuevo BRVTAL CI + re-revisión CodeRabbit antes del squash.
- CI verde significará **VALIDATED IN CODE**. No se declarará **VALIDATED IN PRODUCTION** sin comprobar el deploy real.

## Qué sigue

1. Validar el head final de #410 con BRVTAL CI + CodeRabbit.
2. Squash merge solo con `validate` verde y revisión limpia.
3. Verificar BRVTAL CI del SHA exacto resultante en `main`.
4. Confirmar el deploy por separado antes de cualquier estado **VALIDATED IN PRODUCTION**.
5. Retomar #398 Phase C con **Sets / listening discovery**.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- System Status / GitHub backlog: `#409`.
- Visión pública: `#398`.
- Roster público completado en PR `#408`.
- Estrategia de validación: `docs/TESTING.md`.
