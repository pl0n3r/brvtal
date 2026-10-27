# BRVTAL · ensayo de respaldo y restauración aislada (#1039)

**Estatus: evidencia offline/sintética, PRODUCCIÓN NO VALIDADA.** Esta hoja complementa el contrato ya existente de tests/integration/backup-recovery-rehearsal.php y tests/backup-recovery-rehearsal-contract.php; no habilita el endpoint de restauración ni afirma que se haya respaldado Hostinger, MariaDB real, medios reales o infraestructura.

## Alcance y preflight

- Entorno permitido: exclusivamente conexiones sqlite3 en memoria de Python y medio inventado FAKE-MEDIA-NONPRODUCTION; no conexión de red, filesystem de usuarios, servicios, credenciales, datos personales, chats ni hosts externos.
- Inventario sintético: database.sql procedente de dos filas ficticias de event_fixture y media/fake-banner.txt con texto ficticio. No se exporta ninguna BD del producto.
- restore_supported debe mantenerse **false** en el sistema productivo (config/backups.php y discadmin/backups.php). La restauración de este ensayo ocurre solo en memoria.
- Preflight falla cerrado ante opciones adicionales, hostinger=true, network=true, production=true, credentials no nulas, origen desconocido y cualquier restore_supported=true.

## Ejecución reproducible y evidencia

Comando exacto: **python3 -m unittest -v tests.test_prelive_restore_rehearsal**

1. Crear una base SQLite en memoria con dos filas ficticias; calcular SHA-256 del dump del origen.
2. Ejecutar sqlite3.Connection.backup hacia una segunda base en memoria (snapshot), generar el manifiesto schema: 1, synthetic_only: true, components con bytes y sha256 de los dos artefactos ficticios.
3. Verificar SHA-256 antes de restaurar; escribir una tercera fila **después** del backup como prueba negativa de temporalidad.
4. Restaurar el snapshot a una tercera conexión en memoria, recalcular hash y comparar esquema y filas; rechazar la tercera fila posterior al backup.
5. Verificar una cadena de auditoría terminal de tres etapas (backup, restore, rollback_verify) con PASS y el SHA-256 compartido; cerrar las tres conexiones en finally, sin borrar archivos.
6. Rechazar manifiestos incompletos, alterados, campos futuros, rutas no permitidas, contenido sensible e intentos de producción, todos con resultado FAIL (excepción segura).

### Objetivos de ejercicio, NO SLA de producción

| Métrica | Valor de laboratorio | Evidencia / límite |
| --- | --- | --- |
| RPO | 900 segundos (objetivo ilustrativo) | Desde captura de fixture hasta inicio de restauración; **no** calcula RPO real de un incidente |
| RTO | 3600 segundos (objetivo ilustrativo) | Tiempo de restauración de una conexión en memoria; **no** modela latencia de red, MariaDB ni medios reales |
| Integridad | SHA-256 de dump y manifiesto | backup_sha256 == restored_sha256; alterar un componente genera INTEGRITY_MISMATCH |
| Reversión | Nueva conexión aislada | Sin escrituras ni destrucción de datos reales, sin rutas de producción |
| Auditoría | backup, restore, rollback_verify | Cada etapa debe tener PASS; un FAIL nunca autoriza live |

**Evidencia terminal**: los cuatro PreliveRestoreRehearsalTests deben pasar en el SHA exacto del PR, incluida la restauración aislada y pruebas negativas. Los logs no deben contener credenciales ni datos de usuarios. Registrar run/commit/resultado en PR. Esta evidencia demuestra el laboratorio únicamente; no etiquetar restauración real como LISTO ni certificar backup final de corte.

## Checklist del futuro procedimiento real, separado y sin ejecutar

- [ ] Operación autorizada define la ventana y los RPO/RTO reales junto al alcance de medios y BD.
- [ ] Identificar repositorio protegido, cifrado, acceso mínimo, rotación y retención bajo política aprobada, sin publicar claves.
- [ ] Comprobar copia real completa con hash, tamaño, inventario de uploads/esquema y ubicación fuera del host, bajo registro protegido.
- [ ] Restaurar **solo** en un entorno real aislado y autorizado, nunca sobre la instancia live; verificar integridad, esquema, permisos de medios y smoke antes de confiar en el punto de recuperación.
- [ ] Tener reversión comprobada y registro operativo de PASS/FAIL, operador autorizado y evidencia con identificadores saneados; no borrar copia anterior.
- [ ] Obtener aprobación explícita de go-live tras puertas de seguridad, legal, backups y release; el PR #1039 no hace cambios de despliegue.

**PRODUCCIÓN NO VALIDADA**. restore_supported=false permanece inalterado; la prueba offline no justifica tocar Hostinger ni cargar datos reales. La simulación complementa, no sustituye, el ensayo MariaDB aislado ya implementado por BRVTAL CI.
