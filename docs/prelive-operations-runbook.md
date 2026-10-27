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

## Validacion, evidencia y reversibilidad

python3 -m unittest -v tests.test_prelive_operations_runbook valida
AC-01 a AC-03 con datos sintéticos y casos negativos. Despues se
requiere BRVTAL CI / validate en SHA exacto, reserva V3, evidencia
de seguridad y PR protegido. Verde offline NO CERTIFICA GO-LIVE,
SMOKE PRODUCTIVO, alertas activas ni soporte real.
Revertir el PR retira exclusivamente este documento y su prueba.
