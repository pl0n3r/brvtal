# BRVTAL — puertas de seguridad pre-live

**Estado canónico: NO_VERIFICABLE para autorización de live.** Preparación de seguridad exclusivamente documental y offline en fase `construccion`; dependencia [#1036](https://github.com/pl0n3r/brvtal/issues/1036), matriz [lista de salida](lista-salida-produccion.md), [Security Hardening](SECURITY-HARDENING.md) y [PLAN-AGENTES](https://github.com/pl0n3r/factory/blob/main/PLAN-AGENTES.md). Ni este documento ni una ejecución de CI autorizan activar producción, rotar secretos, cambiar DNS/TLS, comprar, administrar cuentas o tratar datos reales.

## Contrato de decisión y procedencia

- **LISTO (solo control individual):** comprobación terminada por un responsable autorizado, evidencia íntegra, resultado `success` cuando es automatizable, identidad de entorno y sujeto coincidentes, SHA exacto de la candidata, antigüedad y periodo de validez verificables, cero hallazgos de riesgo alto/crítico abiertos y aceptación OWNER cuando corresponda. LISTO de una fila no es LISTO de lanzamiento.
- **NO_VERIFICABLE:** cualquier falta de prueba, artefacto, firma/procedencia, owner, timestamp, SHA, certificación de dominio o entorno; estados `pending`, `queued`, `in_progress`, `skipped`, `neutral`, `failure`, `cancelled`, `stale`, cuota CodeRabbit agotada o cierre no demostrado de hallazgo grave. No convertir UNKNOWN en éxito.
- **DEL_DUEÑO:** requiere acceso/cuenta, control DNS, cambio o rotación de credenciales, inscripción 2FA, certificado final, gasto o aceptación legal/productiva. No crear esas credenciales, no exponer nombres de cuentas ni datos personales, no editar proveedores.
- **Decisión global:** por defecto `NO_VERIFICABLE`. No declarar `LISTO` global con solo `validate` verde: es indispensable comprobar todos los controles aplicables, incluidos controles humanos de dominio y servicios. El único permiso de go-live proviene de una decisión **explícita y posterior** del OWNER.

## Matriz obligatoria de amenazas, controles y autoridad

| Control | Amenaza o frontera | Evidencia exigible y comprobación | Estado antes del corte | Responsable |
| --- | --- | --- | --- | --- |
| Identidades y accesos | Cuentas compartidas, permisos persistentes y shadow admins | Inventario privado de cuentas/roles por servicio, dueño, responsable, altas/bajas, acceso revocado y periodo de recertificación; nunca nombres/usuarios aquí | NO_VERIFICABLE | OWNER / seguridad |
| Least privilege y segregación | Lectura o modificación cross-tenant, cuentas con admin global | Matriz rol/recurso/operación, menor privilegio, revisión server-side de sesión, CSRF, idempotencia y prueba negativa por rol/tenant, sin conceder permisos por UI | NO_VERIFICABLE | Ingeniería + QA / OWNER valida |
| 2FA y recuperación | Compromiso de cuenta, pérdida de factor, bypass de recovery | Evidencia privada de 2FA para administradores y proveedores, factores de recuperación, caducidad de sesiones, rate limits; pruebas sintéticas de alta/revocación | NO_VERIFICABLE | OWNER habilita / seguridad verifica |
| Secretos y rotación | Filtración de tokens, logs o configuración | Inventario privado de ubicaciones y custodios, scopes, rotación probada y rollback, detección de secretos en GitHub/CI/logs; **solo fingerprints/receipts saneados**, nunca valores ni rutas privadas | NO_VERIFICABLE | OWNER autoriza y ejecuta |
| TLS y dominios | MITM, certificados incorrectos, contenido mixto | Preflight HTTPS read-only en dominio final con fecha, hostname, cadena válida, SAN, emisor, expiración, TLS moderno, redirecciones HTTP→HTTPS, HSTS y pruebas sin mixed content; registrar SHA de deploy observado separado de source | NO_VERIFICABLE | OWNER controla DNS/certificados |
| WAF y hosting edge | Tráfico hostil y falsas denegaciones | Compatibilidad Apache/LiteSpeed/Hostinger, reglas y excepciones revisadas contra login, APIs, uploads; evidencia de falsos positivos y rollback previo a cualquier activación | NO_VERIFICABLE | OWNER / operaciones |
| CodeQL | Fallos de seguridad en nuevo código | Análisis SAST del HEAD exacto, job terminal `success`, alertas nuevas graves triadas y evidencia de cierre; no aceptar ausencia de análisis como cero hallazgos | NO_VERIFICABLE | Seguridad + ingeniería |
| SonarCloud | Vulnerabilidades y hotspots sin revisión | Quality Gate y `SonarCloud Code Analysis` `success` del mismo SHA, cero findings nuevos altos/críticos sin resolver, revisión explícita de hotspots | NO_VERIFICABLE | Seguridad + ingeniería |
| CodeRabbit | Revisión final incompleta o cuota agotada | Una revisión manual **FINAL** después de CI verde para el HEAD estable; revisar threads y `CHANGES_REQUESTED`. Si llega rate limit, registrar receipt real D-070 para merge en construcción, **jamás** clasificar live como LISTO | NO_VERIFICABLE | Ingeniería + QA |
| CI `validate` | Integración no comprobada o rama obsoleta | Check `validate` terminal `success` emitido para HEAD exacto y `main` protegido; verificación separada de coordinación V3, claims y SHA de PR | NO_VERIFICABLE | Ingeniería / CI |
| Incidentes y hallazgos | Vulnerabilidad S1/S2 sin remediar | Inventario de issues de seguridad abiertos, CodeQL/Sonar/CodeRabbit, severidad alta o crítica, owner, decisión documentada y prueba de remediación; **un hallazgo grave abierto ⇒ NO_VERIFICABLE** | NO_VERIFICABLE | Seguridad + OWNER |

## Paquete de evidencias y SHA

El expediente para un futuro corte lleva `candidate_sha` (40 hex), `release_sha` observado en destino (no inferir de main), `source`, `conclusion`, `status`, `verified` booleano, `checked_at`, enlace al run/check o acta saneada, sujeto/hostname, ambiente, período de validez, severidad y responsable. No poner tokens, códigos OTP, direcciones de clientes, contraseñas, datos de acceso ni secretos en el expediente público.

Para gates automatizados `validate`, `CodeQL` y `SonarCloud Code Analysis`, el sujeto del check es **candidate_sha exacto**; un check de una revisión anterior, un commit de merge sintético o un HEAD distinto no sirve. `status=completed` y `conclusion=success` son ambos necesarios. El `release_sha` requiere inspección independiente del deploy; no se deduce de checks de origen. Para controles humanos (DNS, proveedores, MFA y rotación) registrar solo recibos de aprobación privada del OWNER, sin exponer identidades. Si expira cualquier evidencia, revalidar: no reutilizar un acta histórica.

En particular, el estudio no hace llamadas de red para validar TLS ni consulta cuentas. Documentar una URL no demuestra titularidad ni certificado vigente. Una respuesta a CodeRabbit de `rate limited` **no equivale a una revisión aprobada**; D-070 podría permitir merge de fuente en `construccion`, no despeja la decisión go-live.

## Ensayo sintético seguro y reglas de abortar

1. Simular dos roles anónimos (mínimo privilegio vs. administrador) y dos tenants inventados; una petición con rol o tenant cruzado debe rechazarse sin consultar secretos reales.
2. Fabricar receipts de CI con HEAD coincidente y resultado terminal; alterar SHA, omitir el receipt, usar estados `pending` o `skipped`, marcar `verified=false` o incluir alertas `high/critical`: **NO_VERIFICABLE**, sin excepción.
3. Simular fallo de 2FA, certificado caducado/hostname no coincidente, ausencia de inventario privado y revisión CodeRabbit rate-limited: **NO_VERIFICABLE** aunque Sonar y CI verde.
4. Registrar únicamente conteos, decisiones, SHA ficticios, enlaces sanitizados a evidencias y resultados de la simulación. Ningún secreto real, dato de usuarios, conexión Hostinger ni configuración de WAF.
5. Abort conditions: identidad o SHA incompletos, verificación DNS/TLS faltante, factor ausente, privilegio excesivo, fuga de secreto, issue grave no resuelto, revisión final sin evidencia o retroceso de estado. Remitir al responsable sin fingir conclusión `LISTO`.

## Puertas exclusivas del dueño, estado y reversión

**DEL_DUEÑO (pendiente):** titularidad del dominio y cuentas, verificación y actualización 2FA, consentimiento a rotación de secretos reales, cambios DNS/WAF/certificados, gastos/proveedores, revisión legal y decisión explícita de go-live. Si no responde, el estado seguro es **NO activar**. El expediente técnico no crea autorizaciones ni obliga a implementar infraestructura antes de live.

Este leaf añade **únicamente** este documento y su prueba. Reversión: revertir el squash; no existe migración, WAF activo, cambio de hosting, importación de PII ni dato de producción que restaurar. Revisión de seguridad (fronteras/secretos/permisos) y segunda pasada QA (negative tests, SHA mismatch, expiración, alta severidad) forman parte del contrato y deben verificarse contra el diff.
