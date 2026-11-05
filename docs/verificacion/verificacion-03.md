# BRVTAL — verificación de cobertura, lote 03/09 · #1595

**Corte:** `brvtal/main@e723928dc42d829970b19084b970d957d46b2c9e`. Épica #533. **50 afirmaciones** originales `NO_VERIFICABLE` (0 `SIN_COBERTURA`).

## Alcance y límites

Esta hoja contrasta regresiones existentes con datos sintéticos y **no acredita producción** ni evidencia viva de GitHub, SonarCloud, CodeRabbit, Hostinger o WebKit. No promueve ninguna fila a CUBIERTO; esa decisión corresponde a la consolidación de `docs/matriz-cobertura.md` tras evidencia independiente.

**Clases:** 44 `REGRESION_LOCAL`, 1 `HISTORICO_NO_REVALIDADO`, 5 `OBSERVADOR_NO_ACREDITADO`. Indican alcance del testigo, no certeza operativa.

## Trazabilidad exacta del Issue #1595

| Fila exacta | Testigo ejecutable existente | Clasificación | Predicado acotado del Issue |
| --- | --- | --- | --- |
| `S21-R004` | `tests/related-content-contract.php` | REGRESION_LOCAL | Set → Artist / Event |
| `S21-R005` | `tests/related-content-contract.php` | REGRESION_LOCAL | Release → Artists |
| `S21-R007` | `tests/related-content-contract.php` | REGRESION_LOCAL | Private/draft names, IDs or relationships must never leak through a published related entity. |
| `S26-R001` | `tests/totp-enrollment-contract.php` | REGRESION_LOCAL | Security / 2FA is an internal DISCADMIN module sharing the same shell, sidebar, session and workspac |
| `S26-R003` | `tests/totp-enrollment-contract.php` | REGRESION_LOCAL | Current persistence foundation includes: |
| `S26-R004` | `tests/totp-enrollment-contract.php` | REGRESION_LOCAL | admins.totp_enabled; |
| `S26-R005` | `tests/totp-enrollment-contract.php` | REGRESION_LOCAL | admins.totp_secret_enc; |
| `S26-R006` | `tests/totp-enrollment-contract.php` | REGRESION_LOCAL | admins.totp_confirmed_at; |
| `S26-R007` | `tests/totp-enrollment-contract.php` | REGRESION_LOCAL | admin_recovery_codes; |
| `S26-R008` | `tests/totp-enrollment-contract.php` | REGRESION_LOCAL | a persistent private encryption key stored in the existing settings table when a dedicated server ke |
| `S26-R009` | `tests/totp-enrollment-contract.php` | REGRESION_LOCAL | The TOTP encryption setting is private: it must not be exposed through public/admin settings listing |
| `S26-R010` | `tests/totp-enrollment-contract.php` | REGRESION_LOCAL | Security principles: |
| `S26-R011` | `tests/totp-enrollment-contract.php` | REGRESION_LOCAL | encrypted TOTP secret; |
| `S26-R012` | `tests/totp-enrollment-contract.php` | REGRESION_LOCAL | recovery codes hashed; |
| `S26-R013` | `tests/totp-enrollment-contract.php` | REGRESION_LOCAL | rate limiting; |
| `S26-R014` | `tests/totp-enrollment-contract.php` | REGRESION_LOCAL | no plaintext recovery-code storage; |
| `S26-R015` | `tests/totp-enrollment-contract.php` | REGRESION_LOCAL | no automatic 2FA disabling when login verification fails. |
| `S26-R016` | `tests/totp-enrollment-contract.php` | REGRESION_LOCAL | AES-256-GCM has been used for the encrypted secret. |
| `S26-R017` | `tests/e2e/discadmin-totp-login.spec.mjs` | HISTORICO_NO_REVALIDADO | CI includes a dedicated browser flow for password → TOTP challenge → verified session → DISCADMIN bo |
| `S27-R001` | `tests/test_admin_staff_api.py` | REGRESION_LOCAL | Multiple administrators are allowed. |
| `S27-R002` | `tests/test_admin_staff_api.py` | REGRESION_LOCAL | For now they may share the same permissions; do not add complex RBAC prematurely. |
| `S27-R004` | `tests/admin-activity-contract.php` | REGRESION_LOCAL | Important editorial/admin mutations record actor, action, resource, changed fields and safe before/a |
| `S27-R005` | `tests/admin-activity-contract.php` | REGRESION_LOCAL | Editorial Version History v1 groups those snapshots by content item in a chronological timeline and  |
| `S27-R006` | `tests/admin-activity-contract.php` | REGRESION_LOCAL | The Admin Activity dashboard remains read-only and never exposes direct restore/revert actions. |
| `S27-R007` | `tests/test_event_version_restore_e2e.py` | REGRESION_LOCAL | Event Version Restore V1 is review-before-save only from the canonical open editor for the same pers |
| `S27-R008` | `tests/test_event_version_restore_e2e.py` | REGRESION_LOCAL | The normal Event Save remains the sole persistence boundary and therefore produces a new Admin Activ |
| `S27-R009` | `tests/test_event_version_restore_plan.py` | REGRESION_LOCAL | Cancel or close before Save discards the staged restore. |
| `S27-R010` | `tests/test_event_version_restore_discadmin.py` | REGRESION_LOCAL | One-click restore, lifecycle rollback, relation restore and restore from NEW EVENT remain out of sco |
| `S28-R001` | `tests/dashboard-v2-contract.php` | REGRESION_LOCAL | Dashboard must be operationally useful, not decorative. |
| `S28-R003` | `tests/dashboard-v2-contract.php` | REGRESION_LOCAL | It centralizes: |
| `S28-R004` | `tests/dashboard-v2-contract.php` | REGRESION_LOCAL | platform health score; |
| `S28-R005` | `tests/dashboard-v2-contract.php` | REGRESION_LOCAL | API/database/runtime/service indicators; |
| `S28-R006` | `tests/dashboard-v2-contract.php` | REGRESION_LOCAL | BRVTAL-managed storage utilization; |
| `S28-R007` | `tests/dashboard-v2-contract.php` | REGRESION_LOCAL | deployment SHA/environment/runtime; |
| `S28-R008` | `tests/dashboard-v2-contract.php` | REGRESION_LOCAL | database content counts; |
| `S28-R009` | `tests/dashboard-v2-contract.php` | REGRESION_LOCAL | GitHub repository metrics; |
| `S28-R010` | `tests/dashboard-v2-contract.php` | REGRESION_LOCAL | Content Health summary; |
| `S28-R011` | `tests/dashboard-v2-contract.php` | REGRESION_LOCAL | recent admin activity; |
| `S28-R012` | `tests/dashboard-v2-contract.php` | REGRESION_LOCAL | actionable issues; |
| `S28-R013` | `tests/dashboard-v2-contract.php` | REGRESION_LOCAL | read-only Advanced Diagnostics. |
| `S28-R014` | `tests/dashboard-v2-contract.php` | REGRESION_LOCAL | The visible storage capacity must represent BRVTAL-managed application data against the configured o |
| `S28-R015` | `tests/dashboard-v2-contract.php` | REGRESION_LOCAL | Shared host-node filesystem capacity may remain available for diagnostics but must not be presented  |
| `S28-R016` | `tests/dashboard-v2-contract.php` | REGRESION_LOCAL | Diagnostics may detect and recommend but should not autonomously repair production. |
| `S28-R017` | `tests/admin-activity-contract.php` | REGRESION_LOCAL | Private logs must never expose sensitive stack traces publicly. |
| `S28-R018` | `tests/test_admin_development_signals.py` | REGRESION_LOCAL | Dashboard Development signals are a read-only operational surface over independent development sourc |
| `S28-R019` | `tests/test_admin_development_signals.py` | OBSERVADOR_NO_ACREDITADO | The GitHub adapter reads the public pl0n3r/brvtal repository and reports open Issue/PR counts, the n |
| `S28-R020` | `tests/test_admin_sonar_signals.py` | OBSERVADOR_NO_ACREDITADO | The Sonar adapter reads the public pl0n3r_brvtal SonarCloud project and reports its Quality Gate plu |
| `S28-R021` | `tests/test_admin_coderabbit_signals.py` | OBSERVADOR_NO_ACREDITADO | The CodeRabbit adapter reads only public GitHub review/comment evidence from the latest active PR an |
| `S28-R022` | `tests/test_admin_deploy_signals.py` | OBSERVADOR_NO_ACREDITADO | The deploy adapter is local to the authenticated runtime and reuses the canonical deployment/migrati |
| `S28-R023` | `tests/test_admin_deploy_signals.py` | OBSERVADOR_NO_ACREDITADO | DB/schema observation failures must degrade that source without fabricating exact identity, readines |

## Verificación, incertidumbre y reversión

- Ejecución: `python3 -m unittest -v tests/test_verificacion_brvtal_03.py`. Lanza cuatro contratos PHP originales (relaciones privadas, TOTP, auditoría, Dashboard) y nueve métodos `unittest` originales sobre rutas de staff, restauración y observadores. Verifica integridad 50/50 y negativos sintéticos.
- El contrato TOTP prueba protecciones offline de secretos; el E2E de login y su regresión Safari/WebKit quedan **históricos no revalidados aquí**. Los indicadores GitHub, SonarCloud, CodeRabbit, despliegue y base de datos solo validan adaptación y degradación sin fabricar GREEN.
- Para elevar evidencia externa se requiere autenticación, productor real, SHA exacto, fecha y estado terminal de la observación; este lote no hace esas consultas ni sustituye `VALIDATED_IN_PRODUCTION`.
- Solo se añaden dos rutas; sin cambios a `docs/matriz-cobertura.md`, fuentes productivas, credenciales, red, datos reales, go-live, reglas ni proveedores. Reversión mediante revert del commit squash.
