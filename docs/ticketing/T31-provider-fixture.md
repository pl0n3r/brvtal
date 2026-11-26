# T31: frontera HTTPS del proveedor Wompi (fixture sintético)

Esta hoja de infraestructura (#2021) prepara el recorrido de #2014, no certifica una compra, un cobro, una firma ni una transacción MariaDB real. **No debe desplegarse ni activarse en producción.**

## Ejecutar

En `BRVTAL CI` el job `real-stack` ejecuta obligatoriamente `bash tests/e2e/run-ticketing-provider-fixture.sh`, después del smoke PHP/MariaDB. Se exige el recibo literal `T31_PROVIDER_FIXTURE_PASS`; omitir el paso o devolver un skip es un fallo de aceptación. Localmente solo con Docker disponible y `BRVTAL_T31_FIXTURE_LOCAL=1`.

El script comprueba el daemon Docker y construye una imagen PHP CLI 8.5 + curl + Node **antes** de iniciar la prueba. Durante la prueba crea un contenedor `--network none` con solo loopback, sin red por defecto, proxy, socket Docker, DNS externo ni archivos fuente escribibles; `/etc/hosts` **dentro del contenedor** liga `sandbox.wompi.co` y `wrong-host.invalid` a loopback. No modifica hosts, CA ni DNS del equipo. Solo monta el repositorio en modo lectura y un directorio temporal descartable. El build inicial puede descargar dependencias del sistema; ese paso no es ejecución del fixture.

Cada ejecución genera una CA y certificado de vida corta en el directorio temporal. El PHP de prueba carga su CA solo con `-d curl.cainfo=...`; **CURLOPT_SSL_VERIFYPEER=true** y la validación del nombre TLS permanecen activas. Un servidor Node HTTPS en puerto 443 devuelve registros predefinidos por ID, independientemente de cualquier payload webhook. Otro listener HTTPS (4443) termina TLS y pasa al router descartable de PHP (8080) para comprobar métodos y Content-Type del endpoint real; no altera ningún endpoint de aplicación. La identidad sintética `prv_test_...` solo existe en esta prueba.

## Evidencia y negativos

Se ejecuta el PHP real `brvtal_wompi_hook_lookup` con respuesta aprobada, ID ausente, cuerpo inválido, HTTP 502, timeout, CA no confiable, hostname no válido, egress a IP reservada sin ruta y autorización ficticia errónea. Un registro de proveedor contradictorio se pasa al **verificador real** `brvtal_wompi_hook_apply`, que rechaza `WEBHOOK_PROVIDER_MISMATCH` **antes** de abrir BD; este ensayo no sustituye las pruebas T08/T31 de persistencia. El audit JSONL almacena exclusivamente método, ID y estado HTTP (no Authorization, claves, contenido firmado ni PII). No se llama a Wompi/Resend reales.

Fallas de Docker, TLS, rutas, pruebas PHP, logs o falta del recibo producen exit no-cero; no se usa `mock` ni TLS inseguro. `docker run --rm`, el trap del shell y `docker image rm` destruyen contenedor, credenciales sintéticas y artefactos temporales, incluso al fallar. Para revertir la integración, retirar el paso del job y estos tres archivos de fixture/documentación; no hay migración, tablas, secretos ni datos del host que restaurar.

## Reutilización

El issue #2014 deberá ejecutar su propio camino autenticado checkout→webhook firmado→BD→emisión/cuenta sobre PHP/MariaDB descartables, usando este mismo proveedor solo como frontera externa. Su evidencia y permisos son independientes; un CI verde aquí **no prueba** pagos, despliegue ni HEALTH de producción.
