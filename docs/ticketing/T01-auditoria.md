# T01 · Auditoría de contratos de boletería BRVTAL

> Epic #1706 · Issue #1707 · Inventario de fuentes sobre `main@1121da47239eafc1a8d097f7c23cd131a90db71f` (17/18-nov-2026 UTC). **Diseño, no implementación ni autorización de cobro.** El estado GitHub/CI debe revalidarse antes de usar esta matriz para crear reservas V3.

## 1. Resultado y límites verificables

El sistema actual administra **eventos y tipos editoriales de boleta**, publica precios/ventanas y enlaces de venta externos. **No hay evidencia en este árbol** de comprador, pedido, línea, intención/transacción Wompi, webhook de pagos, emisión de entradas individuales, QR de un solo uso, control de acceso ni historial Resend. `events.ticket_qr` y `event_ticket_types.qr_image` son **referencias editoriales**, no credenciales emitidas a una persona. Un `status=active` tampoco acredita inventario, cobro ni ingreso.

Esta auditoría se limita al código en el SHA citado y a los contratos de issues. No inspecciona la base de datos de producción ni envía correos/cobros; por tanto **no asegura que migraciones estén aplicadas, que un proveedor esté configurado ni que exista despliegue productivo**. `database/schema.sql` define una base histórica; `database/migration_content_core_01.sql` extiende eventos/tipos, y `config/migrations.php`/`scripts/migrations.php` controlan la evolución explícita. Una fusión no aplica migraciones.

### 1.1 Inventario de código y pruebas

| Entidad/función | Fuente existente exacta | Límite / prueba disponible |
| --- | --- | --- |
| Evento / estado editorial | `database/schema.sql` (`events`); `database/migration_content_core_01.sql`; `config/event_lifecycle.php` | `ticket_url`, `ticket_instructions`, `ticket_qr`; ciclo de publicación distinto del pago; `tests/event-publication-invariant-contract.php`, `tests/e2e/event-publication-invariant-real-stack.spec.mjs` |
| Tipo / precio editorial | `database/migration_content_core_01.sql` (`event_ticket_types`); `api/index.php` (`ticket_types`); `api/event-workflow-lib.php` | `price DECIMAL(12,2)`, `currency`, `external_url`, `qr_image`, `status`, ventanas; **sin cupo reservado ni asignación por pedido**; `tests/e2e/ticket-types-editor.spec.mjs` |
| Edición Event/Ticket Type | `discadmin/content-core.php`, `discadmin/content-core.js`, `discadmin/event-workflow.js`; `api/event-workflow.php`, `api/event-workflow-lib.php` | Edición con sesión/CSRF y guardado coordinado de evento + tipos; no es checkout ni backoffice financiero; `tests/event-workflow-contract.php`, `tests/integration/event-workflow.php` |
| API pública de evento/tipos | `api/public.php`; `config/public_page.php`, `config/public_visibility.php`, `config/event_lifecycle.php` | Publica tipos `active/sold_out` con filtro temporal y restringe CTAs cuando evento no admite venta; sin disponibilidad transaccional; `tests/public-event-timetable-contract.php`, `tests/e2e/public-event-record.spec.mjs` |
| Auth staff / CSRF | `config/admin_auth.php`; `api/index.php`; `config/admin_staff_ops.php` | Sesión exclusiva `BRVTAL_ADMIN`, cookie HttpOnly/Secure/SameSite, `brvtal_admin_require()` y `brvtal_admin_require_csrf()`; no equivale a login de comprador; `tests/admin-session-revalidation-contract.php`, `tests/test_admin_require_permissions.py` |
| Correo previo | `config/admin_mailer.php`; `ops/admin-password-mail-worker.php`; tablas `admin_password_reset_tokens`, `admin_password_mail_outbox` | SMTP/PHPMailer y outbox **solo administrativo**; no hay Resend de compradores ni historial de delivery ticketing; `tests/integration/admin-password-security.php` |
| QR previo | `discadmin/qrcode.min.js`, campo `event_ticket_types.qr_image`, `events.ticket_qr` | Un generador/referencia de QR no valida identidad, token único, doble escaneo ni revocación |
| Migraciones | `config/migrations.php`, `scripts/migrations.php`, `docs/MIGRATIONS.md`, `database/migration_schema_migrations_01.sql` | Control de checksums, CLI manual, estados `pending/applied/mismatch`; DDL MariaDB puede autoconsumir transacción; `tests/migrations-contract.php`, `tests/integration/migrations.php` |
| UI admin / shell | `discadmin/index.php`, `discadmin/index-core.php`, `discadmin/admin-shell.js`, `discadmin/content-core.php` | Reutilizar **un único shell/sidebar/sesión/workspace**; no segundo DISCADMIN; `tests/e2e/discadmin-admin-shell.spec.mjs` |
| Pagos / compradores / escáner / Resend | **Sin rutas de implementación ticketing** en `api/`, `config/`, `database/` o `discadmin/` en el SHA auditado | Son entregables futuros; no inferir existencia de webhooks de pagos por tener una URL externa o un QR editorial |

