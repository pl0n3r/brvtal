# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- Se resuelven los prerequisitos de **#398 / Phase C — Roster + Sound** documentados en **#179** y **#180**.
- `Collective Status` deja de ser metadata libre y pasa a tener una autoridad de lifecycle compartida para `none`, `active` y `alumni`.
- Cualquier mutación que toque estado o fechas de membresía debe enviar el lifecycle completo, evitando updates parciales ambiguos.
- `none` no admite fechas de membresía; `active` exige fecha de ingreso y prohíbe fecha de salida; `alumni` exige ambas fechas y rechaza una salida anterior al ingreso.
- Los cambios válidos de Artists sincronizan `artist_collective_history` dentro de la misma transacción que la mutación y Admin Activity.
- El historial conserva periodos cerrados, soporta reingreso al colectivo y repara el periodo activo de registros legacy cuando el próximo cambio de lifecycle aporta información suficiente.
- Antes de crear un nuevo periodo `active`, se bloquea y consulta el último periodo cerrado; un reingreso desde `alumni` o `none` cuya fecha de ingreso preceda la última salida se rechaza para impedir periodos solapados.
- `created_by` usa el administrador autenticado que realiza la mutación, manteniendo trazabilidad.
- No se crea endpoint, tabla, migration ni modelo paralelo; se reutiliza `artist_collective_history` del Content Core existente.
- Se añade cobertura PHP pura para las invariantes y cobertura MariaDB real para periodos, transición active → alumni, reingresos válidos/no válidos desde alumni y none, correcciones y actor.
- El contrato compartido de validación prueba que un update parcial de membership se rechaza explícitamente.
- El fixture MariaDB del contrato es autocontenido y usa tablas temporales; además la detección de `artist_collective_history` comprueba que la tabla sea realmente consultable, funcionando con schema productivo y fixtures temporales.

## Archivos modificados en este deploy

**Diff antes de este README:** `7 archivos` · **+554** líneas · **−6** líneas.  
Leyenda: 🟢 nuevo · 🟡 modificado · `+ / −` líneas frente al `main` base de este deploy.

### LIFECYCLE / API

- `api/content-validation.php` — 🟡 MOD · **+10 / −0** · conecta la validación temporal de Artists con la autoridad canónica de Collective Status.
- `config/artist_collective_lifecycle.php` — 🟢 NEW · **+275 / −0** · define invariantes, detección segura del schema, bloqueo del último periodo cerrado y sincronización transaccional de `artist_collective_history`.
- `config/admin_activity.php` — 🟡 MOD · **+12 / −0** · sincroniza el historial de membresía dentro de la misma transacción y con el actor de Admin Activity.

### TESTS / TOOLING

- `tests/artist-collective-lifecycle-contract.php` — 🟢 NEW · **+239 / −0** · contratos de lifecycle, verificación de migration y fixture MariaDB temporal con regresiones de solapamiento para `alumni → active` y `none → active`.
- `tests/content-validation-contract.php` — 🟡 MOD · **+14 / −3** · alinea el contrato temporal existente con el lifecycle completo y cubre rechazo de updates parciales.
- `package.json` — 🟡 MOD · **+1 / −1** · incluye el contrato de Collective Status en `test:integration`.

### CONTEXTO DURABLE

- `AGENTS.md` — 🟡 MOD · **+3 / −2** · registra Collective Status como lifecycle estructurado y el historial como fuente durable para el futuro Roster público.
- `README.md` — 🟡 MOD · **AUTO** · este mismo snapshot; su conteo se excluye para evitar referencia circular.

## Validación

- Base exacta: `main` `4373b3c265048a89a03c5bc592d0e77922d69e08`.
- Esa base quedó con `BRVTAL CI / validate` verde en el run **#618**.
- PR: **#406** · issues objetivo: **#179** y **#180** · parent: **#398 Phase C**.
- Run **#619** detectó que `content-validation-contract.php` aún asumía un update parcial de `collective_joined_at`; se alineó con la nueva invariancia y se añadió regresión explícita.
- Run **#621** pasó `fast` pero el job database reveló que el nuevo contrato asumía que la suite compartía `artist_collective_history`; el fixture se hizo autocontenido con tablas temporales y conserva una verificación estática de que la migration real define esa tabla.
- Run **#624** quedó completamente verde (`fast`, database, Chromium, real-stack, WebKit-TOTP y `validate`).
- CodeRabbit detectó después un riesgo de periodos solapados durante reingreso; se corrigió bloqueando/validando el último periodo cerrado y añadiendo cobertura MariaDB para ambos caminos de reingreso.
- La suite MariaDB usa únicamente el database `brvtal_test*`; no ejecuta migration ni modifica datos de producción.
- Pendiente: BRVTAL CI y re-revisión de CodeRabbit sobre el head final con el guard de reingreso.
- CI verde significará **VALIDATED IN CODE**. No se declarará **VALIDATED IN PRODUCTION** sin comprobar el deploy real.

## Qué sigue

1. Validar el head final de #406 con BRVTAL CI y CodeRabbit.
2. Corregir cualquier finding válido adicional sobre la misma rama.
3. Squash merge solo con `BRVTAL CI / validate` verde y revisión limpia.
4. Verificar el CI del SHA exacto resultante en `main`.
5. Continuar #398 Phase C con el **Roster conectado público** y luego Sets discovery, usando únicamente relaciones estructuradas reales.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Visión pública: `#398`.
- Prerequisitos abordados: `#179`, `#180`.
- Estrategia de validación: `docs/TESTING.md`.
