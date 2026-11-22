# Evidencia de QA visual · BRVTAL #1862

**Origen y límites:** capturas locales de referencia generadas en Chromium en este ciclo, con fixtures **sintéticos** de la composición Cromo. No son capturas del servidor público de producción ni una aprobación de política jurídica. El render real queda cubierto por `tests/public-legal-placeholders-contract.php` y `tests/test_privacy_rights_page.py`.

## Matriz antes/después

| Viewport | Antes (fixture sintético) | Después (fixture sintético) | Lectura |
| --- | --- | --- | --- |
| 390 × 900 | before-390.webp | after-390.webp | Sin desbordamiento horizontal; información legal no resuelta se omite; banner da opciones Rechazar/Aceptar |
| 1440 × 900 | before-1440.webp | after-1440.webp | Jerarquía Cromo conservada: cabecera, título, apartados y pie; nota de contacto visible |

## Checklist de revisión (modo Read)
- [x] Copy visible: se retiran `[COMPLETAR POR EL DUEÑO]` y el estado interno «Texto legal pendiente de aprobación profesional» de la propuesta; el backend no inventa una identidad ni plazos.
- [x] Ruta alternativa: faltantes llevan a `/contact`, sin enlazar correo inválido.
- [x] Estructura: jerarquía `h1`/`h2` conservada, sin filas sin valor; `noindex,follow` intacto.
- [x] Cromo: negro/hueso/rojo; tipografía/display y superficies existentes, sin otra librería ni animaciones.
- [x] Móvil: comprobación local sin overflow a 390px y escritorio 1440px.
- [x] Cookies: Rechazar y Aceptar con prominencia equivalente, 48px de alto; la aceptación opcional sigue siendo voluntaria.
- [x] Accesibilidad: enlaces reales, foco visible y reduced-motion heredados. Prueba contractual PHP, Python y browser CI son la fuente final del comportamiento.
- [ ] Producción: no validada; no ejecutar deploy ni tratar fixtures como observación productiva.

**Procedencia:** Issue https://github.com/pl0n3r/brvtal/issues/1862 · PR https://github.com/pl0n3r/brvtal/pull/1878. Los archivos de esta carpeta no contienen datos reales ni secretos.
