# Cuotas gratuitas · observación no bloqueante

Issue BRVTAL #1354 · implementación de preparación fuente, sin activar DeepL ni usar servicios externos.

## Alcance

La sección **System Status** de DISCADMIN obtiene una tarjeta de solo lectura desde
`/discadmin/free-quotas.php`, protegida por la sesión administrativa. Muestra cuatro
recursos: DeepL Free, correo gratuito, copias gratuitas y hosting. Cada fila
expone usado/límite, fuente de la lectura, estado y hora de medición en
`America/Bogota` (reloj de 12 horas). Una medición ausente, inválida o
incompleta queda explícitamente en **UNKNOWN**; no se reemplaza por cero.

## Medidas, privacidad y límites

- **DeepL Free:** `storage/deepl-usage.json` es un **contador local de caracteres
  reservados**, no una consulta de saldo oficial a DeepL. Solo se muestra cuando
  contiene un registro válido del mes UTC actual. Su hora es la de modificación
  del registro. El límite local es `BRVTAL_DEEPL_MONTHLY_CHARS` o 300 000
  caracteres. El transporte existente
  `config/public_translation_deepl_transport.php` hace `reserveBudget()` bajo
  bloqueo exclusivo y **rechaza antes de invocar HTTP** si excedería ese límite;
  el adaptador ya cae al texto español original. No se activa un plan de pago.
- **Correo, copias y hosting:** no existe en la aplicación una fuente fiable de
  saldo del proveedor. Por defecto se muestran **UNKNOWN**. Solo se visualizan
  si el operador configuró una medición explícita con
  `BRVTAL_FREE_<MAIL|BACKUPS|HOSTING>_USED`, `_LIMIT` y `_MEASURED_AT`
  (ISO 8601). No se confunde el disco total del nodo Hostinger con la cuota del
  alojamiento; el panel de almacenamiento existente conserva su propio alcance.
- **Umbral:** `BRVTAL_FREE_QUOTA_WARNING_PERCENT`, entero 1..99; por defecto
  80 %. La tarjeta muestra `WARNING` o `EXHAUSTED`, **sin bloquear** los
  módulos del sitio. Solo DeepL mantiene su protección propia contra consumo.
- **Seguridad:** no se leen ni envían claves, correo personal, tokens o datos de
  clientes. El endpoint requiere sesión admin y usa `no-store`. No hay llamadas
  a DeepL, SMTP, Hostinger ni herramientas de copia para consultar estas cifras.

## Pruebas sintéticas

`python -m unittest tests.test_cuotas_gratuitas -v` cubre valores medidos,
`UNKNOWN`, umbral, reloj Bogotá y respuesta sin secretos; invoca casos PHP de
`tests/free_quotas_scenarios.php`. Ejecuta además el escenario de presupuesto
preexistente de DeepL con transporte HTTP inyectado, donde el tercer envío al
superar la cuota no realiza llamada de red.

## Autoridad pendiente

La prueba sintética y un merge de fuente **no certifican** saldo oficial de
DeepL, uso de Hostinger, disponibilidad SMTP, DPA, activación, deploy ni prueba
con credenciales. No hay autorización de gasto ni nueva acción de producción.
