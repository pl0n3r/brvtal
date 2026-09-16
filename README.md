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
- `created_by` usa el administrador autenticado que realiza la mutación, manteniendo trazabilidad.
- No se crea endpoint, tabla, migration ni modelo paralelo; se reutiliza `artist_collective_history` del Content Core existente.
- Se añade cobertura PHP pura para las invariantes y cobertura MariaDB real para periodos, transición active → alumni, reingreso alumni → active, correcciones y actor.

## Archivos modificados en este deploy

**Diff antes de este README:** `6 archivos` · **+455** líneas · **−3** líneas.  
Leyenda: 🟢 nuevo · 🟡 modificado · `+ / −` líneas frente al `main` base de este deploy.

### LIFECYCLE / API

- `api/content-validation.php` — 🟡 MOD · **+10 / −0** · conecta la validación temporal de Artists con la autoridad canónica de Collective Status.
- `config/artist_collective_lifecycle.php` — 🟢 NEW · **+250 / −0** · define invariantes y sincronización transaccional de periodos en `artist_collective_history`.
- `config/admin_activity.php` — 🟡 MOD · **+12 / −0** · sincroniza el historial de membresía dentro de la misma transacción y con el actor de Admin Activity.

### TESTS / TOOLING

- `tests/artist-collective-lifecycle-contract.php` — 🟢 NEW · **+179 / −0** · contratos de lifecycle y pruebas MariaDB del historial real.
- `package.json` — 🟡 MOD · **+1 / −1** · incluye el contrato de Collective Status en `test:integration`.

### CONTEXTO DURABLE

- `AGENTS.md` — 🟡 MOD · **+3 / −2** · registra Collective Status como lifecycle estructurado y el historial como fuente durable para el futuro Roster público.
- `README.md` — 🟡 MOD · **AUTO** · este mismo snapshot; su conteo se excluye para evitar referencia circular.

## Validación

- Base exacta: `main` `4373b3c265048a89a03c5bc592d0e77922d69e08`.
- Esa base quedó con `BRVTAL CI / validate` verde en el run **#618**.
- Issues objetivo: **#179** y **#180** · parent: **#398 Phase C**.
- La nueva suite MariaDB usa el esquema real existente y no requiere migration de producción.
- Pendiente: abrir PR, ejecutar BRVTAL CI y revisar CodeRabbit sobre el head final.
- CI verde significará **VALIDATED IN CODE**. No se declarará **VALIDATED IN PRODUCTION** sin comprobar el deploy real.

## Qué sigue

1. Abrir PR de `fix/artist-collective-lifecycle` contra `main` cerrando #179 y #180.
2. Corregir cualquier finding válido de CI/CodeRabbit sobre la misma rama.
3. Squash merge solo con `BRVTAL CI / validate` verde y revisión limpia.
4. Verificar el CI del SHA exacto resultante en `main`.
5. Continuar #398 Phase C con el **Roster conectado público** y luego Sets discovery, usando únicamente relaciones estructuradas reales.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Visión pública: `#398`.
- Prerequisitos abordados: `#179`, `#180`.
- Estrategia de validación: `docs/TESTING.md`.
