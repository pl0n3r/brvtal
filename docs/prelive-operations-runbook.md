# BRVTAL — operaciones y soporte pre-live (runbook offline)

Issue #1040; dependencia #1036 fusionada. Fase construccion, solo build-ahead.
NO CERTIFICA GO-LIVE, no enciende alertas, cron, proveedor, SSH, datos reales,
permisos, backups, despliegues ni servicios. Los ensayos son **sintéticos**.

## AC-01 — Senales, umbrales y escalamiento

Fuentes existentes:
- HEALTH: api/health.php publica /api/health.php: HTTP 200, ok=true,
  deployment.exact=true, schema_up_to_date=true y release_sha exacto.
  HTTP 503, schema desconocido o JSON ilegible no equivalen a salud verde.
- DEPLOY: api/deployment.php publica /api/deployment.php: comparar
  data.exact=true, data.source=git_checkout, version y commit SHA con
  la version autorizada. .github/workflows/production-deploy-observer.yml
  consume observer Factory al hacer push en main; CI verde no prueba el
  despliegue final ni la disponibilidad sostenida.
- SMOKE: .github/workflows/production-authenticated-smoke.yml requiere
  actor OWNER y main, o comando gobernado en la hoja autorizada.
  tests/production-smoke-contract.php prueba el contrato local, no un
  smoke real. Sin recibo exact-SHA terminal, queda NO_VERIFICADO.
- Diagnostico: docs/PRODUCTION-HEALTH-INSPECTION.md describe
  inspeccion owner-only, no SQL, migracion, restore ni despliegue.

| Senal | GREEN (solo fixture) | WARN | CRITICAL / UNKNOWN | Responsabilidad |
| --- | --- | --- | --- | --- |
| HEALTH | HTTP 200 y campos validos | 1-2 errores en 5 min | 3 errores en 5 min o 503 = CRITICAL; falta evidencia = UNKNOWN | OPERADOR avisa a SRE |
| DEPLOY | commit SHA exacto y version autorizada | Convergencia no concluida | SHA distinto o exact=false = CRITICAL; sin fuente = UNKNOWN | SRE y OWNER |
| SMOKE | Recibo success exact-SHA autorizado | Acuse demorado en ventana aprobada | Failed = CRITICAL; no ejecutado = UNKNOWN | QA y OPERADOR |
| Recepcion | Aviso acusado | Sin acuse en 5 min | Sin acuse en 15 min, escalar | OPERADOR y OWNER |

Los umbrales propuestos de 5 min y 15 min son para ejercicios,
NO son SLA ni configuracion live. No interpretar un timeout como GREEN.
CRITICAL o UNKNOWN obligan a ESCALAR AL OWNER y mantener pausa en
cualquier paso de publicacion; no habilitan reparacion de base de datos.

Escalamiento funcional sin contactos personales: OPERADOR de guardia ->
SRE/RESPONSABLE TECNICO -> SEGURIDAD para incidentes de permisos/datos ->
OWNER para dinero, DNS, apagado, despliegue y go-live. QA valida
resultados exact-SHA. Ausencia de responsable o acuse = UNKNOWN.

## AC-02 — Rutina diaria y registro de incidentes

Rutina propuesta, NO PROGRAMADA:
1. Apertura de turno: OPERADOR verifica Factory #767 RUNNING,
   freeze #921 y estado de los registros del observador.
2. Salud: OPERADOR revisa HEALTH, esquema, SHA y 503/UNKNOWN; si falta
   evidencia mantiene pausa y eleva incidencia.
3. Version: QA coteja main, commit esperado, identity del DEPLOY,
   CI, observer y recibo de SMOKE. CI y smoke real son distintos.
4. Atencion entrante: SOPORTE clasifica un folio sintetico y escala;
   no guardar nombre, email, telefono, IP, token, prompt o mensaje real.
5. Cierre y handoff: OPERADOR registra acuse por rol, severidad,
   siguiente revision y evidencia tecnica minimizada.
6. Frecuencia, canales de contacto reales, presupuesto, horario y
   responsables son DECISION DEL OWNER; no inventar contactos.

Plantilla de incidente, enteramente ficticia:
- folio = DEMO-0001; kind = HEALTH|DEPLOY|SMOKE|SUPPORT|SECURITY
- severity = INFO|WARN|CRITICAL|UNKNOWN; owner_role = OPERADOR|QA|
  SOPORTE|SEGURIDAD|OWNER; acknowledged = boolean
