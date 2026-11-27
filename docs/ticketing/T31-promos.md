# T31: importe neto de cupón y emisión idempotente (#2131)

Solo pruebas sintéticas, sin modificar producto ni activar promociones. El runner canónico `tests/e2e/run-ticketing-real-stack.sh` ejecuta checkout y promo en contenedores independientes, sin egress, MariaDB descartable y TLS local. El escenario promo exige el recibo TAP exacto y `T31_SCENARIO_PASS: promo`; un skip no acredita cobertura. Ejecutar localmente con Docker y `BRVTAL_T31_FIXTURE_LOCAL=1`.

| Criterio | Evidencia esperada |
|---|---|
| AC-01 | Checkout público autenticado: bruto 456 COP, descuento 56, neto 400 (40 000 centavos). Se verifica firma del widget, línea, intento y redención persistidos; rechazo de importes del navegador y callback firmado de 45 600 centavos; callback neto real vía proveedor HTTPS, worker y cuenta con la misma orden, una redención y una credencial tras replay. |
| AC-02 | T23 MariaDB reutiliza fixtures existentes de límites, expiración exclusiva, regla pausada no agotada y ausencia de modo sintético. Los rechazos no aumentan cuota ni redenciones. |
| AC-03 | `real-stack`, `database`, `T23 MariaDB` y `validate` en HEAD exacto; logs T31 sanitizados y sin QR, cookies o payloads. |

No se cambia la política de liberación de cuota por abandono (#2038). La prueba de replay corresponde al intento pendiente original, no reabre una orden pagada. El gate jurídico #1671 sigue impidiendo activación real. Revertir este PR retira únicamente pruebas y documentación; no hay migración ni datos reales.