### 1.2 Contratos y decisiones compartidas

- `#1702` es dueño de la mejora de la ficha pública de evento; `#1705` lo es del editor Event/Tickets; `#1703` del mapa. Sus rutas son **interfaces preexistentes** para este diseño, no trabajo que T01 ni los slices posteriores deban copiar. Leer diff y claims de cada PR antes de cablear una integración.
- `#1709` es el carril legal/privacidad independiente de T01; las decisiones sobre facturación, responsabilidad del organizador, reembolsos, política de datos y requisitos Wompi/Resend no se resuelven aquí.
- ControlBot `/ops/staff` es el plano de personal (D-060), mientras BRVTAL posee datos de **compradores**. Rol de puerta con privilegio mínimo; identidad/compras de clientes separadas de `admins`.
- La decisión de #1706 es **comisión Wompi a cargo de BRVTAL**, no recargo al precio mostrado. No inventar tarifa ni IVA; registrar bruto, comisión, impuestos/retenciones y neto por separado, pendientes de comprobante.

## 2. Fuente de verdad propuesta, invariantes y API

| Modelo nuevo (tentativo) | Fuente persistente autoritativa | Claves/seguridad | API y consumidor esperados |
| --- | --- | --- | --- |
| Buyer / guest claim | `ticket_buyers`, `buyer_sessions`, `buyer_verifications` | Email normalizado, ownership por sesión de comprador, prueba criptográfica de correo para reclamar compra invitada; consentimiento versionado | `api/buyer/auth.php` → `buyer/login.php`; jamás reutilizar sesión admin |
| Pedido / líneas | `ticket_orders`, `ticket_order_items` | UUID público opaco; precio/COP entero server-side y snapshot inmutable; cantidad, tipo, etapa, total y expiración | `api/ticketing/checkout.php`/`inventory.php` → checkout público |
| Reserva de cupo | `ticket_inventory_holds` | Unicidad/idempotencia por pedido/tipo; transacción `SELECT ... FOR UPDATE`, expiración UTC, reconciliación; nunca stock negativo | `api/ticketing/inventory.php` → UI disponibilidad y reserva temporal |
| Pago / webhook | `ticket_payment_attempts`, `ticket_payment_events` | Índice único `(provider,provider_transaction_id)`; referencia interna única, importe/moneda/entorno/firma verificados, replay protection; el redirect no aprueba | `api/ticketing/checkout.php` inicia; `api/ticketing/webhook-wompi.php` confirma; pago solo desde proveedor auténtico |
| Entrada individual / QR | `ticket_entries`, `ticket_entry_credentials`, `ticket_checkins` | Una entrada por unidad pagada; token aleatorio con hash en reposo y versión; cancelación/reemisión revoca; doble escaneo gana solo una transacción | `api/ticketing/checkin.php` y área comprador; regenerar **imagen** no rota token |
| Correo | `ticket_mail_outbox`, `ticket_mail_messages`, `ticket_mail_events` | Intentos/estados/errores reintentables, envío tras commit, claves idempotentes; datos personales protegidos y retención por política | `config/ticketing_resend.php`, `ops/ticketing-mail-worker.php`, webhooks `api/ticketing/webhook-resend.php` |
| Finanzas | Consultas/conciliaciones sobre pagos confirmados + campos/tabla de settlement | Bruto ≠ descuento ≠ comisión/IVA/retenciones ≠ desembolso ≠ utilidad; proveedor/fecha/transacción trazables | `api/ticketing/admin-finances.php` → DISCADMIN, permisos financieros |

