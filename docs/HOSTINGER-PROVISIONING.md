# Aprovisionamiento seguro BRVTAL en Hostinger

**Entorno actual: construcción.** Este procedimiento permite configurar **únicamente Wompi sandbox**, sin procesar dinero real ni autorizar salida a producción de taquilla. No subas claves a GitHub, Issues, PR, capturas de pantalla ni logs.

## 1. Archivo privado de configuración

1. En **Hostinger hPanel → Sitios web → Administrar → Administrador de archivos** abre la instalación que sirve `www.brvtal.com.co`. El directorio suele ser `domains/brvtal.com.co/public_html`; verifica la ruta exacta de tu cuenta.
2. Entra a `config/config.php` **en el servidor**. Haz una copia privada antes de editar. Si no existe, parte de `config/config.example.php` pero reemplaza DB y seguridad usando tus datos existentes.
3. Mantén las secciones existentes `app`, `db`, `security`, `contact` y demás. Agrega las secciones `ticketing`, `mail`, `analytics`, y en `observability` la clave `sentry_dsn` según el ejemplo versionado. **No crees un segundo `return`**: todo pertenece al mismo arreglo PHP.
4. Introduce las credenciales solamente en el editor privado de Hostinger; revisa permisos del archivo (solo propietario/proceso PHP), y comprueba que `https://www.brvtal.com.co/config/config.php` no muestra contenido PHP ni valores. Si descarga el archivo o muestra texto fuente, **detente y corrige la exposición antes de guardar secretos**.
5. Las claves no vacías en `config/config.php` tienen prioridad sobre las variables de entorno `BRVTAL_*`. Los campos vacíos conservan el fallback de entorno. Reinicia o recarga PHP si el hosting mantiene caché de opcode.
6. Abre **DISCADMIN → System status → Integraciones**. «configurado» solo significa que las claves requeridas están presentes, no que el proveedor esté activo o aprobado. Nunca se revelan valores. «falta» indica campos pendientes.

## 2. Wompi solo pruebas

En el panel de **Wompi**, selecciona explícitamente el ambiente de pruebas. Copia las claves `pub_test_...` y `prv_test_...` en `ticketing.wompi.public_key` y `private_key`. Copia **por separado** el secreto de integridad (`integrity_secret`) y el secreto de eventos (`events_secret`): no son intercambiables y este proyecto valida sus formatos de prueba. Configura:

```php
'ticketing' => [
  'wompi' => [
    'mode' => 'sandbox',
    'sandbox_enabled' => '1',
    'public_key' => 'PEGAR_EN_HOSTINGER',
    'private_key' => 'PEGAR_EN_HOSTINGER',
    'integrity_secret' => 'PEGAR_EN_HOSTINGER',
    'events_secret' => 'PEGAR_EN_HOSTINGER',
    'enabled_methods' => 'CARD,NEQUI,PSE', // solo métodos habilitados en tu panel de pruebas
    'return_url' => 'https://www.brvtal.com.co/', // HTTPS existente; no confirma pago
  ],
],
```

En el panel de Wompi configura la URL de **eventos/webhook**:
`https://www.brvtal.com.co/api/ticketing/webhook-wompi.php`.

La **URL de retorno** debe coincidir exactamente con `ticketing.wompi.return_url`. Un retorno del navegador **no** confirma el pago. Solo el webhook autenticado, la verificación del proveedor y las transacciones idempotentes pueden cambiar el pedido a pagado. Comprueba en una compra sintética de sandbox el intento de pago y el evento de pago firmado. No ingreses datos bancarios reales ni activas claves `pub_prod_`/`prv_prod_`: el código rechaza modo `live`.

## 3. Resend, SMTP, GA4 y Sentry

