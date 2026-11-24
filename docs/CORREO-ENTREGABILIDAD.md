# Correos BRVTAL: nombre visible y entregabilidad (issue #1993)

Los correos transaccionales de Resend salen con nombre visible **BRVTAL** por defecto, correo de envío verificado y cabecera `Reply-To`. El soporte solo recibirá respuestas si configura una dirección real que controle.

## Configuración sin publicar DNS desde la aplicación

1. En **DISCADMIN → Settings → Integraciones → Resend**, guarda **Remitente** (correo verificado), **Nombre del remitente** (por ejemplo, `BRVTAL`) y **Responder a** (buzón auténtico de soporte).
2. Envía un **correo de prueba** y comprueba los encabezados recibidos: `From: BRVTAL <no-reply@brvtal.com.co>` y `Reply-To:` con la dirección configurada. Sin `Responder a`, el adaptador usa el propio correo remitente; evita dejar ese valor en un buzón `no-reply`.
3. El dueño administra el DNS: mantener DKIM de Resend, SPF del dominio de envío, y publicar DMARC con reportes `rua=mailto:` apuntando **a un buzón de reportes que exista**. El valor `p=none` permite empezar a medir antes de endurecer la política. No cambiar DNS automáticamente.
4. Calentar el dominio: empezar con volúmenes bajos de mensajes esperados, aumentar gradualmente y monitorear rebotes, quejas, tasas de aceptación y reputación de Outlook/Hotmail.
5. Pedir a los primeros compradores reales que, si el correo legítimo aparece en Correo no deseado, seleccionen **«No es correo no deseado»**. No manipular listas compradas ni enviar mensajes de prueba masivos.
6. Los mensajes de boletas, verificación de cuenta y restablecimiento de contraseña **no** incluyen `List-Unsubscribe`. Un recordatorio de marketing solo puede incluirlo si el envío proporciona una URL HTTPS de baja real de BRVTAL (no se inventa una ruta funcional).

## Verificación y reversión

- Confirmar en cabeceras reales el resultado SPF/DKIM/DMARC y que el proveedor de destino recibe `From` y `Reply-To`. La aceptación de Resend o el CI no garantizan bandeja de entrada.
- Si hay problemas, volver a guardar nombre/reply-to seguros o revertir el commit; la modificación no cambia credenciales API, modo de envío, DNS ni cuotas.
- Los secretos permanecen cifrados y la API de administración solo muestra el estado de guardado; no muestra las nuevas direcciones ni el valor del nombre.
