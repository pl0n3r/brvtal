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
- Los campos históricos de tema que no tienen consumidor real se preservan como **PRESERVED / UNWIRED**, no como controles falsamente funcionales.
- El runtime vuelve a aplicar el tema después del pase legacy de `appearance` y restaura después el SEO server-side, manteniendo una sola autoridad por categoría.
- Se documentó la propiedad de cada categoría en `docs/CONFIGURATION.md`.

## Archivos modificados en este deploy

**Diff funcional:** `16 archivos` · **+960** líneas · **−12** líneas *(sin contar README, porque este snapshot modifica su propio diff al actualizarse).*  
Leyenda: 🟢 nuevo · 🟡 modificado · `+ / −` líneas frente al `main` base de este deploy.

### DISCADMIN

- `discadmin/index.php` — 🟡 MOD · **+5 / −1** · carga Settings V2 y la extensión de configuración de Theme Studio.
- `discadmin/settings-v2.css` — 🟢 NEW · **+1 / −0** · layout responsive y visual del nuevo control plane.
- `discadmin/settings-v2.js` — 🟢 NEW · **+274 / −0** · General, Social, SEO, Analytics & Privacy y Advanced con persistencia tipada.
- `discadmin/theme-studio-configuration.css` — 🟢 NEW · **+1 / −0** · estilos de wordmark y mapa de compatibilidad.
- `discadmin/theme-studio-configuration.js` — 🟢 NEW · **+104 / −0** · wordmark configurable, ownership map y retiro del SEO duplicado como superficie principal.

### SERVER / PUBLIC DELIVERY

- `config/public_analytics.php` — 🟡 MOD · **+16 / −2** · `settings.analytics` pasa a ser autoridad con fallback legacy de tema.
- `config/public_seo.php` — 🟡 MOD · **+29 / −7** · defaults SEO canónicos del Home desde Settings.
- `config/public_settings.php` — 🟢 NEW · **+36 / −0** · lector server-side fail-safe de settings tipados.
- `index.php` — 🟡 MOD · **+2 / −1** · aplica los defaults SEO server-side al documento público.
- `js/public-runtime-loader.js` — 🟡 MOD · **+3 / −1** · carga resiliente de los nuevos módulos públicos.
- `js/public-settings-harmony.js` — 🟢 NEW · **+52 / −0** · mantiene Theme Studio como autoridad visual y restaura SEO del servidor.
- `js/public-theme-wordmark.js` — 🟢 NEW · **+87 / −0** · aplica el wordmark en header/loader con fallback textual seguro.

### DOCUMENTACIÓN / TESTS

- `docs/CONFIGURATION.md` — 🟢 NEW · **+104 / −0** · define propiedad, compatibilidad y límites de cada categoría configurable.
- `tests/e2e/configuration-runtime.spec.mjs` — 🟢 NEW · **+84 / −0** · cubre wordmark, fallback y autoridad del runtime.
- `tests/e2e/discadmin-settings-v2.spec.mjs` — 🟢 NEW · **+94 / −0** · cubre Settings desktop/mobile y preservación de JSON.
- `tests/settings-control-plane-contract.php` — 🟢 NEW · **+68 / −0** · contratos de SEO/Analytics, privacidad, persistencia y wordmark.

### SNAPSHOT

- `README.md` — 🟡 MOD · **AUTO** · este mismo snapshot; su conteo exacto se excluye para evitar una referencia circular al actualizar el diff.

## Validación

- Base exacta: `main` `868c1b39b06845dcfd7c8289c9dab7efd11f8b0f`.
- La base quedó con `BRVTAL CI / validate` verde en el run #590.
- Issue cubierto: `#400`.
- No hay migración ni cambio de schema. La tabla `settings`, el endpoint `/settings`, Theme Studio y Media existentes se reutilizan.
- `api/public.php` no expone `settings.analytics` ni el setting SEO canónico; las credenciales/IDs de integración permanecen fuera del payload público.
- El allowlist genérico de uploads no se amplía a SVG sin sanitización. El wordmark puede usar un SVG ya servido por una ruta/URL pública segura.
- La especificación i18n de #212 sigue **NOT IMPLEMENTED**; los locales guardados se muestran solo como contexto y no como control prometido.
- Run #591: PHP 8.5/contratos y sintaxis JS verdes; el primer intento falló solo por el formato de lista exacta del README. Se corrigió sin cambiar lógica.
- Pendiente: nuevo `BRVTAL CI / validate`, revisión automática y cualquier corrección válida sobre el head actual.
- CI verde significará **VALIDATED IN CODE**. No se declarará **VALIDATED IN PRODUCTION** sin comprobar el deploy real.

## Qué sigue

1. Resolver cualquier fallo o finding válido de BRVTAL CI/revisión automática.
2. Hacer squash merge solo con `BRVTAL CI / validate` verde.
3. Verificar `BRVTAL CI / validate` del SHA exacto resultante en `main`.
4. Verificar el control plane en el deploy real y continuar las siguientes fases de #398 sin reestructurar el backend existente.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de validación: `docs/TESTING.md`.
- Arquitectura de configuración: `docs/CONFIGURATION.md`.
- Visión pública: `#398`.
- Issue abordado: `#400`.
