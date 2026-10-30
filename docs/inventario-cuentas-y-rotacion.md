# BRVTAL — inventario de accesos y ensayo de rotación (#1353)

**Estado: NO_VERIFICABLE / DEL_DUEÑO.** Este inventario contiene únicamente categorías por comprobar, **no** un listado de cuentas o llaves existentes. Solo el dueño conoce y custodia el inventario auténtico fuera del repositorio. Ni el documento ni CI certifican la existencia de 2FA, respaldos, cuentas o una rotación de producción. No autoriza live, gasto, acceso a proveedores o cambios de producción.

## Registro sin valores, identidades ni rutas privadas

Cada fila debe completarse **solo en un registro privado bajo control del dueño**, nunca en este Markdown, GitHub Issues, PR, CI, capturas ni logs. Ese soporte privado incluirá roles, privilegios mínimos, titular, existencia de credenciales, custodia, 2FA y factores de recuperación, fecha UTC de última revisión y recibo saneado. `POR_COMPROBAR`, `NO_VERIFICADO` y `SIN_REVISAR` son estados pendientes, no evidencias de cumplimiento.

| Sistema a comprobar | Clase de acceso | Existencia | 2FA | Custodia / ubicación de evidencia | Última revisión | Responsable |
| --- | --- | --- | --- | --- | --- | --- |
| GitHub / repositorios y CI | Administradores y credenciales de automatización | POR_COMPROBAR | NO_VERIFICADO | Registro privado bajo custodia del dueño; ubicación pendiente de confirmar | SIN_REVISAR | DUEÑO |
| Hostinger / panel de hosting | Panel y roles delegados | POR_COMPROBAR | NO_VERIFICADO | Registro privado bajo custodia del dueño; ubicación pendiente de confirmar | SIN_REVISAR | DUEÑO |
| Publicación / acceso SFTP o SSH | Llaves y accesos de despliegue | POR_COMPROBAR | NO_VERIFICADO | Registro privado bajo custodia del dueño; ubicación pendiente de confirmar | SIN_REVISAR | DUEÑO |
| MariaDB / base de datos | Cuentas de aplicación, mantenimiento y backup | POR_COMPROBAR | NO_VERIFICADO | Registro privado bajo custodia del dueño; ubicación pendiente de confirmar | SIN_REVISAR | DUEÑO |
| Correo / servicio SMTP | Cuentas y claves de envío | POR_COMPROBAR | NO_VERIFICADO | Registro privado bajo custodia del dueño; ubicación pendiente de confirmar | SIN_REVISAR | DUEÑO |
| Dominio / registrador y DNS | Roles de gestión y recuperación | POR_COMPROBAR | NO_VERIFICADO | Registro privado bajo custodia del dueño; ubicación pendiente de confirmar | SIN_REVISAR | DUEÑO |
| TLS / certificados | Titulares y claves de renovación cuando corresponda | POR_COMPROBAR | NO_VERIFICADO | Registro privado bajo custodia del dueño; ubicación pendiente de confirmar | SIN_REVISAR | DUEÑO |
| Traducción / DeepL | Accesos y credenciales si existe integración | POR_COMPROBAR | NO_VERIFICADO | Registro privado bajo custodia del dueño; ubicación pendiente de confirmar | SIN_REVISAR | DUEÑO |
| Analítica / medición e indexación | Roles y claves si aplican | POR_COMPROBAR | NO_VERIFICADO | Registro privado bajo custodia del dueño; ubicación pendiente de confirmar | SIN_REVISAR | DUEÑO |
| Integración de orquestación / ControlBot | Accesos de servicio si están habilitados | POR_COMPROBAR | NO_VERIFICADO | Registro privado bajo custodia del dueño; ubicación pendiente de confirmar | SIN_REVISAR | DUEÑO |

La matriz no contiene identificadores reales, usuarios, correos, fragmentos o hashes de llaves, rutas privadas ni fechas ficticias. Los servicios que no soporten 2FA se documentarán solo en el inventario privado, junto con controles compensatorios y aprobación explícita del dueño. La revisión y el vencimiento se registran únicamente después de comprobarlos realmente.

## Ensayo aislado de rotación (claves ficticias)

La simulación utiliza un proveedor falso y un archivo privado **simulado dentro de un directorio temporal desechable**. No lee ni escribe cuentas, credenciales, archivos privados existentes, configuración de Hostinger, bases de datos ni redes. En esta **simulación**, los pasos se ejecutan en orden, que no determina el orden de producción:

1. **Revocar:** invalidar el marcador anterior en el proveedor ficticio y comprobar que se rechaza.
2. **Generar:** obtener en memoria otro marcador sintético, distinto del anterior, sin ninguna API de un proveedor.
3. **Actualizar archivo privado:** escribir el marcador nuevo en un archivo temporal aislado, restringir permisos y evitar imprimir su contenido.
4. **Verificar:** comprobar la aceptación exclusiva del marcador nuevo, la denegación del anterior y la limpieza automática del directorio temporal.

El resultado `ENSAYO_SINTETICO` prueba el contrato offline, **no** una rotación real, un 2FA operativo ni la custodia privada. No utilizar nunca marcadores ficticios como credenciales.

## Procedimiento eventual de live, reservado al dueño

Antes de rotar realmente, el dueño confirma permisos, servicio, scopes, estado de 2FA, dependencia, ventana de cambio, rollback y recibos privados. Para preservar disponibilidad, si el proveedor permite solapamiento se **genera primero la credencial nueva**, se actualiza el almacén privado y consumidores autorizados, se verifica su funcionamiento, y **solo entonces se revoca la anterior**. Si se exige revocación previa, el dueño evalúa la interrupción y el procedimiento de recuperación antes de autorizarla. Evidencia saneada, nunca valores, identificadores de personas, logs ni fragmentos de claves.

Ante evidencia de custodia ausente, permiso desconocido, fallo de 2FA, reversión imposible o falta de autorización: `NO_VERIFICABLE` y detener. Este PR no actúa en producción ni activa live.

## Validación local y reversión

~~~bash
python3 -m unittest -v tests.test_inventario_cuentas
python3 -m unittest -v tests.test_inventario_cuentas.InventarioCuentasTests.test_criterio_principal
~~~

La prueba comprueba existencia del documento, cobertura de categorías, estados pendientes, ausencia de formatos comunes de claves y el recorrido revocar → generar → actualizar → verificar con marcadores falsos. Solo se modifican este Markdown y `tests/test_inventario_cuentas.py`. Revertir el squash elimina ambos sin modificar cuentas o archivos privados.
