# Prueba DeepL de una única página pública: preparación offline

**Estado:** solo build-ahead en fase `construccion`. Esta rama **NO** autoriza tráfico real a DeepL, cambio de entorno, despliegue, gastos ni datos personales. El español sigue siendo canónico.

## Autoridad y límites

- BRVTAL #212, decisión #1033/A: transporte preparado, OFF.
- BRVTAL #1055: voto OWNER `/decidir A`, journal `factory-human-decision` v2/A; autoriza preparar una hoja acotada, **no** el ensayo real ni datos, claves, proveedores, cargos o deploy.
- BRVTAL #1054: solo cinco rutas reclamadas y reserva V3 válida. El slug de una página `pages` necesita elección específica del propietario. Nunca artistas, biografías, perfiles, correos, teléfonos ni rich HTML.
- El runtime mantiene OFF los requisitos `BRVTAL_DEEPL_ENABLED`, `DEEPL_AUTH_KEY`, `BRVTAL_DEEPL_TRIAL_PAGE_SLUG` y el permiso operativo separado `BRVTAL_DEEPL_TRIAL_OPERATIONAL_PERMIT`. **No configurarlos en esta entrega.** Una variable de entorno, por sí sola, no es la prueba auténtica de cumplimiento.

## Comportamiento y seguridad

- Solo `/en/pages/<slug>` con coincidencia exacta del slug, entidad `published`, confianza y visibilidad públicas; fuera del alcance vuelve íntegro el contenido español.
- Se traducen únicamente los campos planos de texto editorial que ya expone la proyección existente. Los cuerpos HTML permanecen españoles. Rechazar correo, enlaces, HTML, cadenas largas de dígitos y contenido malformado antes de usar caché o transporte.
- Error de proveedor, cuota o caché: fallback español. El presupuesto usa el bloqueo exclusivo y archivo persistente del transporte existente, pero la compatibilidad/permiso del hosting aún requiere validación operativa; un fixture local no la acredita.
- La rama no añade claves ni solicitudes HTTP a los tests: `tests/deepl_one_page_trial_scenarios.php` inyecta un adaptador falso y `tests/test_deepl_one_page_trial.py` ejecuta el PHP real.

## Puertas pendientes antes de la prueba real y del merge

**No habilitar ni fusionar** solo porque `validate` o los tests offline resulten verdes. El propietario debe identificar el slug exacto, comprobar clasificación del contenido y consentimiento/términos/privacidad, confirmar presupuesto efectivo del plan, persistencia/locking en Hostinger, backup y restauración real, rollback y autorización operativa. Se exige evidencia auténtica D-043 `VALIDATED_IN_PRODUCTION` con lista de pendientes vacía y SHA consistente, más los controles de seguridad, CI y CodeRabbit FINAL exact-HEAD. No generar recibos sintéticos de producción. En ausencia de cualquiera de esos requisitos, permanecer apagado **sin permiso de operación**.

Reversión: dejar el permiso operativo en cero, conservar ruta original y no exponer traducción.
