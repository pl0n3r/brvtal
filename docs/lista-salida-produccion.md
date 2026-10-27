# BRVTAL — lista de salida a producción (pre-live)

**Auditoría 27-oct-2026.** Fuente de partida: main@4fc4d9625fca30a6e8fbe65dab31e44eed5d0924; hoja [#1036](https://github.com/pl0n3r/brvtal/issues/1036). **No autoriza go-live.** Evidencia de pruebas y código no constituye aprobación de hosting, restauración real ni publicación. Revalidar SHA, estado de PRs y evidencia productiva en cada futuro corte.

## Criterio conservador

- **LISTO:** únicamente el requisito acotado de la fila cuenta con prueba o artefacto verificable; no significa que toda su área esté lista para lanzar.
- **EN_CURSO:** existe hoja implementable abierta y PR activo, no basta epic o roadmap.
- **SIN_COBERTURA:** falta evidencia de salida; se crea hoja canónica independiente, bloqueada hasta que termine #1036.
- **DEL_DUEÑO:** decisión sobre dinero, proveedor, contrato, contenido, datos reales, acceso o autorización visual que pertenece al titular. Se describe sin generar tareas administrativas ni solicitar secretos.
- **NO_APLICA:** exclusión con justificación explícita; no ocultar UNKNOWN con este estado.

Fuentes: [BRVTAL-SPEC](https://github.com/pl0n3r/brvtal/blob/main/docs/BRVTAL-SPEC.md), [Migraciones](https://github.com/pl0n3r/brvtal/blob/main/docs/MIGRATIONS.md), [Seguridad](https://github.com/pl0n3r/brvtal/blob/main/docs/SECURITY-HARDENING.md), [Performance](https://github.com/pl0n3r/brvtal/blob/main/docs/PERFORMANCE-BASELINE.md), [Privacidad](https://github.com/pl0n3r/brvtal/tree/main/docs/privacidad), [decisiones](https://github.com/pl0n3r/brvtal/blob/main/decisiones.yml), [matriz #1032](https://github.com/pl0n3r/brvtal/blob/main/docs/matriz-cobertura.md) y [roadmap #533](https://github.com/pl0n3r/brvtal/issues/533).

## Matriz de salida

| Área | Requisito | Estado | Evidencia | Issue |
| --- | --- | --- | --- | --- |
| Respaldos y restauración | Sistema de respaldos: contrato de código y pruebas | LISTO | tests/backups-contract.php y tests/backups-automation-contract.php; #389 cerrado acredita funcionalidad, no restore real | [#389](https://github.com/pl0n3r/brvtal/issues/389) |
| Respaldos y restauración | Ensayo de restauración íntegra con evidencia de integridad | SIN_COBERTURA | Falta acta reproducible de restauración aislada con datos ficticios y prueba de recuperación | [#1039](https://github.com/pl0n3r/brvtal/issues/1039) |
| Copias de seguridad finales | Manifiesto de backup previo al corte con checksum y RPO | SIN_COBERTURA | No se ha certificado una copia de pre-corte ni sus tiempos de recuperación | [#1039](https://github.com/pl0n3r/brvtal/issues/1039) |
| Monitoreo y alertas | Contratos de HEALTH y smoke incorporados al código | LISTO | tests/factory-health-contract.php y tests/production-smoke-contract.php; #991 evidencia diagnóstico técnico | [#991](https://github.com/pl0n3r/brvtal/issues/991) |
| Monitoreo y alertas | Alertas con umbral, recepción, escalamiento y simulacro | SIN_COBERTURA | No existe un ensayo trazable de recepción y escalado de alertas operativas | [#1040](https://github.com/pl0n3r/brvtal/issues/1040) |
| Dominios/DNS/TLS | Titularidad del DNS y autorización de ventana de cambio | DEL_DUEÑO | El dueño de la cuenta debe verificar control del dominio, DNS y quién autoriza cambios | [#1036](https://github.com/pl0n3r/brvtal/issues/1036) |
| Dominios/DNS/TLS | Preflight HTTPS y certificado TLS vigente para dominios finales | SIN_COBERTURA | No hay acta verificable de certificados y redirecciones exactas en la ventana del corte | [#1042](https://github.com/pl0n3r/brvtal/issues/1042) |
| Secretos y accesos | Autenticación y recuperación de sesiones comprobadas en código | LISTO | tests/admin-session-revalidation-contract.php y tests/totp-enrollment-contract.php; #629 | [#629](https://github.com/pl0n3r/brvtal/issues/629) |
| Secretos y accesos | Inventario de cuentas, 2FA y rotación de secretos productivos | DEL_DUEÑO | El titular debe validar permisos de sus cuentas; no compartir credenciales con agentes | [#1036](https://github.com/pl0n3r/brvtal/issues/1036) |
| Costos y límites | Presupuesto de hosting y cuotas de correo, backups y DeepL Free | DEL_DUEÑO | El dueño confirma cuotas, proveedores y topes monetarios; no autorizar gasto por inferencia | [#1033](https://github.com/pl0n3r/brvtal/issues/1033) |
| Privacidad y aspectos legales | Carpeta técnica de privacidad entregable a revisión | LISTO | docs/privacidad/politica-tratamiento.md y tests/test_revision_juridica.py; #1000 | [#1000](https://github.com/pl0n3r/brvtal/issues/1000) |
| Privacidad y aspectos legales | Identidad del responsable y aprobación de textos y consentimientos | DEL_DUEÑO | El dueño y su asesor legal deben validar responsable, términos, retención y derechos; documentos técnicos no son aprobaciones | [#1000](https://github.com/pl0n3r/brvtal/issues/1000) |
| Soporte y operación diaria | Guardia, atención, contactos por rol y escalamiento diario | SIN_COBERTURA | No hay runbook operativo probado con responsables por rol y protocolo de incidentes | [#1040](https://github.com/pl0n3r/brvtal/issues/1040) |
| Migración de datos | Registro checksum e interfaz segura de migraciones | LISTO | docs/MIGRATIONS.md y tests/migrations-contract.php; #991; no prueba escritura productiva | [#991](https://github.com/pl0n3r/brvtal/issues/991) |
| Migración de datos | Dry-run sintético, preflight backup y reconciliación DB | SIN_COBERTURA | Falta ensayo verificable con esquema y reversión antes de cualquier migración real | [#1041](https://github.com/pl0n3r/brvtal/issues/1041) |
| Reversión y apagado de emergencia | Simulacro rollback de release y datos | SIN_COBERTURA | No hay evidencia terminal de rollback/restore de un corte; git revert no restaura DB | [#1041](https://github.com/pl0n3r/brvtal/issues/1041) |
| Reversión y apagado de emergencia | Procedimiento de pausa y respuesta a emergencia | SIN_COBERTURA | No se ha ensayado un incidente simulado con roles y decisión de apagado seguro | [#1040](https://github.com/pl0n3r/brvtal/issues/1040) |
| Seguridad (revisión y pruebas) | Protección main y análisis de seguridad automatizado | LISTO | tests/sonar-security-contract.php y tests/security-workflow-contract.php; #1009 | [#1009](https://github.com/pl0n3r/brvtal/issues/1009) |
| Seguridad (revisión y pruebas) | Auditoría final de riesgos, WAF/TLS, permisos y findings | SIN_COBERTURA | docs/SECURITY-HARDENING.md no constituye WAF activo ni revisión de riesgo live | [#1042](https://github.com/pl0n3r/brvtal/issues/1042) |
| Accesibilidad y rendimiento | Baseline y tests de regresión de accesibilidad | LISTO | docs/PERFORMANCE-BASELINE.md y tests/test_public_concept05_release_polish.py; #944 | [#944](https://github.com/pl0n3r/brvtal/issues/944) |
| Accesibilidad y rendimiento | Validación final 1440/390 y métricas exact-release | SIN_COBERTURA | Falta evidencia final de contraste, teclado, reduced-motion, imágenes y budgets móviles | [#1043](https://github.com/pl0n3r/brvtal/issues/1043) |
| Smoke de producción | Contrato ejecutable smoke técnico disponible | LISTO | tests/production-smoke-contract.php y tests/test_production_smoke_diagnostics.py; #945 | [#945](https://github.com/pl0n3r/brvtal/issues/945) |
| Smoke de producción | Smoke terminal sobre despliegue autorizado con SHA real | SIN_COBERTURA | Falta acta de smoke público/autenticado y verificación exact-SHA de release | [#1044](https://github.com/pl0n3r/brvtal/issues/1044) |
| Contenido real y medios definitivos | Textos reales, artistas, fechas y enlaces aprobados | DEL_DUEÑO | El responsable editorial debe entregar o aprobar datos definitivos, sin inventar contenido | [#1036](https://github.com/pl0n3r/brvtal/issues/1036) |
| Contenido real y medios definitivos | Fotografías y medios finales con derechos autorizados | DEL_DUEÑO | El dueño debe acreditar permisos de medios finales; placeholders no son licencias | [#975](https://github.com/pl0n3r/brvtal/issues/975) |
| Imágenes del home | Conectar imágenes reemplazables al CMS del Concepto 05 | EN_CURSO | Issue #975 abierto, PR #1031 activo; placeholders no equivalen a imágenes finales | [#975](https://github.com/pl0n3r/brvtal/issues/975) |
| Imágenes del home | Selección definitiva de imágenes en desktop y móvil | DEL_DUEÑO | El dueño debe aportar o aprobar imágenes definitivas y sus derechos de uso | [#975](https://github.com/pl0n3r/brvtal/issues/975) |
| Traducción ES/EN y DeepL | Selector ES/EN, fallback y rutas internacionalizadas | LISTO | tests/test_public_i18n_contract.py y tests/test_public_i18n_routing.py; #857 | [#857](https://github.com/pl0n3r/brvtal/issues/857) |
| Traducción ES/EN y DeepL | Transporte DeepL Free apagado por defecto con pruebas offline | EN_CURSO | Issue #1034 abierto, PR #1037 activo; autorización limitada por #1033 | [#1034](https://github.com/pl0n3r/brvtal/issues/1034) |
| Traducción ES/EN y DeepL | Credenciales, términos y activación real de API externa | DEL_DUEÑO | El titular decide sobre cuenta y habilitación productiva; #1033 no autoriza gastos ni publicar secretos | [#1033](https://github.com/pl0n3r/brvtal/issues/1033) |
| Aprobación visual del dueño | Visto bueno final Concepto 05 sobre 1440/390 y medios | DEL_DUEÑO | La aprobación estética del dueño fue diferida en #1025; la prueba sintética no la reemplaza | [#1025](https://github.com/pl0n3r/brvtal/issues/1025) |
| SEO e indexación en producción | Soporte técnico sitemap e IndexNow | LISTO | tests/public-sitemap-contract.php y tests/indexnow-contract.php; #481 | [#481](https://github.com/pl0n3r/brvtal/issues/481) |
| SEO e indexación en producción | Verificación robots, canonical, ES/EN, indexación real | SIN_COBERTURA | No se ha acreditado indexabilidad/productive URLs sobre el release de salida | [#1044](https://github.com/pl0n3r/brvtal/issues/1044) |
| Autorización final de live | Aprobación formal de lanzamiento con respaldos y legal | DEL_DUEÑO | El dueño decide la salida solo con puertas de seguridad, datos y derecho satisfechas | [#1025](https://github.com/pl0n3r/brvtal/issues/1025) |

## Resumen final

- LISTO: 10
- EN_CURSO: 2
- SIN_COBERTURA: 12
- DEL_DUEÑO: 10
- NO_APLICA: 0
- TOTAL: 34

**Impedimentos para go-live:** faltan restauración verificable, alertas y soporte, validación DNS/TLS, ensayo de migración y rollback, auditoría de seguridad, pruebas finales de accesibilidad/rendimiento, smoke exact-SHA e indexabilidad (hojas #1039–#1044). Los PR activos #1031/#1037 cubren integración de imágenes CMS y transporte DeepL pero no prueba de live. El titular debe validar contenido y licencias reales, responsable legal, presupuestos, cuentas, dominio, imágenes y aprobación visual, además de emitir autorización explícita para salir a producción.

Las hojas #1039–#1044 se crearon bajo petición expresa de #1036; comienzan bloqueadas por dependencia de su merge. No tocar producción, secretos, datos reales, DNS, pagos o go-live. No hay permiso implícito para desplegar.