**Estados no intercambiables:** `event.status` controla visibilidad/editorial; `ticket_type.status` habilita oferta; `hold` es `active|expired|consumed|released`; `order` es `draft|pending|paid|failed|cancelled|expired|refunded`; `payment_attempt` es `created|pending|approved|declined|error|refunded` con historial, no overwrite de eventos; `entry` es `issued|used|revoked|refunded` con estado de credencial independiente; `mail` es `queued|attempted|accepted|delivered|bounced|failed`. Estos son **vocabularios propuestos** por validar en cada slice, no enums existentes.

**Transiciones irreversibles:** `paid` requiere evidencia del proveedor; evento repetido es no-op; un webhook fuera de orden no rebaja `paid`; emisión se confirma después de commit del pago, mediante outbox/idempotencia; un ingreso no se habilita tras `used`, `revoked` o `refunded`; ningún `500`, timeout ni conexión caída concede acceso. Reembolsos y disputas conservan evidencia, no se traducen en eliminación del pedido. Separar UTC en persistencia de zona `America/Bogota` de la fiesta.

**API contractual propuesta:** sesión comprador (SameSite/CSRF y rate-limit), `GET` de catálogo público solo de ofertas publicables, `POST` reserva/checkout con idempotency key y precios recalculados, `GET` estado de orden por dueño, callback Wompi firmado e idempotente con respuesta corta, `GET` QR solo comprador/staff autorizado (no cache), `POST` check-in atómico por operador/evento autorizado y `GET` administración bajo RBAC. Errores tipados `400`/`401`/`403`/`404`/`409`/`422`/`429`/`503`, sin secretos, token QR o PII en logs. Definir formalmente esquema JSON/request/response en cada issue dueño.

**Aislamiento:** HTTP público no revela usuarios ni emails; buyers no pueden navegar órdenes de terceros; puerta no puede ver información de pagos; operador de finanzas no puede emitir/canjear entradas por privilegio implícito; webhooks sin cookies/CSRF de navegador exigen firma y validación server-side propias; sandbox fail-closed si falta configuración. Cualquier dato real o proveedor externo sigue bloqueado por decisión legal/operativa humana.

## 3. DAG y migraciones aditivas

```
#1707 (T01) ─┬─ #1710 (T03) ─ #1712 (T05) ─ #1714 (T07) ─ #1715 (T08) ─ #1716 (T09)
              ├─ #1711 (T04) ───────────────────────────────────────────┘
              ├─ #1713 (T06) ───────────────────────┬─ #1719, #1721
              ├─ #1717 (T10) ─ #1718 (T11) ─ #1719 ┘
              └─ #1724 (T17) ─ #1725, #1726, #1727
#1716 ─ #1722 (T15) ─ #1723 (T16)
#1712 + #1714 ─ #1720 (T13)
#1713 + #1716 ─ #1721 (T14)
#1711 + #1716 ─ #1722 (T15)
#1710 + #1713 + #1715 + #1724 ─ #1725 (T18)
#1716 + #1722 + #1724 ─ #1726 (T19)
#1710 + #1715 + #1724 ─ #1727 (T20)
```

**Orden DB:** (1) persistir buyers/orders/items/attempts, índices y snapshots; (2) entradas/credenciales/auditoría; (3) holds; (4) outbox de correo; (5) historial/receipts. Usar archivos `migration_*.sql` **nuevos e inmutables**, expand-only (tablas, campos nullable e índices), checksum en `schema_migrations`, pruebas de esquema limpio y DB previamente migrada con filas editoriales sintéticas. No `DROP` de `event_ticket_types`, ni cambios disruptivos a `events`; `ticket_qr` y `qr_image` siguen editoriales. En particular, `api/event-workflow-lib.php` permite borrar tipos que desaparecen de un guardado: antes de habilitar ventas ligadas a tipos, un slice dueño debe establecer **RESTRICT de borrado** y retiro lógico para tipos con ventas, sin alterar silenciosamente ese archivo bajo otra lease.

