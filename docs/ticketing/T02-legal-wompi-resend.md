# T02 — Matriz legal Colombia y alta Wompi/Resend para boletería BRVTAL

> **Estado:** preparación documental, no dictamen legal ni permiso de activación. Hoja #1709 de épica #1706. Investigación de fuentes oficiales al 17-nov-2026. Las decisiones por evento deben ratificarse con asesoría jurídica/tributaria colombiana. Sin contratación, cobros, despliegue, envío a clientes ni tratamiento de datos reales.

## 1. Alcance y premisas

BRVTAL planea comercializar entradas propias, cobrar con Wompi y entregar QR/correos con Resend desde su sistema PHP/DISCADMIN. **El QR de ingreso y el recibo Wompi no son, por sí solos, factura ni documento equivalente tributario**. Se requieren soportes fiscales según el emisor y la operación. No duplicar DISCADMIN ni instalar WooCommerce.

Pendientes de verificar: identidad fiscal de vendedor/productor; condición de productor, organizador u operador de boletería para cada evento; municipio, permisos, sujeción a Ley 1493, régimen DIAN, condiciones contractuales Wompi, roles en tratamiento de datos y política de cancelaciones. Ninguno se presume por ser un colectivo de techno o un evento de invitados.

**Decisión de negocio ya aprobada en #1706:** la **comisión Wompi es un gasto operativo de BRVTAL**, nunca un recargo al comprador. No fijar tasas ni confundir bruto, descuentos, abono y utilidad.

## 2. Matriz Colombia: fuente, condición, evidencia y decisión

