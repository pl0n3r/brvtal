# T33 · Evaluación post-MVP: Wallet, pases PDF, fidelización y merchandising

> Epic #1706 · Issue #1740 · 21-nov-2026 · **NO_LIVE / NO_GO**. Informe de alternativas, no autorización, implementación, compra, despliegue ni alta de proveedor.

## Alcance y evidencia

La fase prioritaria es finalizar el MVP de venta Wompi, gestión de pedidos e inventario y emisión/validación de QR, sin competir con esos entregables. Las dependencias #1721 (cuenta del comprador) y #1727 (finanzas y conciliación) están cerradas; ese estado de GitHub **no acredita** cobros, entregas, migraciones o funcionamiento en producción. Fuente del análisis: contratos de `docs/BRVTAL-SPEC.md`, T01 y T02, y la arquitectura PHP 8.5 / MariaDB / DISCADMIN; no se consultaron datos de compradores ni proveedores mediante credenciales.

**Criterio de comparación:** valor y esfuerzo son estimaciones cualitativas (alto/medio/bajo), no métricas observadas ni compromisos de precio. Toda opción requiere decisión separada del propietario, hoja V3 propia, pruebas, revisión legal/privacidad y puertas productivas antes de cualquier implementación. **Sin integraciones habilitadas.**

## Matriz valor / esfuerzo y riesgos

| Opción | Valor | Esfuerzo | Dependencias | Riesgos de seguridad / operación | Recomendación | Fase |
| --- | --- | --- | --- | --- | --- | --- |
| Apple Wallet | Medio | Alto | QR emitido y revocable T09, cuenta T14; Apple Developer y Pass Type ID/certificado de firma; diseño de actualizaciones | Filtración/reutilización de pase, rotación de certificados, QR obsoleto, requisitos externos | Aplazar; evaluar un prototipo aislado solo tras MVP | P6 post-MVP |
| Google Wallet | Medio | Alto | QR T09, cuenta T14; issuer Google Wallet, cuenta de servicio y JWT firmado; alta y revisión del emisor | Claves sensibles, objetos desactualizados, permisos y dependencia API de tercero | Aplazar; solicitar evaluación de seguridad y costes tras MVP | P6 post-MVP |
| Pase PDF | Alto | Medio | QR T09, titular autenticado T14, reemisión/revocación y verificación de acceso; salida PHP/HTML imprimible | Compartir archivo equivale a portar credencial; duplicación, caché, pérdida de dispositivo y acceso sin conexión | Priorizar **análisis** de PDF descargable con QR canónico, no habilitar emisión todavía | P6 post-MVP |
| Fidelización | Medio | Medio | Pedidos pagados y conciliados T14/T17; reglas de identidad, consentimiento independiente, retención y antifraude | Perfilado de compradores, abuso de descuentos, errores de atribución, privacidad y contabilidad | Aplazar; estudiar reglas mínimas de recompensa después de métricas reales | P6 post-MVP |
| Entrada + merchandising | Medio | Alto | Inventario T05, órdenes Wompi y finanzas T17; SKU/stock separado, entrega, impuestos y devoluciones parciales | Sobreventa, inconsistencias entre stock físico y aforo, reversos parciales y obligaciones fiscales | Aplazar; diseñar contrato de stock/fulfillment independiente de los QR | P6 post-MVP |

## Alternativas compatibles con la arquitectura

