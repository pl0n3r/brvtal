# BRVTAL · Diagnóstico de HEALTH 503 de solo lectura

**Issue de reparación:** [#991](https://github.com/pl0n3r/brvtal/issues/991). **Incidente padre:** [#739](https://github.com/pl0n3r/brvtal/issues/739).

## Finalidad y límites

El observer de producción falló sobre BRVTAL v0.1.142 / `b6613be95c57172a46cb9ead5d04f47f14dbce2c` porque `/api/health.php` no respondió 200 JSON. Hay evidencia separada de `api/deployment.php` con versión/SHA exactos y de endpoints públicos que consultan DB con éxito. Esto **no acredita** la salud estricta del esquema; la migración `migration_set_duration_01.sql` continúa siendo una hipótesis, no causa confirmada.

La inspección utiliza exclusivamente el transporte ya existente `ops/factory/transport.py inspect-migrations`, que primero comprueba identidad exacta vía HTTPS, conecta por SSH con host key fijado, ejecuta `php scripts/migrations.php reconcile-plan --json` y valida un plan estructural acotado. No consulta filas de negocio, no imprime respuestas crudas ni ejecuta SQL de modificación.

## Activación segura (posterior al merge)

El workflow `.github/workflows/production-health-inspect.yml` solo acepta `workflow_dispatch` desde **main**, ejecutado por el **OWNER** del repositorio. No se activa por pushes, PR, issue comments ni cron. Solo se puede ejecutar cuando la versión de producción y el HEAD de `main` coinciden exactamente: SHA del evento, checkout, `origin/main` y endpoint `api/deployment.php`.

El job usa `contents: read`, checkout sin persistencia de credenciales, tiempo máximo de diez minutos y `DEPLOY_TOKEN` / `DEPLOY_SSH_KEY` desde GitHub Actions. El paso de transporte falla cerrado si faltan secretos, falla DNS/SSH, el certificado/host key no concuerda, la identidad difiere o el plan está incompleto. No editar secretos ni volver permisivos estos checks para conseguir una ejecución verde.

**Salida autorizada:** únicamente `GITHUB_STEP_SUMMARY` con SHA, presencia del registro, requisito de registro y conteos de `pending`, `baseline`, `apply` y `blocked`. El JSON temporal se crea con `umask 077` y se elimina al terminar. Los nombres de migraciones, pruebas estructurales, registros de clientes, logs no sanitizados y secretos no deben copiarse a los comentarios.

## Interpretación SRE

- **pending positivo, blocked cero:** existe trabajo pendiente identificado; aún no está autorizado ejecutarlo. Comprobar evidencia de respaldo y la puerta específica de reconciliación.
- **blocked positivo:** hay ambigüedad estructural. Mantener fail-closed y detener toda reconciliación hasta resolver la divergencia con datos verificables.
- **pending cero:** no demuestra por sí solo HTTP 200 ni elimina fallos PHP, DB o transporte. Investigar `HEALTH_ERROR` sanitizado y repetir observación canónica solamente tras una corrección efectiva.
- **error de inspección:** significa identidad, transporte o esquema de respuesta desconocidos; nunca reportar HEALTH GREEN ni ejecutar la reconciliación para evitar la falla.

El workflow **no realiza** backup, `reconcile-migrations`, DDL, despliegue, rollback o smoke autenticado. La reconciliación productiva pertenece a `production-migration-reconcile.yml` y exige el comando OWNER literal en #681, comprobación de plan y respaldo; no se autoriza implícitamente desde una inspección correcta. Tampoco es un sustituto de `Production Deploy Observer` ni de su evidencia exact-SHA terminal.

## Aceptación y reversión

Ejecutar `python3 -m unittest tests/test_production_health_inspect_workflow.py`, y exigir `BRVTAL CI / validate` para el SHA exacto del PR antes de la integración. Registrar la prueba, la reserva V3 y el resultado de los gates en el PR sin imprimir información privada. Reversión de este cambio: revert de los tres archivos de #991; como esta hoja no toca DB ni runtime, no se requiere rollback de datos.