| Fuente oficial | Requisito / condición | Evidencia mínima para live | Decide |
| --- | --- | --- | --- |
| [Ley 1493 de 2011, MinCultura](https://normograma.mincultura.gov.co/compilacion/docs/ley_1493_2011.htm) y [PULEP Productores](https://pulep.mincultura.gov.co/Paginas/productores.aspx) | Determinar si cada fiesta es espectáculo público de artes escénicas y si BRVTAL es productor permanente u ocasional. Si aplica, inscripción, reportes y obligaciones del productor. No confundir organizador con prestador técnico. | Concepto por tipo de evento/municipio, responsable contractual, registro PULEP o sustento de no aplicación. | Titular + abogado/autoridad cultural. |
| [PULEP Operadores](https://pulep.mincultura.gov.co/PaginasHtml/operadores.html) | Si BRVTAL vende boletas para un productor tercero, estudiar autorización de operador, contrato, retenciones, declaraciones y reportes; no presumir equivalencia entre productor y operador. | Contrato productor-operador, registro/autorización aplicable y matriz de responsabilidad. | Titular + asesor legal/tributario. |
| [MinCultura, contribución](https://mng.mincultura.gov.co/areas/artes/ley-de-espect%C3%A1culos-publicos/declaracion-pago-contribucion/Paginas/default.aspx) | Para espectáculos sujetos a Ley 1493, contribución parafiscal del 10% de boletas/derechos de asistencia cuyo precio individual sea igual o superior a **3 UVT**. Confirmar UVT de la vigencia, cortesías y responsables; no generalizar a cualquier evento. | Clasificación fiscal del evento, cálculo según UVT, declaración y pago cuando proceda. | Contador + productor. |
| [DIAN, documento equivalente](https://micrositios.dian.gov.co/sistema-de-facturacion-electronica/documento-equivalente-electronico/) y [Concepto DIAN 1661/2025](https://normograma.dian.gov.co/dian/compilacion/docs/oficio_dian_1661_2025.htm) | Para obligado a facturar, analizar factura electrónica o documento equivalente válido para espectáculos. La excepción a **implementación electrónica** cuando se reporta a otras entidades **no elimina automáticamente la obligación de expedir el documento exigible**. QR comercial no basta. | Concepto contador sobre sujeto/documento, reportes estatales, habilitación DIAN y ejemplo válido. | Contador + titular. |
| [DIAN, Resolución 165/2023 y normas compiladas](https://normograma.dian.gov.co/dian/compilacion/docs/resolucion_dian_0165_2023.htm) | Confirmar datos obligatorios, emisor, numeración, contingencias, impuestos y vigencia aplicable de sistema fiscal. El sistema QR no se declara homologado ni se usa para suplir la solución DIAN. | Procedimiento de emisión y proveedor/solución autorizados por responsable fiscal. | Contador + titular. |
| [SIC, Resolución 7749/2025 sobre boletería](https://sedeelectronica.sic.gov.co/sites/default/files/publicaciones/R7749%20de%202025.pdf), Ley 1480 arts. 11, 42, 43 y 47 | Informar evento, lugar, fecha, precio total, proveedor, atención, retracto aplicable, reprogramaciones, cancelaciones y devoluciones. **Prohibido asumir que “no hay devoluciones” es válido**: SIC examinó cláusulas abusivas ante retracto y cambios de fecha/lugar. | Términos y condiciones revisados, versión de aceptación, canal PQR, flujo de devoluciones. | Abogado consumo + titular. |
| [SIC, reversión de pago](https://sedeelectronica.sic.gov.co/atencion-y-servicios-a-la-ciudadania/glosario/reversion-del-pago) | Tratar fraude, operación no solicitada y prestación incumplida según supuestos legales; reversión del instrumento electrónico y reembolso comercial son procesos diferentes. | Procedimiento por causa/plazo, responsable de conciliación y trazabilidad de reclamos. | Legal + operaciones. |
| [Ley 1581/2012](https://sedeelectronica.sic.gov.co/sites/default/files/normatividad/Ley_1581_2012.pdf) y [SIC, políticas de tratamiento](https://sedeelectronica.sic.gov.co/publicaciones/boletin-juridico/concepto/politicas-de-tratamiento-de-datos-personales) | Minimizar nombre, contacto, identidad, pago y logs; definir finalidades, base jurídica/autorizaciones, responsable/encargado, acceso, retención, supresión y terceros. Evaluar transmisión/transferencia internacional a Wompi/Resend y subencargados. | Mapa de datos/finalidad, aviso/política, acuerdos, procedimiento de derechos y plazo de conservación; actualizar datos.yml en el PR que introduzca tratamiento. | Responsable datos + abogado. |
| [SIC, consentimiento explícito](https://sedeelectronica.sic.gov.co/publicaciones/boletin-juridico/boletin/el-silencio-las-casillas-premarcadas-por-defecto-y-la-inaccion-no-constituyen-el-consentimiento-conforme-con) | Separar mensajes necesarios de compra/acceso/soporte y publicidad; no inferir permiso de marketing por comprar boleta ni usar casilla preseleccionada. | Texto aprobado, finalidad y consentimiento auditable con versión/fecha; mecanismo de baja. | Privacidad + marketing + titular. |

**Incógnitas bloqueantes de go-live:** (a) rol e identidad tributaria del vendedor/productor, (b) sujeción a PULEP/contribución y permisos municipales, derechos de autor y seguros por evento, (c) documento tributario y sujeto obligado, (d) retracto/devolución/reprogramación aplicables, (e) tratamiento/transmisión internacional de datos. No resolver por inferencia; obtener concepto profesional antes de live.

## 3. Checklist Wompi: persona natural, cuenta y liquidación

- [ ] **Titular:** si se elige persona natural, confirmar cédula, RUT actualizado, identificación, actividad y obligaciones según contador; **declarar renta no determina por sí solo** IVA, facturación ni aptitud contractual. No publicar RUT, cuenta o documentos en GitHub. [Vinculación oficial](https://soporte.wompi.co/hc/es-419/articles/360020955173--C%C3%B3mo-es-el-proceso-de-vinculaci%C3%B3n-a-la-pasarela-de-pago).
- [ ] **Modalidad:** validar el producto de **recibir pagos** y su plan, no confundir con la API de pagos a terceros/dispersión. Verificar cuenta bancaria del mismo titular, condición de banco y antigüedad para **esa modalidad**; la [guía general Wompi](https://www.wompi.co/es/co/ayuda/como-crear-cuenta) describe condiciones específicas de persona natural, incluyendo cuenta Bancolombia de más de 30 días y primer abono diferido. Confirmar vigencia con Wompi.
- [ ] **Medios:** habilitación real de tarjeta, PSE, Nequi y otros depende de aprobación/comercio/plan; no prometerlos antes de confirmación.
- [ ] **Tarifas y conciliación:** recibir contrato/oferta privada con tarifas, IVA sobre comisión si procede, retenciones, topes, calendario de desembolso y reembolsos. [Planes Wompi](https://wompi.co/es/co/planes-tarifas/). Guardar **bruto cobrado, comisión, IVA de la comisión si aplica, retenciones, ajustes y neto abonado** como partidas separadas; conciliar con extractos. **Comisión asumida por BRVTAL, sin recargo al comprador. Neto abonado no es utilidad.**
- [ ] **Pruebas:** llaves, callbacks y webhooks de sandbox separados de live; verificar firma, replay e idempotencia con fixtures sintéticos. Secretos únicamente en config privada/gestor seguro, nunca en issues/PR/logs. [Ambientes y llaves](https://docs.wompi.co/docs/colombia/ambientes-y-llaves/) y [webhooks](https://docs.wompi.co/docs/colombia/eventos/).
- [ ] **Activación:** solo con aprobación humana específica de alta y gasto, situación jurídica/fiscal resuelta, pruebas, antifraude, conciliación, backup/reversión y autorización separada de dinero real/go-live. Esta hoja no la concede.

## 4. Checklist Resend: DNS, DPA, mensajería

- [ ] Identificar titular de cuenta y dominio/subdominio remitente BRVTAL. Separar permisos y claves de entorno.
- [ ] Verificar dominio en Resend con **valores DNS proporcionados por el dashboard**, incluyendo DKIM, SPF/return-path/MX pertinentes; establecer DMARC alineado y revisar propagación sin crear registros inventados ni duplicar SPF. [Verified Domains](https://resend.com/docs/dashboard/domains/introduction), [guía entregabilidad](https://resend.com/guides/email/deliverability/why-emails-go-to-spam).
- [ ] Preparar correos de **prueba a destinatarios sintéticos/autorizados**, sin clientes reales ni reputación productiva. Separar templates, URL de webhook, credenciales y remitentes de live. [Pruebas Resend](https://resend.com/changelog/sending-test-emails).
- [ ] Evaluar [DPA](https://resend.com/legal/dpa) y [subprocesadores](https://resend.com/legal/subprocessors), residencia y transmisión internacional; la firma de DPA no reemplaza el análisis bajo Ley 1581. Registrar proveedor/datos en datos.yml cuando se integre.
- [ ] Separar transaccional (compra, QR, soporte) de promocional (consentimiento específico), minimizar contenido personal y definir retención de destinatarios, estados, errores, rebotes, entregas y auditoría; contemplar supresión y acceso.
- [ ] En futuros PRs: firma de webhooks, deduplicación, colas/reintentos idempotentes, secretos server-side y evidencia de entregabilidad antes de activar clientes.

## 5. Puerta humana antes de dinero real, datos y go-live

**Default seguro B: NO ACTIVAR**, continuar solo con preparación offline y datos sintéticos. Ningún voto se deduce de la aprobación de código/CI o de esta tabla; crear/usar el mecanismo real de puerta humana cuando exista evidencia.

| Puerta reservada al titular | Opción A (solo autorización expresa, por evento/proveedor y evidencia) | Opción B (predeterminada) |
| --- | --- | --- |
| Rol jurídico y permisos | Aprobar productor/operador/vendedor y requisitos PULEP/municipio acreditados por asesor | No ofertar/abrir checkout. |
| Fiscal y parafiscal | Aprobar documento DIAN, sujeto emisor, impuestos, retenciones y aporte, con concepto contador | No recaudar ni emitir soportes pretendidamente fiscales. |
| Consumidor | Aprobar T&C, retracto, cancelaciones, reprogramaciones y devoluciones verificadas | No publicar condiciones de venta. |
| Privacidad | Aprobar política, minimización, conservación y acuerdos Wompi/Resend con análisis internacional | No recoger/enviar datos reales. |
| Consentimiento comercial | Aprobar texto opt-in y trazabilidad de publicidad | Solo mensajes transaccionales de prueba; cero marketing. |
| Proveedores, gasto y live | Aprobar contratos/costos, claves productivas, controles, backups, pruebas y activación de cobros/envíos | Sandbox aislado y nada live. |

**Explicación simple para la puerta:** A permite el paso exacto que se apruebe *cuando* estén los documentos y pruebas; B deja el trabajo preparado sin vender boletas ni usar datos de compradores. No solicitar secretos en el Issue público. Si el mecanismo exige campos de decisión simples, proveer título, resumen, beneficios, riesgos, coste, reversibilidad y explicación sencilla de cada opción.

## 6. Evidencia de aceptación y reversión

| AC | Prueba documental | Check requerido | Producción |
| --- | --- | --- | --- |
| AC-01 | §2 distingue fuente, condición, evidencia y responsable, con incertidumbres y asesoría explícitas | Agregado **validate** del PR sobre HEAD definitivo | No autorizada/no evaluada |
| AC-02 | §1, §3–5 prohíben dinero, contratación, deploy, PII y secretos; guardan live para el titular | Agregado **validate** sobre HEAD definitivo | No autorizada/no evaluada |
| AC-03 | §1 y §3: comisión Wompi como gasto BRVTAL, sin recargo al comprador; conciliación bruto/descuentos/IVA/retenciones/neto | Agregado **validate** sobre HEAD definitivo | No autorizada/no evaluada |

**QA/segunda pasada legal-privacy:** comprobar casos negativos de operador tercero, espectáculo no sujeto, excepción fiscal condicionada, venta cancelada/reprogramada, sandbox contaminado, marketing sin consentimiento y retransmisión internacional; ninguno implica luz verde. Revisar finalidad, minimización, plazo y borrado, terceros y acceso. **No aplica** E2E UI/API/DB, móvil ni migraciones a este cambio exclusivamente documental. Las pruebas de negocio corresponden a futuros slices técnicos; no declararlas ejecutadas aquí.

**Riesgo/reversión:** documentación reversible por revert del commit, sin cambios a esquemas, aplicaciones, secretos, pagos ni despliegues. **Fuera de alcance:** editar SPEC/README por reflejo, implementar Wompi/Resend, cambiar datos.yml sin procesamiento nuevo, contratar proveedores o afirmar validación productiva.
