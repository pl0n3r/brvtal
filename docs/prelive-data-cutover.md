# BRVTAL pre-live: simulación de migración y reversión (#1041)

**Estado:** runbook y regresión local reproducible. **No es una autorización de go-live, un respaldo productivo ni una restauración MariaDB validada.** La fuente de brechas es el checklist #1036, integrado mediante PR #1046. Este ensayo solo usa datos sintéticos en memoria y no llama servicios externos.

## AC-01. Inventario, compatibilidad y decisión

| Fuente / contrato existente | Utilidad para el corte | Compatibilidad y límite |
| --- | --- | --- |
| docs/lista-salida-produccion.md (#1036) | Inventario de brechas de migración, rollback, backup y smoke | Es auditoría, no observación en producción |
| docs/MIGRATIONS.md y scripts/migrations.php | Registro schema_migrations, checksum, status y aplicación de una migración por vez | La CLI real usa PHP + PDO + MariaDB; este ensayo NO la invoca |
| config/migrations.php y tests/migrations-contract.php | Reglas aditivas, verificación de drift y registros inmutables | MariaDB DDL puede hacer autocommit; SQLite no demuestra sus semánticas de producción |
| config/backups.php y tests/backups-contract.php | Contrato de inventario/checksum de respaldos privados | El backup actual declara restore_supported=false; no inferir recuperación real |
| discadmin/backups.php | Backup autenticado, descargas protegidas y sin restauración automática | No hay llamadas HTTP ni autenticación real en el ensayo |
| tests/production-smoke-contract.php | Referencia para checks de rutas/servicios | El smoke de este ensayo es solo integridad de fixtures sintéticos |

**Decisión:** ejecutar exclusivamente la prueba Python con SQLite en memoria y fixtures SIM-*. Nunca importar un volcado, conectarse a MariaDB/Hostinger ni ejecutar scripts/migrations.php; su compatibilidad real permanece UNKNOWN hasta un procedimiento independiente, aprobado y con backup verificado. La hoja #1039 es dueña del ensayo de restauración aislada; #1044, de evidencias live/smoke.

## AC-02. Orden obligatorio del ensayo

| Paso | Precondición | Evidencia terminal PASS | Abort / FAIL |
| --- | --- | --- | --- |
| 1. inventory | Destino sqlite-memory y dataset synthetic-only, sin secretos, red o producción | Fixture event_fixture con solo eventos SIM y fingerprint SHA-256 de esquema + filas | Destino/DSN inesperado o fuente sin identificar |
| 2. backup_preflight | backup_ready=true, sin credenciales y sin fuentes externas | Copia SQLite en memoria y fingerprint idéntico; marcador BACKUP_VERIFIED_SYNTHETIC | Falta de copia, hash distinto o evidencia parcial |
| 3. migration | Snapshot de respaldo idéntico | ALTER TABLE aditivo permitido agrega release_tag sobre tabla sintética; marcador MIGRATION_APPLIED_SYNTHETIC | SQL fuera de la allowlist, error de esquema, drift |
| 4. smoke | Migración aislada aplicada | Fila sintética legible, nueva columna presente, recuento estable; marcador SMOKE_PASSED_SYNTHETIC | Fila ausente, integridad inválida o discrepancia |
| 5. rollback | Backup sintético verificado y smoke completado | Restauración desde snapshot a **otra conexión en memoria**, sin DROP sobre BD externa; marcador ROLLBACK_RESTORED_SYNTHETIC | Snapshot ausente o error de restauración |
| 6. verify | Restore aislado terminado | Fingerprint idéntico al inicial, release_tag ausente, datos originales conservados; estado terminal PASS | Cualquier checksum distinto, tabla/columna inesperada: FAIL |

**Ejecución reproducible:** python3 -m unittest tests.test_prelive_data_cutover -v (sin servicios, variables de entorno, red ni ficheros temporales de datos). El resultado devuelve únicamente nombres de fases, checksums de fixtures sin PII y estados PASS; nunca conecta a producción. Un fallo de cualquier paso no se convierte en PASS ni se continúa a un cambio real.

## AC-03. Criterios de abstención obligatoria

**STOP / NO-GO** si el destino no es SQLite en memoria, el dataset no es synthetic-only, hay flags de producción/despliegue/red, se suministran credenciales o claves inesperadas (host, DSN, tokens, password), falta evidencia de backup o se solicita SQL diferente del literal aditivo permitido. Rechazar DROP, TRUNCATE, DELETE, ALTER destructivo y restauraciones que puedan sobrescribir datos recientes. La prueba valida rechazos antes de abrir cualquier conexión.

**Frontera de autoridad:** pasar este ensayo NO demuestra RPO/RTO real, backups restaurables, compatibilidad MariaDB, despliegue exact-SHA, observación HEALTH, integridad productiva ni permiso para migrar/publicar. Para un corte posterior se requerirían backup real verificable y privado, inventario/ownership de tablas y dependencias, ventana humana aprobada, observabilidad terminal, rollback preservador de cambios posteriores, validación #1039/#1044 y gates D-043/D-059. Ante UNKNOWN, no ejecutar.

## Reversión del cambio documental

El PR solo agrega este documento y tests/test_prelive_data_cutover.py. Revertir el squash retira el runbook/test sin tocar aplicación, servidor, DNS o base de datos. No escribe datos personales ni altera datos.yml.

## Checklist de roles

- Software engineering: cambio acotado a dos rutas, reversible, sin integración ni secretos; casos inválidos rechazados.
- QA: fixtures deterministas, regresiones del orden de etapas, hash de backup/restauración y negativos de seguridad.