- **Resend:** configura `ticketing.mail.api_key`, `domain` y `from` tras verificar el dominio y SPF/DKIM en Resend. En el panel **Webhooks**, registra `https://www.brvtal.com.co/api/ticketing/webhook-resend.php` y guarda su secreto `whsec_...` en `ticketing.mail.webhook_secret`. Los eventos de entrega y fallo se verifican criptográficamente; antes de habilitar `mode=live`, `allow_live=1`, `privacy_approved=1`, `dns_verified=1` y `webhook_enabled=1`, revisa las puertas legales/privacidad de #1706. **Deja modo `disabled` mientras no haya autorización.**
- **SMTP de DISCADMIN:** en `mail.smtp` introduce `host`, `port`, `user`, `password`, `encryption` (`tls`/`ssl`), `from` y `from_name`. Comprueba que el remitente está autorizado por el proveedor; prueba un correo de recuperación a una cuenta sintética/controlada. Nunca imprimas contraseñas.
- **GA4:** en `analytics.ga4` usa `property_id`, `client_email` y `private_key` de una cuenta de servicio autorizada con permiso de lectura a esa propiedad. Conserva cabecera/pie de clave PEM y saltos de línea. El indicador solo señala presencia de configuración, no prueba acceso a Google Analytics.
- **Sentry:** en `observability.sentry_dsn` pega el DSN del proyecto habilitado, sin parámetros adicionales en URL. El indicador solo señala presencia, no prueba llegada de eventos.

## 4. Tareas cron de Hostinger

En **hPanel → Avanzado → Cron Jobs**, consulta primero cuál ejecutable PHP CLI de la versión del sitio (8.5) proporciona Hostinger y cuál es la ruta absoluta real de tu cuenta. Usa estos dos comandos como plantilla, sustituyendo `USUARIO` y el ejecutable por los valores reales del panel:

```sh
# Cron 1 · cada minuto (*/1 * * * *)
/usr/bin/php -q /home/USUARIO/domains/brvtal.com.co/public_html/ops/ticketing-mail-worker.php >> /home/USUARIO/domains/brvtal.com.co/public_html/storage/logs/ticketing-mail-worker.log 2>&1

# Cron 2 · cada minuto (*/1 * * * *)
/usr/bin/php -q /home/USUARIO/domains/brvtal.com.co/public_html/ops/admin-password-mail-worker.php >> /home/USUARIO/domains/brvtal.com.co/public_html/storage/logs/admin-password-mail-worker.log 2>&1
```

Antes de habilitar, comprueba que `storage/logs` existe y es escribible por el cron, que el ejecutable es PHP CLI compatible y que los scripts y archivos de log no están expuestos públicamente. Usa la opción nativa de hPanel para elegir frecuencia **cada minuto**; si tu plan limita la frecuencia, selecciona la mínima permitida y registra el intervalo. Cada ejecución procesa como máximo un trabajo. Los scripts no deben invocarse por HTTP.

**Comprobación:** con colas vacías, ejecuta temporalmente cada comando desde una terminal CLI permitida por Hostinger o espera una ejecución real del cron. Comprueba que el archivo de log actualiza su fecha y contiene un estado no sensible (por ejemplo `idle`); ante exit code distinto de cero investiga en Hostinger, sin copiar correos ni credenciales. System status muestra la **fecha del último cambio de esos logs** o «sin ejecución registrada»: es observación del cron, no garantía de entrega. Si la cuenta no permite usar estos logs, el indicador seguirá sin evidencia y debes revisar el historial de cron en hPanel.

## 5. Caducidad de reservas de boletas (holds)

El código actual compara `ticket_inventory_holds.expires_at_utc` con la hora UTC al verificar cupos y antes del checkout. Los holds expirados dejan de ocupar inventario incluso si su fila sigue etiquetada como `active`; `brvtal_ticket_inventory_reconcile()` puede actualizar estados vencidos de forma acotada. **No es requisito crear un cron adicional para liberar capacidad.** Una fila pendiente de reconciliación no debe tomarse como una reserva vigente. El usuario deberá iniciar otra reserva cuando expire.

## 6. Validación antes de habilitar servicios

Confirma que DISCADMIN esté autenticado, que los indicadores sean exclusivamente `falta`, `configurado` o `modo sandbox`, y que la API `/api/admin-system-status.php` no devuelva valores ni para invitados (401/403). Ejecuta las suites de contrato PHP, stack desechable MariaDB y Playwright Chromium con datos **sintéticos**. Comprueba que Wompi sandbox crea una intención y solo el webhook verificado marca el pedido como pagado; Resend/SMTP no deben enviar producción sin autorización.

**Nunca** pegues claves de pago, claves SMTP, service-account PEM ni DSN en GitHub o registros. Si sospechas exposición, revócalas en cada proveedor antes de repetir la configuración.