**Rollback:** revertir despliegue/feature flag sin eliminar tablas nuevas, mantener ventas/eventos históricos; revertir esquema solamente en entorno desechable cuando no haya registros y bajo un plan autorizado. MariaDB DDL puede autocommit, por lo que inspeccionar estados parciales por objeto e índices y usar re-ejecución idempotente. No afirmar reversión productiva probada sin ensayo de restore y backup verificables. Gate live separado: legal #1709, proveedor/secretos, D-043 y aprobación humana.

## 4. Mapa propuesto de claims canónicos para #1710–#1727

Este mapa es **insumo para reescribir las issues**, no su reserva ni un cambio a su contrato actual. Los 18 issues están `status: blocked` y carecen de los markers `factory-acceptance`, `factory-plan-task` y rutas reclamadas; **no ejecutar `/take`** sobre ellas antes de materializar contrato válido, cerrar `depends_on` en `main` y comprobar ausencia de colisiones. Rutas abajo son **propuestas exactas, no existentes** salvo mención explícita. No reclamar directorios comodín. Una reserva reclama la totalidad de las rutas reales que su PR cambiará; si necesita una nueva ruta compartida, rediseñar/serializar el slice y renovar contrato antes de editar, nunca invadir la lease vecina.

En `AC` cada ítem tiene un ID y un check de CI esperado. `fast`, `database`, `chromium`, `webkit`, `real-stack` son jobs específicos path-aware de **BRVTAL CI**; `validate` es el agregado obligatorio. Un job saltado para docs no es evidencia de haber ejecutado test de integración. Tests mencionados para issues futuros **deben crearse**, no están hoy validados.

