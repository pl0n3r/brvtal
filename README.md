# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- Se convirtió **DISCADMIN / Settings** en un control plane tipado: GENERAL, SOCIAL & CONTACT, SEO, ANALYTICS & PRIVACY y ADVANCED, sin crear otra tabla ni otro backend de configuración.
- Los editores tipados reutilizan la tabla `settings` y `/settings`; al guardar fusionan sus campos con el JSON existente para conservar claves hermanas desconocidas.
- El JSON crudo queda como escape técnico dentro de ADVANCED. `appearance` se clasifica como compatibilidad legacy y Theme Studio queda como autoridad visual.
- **SEO** deja de tener dos dueños: los defaults canónicos del Home (`site_title`, `description`, `share_image`) viven en `settings.seo` y se aplican server-side. Los campos SEO de cada entidad siguen siendo autoridad de sus rutas.
- **Analytics** vive en `settings.analytics.ga4_id`, se lee server-side, nunca se añade al API público y mantiene `theme.analytics.google` como fallback no destructivo. El consentimiento de usuario continúa siendo obligatorio.
- Theme Studio mantiene BRAND / PALETTE / TYPE / NAVIGATION / EXPERIENCE / MANAGE como controles visuales principales y oculta el editor SEO duplicado.
- Theme Studio incorpora un **BRVTAL wordmark** configurable dentro de `theme.<slug>.branding.wordmark`. Acepta una ruta/URL pública de imagen, incluido SVG externo, sin inyectar SVG/HTML inline.
- El wordmark se aplica al header y loader solo después de cargar correctamente; ante ausencia/error conserva el texto `BRVTAL` como fallback.
- Los campos históricos de tema que no tienen consumidor real (`responsive`, flags FX avanzados, comportamiento de preloader, custom code, etc.) se preservan y se muestran como **PRESERVED / UNWIRED**, no como controles falsamente funcionales.
- El runtime vuelve a aplicar el tema después del pase legacy de `appearance`, por lo que Theme Studio conserva autoridad sobre la paleta. Después restaura SEO server-side desde las etiquetas canónicas para que un `theme.seo` histórico no pueda sobreescribirlo en cliente.
- Se documentó la propiedad de cada categoría en `docs/CONFIGURATION.md`.

## Archivos modificados en este deploy

- `README.md` — snapshot operativo exacto de este deploy.
- `config/public_analytics.php` — `settings.analytics` como autoridad con fallback legacy de tema.
- `config/public_seo.php` — defaults SEO canónicos del Home desde Settings.
- `config/public_settings.php` — lector server-side fail-safe de settings tipados.
- `discadmin/index.php` — carga los módulos nuevos del control plane.
- `discadmin/settings-v2.css` — layout responsive de Settings tipados.
- `discadmin/settings-v2.js` — editores General, Social, SEO, Analytics y Advanced.
- `discadmin/theme-studio-configuration.css` — estilos del wordmark y mapa de compatibilidad.
- `discadmin/theme-studio-configuration.js` — wordmark, ownership map y SEO duplicado fuera de Theme Studio.
- `docs/CONFIGURATION.md` — mapa durable de propiedad y compatibilidad de configuración.
- `index.php` — aplica defaults SEO server-side al documento público.
- `js/public-runtime-loader.js` — carga resiliente de los módulos públicos nuevos.
- `js/public-settings-harmony.js` — conserva autoridad visual del tema y SEO del servidor.
- `js/public-theme-wordmark.js` — aplica wordmark con fallback textual seguro.
- `tests/e2e/configuration-runtime.spec.mjs` — wordmark/fallback y autoridad runtime.
- `tests/e2e/discadmin-settings-v2.spec.mjs` — Settings desktop/mobile y preservación de JSON.
- `tests/settings-control-plane-contract.php` — contratos de SEO/Analytics, privacidad, persistencia y wordmark.

## Validación

- Base exacta: `main` `868c1b39b06845dcfd7c8289c9dab7efd11f8b0f`.
- La base quedó con `BRVTAL CI / validate` verde en el run #590.
- Issue cubierto: `#400`.
- No hay migración ni cambio de schema. La tabla `settings`, el endpoint `/settings`, Theme Studio y Media existentes se reutilizan.
- `api/public.php` no expone `settings.analytics` ni el setting SEO canónico; las credenciales/IDs de integración permanecen fuera del payload público.
- El allowlist genérico de uploads no se amplía a SVG sin sanitización. El wordmark puede usar un SVG ya servido por una ruta/URL pública segura.
- La especificación i18n de #212 sigue **NOT IMPLEMENTED**; los locales guardados se muestran solo como contexto y no como control prometido.
- Run #591: PHP 8.5/contratos y sintaxis JS verdes; el primer intento falló solo porque el snapshot agrupaba varios paths en una viñeta. Se corrigió la lista exacta sin cambiar lógica.
- Pendiente en este snapshot: nuevo `BRVTAL CI / validate`, revisión automática y cualquier corrección válida sobre el head corregido.
- CI verde significará **VALIDATED IN CODE**. No se declarará **VALIDATED IN PRODUCTION** sin comprobar el deploy real.

## Qué sigue

1. Resolver en esta misma rama cualquier fallo o finding válido de BRVTAL CI/revisión automática.
2. Hacer squash merge solo con `BRVTAL CI / validate` verde.
3. Verificar `BRVTAL CI / validate` del SHA exacto resultante en `main`.
4. Verificar el control plane en el deploy real y, cuando corresponda, continuar las siguientes fases de #398 sin reestructurar el backend existente.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de validación: `docs/TESTING.md`.
- Arquitectura de configuración: `docs/CONFIGURATION.md`.
- Visión pública: `#398`.
- Issue abordado: `#400`.
