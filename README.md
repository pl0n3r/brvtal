# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- El formulario completo de Contact deja de vivir dentro del Home: la navegación y el CTA públicos resuelven ahora a la ruta canónica `/contact`.
- Se añadió una página Contact server-rendered con hero BRVTAL, contexto para bookings/collaborations/events/media, formulario completo y bloque de redes sociales.
- La página reutiliza sin modificar el backend existente `/api/contact.php`: CAPTCHA firmado, trusted proxies, rate limiting fail-closed, validación, sanitización y transporte de correo conservan su frontera actual.
- Las redes continúan hidratándose desde `settings.social` del API público; no se duplicaron URLs sociales en el frontend.
- `/contact` recibe canonical/OG/Twitter/JSON-LD propios, entra al sitemap y tiene rewrite explícito para direct load/refresh en Hostinger/LiteSpeed.
- El runtime de Contact ya no transforma el footer del Home; solo se activa dentro de la página dedicada y la hidratación social se agenda al iniciar el runtime, sin depender de un evento `load` que pudiera haber ocurrido antes.
- Se añadió cobertura de contrato y Chromium para routing, SEO, seguridad heredada, hidratación social/CAPTCHA, teclado, targets táctiles, legibilidad móvil y ausencia de overflow horizontal.

## Archivos modificados en este deploy

- `README.md` — snapshot operativo exacto de este deploy.
- `.htaccess` — enruta `/contact` server-side a la entrega pública canónica.
- `config/public_contact_page.php` — renderer server-side y SEO específico de la página Contact.
- `css/contact-social.css` — superficie visual BRVTAL dedicada, formulario responsive, focus states y reduced-motion.
- `index.php` — entrega `/contact` y convierte navegación/CTA de Home hacia la nueva ruta sin incrustar el formulario.
- `js/public-contact.js` — limita la hidratación a Contact, elimina la carrera de inicialización social y conserva CAPTCHA, envío, errores/rate-limit y redes desde settings.
- `sitemap.php` — publica `https://www.brvtal.com.co/contact` en el sitemap.
- `tests/e2e/public-contact-page.spec.mjs` — regresión Chromium desktop/mobile, teclado, touch targets, CAPTCHA y redes configuradas.
- `tests/public-contact-contract.php` — mantiene la regresión de seguridad existente y añade contratos de routing, canonical, sitemap y separación Home/Contact.

## Validación

- Base exacta: `main` `c67cd9d21f4255db20187c14340bd3611c4a018d`, con `BRVTAL CI / validate` verde (run #578).
- Issue cubierto: `#387`.
- No hay migración, cambio de schema, restore, bulk delete ni mutación de datos de producción.
- `api/contact.php` y `config/public_contact.php` no se modificaron: las protecciones de CAPTCHA, rate limiting, trusted proxy y sanitización permanecen en su frontera existente.
- La ejecución local dirigida no pudo materializar el checkout porque el runtime auxiliar no tiene resolución de red hacia GitHub; no se declara validación local inexistente.
- El primer BRVTAL CI del PR (run #579) dejó `fast`, `database` y `real-stack` verdes y detectó una carrera en la hidratación social del nuevo smoke Chromium; la causa fue corregida en esta misma rama sin ampliar el file set.
- Pendiente en este snapshot: nuevo `BRVTAL CI / validate`, revisión CodeRabbit y análisis automático de SonarQube Cloud sobre el head final.
- CI verde significará **VALIDATED IN CODE**. No se declarará **VALIDATED IN PRODUCTION** sin comprobar el deploy real.

## Qué sigue

1. Resolver en esta misma rama cualquier fallo o finding válido de BRVTAL CI, CodeRabbit o SonarQube Cloud.
2. Hacer squash merge solo con `BRVTAL CI / validate` verde y revisión final limpia.
3. Verificar `BRVTAL CI / validate` del SHA exacto resultante en `main`.
4. Continuar con `#388` para exponer de forma segura la acción CLEAR/RESET LOG ya existente dentro de System Status, sin duplicar backend.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de validación: `docs/TESTING.md`.
- Issue abordado: `#387`.