- **Pase PDF:** el candidato de menor dependencia externa es presentar/descargar una vista imprimible generada por PHP desde una sesión de comprador ya autenticada, reutilizando la autoridad de emisión y revocación del QR. El PDF no debe ser un nuevo emisor de credenciales ni convertir un hash persistido en token recuperable. Deben definirse autorización, expiración, `no-store`, revocación tras reemisión y experiencia sin conexión antes de programarlo. La descarga no está implementada aquí.
- **Apple Wallet:** pases `.pkpass` firmados; exige Pass Type ID/certificado de firma y mantenimiento del ciclo de vida. El payload debe derivar del mismo registro canónico de la entrada; Wallet no sustituye la consulta de revocación ni el escaneo de acceso. Referencias oficiales: https://developer.apple.com/documentation/walletpasses/building-a-pass y https://developer.apple.com/help/account/capabilities/create-wallet-identifiers-and-certificates.
- **Google Wallet:** evaluar el tipo *Event ticket* y el uso de issuer y objetos/clases firmados con JWT; el registro del emisor y las credenciales de cuenta de servicio son nuevos límites de confianza. No autoriza enlazar cuentas ni dar permisos. Referencias oficiales: https://developers.google.com/wallet/tickets/events/use-cases/jwt y https://developers.google.com/wallet/generic/getting-started/issuer-onboarding.
- **Fidelización:** proponer reglas puras sobre compras verificadas y no sobre clics o registros; no mandar mensajes sin consentimiento específico. Evitar identificadores externos o compartir perfiles hasta aprobación jurídica. Analizar fraude, compras reembolsadas y borrado/retención antes de crear tablas.
- **Merchandising:** separar `SKU` y stock físico de tipos de entrada y aforo; la conciliación Wompi no sustituye la logística, devoluciones parciales ni tributación. Un paquete debe reservar ambos inventarios de manera coherente y fallar cerrado ante agotamiento; no modificar flujos Wompi del MVP para este estudio.

## Puertas y protección del MVP

1. **Prioridad absoluta:** Wompi/QR/checkout, inventario y compradores del MVP con pruebas de concurrencia, idempotencia, antifraude y revisión legal. No consumir sus claims, no crear UI, API ni migraciones en esta hoja.
2. **NO_LIVE / NO_GO:** sin proveedor nuevo, WooCommerce, plugin nativo, certificados, credenciales, cuentas de servicio, endpoints, mensajes, pagos, compras, despliegues, datos reales o activación de funciones. Ni un pase PDF ni uno de Wallet otorgan acceso por sí solos.
3. **Autoridad de entrada:** QR único, estado `paid` verificado, titularidad, expiración, revocación y check-in atómico deben seguir siendo la fuente de verdad; no emitir tokens alternativos ni almacenar el secreto QR en claro. Los incidentes de migración y producción del MVP permanecen sujetos a sus propias puertas.
4. **Privacidad y Colombia:** revisar tratamiento de datos, SIC, obligaciones fiscales DIAN/PULEP según contexto, finalidad/retención, avisos y política de devoluciones con responsables jurídicos antes de habilitar cualquier opción.
5. **No equivalencia:** merge o CI `validate` verde acredita código/documentos en el SHA exacto, no `VALIDATED_IN_PRODUCTION`, aprobación comercial, envío a proveedores, cobro real ni autorización de go-live.

## Decisiones futuras y backlog separado

**DECISION_PENDIENTE:** el propietario puede seleccionar una o ninguna de las alternativas después de aceptar el MVP y verificar demanda y costes. Ninguna se declara aprobada por esta matriz.

| Hoja futura propuesta (no creada) | Precondición mínima | Evidencia de aceptación a exigir |
| --- | --- | --- |
| T33-PDF, vista imprimible | QR/seguridad del comprador y revocación en producción verificados | Descarga autenticada, PDF no cacheable, token revocable, pruebas de fuga/doble acceso |
| T33-APPLE, pase firmado | Certificado, firma y tratamiento de cambios aprobados | Prueba de firma y actualización, rechazo de pase revocado, sin secreto en logs |
| T33-GOOGLE, pase de evento | Issuer/API/cuenta de servicio y permisos aprobados | Firma verificable, revisión de scopes, rotación y revocación sin PII residual |
| T33-LOYALTY, reglas opt-in | Consentimiento legal, métricas de compradores y antifraude | Casos de reversión/reembolso, transparencia, eliminación/retención verificadas |
| T33-MERCH, stock independiente | Contrato de SKU, contabilidad e inventario físico | Reserva atómica del bundle, reverso parcial, stock y aforo coherentes |

Cada opción **aprobada explícitamente** requiere *después* un Issue leaf distinto con su propio `factory-plan-task`, aceptación ejecutable, claims V3 y pruebas. No crear hojas ni reservas especulativas durante T33. La única salida de esta hoja es este documento y su test determinista; ninguna decisión, proveedor o activación está tomada.