- evidence_ref = identificador opaco, sin datos reales ni logs crudos
- observed_sha = SHA ficticio; next_review = UTC sintetico
- decision = OBSERVAR|ESCALAR|MANTENER_PAUSA
- closed_at solo cuando exista evidencia de resolucion autentica.

Una falta de acuse se escala por rol, no se silencia automaticamente.
Un error de seguridad requiere evidencia minimizada y responsable de
SEGURIDAD. D-059 obliga respaldo previo a cualquier accion futura
autorizada en hosting; dinero, borrado y go-live no estan aprobados.

## AC-03 — Simulacro de apagado sin efectos

NO ejecutar scripts de produccion, POST, workflows, SSH, cron, DB,
backup, DNS, ni modificar Factory #767. El marcador ficticio
<!-- factory-unattended-kill-switch {"version":1,"state":"RUNNING","owner":"pl0n3r"} -->
solo sirve para una prueba en memoria: **no modifica el interruptor real**.

El decisor comprueba marcador unico, version 1, owner pl0n3r,
issue CLOSED, fuente autentica, estado RUNNING y ausencia de freeze.
Un estado RUNNING concede solo ALLOW_EVALUATION de los demas gates,
NUNCA autoriza fusion o despliegue por si solo.
PAUSED, UNKNOWN, fuente no confiable, lectura fallida, duplicados,
marcador alterado o freeze => DENY y STOP / MANTENER_PAUSA.
Se ensayan snapshots RUNNING, PAUSED, UNKNOWN y duplicado con
contador de efectos externos igual a 0, sin red y sin servidores.

En emergencia real futura, OPERADOR preserva evidencia sin datos,
informa por canal aprobado y ESCALAR AL OWNER. Un apagado real y su
reversion requieren autoridad, backup, plan, auditoria y decisiones
de seguridad separadas. Este documento NO inventa un comando
para apagar el hosting.

## Go-live de boletería (#2193) — preparación sin activación

**Estado de todas las puertas: NO ACTIVAR.** Plan offline para #1739, no
autorización para ventas, correo, cobro, cambio de DNS, configuración o datos.
Los roles son responsabilidades propuestas; el OWNER debe designar titulares
y suplentes antes del corte, sin publicar identidades ni secretos.

### Entornos, credenciales y flags

| Control | Configuración objetivo / prueba | Responsable |
| --- | --- | --- |
| Wompi | BRVTAL_TICKET_WOMPI_MODE=off; sandbox solo con BRVTAL_TICKET_WOMPI_* de pruebas, producción con BRVTAL_TICKET_WOMPI_PROD_* segregadas; comprobar prefijos, integridad y firma de eventos sin imprimir valores | OWNER + SEGURIDAD |
| Resend | BRVTAL_TICKET_MAIL_MODE=disabled y BRVTAL_TICKET_MAIL_ALLOW_LIVE=0; credenciales API, webhook, dominio y remitente separados por entorno; test únicamente con allowlist sintética | OWNER + SEGURIDAD |
| Dominio | Verificar titularidad, HTTPS y DNS del remitente, SPF/DKIM/DMARC; BRVTAL_TICKET_MAIL_DNS_VERIFIED y BRVTAL_TICKET_MAIL_PRIVACY_APPROVED solo tras evidencia autorizada | OWNER + SEGURIDAD |
| Capacidades | Revisar checkout, correo, POS, promociones y marketing por separado; mantener deshabilitada cada capacidad sin su gate específico; no activar todas a la vez | OWNER + SRE |

No guardar claves, destinatarios, tokens ni cabeceras en actas o logs.
En staging usar mock/fixtures descartables sin dinero ni proveedores reales;
una prueba sandbox externa requiere autorización separada. No copiar claves
de producción a staging ni deducir readiness de que exista una variable.

### Monitoreo, respaldo y retorno

- OPERADOR verifica HEALTH, DEPLOY y SMOKE exact-SHA según AC-01; CI
  verde no equivale a deploy ni a validación productiva.
- SRE propone métricas de 503/latencia, pagos pendientes y discrepancias
  de conciliación, duplicados, cola outbox/edad/error de correo, esquema
  incompleto y puerta sin red. CRITICAL/UNKNOWN: pausa y aviso al OWNER;
  QA verifica recibo y acuse. No habilitar alertas ni cron aquí.