| Issue / depende de | Rutas exactas reclamadas sugeridas (todas exclusivas de esta fila) | AC ejecutables / checks futuros |
| --- | --- | --- |
| **#1710 T03** ← #1707 | `database/migration_ticketing_orders_01.sql`, `database/schema.sql`, `tests/integration/ticketing-orders.php` | [AC-01] fresh+upgrade conserva Events (`database`, `validate`); [AC-02] proveedor+transacción duplicados/concurrentes son únicos (`database`, `real-stack`); [AC-03] sin PAN/CVV (`fast`, `validate`) |
| **#1711 T04** ← #1707 | `database/migration_ticketing_entries_01.sql`, `tests/integration/ticketing-entries.php` | [AC-01] token aleatorio no adivinable y unicidad (`database`); [AC-02] FK/order/event + revocación (`database`, `real-stack`); [AC-03] sin emisión prematura (`fast`, `validate`) |
| **#1712 T05** ← #1710 | `config/ticketing_inventory.php`, `api/ticketing/inventory.php`, `tests/integration/ticketing-inventory.php` | [AC-01] último cupo, 2 competidores ⇒ 1 hold (`database`, `real-stack`); [AC-02] TTL/idempotencia y no emitir (`database`); [AC-03] ventanas/evento no publicable (`fast`, `validate`) |
| **#1713 T06** ← #1707 | `config/buyer_auth.php`, `api/buyer/auth.php`, `buyer/login.php`, `tests/e2e/buyer-auth.spec.mjs` | [AC-01] rate-limit, CSRF, reset único (`fast`, `real-stack`); [AC-02] cross-account 403 (`real-stack`); [AC-03] móvil y foco/estados con mail simulado (`chromium`, `webkit`, `validate`) |
| **#1714 T07** ← #1710, #1712 | `config/ticketing_wompi_client.php`, `api/ticketing/checkout.php`, `tests/ticketing-wompi-client-contract.php` | [AC-01] precio de servidor/entorno/firma, 5xx fail-closed (`fast`); [AC-02] retry no duplica (`database`, `real-stack`); [AC-03] sin credenciales/live (`validate`) |
| **#1715 T08** ← #1710, #1714 | `config/ticketing_wompi_webhook.php`, `api/ticketing/webhook-wompi.php`, `tests/integration/ticketing-wompi-webhook.php` | [AC-01] falsificación/replay/orden inverso (`fast`, `database`); [AC-02] approved monotónico (`real-stack`); [AC-03] log sin secretos (`validate`) |
| **#1716 T09** ← #1711, #1715 | `config/ticketing_issue.php`, `tests/integration/ticketing-issue.php` | [AC-01] N tickets exactos tras N×webhook (`database`, `real-stack`); [AC-02] pending/refund no emite (`database`); [AC-03] descarga autorizada y token rechazado (`fast`, `validate`) |
| **#1717 T10** ← #1707 | `database/migration_ticketing_mail_outbox_01.sql`, `config/ticketing_resend.php`, `config/ticketing_mail_outbox.php`, `ops/ticketing-mail-worker.php`, `tests/integration/ticketing-mail-outbox.php` | [AC-01] errores 429/5xx y sin secretos (`fast`); [AC-02] retries idempotentes (`database`); [AC-03] no duplicar ticket por fallo mail (`real-stack`, `validate`) |
| **#1718 T11** ← #1717 | `database/migration_ticketing_mail_history_01.sql`, `config/ticketing_mail_events.php`, `api/ticketing/webhook-resend.php`, `tests/integration/ticketing-mail-history.php` | [AC-01] consulta durable por id lógico (`database`); [AC-02] webhook falso/duplicado/fuera de orden (`real-stack`); [AC-03] accepted ≠ delivered (`fast`, `validate`) |
| **#1719 T12** ← #1713, #1716, #1717, #1718 | `config/ticketing_mail_templates.php`, `templates/ticketing/transactional.php`, `tests/ticketing-mail-templates-contract.php` | [AC-01] plantillas/trigger after-commit sin duplicado (`fast`, `database`); [AC-02] falla SMTP/API y reintenta (`real-stack`); [AC-03] accesible/español/consentimiento (`validate`) |
| **#1720 T13** ← #1712, #1714 | `js/public-ticket-checkout.js`, `css/public-ticket-checkout.css`, `config/public_ticket_checkout.php`, `config/public_page.php`, `tests/e2e/public-ticket-checkout.spec.mjs` | [AC-01] stock/estado real (`real-stack`); [AC-02] redirect no emite (`database`); [AC-03] móvil/teclado/red caída (`chromium`, `webkit`, `validate`) |
| **#1721 T14** ← #1713, #1716 | `config/buyer_account.php`, `api/buyer/account.php`, `buyer/index.php`, `tests/e2e/buyer-account.spec.mjs` | [AC-01] A≠B permisos (`real-stack`); [AC-02] ticket legible sin Resend (`database`); [AC-03] estados/QR móvil (`chromium`, `webkit`, `validate`) |
| **#1722 T15** ← #1711, #1716 | `config/ticketing_checkin.php`, `api/ticketing/checkin.php`, `tests/integration/ticketing-checkin.php` | [AC-01] dos scanners ⇒ uno válido (`database`, `real-stack`); [AC-02] revocado/otro evento (`database`); [AC-03] rol/red/DB fail-closed (`fast`, `validate`) |
| **#1723 T16** ← #1722 | `discadmin/ticketing-door.php`, `discadmin/ticketing-door.js`, `discadmin/ticketing-door.css`, `tests/e2e/ticketing-door.spec.mjs` | [AC-01] móvil cámara/manual y duplicados (`chromium`); [AC-02] permisos/cámara denegada (`webkit`); [AC-03] red caída nunca concede entrada (`real-stack`, `validate`) |
| **#1724 T17** ← #1707 | `discadmin/index-core.php`, `discadmin/admin-shell.js`, `discadmin/ticketing.php`, `discadmin/ticketing.js`, `discadmin/ticketing.css`, `config/ticketing_staff_permissions.php`, `tests/e2e/discadmin-ticketing-shell.spec.mjs` | [AC-01] módulo monta dentro de único shell (`chromium`, `real-stack`); [AC-02] RBAC/secretos ausentes (`fast`, `database`); [AC-03] no duplica editor #1705 (`webkit`, `validate`) |
| **#1725 T18** ← #1710, #1713, #1715, #1724 | `api/ticketing/admin-orders.php`, `discadmin/ticketing-orders.php`, `discadmin/ticketing-orders.js`, `tests/e2e/discadmin-ticketing-orders.spec.mjs` | [AC-01] joins sin duplicar importes (`database`, `real-stack`); [AC-02] filtro/PII/RBAC (`chromium`); [AC-03] estados mail/pago (`webkit`, `validate`) |
| **#1726 T19** ← #1716, #1722, #1724 | `api/ticketing/admin-entries.php`, `discadmin/ticketing-entries.php`, `discadmin/ticketing-entries.js`, `tests/e2e/discadmin-ticketing-entries.spec.mjs` | [AC-01] reemisión invalida QR anterior (`database`, `real-stack`); [AC-02] re-render sin rotar (`fast`); [AC-03] RBAC/doble clic/auditoría (`chromium`, `validate`) |
| **#1727 T20** ← #1710, #1715, #1724 | `config/ticketing_finance.php`, `api/ticketing/admin-finances.php`, `discadmin/ticketing-finance.php`, `discadmin/ticketing-finance.js`, `tests/integration/ticketing-finance.php` | [AC-01] suma única por venta (`database`); [AC-02] comisión real y settlement desconocido (`real-stack`); [AC-03] filtros/export/RBAC (`chromium`, `validate`) |

