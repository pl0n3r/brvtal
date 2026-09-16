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
- El wordmark se integra en el Theme Runtime ya existente para header y loader; ante ausencia/error conserva el texto `BRVTAL` como fallback, sin añadir requests JS públicos nuevos.
- Los campos históricos de tema que no tienen consumidor real se preservan como **PRESERVED / UNWIRED**, no como controles falsamente funcionales.
- El runtime mantiene Theme Studio como autoridad visual y deja el SEO canónico bajo autoridad server-side.
- Se documentó la propiedad de cada categoría en `docs/CONFIGURATION.md`.

## Archivos modificados en este deploy

**Diff funcional:** `15 archivos` · **+911** líneas · **−53** líneas *(sin contar README, porque este snapshot modifica su propio diff al actualizarse).*  
Leyenda: 🟢 nuevo · 🟡 modificado · `+ / −` líneas frente al `main` base de este deploy.

### DISCADMIN

- `discadmin/index.php` — 🟡 MOD · **+5 / −1** · carga Settings V2 y la extensión de configuración de Theme Studio.
- `discadmin/settings-v2.css` — 🟢 NEW · **+1 / −0** · layout responsive y visual del nuevo control plane.
- `discadmin/settings-v2.js` — 🟢 NEW · **+274 / −0** · General, Social, SEO, Analytics & Privacy y Advanced con persistencia tipada.
- `discadmin/theme-studio-configuration.css` — 🟢 NEW · **+1 / −0** · estilos de wordmark y mapa de compatibilidad.
- `discadmin/theme-studio-configuration.js` — 🟢 NEW · **+105 / −0** · wordmark configurable, ownership map, retiro del SEO duplicado y protección contra loops de preview.

### SERVER / PUBLIC DELIVERY

- `config/public_analytics.php` — 🟡 MOD · **+16 / −2** · `settings.analytics` pasa a ser autoridad con fallback legacy de tema.
- `config/public_seo.php` — 🟡 MOD · **+29 / −7** · defaults SEO canónicos del Home desde Settings.
- `config/public_settings.php` — 🟢 NEW · **+36 / −0** · lector server-side fail-safe de settings tipados.
- `index.php` — 🟡 MOD · **+2 / −1** · aplica los defaults SEO server-side al documento público.
- `js/public-theme-branding-sync.js` — 🟡 MOD · **+33 / −4** · reconcilia branding/social sin crear una segunda autoridad de tema.
- `js/public-theme-runtime.js` — 🟡 MOD · **+50 / −38** · integra wordmark/fallback y evita que SEO legacy del tema sobrescriba el SEO server-side.

### DOCUMENTACIÓN / TESTS

- `docs/CONFIGURATION.md` — 🟢 NEW · **+104 / −0** · define propiedad, compatibilidad y límites de cada categoría configurable.
- `tests/e2e/configuration-runtime.spec.mjs` — 🟢 NEW · **+92 / −0** · cubre wordmark, fallback, SEO canónico y autoridad del runtime.
- `tests/e2e/discadmin-settings-v2.spec.mjs` — 🟢 NEW · **+94 / −0** · cubre Settings desktop/mobile y preservación de JSON.
- `tests/settings-control-plane-contract.php` — 🟢 NEW · **+69 / −0** · contratos de SEO/Analytics, privacidad, persistencia y wordmark integrado.

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
- Run #591 detectó el primer desfase del snapshot README; se corrigió sin cambiar lógica.
- Run #592 validó PHP, real-stack, WebKit-TOTP y database; Chromium detectó integración/runtime y overflow móvil, que se corrigieron sin relajar tests.
- Run #601 detectó únicamente un contrato PHP que todavía apuntaba al módulo wordmark ya integrado/eliminado; el contrato se alineó con `public-theme-runtime.js`.
- Run #604 dejó verde el resto de Chromium y reveló un loop real del `MutationObserver` al actualizar el preview del wordmark; se corrigió evitando reescribir un preview ya sincronizado.
- Pendiente: `BRVTAL CI / validate` verde sobre el head actual y cierre de revisión automática.
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
