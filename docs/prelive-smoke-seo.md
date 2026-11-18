# BRVTAL #1044: protocolo pre-live de smoke/SEO

**Estado: diseño y pruebas sintéticas, NO VALIDATED_IN_PRODUCTION ni autorización de go-live.** Este trabajo es offline, sin red, sin escrituras a proveedores, sin credenciales y sin publicación a buscadores. Los estados sin observación real son UNKNOWN / NO-GO.

## Matriz fail-closed: DNS, TLS, canonical, robots.txt, sitemap.xml, ES/EN e indexability

Origen canónico del código actual: https://www.brvtal.com.co. Toda evidencia futura debe acreditar SHA exacto de main, versión publicada y UTC de observación. El fixture synthetic-event es ficticio y no demuestra que exista un evento publicado.

| Superficie | URL / comprobación futura | Requisito de evidencia | Falta o error |
| --- | --- | --- | --- |
| DNS | www.brvtal.com.co | respuesta A/AAAA/CNAME comparada con destino aprobado | UNKNOWN / NO-GO |
| TLS | https://www.brvtal.com.co | SAN, cadena, vigencia y nombre, sin material sensible | UNKNOWN / NO-GO |
| Home ES | / | 200, canonical propio, indexable, hreflang es/en/x-default | UNKNOWN / NO-GO |
| Home EN | /en | 200, canonical propio, indexable, hreflang recíproco | UNKNOWN / NO-GO |
| Evento ES/EN | /events/{slug}, /en/events/{slug} | solo publicado, canonical local y alternates | UNKNOWN / NO-GO |
| robots.txt | /robots.txt | 200, Allow público, Disallow /discadmin/, /api/, /config/, /database/, /storage/ | UNKNOWN / NO-GO |
| sitemap.xml | /sitemap.xml | XML válido, loc absolutas canónicas, variantes ES/EN, sin URLs privadas | UNKNOWN / NO-GO |
| indexability | respuesta pública, robots y X-Robots-Tag | ausencia de noindex, canonical y comprobación de Search Console autorizada | UNKNOWN / NO-GO |
| smoke autenticado | identidad sintética únicamente en tests | lectura aislada autorizada en un futuro corte, sin exponer tokens | UNKNOWN / NO-GO |

El archivo robots.txt ya apunta a https://www.brvtal.com.co/sitemap.xml. El código sitemap.php y config/public_sitemap.php demuestra una implementación, no su disponibilidad productiva. Cualquier redirección no autorizada, hostname alterno, contenido de borrador, HTTP inseguro, URL con credenciales o parámetros de conversación mantiene el corte bloqueado. No inferir indexación real por tener sitemap y SEO estático.

## Protocolo para smoke público y autenticado

1. **Preflight sin red:** comprobar permisos y decisión humana sobre live, puertas legales, SHA de origen y SHA realmente desplegado; registrar versión, riesgo y rutas aprobadas. Ausencia de identidad exacta equivale a UNKNOWN.
2. **Simulación sintética:** generar evidencia sin red para DNS/TLS, home ES/EN, eventos ficticios ES/EN, robots, sitemap y sesión sintética. Los campos sha y version son de prueba, no un deploy real. Solo admitir modo synthetic-offline y auth.identity=synthetic con writes=0 y network_calls=0.
3. **Pruebas:** ejecutar python3 -m unittest -v tests.test_prelive_smoke_seo. Rechazar SHA inconsistente, versión desconocida, observación stale de más de 900 segundos, reloj futuro, redirección no autorizada, canonical fuera de origen, TLS vencido, campos sensibles y claves no esperadas.
4. **Observación futura autorizada:** solo un operador autorizado puede recoger una respuesta pública read-only y un smoke autenticado read-only con ambiente separado y consentimiento. Registrar origen final, status HTTP, DNS/TLS, canonical, hreflang y sitemap, y cotejar el SHA/version realmente servido. No hacer estas solicitudes en este Issue.
5. **Puerta de lanzamiento:** QA y seguridad comprueban hallazgos críticos, respaldos y resultados reales. Google/Search Console/IndexNow requieren autoridad propia; no enviar sitemaps ni solicitar indexación. Sin evidencia productiva: NO-GO, aun con CI verde.

## Privacidad, reversión y límites

No go-live. No secretos, cookies, contraseñas, emails, tokens, identificadores privados, chats, cabeceras Authorization, datos de terceros, DNS writes, hosting, gastos, deploy ni cuentas reales. Nunca guardar páginas autenticadas, parámetros de sesión o trazas sensibles. Recoger únicamente evidencia minimizada con timestamp y checksum una vez exista autorización independiente. La reversión de este PR consiste en squash-revert de documento y test: no hay migración ni efecto de runtime.