**Cortes de PR:** cada fila es una intención de slice, no permiso para hacerla toda si excede ~400 líneas o cruza capas. Dividir en subtareas aprobadas de la epic si hace falta, conservar ruta exclusiva de cada hoja y revalidar `depends_on`, `factory-acceptance`, `factory-plan-task`, CI requerido y V3. `database/schema.sql`, `config/public_page.php`, `discadmin/index-core.php` y `discadmin/admin-shell.js` son rutas compartidas existentes: quedan asignadas a **un solo** issue futuro en esta matriz y la integración con #1702/#1705/#1693 debe serializarse hasta que sus PR/leases terminen. Ninguna ruta nueva autoriza modificar otras implícitamente.

## 5. Matriz de verificación y lectura de CI

| AC de T01 | Evidencia exigida | Estado en este documento |
| --- | --- | --- |
| [AC-01] inventario verificable | `main` exacto, rutas `events/event_ticket_types`, scripts de migración, APIs/publicador, admin y suites arriba; `validate` PR HEAD terminal | **Inventariado desde fuente**, CI PR aún no ejecutado |
| [AC-02] claims/API/seguridad | 18 filas con rutas exclusivas, DAG/dep y AC/check; revisión técnica de dev/DB/QA/privacidad, contrato de estados, límites e idempotencia; `validate` | **Propuesta pendiente de materialización en Issues**; no afirmar aprobación de otros revisores |
| [AC-03] hallazgos Issue/PR | Documento PR ligado `Closes #1707` y comentario de resultados; `docs/BRVTAL-SPEC.md` no se modifica por estar fuera de las rutas reclamadas | **Pendiente de PR/reserva V3 válida** |

QA de futuras integraciones: tests de esquema limpio + migrated con datos sintéticos; HTTP 401/403/409/422/429/503, fallos y red caída; 2 compras/holds/webhooks/escáneres simultáneos; diferencia `accepted`/`delivered`; 390 px + 1440 px, teclado/Escape/foco; correo y pasarela *mock* sin tokens de producción. Solo asociar `fast`, `database`, `chromium`, `webkit`, `real-stack` a un SHA y un run concretos **cuando efectivamente se ejecuten**. `validate=success` acredita fuente/PR, no despliegue, SQL de producción ni una pasarela operativa.

### Decisiones/guardas abiertas

1. Legal #1709 + validación tributaria y de protección de datos de Colombia antes de dinero real, cuentas o marketing.
2. Política de retención/acceso/transferencia/reembolso y documentación de consentimiento de compradores; ningún log expone email/tokens/PAN/CVV.
3. Migraciones en producción requieren prueba de backup/restore y gate operativo, no automatizarlas por merge; `schema_migrations` con checksum inmutable.
4. Wompi/Resend se prueban con dobles de prueba, no con llaves/pagos reales; webhooks con autenticidad e idempotencia.
5. Puerta #767 `RUNNING`, freeze #921, revisión de claims/leases y CI `validate` en SHA exacto, CodeRabbit solo FINAL; `#1709` no se sustituye por esta auditoría.

**Rollback del PR T01:** revertir únicamente `docs/ticketing/T01-auditoria.md` si el inventario está obsoleto. No contiene credenciales, datos personales ni cambios ejecutables. La revisión/mutación de `docs/BRVTAL-SPEC.md` exige una reserva propia o una ampliación explícita y verificada de paths.