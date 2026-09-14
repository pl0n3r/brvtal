# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
[![PHP 8.5 Compatibility](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/php85-compatibility.yml)

Este README es un **snapshot operativo de solo el deploy actual**. Se reemplaza en cada deploy. El contexto durable vive en `AGENTS.md` y, cuando aplica, en `docs/`.

## Qué se hizo

- Se añade un ensayo real de recuperación de backups exclusivamente en CI, sobre MariaDB desechable y archivos temporales.
- El rehearsal genera un backup normal con el motor existente, incluyendo dump SQL y media ZIP, y después restaura el dump en una base nueva con namespace estricto `brvtal_test_recovery_*`.
- La prueba valida datos con Unicode/apóstrofes/NULL, relaciones con foreign key, una vista SQL y hashes del media recuperado.
- Después del backup se muta deliberadamente la fuente para confirmar que la recuperación representa el punto de snapshot y no el estado posterior.
- La base de recuperación y los fixtures se eliminan siempre en cleanup; el workflow no usa secretos ni URLs de producción.
- `restore_supported=false` y `RESTORE_NOT_SUPPORTED` siguen siendo obligatorios: no se añade endpoint, botón ni restore automático de producción.

## Archivos modificados en este deploy

- `.github/workflows/backup-recovery-rehearsal.yml` — workflow aislado con MariaDB 11.4, evidencia y sin acceso a producción.
- `tests/integration/backup-recovery-rehearsal.php` — crea el snapshot, lo restaura en una DB temporal, valida integridad y limpia todo al finalizar.
- `tests/backup-recovery-rehearsal-contract.php` — contrato estático que impide que el rehearsal salga del namespace de test o habilite restore de producción.
- `docs/TESTING.md` — documenta el procedimiento, evidencia y límites de seguridad del recovery rehearsal.
- `README.md` — snapshot operativo de este deploy.

## Validación

- El PR debe pasar `BRVTAL CI / validate`, `PHP 8.5 Compatibility / php85`, `Backup Recovery Rehearsal / rehearsal` y el gate de README antes del merge.
- El rehearsal usa únicamente `brvtal_test_backup_source` y una DB efímera `brvtal_test_recovery_<random>` dentro del servicio MariaDB del runner.
- La recuperación comprueba checksum del dump, estado de snapshot, foreign keys, view y media ZIP.
- Ningún SQL de producción, backup real de Hostinger ni contenido real participa en esta validación.
- Un rehearsal verde demuestra recuperabilidad del formato de backup en CI; **no habilita ni valida un restore de producción**.

## Qué sigue

1. Mantener `Backup Recovery Rehearsal` verde como evidencia continua de que el formato de backup puede reconstruirse en un entorno aislado.
2. Ejecutar manualmente `Authenticated Production Smoke` para #123–#125 cuando estén disponibles las credenciales del environment `production-smoke`.
3. Ejecutar `Controlled Production Page Write Smoke` para #122 con `WRITE_AND_DELETE_TEMP_PAGE` y cerrar únicamente los issues que queden realmente **VALIDATED IN PRODUCTION**.
4. Continuar después con simplificación/estabilización de DISCADMIN o mejoras de discovery respaldadas por relaciones reales.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de pruebas: `docs/TESTING.md`.
- CI/deploy principal: `.github/workflows/update-release-metadata.yml`.