- SRE + OWNER: antes de cualquier escritura futura autorizada, backup
  persistente cifrado, inventario de BD/medios, hash SHA-256, retención,
  copia fuera del host y acceso mínimo. Registrar solo recibos saneados.
- QA ensaya restore en entorno descartable según
  [prelive-restore-rehearsal.md](prelive-restore-rehearsal.md):
  comparar esquema, integridad, filas ficticias y medios; medir RPO/RTO y
  registrar PASS/FAIL y SHA exacto. No restaurar sobre producción ni borrar
  transacciones posteriores; mantener pausa si falta prueba de retorno.
- SRE registra decisión de reversión, versión previa autorizada y plan de
  conciliación preservando pedidos, pagos, entradas y auditoría. Una reversión
  real requiere autorización D-059, copia verificada y control de concurrencia.

### Ensayos de contingencia sintéticos (pendientes de ejecución)

| Escenario | Invariante / señal | Recuperación no destructiva | Responsable |
| --- | --- | --- | --- |
| Proveedor caído | Timeout/5xx; pedido no se marca pagado por inferencia | Pausar nuevos cobros, mantener pendientes, conciliar con fuente canónica tras recuperar servicio | SRE + QA |
| Webhook duplicado | Firma e idempotencia; una transición/entrada por pago | Conservar auditoría, deduplicar por identidad canónica y reconciliar sin repetir cobro | SRE + QA |
| Correo fallido | Outbox conserva estado y prueba sellada; no duplicar entrada | Retener cola, reintento acotado tras resolver causa, no reemitir QR por fallo de transporte | OPERADOR + QA |
| BD a medio migrar | Readiness/schema UNKNOWN o 503; ninguna venta ni admisión nueva | Pausar capacidades, verificar inventario y respaldo, completar migración solo autorizada o volver a artefacto compatible sin perder filas | SRE + OWNER |
| Puerta sin red | No confirmar entrada ni asumir QR válido; riesgo de doble uso | Mantener admisión electrónica en pausa; escalar, recuperar conexión y validar credencial en sistema canónico antes de admitir | OPERADOR + QA |

Registrar por ensayo fixture, SHA, expected/observed, PASS/FAIL/UNKNOWN,
responsable, acuse y evidencia de recuperación. Sin evidencia = UNKNOWN;
no certificar que los cinco ensayos se ejecutaron en este PR.

### Puerta humana de encendido

| Puerta | Evidencia exigida | Responsable | Estado |
| --- | --- | --- | --- |
| Titularidad | Dominio, cuenta Wompi/Resend y onboarding comercial verificados | OWNER | NO ACTIVAR |
| Impuestos | Revisión fiscal, facturación y obligaciones aplicables | OWNER + asesoría fiscal | NO ACTIVAR |
| Datos personales #1671 | Decisión legal y privacidad, retención y derechos aprobados | OWNER + SEGURIDAD + asesoría jurídica | NO ACTIVAR |
| Medios de pago | Métodos habilitados individualmente, conciliación y sandbox autorizada con recibo | OWNER + QA | NO ACTIVAR |
| Reembolsos | Política, responsables, reversión y escenarios de devolución probados | OWNER + QA | NO ACTIVAR |
| Encendido | Todas las puertas acreditadas, backup/restore, salud, CI/revisión exact-HEAD, deploy/smoke exact-SHA y aprobación explícita posterior del OWNER por capacidad | OWNER + SRE | NO ACTIVAR |

Sin responsable, evidencia auténtica vigente, SHA coincidente o decisión
explícita del OWNER: NO ACTIVAR. Legal #1671 permanece una puerta independiente.
No ejecutar esta lista como comandos. El merge documental no abre ventas,
no modifica flags y no constituye go-live ni certificación de producción.

## Validacion, evidencia y reversibilidad

python3 -m unittest -v tests.test_prelive_operations_runbook valida
AC-01 a AC-03 y el contrato de go-live #2193 con datos sintéticos y casos negativos. Despues se
requiere BRVTAL CI / validate en SHA exacto, reserva V3, evidencia
de seguridad y PR protegido. Verde offline NO CERTIFICA GO-LIVE,
SMOKE PRODUCTIVO, alertas activas ni soporte real.
Revertir el PR retira exclusivamente este documento y su prueba.
