# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md`, `docs/` y los issues de producto.

## Qué se hizo

- `DISCADMIN → System Status → Attention Required` ahora refleja también el backlog abierto de GitHub.
- El estado sano deja de usar el ambiguo `NO ACTIVE ISSUES` y muestra **NO ACTIVE PLATFORM SIGNALS**, limitado a salud operativa.
- `discadmin/technical.php` reutiliza la integración server-side existente con la GitHub Public API; no añade token, secreto, servicio nuevo ni request directo desde el navegador.
- La consulta usa `is:issue is:open`, excluye Pull Requests y entrega el total de Issues abiertos más los seis actualizados más recientemente.
- El payload GitHub expone `open_issues`, `recent_issues` y `backlog_state` con estados `fresh`, `stale` o `unavailable`.
- Una caché legacy sin campos de backlog nunca se interpreta como un cero conocido; si no puede refrescarse, el backlog aparece como **UNAVAILABLE**.
- `Attention Required` separa **PLATFORM SIGNALS** de **GITHUB BACKLOG**. Los Issues de desarrollo no reducen el health score ni se convierten en incidentes de producción.
- Los avisos de disponibilidad/caché GitHub se exponen mediante `repository_diagnostics`: son visibles junto al backlog, pero nunca incrementan `PLATFORM SIGNALS`.
- Repository añade **OPEN ISSUES**; el backlog muestra links canónicos a cada Issue y `VIEW ALL` hacia GitHub.
- Títulos y labels se escapan al renderizar y las URLs individuales se reconstruyen a partir del número de Issue del repositorio conocido.
- La UI conserva estados fresh/stale/unavailable, focus visible, targets táctiles y layout mobile sin overflow.
- `AGENTS.md` registra como regla durable la separación entre salud operativa y backlog de desarrollo.

## Archivos modificados en este deploy

**Diff funcional:** `7 archivos` · **+284** líneas · **−43** líneas *(sin contar README, porque este snapshot modifica su propio diff al actualizarse).*  
Leyenda: 🟡 modificado · `+ / −` líneas frente al `main` base de este deploy.

### SYSTEM STATUS / GITHUB

- `discadmin/technical.php` — 🟡 MOD · **+58 / −6** · total/lista de Issues abiertos, freshness explícita, fallback honesto y `repository_diagnostics` separado de salud operativa.
- `discadmin/system-status-v2.js` — 🟡 MOD · **+55 / −7** · separa PLATFORM SIGNALS, diagnósticos del repositorio y GITHUB BACKLOG; añade OPEN ISSUES, links canónicos y VIEW ALL.
- `discadmin/system-status-v2.css` — 🟡 MOD · **+8 / −7** · presentación diferenciada del backlog, focus/touch targets y comportamiento responsive.

### TESTS

- `tests/system-status-contract.php` — 🟡 MOD · **+20 / −1** · exige query `is:issue is:open`, payload acotado, caché compatible, ausencia de token y separación `issues` / `repository_diagnostics`.
- `tests/e2e/discadmin-system-status-v2.spec.mjs` — 🟡 MOD · **+63 / −11** · valida contador/listado GitHub, links, health independiente y mobile/touch/overflow con el CSS real del módulo.
- `tests/e2e/discadmin-system-status-degraded.spec.mjs` — 🟡 MOD · **+78 / −10** · valida fallos auxiliares y estados fresh/stale/unavailable; los diagnósticos GitHub permanecen visibles con `0 PLATFORM` y sin falso cero.

### CONTEXTO DURABLE

- `AGENTS.md` — 🟡 MOD · **+2 / −1** · registra que System Status muestra backlog GitHub read-only sin incorporarlo al health score ni falsear cero cuando la fuente no está disponible.

### SNAPSHOT

- `README.md` — 🟡 MOD · **AUTO** · este snapshot operativo del deploy.

## Validación

- Base exacta: `main` `83b6dc8e240010dc3f760daf6477000ace7a24fa`.
- Scope: issue **#409** / PR **#410**.
- No hay migration, cambio de schema, credenciales nuevas ni mutación de producción.
- Cobertura: contratos PHP 8.5, Chromium sobre estados fresh/stale/unavailable, mobile/touch/overflow y smoke autenticado real-stack.
- El backlog GitHub permanece fuera del cálculo de salud operativa y los diagnósticos de repositorio tienen canal separado.
- Estado del cambio: **IMPLEMENTED**. El merge exige `BRVTAL CI / validate` verde y revisión sin findings abiertos; la validación del SHA exacto de `main` se ejecuta después del squash.
- CI no equivale a validación en producción.

## Qué sigue

1. Squash merge de #410 únicamente con gates verdes.
2. Verificar BRVTAL CI del SHA exacto resultante en `main`.
3. Retomar #398 Phase C con **Sets / listening discovery**.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- System Status / GitHub backlog: `#409`.
- Visión pública: `#398`.
- Roster público completado en PR `#408`.
- Estrategia de validación: `docs/TESTING.md`.
