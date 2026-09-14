# BRVTAL Platform

> **RAVE TILL GRAVE**  
> Manual técnico, mapa de arquitectura y tablero operativo del software BRVTAL.

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php&logoColor=white)
![MariaDB](https://img.shields.io/badge/MariaDB-11.x-003545?logo=mariadb&logoColor=white)
![Status](https://img.shields.io/badge/status-active_development-111111)

Este README no está pensado como una página de marketing. Su función es servir como **referencia rápida del sistema**: qué software existe, cómo está conectado, qué ya está terminado, qué sigue pendiente y cómo interpretar cada build/deploy.

> **Regla de mantenimiento:** cuando una PR cambia el estado de una característica importante, también debe actualizar el checklist correspondiente de este README. El SHA actual no se fija aquí para evitar información obsoleta; se consulta en **GitHub Actions / BRVTAL CI** y en **DISCADMIN → System Status**.

---

## 1. Snapshot operativo

| Dato | Valor |
|---|---|
| Producción | `https://brvtal.com.co` |
| Administración | `https://brvtal.com.co/discadmin` |
| Repositorio | `pl0n3r/brvtal` |
| Branch canónica | `main` |
| Hosting | Hostinger shared / LiteSpeed |
| Backend | PHP 8.3+ |
| Base de datos | MariaDB / MySQL-compatible |
| Frontend público | HTML + CSS + JavaScript vanilla |
| Admin | DISCADMIN propietario |
| Tests browser | Playwright / Chromium + WebKit donde aplica |
| CI | GitHub Actions — `BRVTAL CI` |
| Deploy | `main` → integración Git de Hostinger |
| Idioma público actual | English |
| Arquitectura de producto | [`docs/BRVTAL-SPEC.md`](docs/BRVTAL-SPEC.md) |
| Contrato Hero Slider | [`docs/HERO-SLIDER.md`](docs/HERO-SLIDER.md) |
| Testing | [`docs/TESTING.md`](docs/TESTING.md) |
| Reglas de trabajo | [`AGENTS.md`](AGENTS.md) |

### Estados usados en este proyecto

BRVTAL separa deliberadamente cuatro estados para evitar afirmaciones imprecisas:

| Estado | Significado |
|---|---|
| **IMPLEMENTED** | El código existe en una branch/PR. |
| **VALIDATED IN CODE** | CI/tests pasaron. |
| **DEPLOYED** | Hostinger recibió el source de `main`. |
| **VALIDATED IN PRODUCTION** | El comportamiento real fue comprobado en `brvtal.com.co`. |

Un CI verde **no equivale** por sí solo a validación en producción.

---

## 2. Qué es el software BRVTAL

BRVTAL es una plataforma digital propia para operar el colectivo, sus eventos y su ecosistema editorial/label. No es únicamente una landing page.

El sistema combina:

- experiencia pública art-directed;
- gestión de Events, Artists, Sets y Releases;
- Blog y CMS Pages;
- Media Library reutilizable;
- archivo histórico y relaciones entre contenidos;
- Hero / Slider Manager visual para Home;
- Content Core para flujos editoriales guiados;
- seguridad administrativa con sesión, CSRF y 2FA;
- Content Health, Search y Bulk Actions;
- historial administrativo y version history de lectura;
- backups manuales seguros;
- System Status para observabilidad;
- SEO y structured data;
- analytics opcional con consentimiento;
- CI, pruebas y deploy automático a Hostinger.

### Principio central del admin

DISCADMIN debe conservar siempre:

**ONE SHELL / ONE SIDEBAR / ONE SESSION / ONE CENTRAL WORKSPACE**

Los módulos cambian el workspace central dentro de `/discadmin`; no deben aparecer mini-admins, sidebars paralelos ni sistemas de autenticación alternos.

---

## 3. Arquitectura general

```mermaid
flowchart LR
    VISITOR[Public visitor]
    ADMIN[Administrator]

    VISITOR --> HOME[index.php / public Home]
    VISITOR --> ENTITY[Public entity routes]

    HOME --> PUBAPI[api/public.php + public read APIs]
    ENTITY --> PUBPHP[Public PHP delivery]

    ADMIN --> DISC[/discadmin]
    DISC --> AUTH[Session + CSRF + optional TOTP]
    AUTH --> ADMINAPI[Protected PHP APIs]

    PUBAPI --> DB[(MariaDB)]
    PUBPHP --> DB
    ADMINAPI --> DB

    DISC --> MEDIA[Media Library / Media Engine]
    MEDIA --> UPLOADS[(uploads)]
    ADMINAPI --> PRIVATE[(private storage)]

    GITHUB[GitHub main] --> CI[BRVTAL CI]
    GITHUB --> HOST[Hostinger Git auto-deploy]
    HOST --> PROD[brvtal.com.co]
```

### Separación de responsabilidades

**Public web**

- renderiza contenido publicado;
- consume únicamente datos públicos allowlisted;
- no expone settings privados;
- mantiene fallbacks estáticos donde son necesarios;
- prioriza accesibilidad, responsive y rendimiento móvil.

**DISCADMIN**

- maneja CRUD editorial y herramientas técnicas;
- usa autenticación centralizada;
- protege mutaciones con CSRF;
- conserva drafts y lifecycle states;
- registra actividad administrativa relevante.

**MariaDB**

- es la fuente de verdad para entidades editoriales y settings persistentes;
- las migraciones son explícitas y separadas del deploy de source.

**Filesystem / media**

- originales de Media Library se conservan;
- variantes derivadas no sustituyen el original;
- backups privados no deben exponerse como assets públicos.

**GitHub / Hostinger**

- GitHub valida el source;
- `main` es la única branch de deploy rutinario;
- Hostinger hace deploy automático desde Git;
- no se usa FTP como flujo normal.

---

## 4. Stack y restricciones técnicas

| Capa | Tecnología / decisión |
|---|---|
| Backend | PHP 8.3+ |
| Database | MariaDB 11.x / MySQL-compatible SQL |
| Public frontend | HTML, CSS, vanilla JS |
| Admin frontend | DISCADMIN + módulos JS/CSS montados en el mismo shell |
| Animación pública | GSAP / ScrollTrigger; Lenis donde no se desactiva por performance |
| Auth | PHP sessions + CSRF + rate limiting + TOTP |
| Media | PHP filesystem/media engine + browser picker |
| Testing | PHP contracts + MariaDB integration + Playwright |
| CI | GitHub Actions + MariaDB service container |
| Hosting | Hostinger shared hosting / LiteSpeed |
| Deployment | GitHub `main` → Hostinger Git integration |

### Restricciones de diseño

El runtime de producción **no depende** de:

- un servidor Node permanente;
- Docker en producción;
- workers long-running;
- SSH deploy;
- microservicios externos para el CMS.

La arquitectura debe seguir funcionando en shared hosting.

---

## 5. Mapa del repositorio

```text
brvtal/
├── .github/              GitHub Actions / CI
├── api/                  APIs públicas y protegidas
├── assets/               Assets estáticos públicos
├── config/               Bootstrap, auth, media, deployment, release config
├── css/                  CSS público
├── database/             Schema base + migraciones explícitas
├── discadmin/            Shell canónico y módulos DISCADMIN
├── docs/                 Especificaciones y contratos técnicos
├── js/                   JavaScript público
├── scripts/              Herramientas de mantenimiento
├── storage/              Runtime/private application storage
├── tests/                Contracts, integración y browser tests
├── uploads/              Media administrada
├── index.php             Entrada/router público server-side
├── index.html            Estructura/fallback pública
├── package.json          Tooling de tests
└── playwright.config.mjs Configuración Playwright
```

---

## 6. DISCADMIN

### Arquitectura

`/discadmin` es una única aplicación administrativa. El shell contiene:

- sidebar canónico;
- sesión canónica;
- workspace central;
- feedback global;
- dialogs/forms;
- módulos editoriales y técnicos.

Los módulos adicionales deben montarse sobre este shell, no reemplazarlo.

### Módulos editoriales principales

| Módulo | Función |
|---|---|
| Dashboard | Resumen operativo, actividad y accesos rápidos |
| Events | Eventos, lifecycle, tickets, lineup/roster, media y SEO |
| Hero Slider | Home hero/banner/video manager visual |
| Artists | Perfiles, bio, media y relaciones |
| Releases | Catálogo/label, artwork, artists y plataformas |
| Sets | Sets, links de plataformas y relaciones |
| Media | Media Library + Media Engine |
| Pages | CMS pages |
| Blog | Editorial, tags, media, relaciones y SEO |
| Content Core | Flujo guiado para creación/edición compleja |

### Herramientas técnicas / cross-cutting

- Global Search (`⌘K / Ctrl+K`);
- Bulk Actions seguras;
- Content Health;
- SEO editorial defaults;
- Admin Activity / Version History;
- Theme Studio público;
- Settings;
- Security / 2FA;
- System Status;
- Backups Foundation;
- Appearance selector del shell.

### Apariencia del admin

El sidebar permite seleccionar de forma inmediata:

- **Dark**;
- **Light**;
- **Glass** — interpretación translúcida tipo iOS/macOS adaptada a BRVTAL.

La selección es local/persistente y no está acoplada al Theme Studio público.

### Sesión administrativa

La política actual está pensada para evitar relogins constantes sin eliminar controles de seguridad:

- hasta **7 días de inactividad**;
- máximo **30 días desde el login**;
- cookie persistente;
- renovación periódica durante actividad;
- `HttpOnly`;
- `SameSite=Strict`;
- CSRF activo;
- regeneración del session ID.

---

## 7. Hero Slider Manager

El Home Hero Slider es un manager propio inspirado en la facilidad de LayerSlider, no un page builder genérico.

### v1

- hasta 20 slides;
- image o muted video;
- desktop asset;
- mobile override opcional;
- poster de video;
- autoplay/interval;
- publish/unpublish;
- overlay;
- texto y CTA legacy;
- desktop/mobile preview;
- fallback permanente al hero original.

### v2

- hasta 12 layers por slide;
- layer types: text, image, logo y CTA;
- posicionamiento visual por pointer/touch;
- porcentaje X/Y y width;
- animaciones de entrada;
- delay/duration;
- mobile X/Y/width overrides;
- mobile media override;
- hide-on-mobile;
- duplicado seguro de slide/layers;
- sanitización/allowlist en el endpoint público;
- compatibilidad con slides v1.

El contrato técnico completo está en [`docs/HERO-SLIDER.md`](docs/HERO-SLIDER.md).

---

## 8. Modelo de contenido

### Entidades principales

- `events`;
- `artists`;
- `sets_media` / Sets;
- `releases`;
- `pages`;
- `blog_posts`;
- Media Library;
- Settings.

### Relaciones importantes

Ejemplos:

- Event ↔ Artists mediante lineup/participation;
- Event ↔ Sets;
- Artist ↔ Sets;
- Release ↔ Artists;
- Blog ↔ Events / Artists / Sets / Releases;
- Media ↔ múltiples entidades mediante referencias de campos.

La plataforma evita duplicar relaciones como texto cuando deben ser datos estructurados.

### Lifecycle

Los drafts son first-class. Publicar contenido puede exigir validaciones más estrictas que simplemente guardarlo.

Public delivery nunca debe filtrar drafts/private content por accidente.

---

## 9. Media Library / Media Engine

Media se trata como contenido reutilizable, no como attachments desechables.

Implementado:

- picker visual;
- upload validado;
- metadata;
- normalización de paths;
- original preservation;
- dimensiones y quality warnings;
- WebP variants cuando el runtime lo permite;
- tamaños derivados para contextos útiles;
- reference-aware deletion protection;
- focal point;
- preview square/card/hero;
- context-aware variants;
- guidance de resolución.

La eliminación de media usada por contenido debe bloquearse o advertir explícitamente; nunca debe romper referencias silenciosamente.

---

## 10. Public delivery

### Home

La Home conserva una base estática art-directed pero permite reemplazar el primer hero mediante Hero Slider cuando existe configuración publicada válida.

Si el slider falla, está deshabilitado o no tiene media válida, el hero original permanece visible.

### Entity routes

Rutas canónicas:

```text
/events/{slug}
/artists/{slug}
/sets/{slug}
/releases/{slug}
/blog/{slug}
/pages/{slug}
```

### Archive / discovery

Archive separa eventos históricos de eventos activos y soporta:

- año;
- texto;
- relaciones con Artists;
- relaciones con Sets;
- enlaces al Event canónico;
- estado de filtros compartible mediante URL;
- restauración de filtros al abrir un link;
- Back/Forward del navegador.

### Public Media Discovery

Media pública soporta:

- búsqueda textual;
- filtro image/video/audio;
- viewer accesible para imágenes;
- controles nativos para audio/video;
- estado compartible mediante URL;
- Back/Forward del navegador.

### Related Content

Events, Artists, Sets y Releases pueden enlazarse por relaciones reales. El servidor filtra entidades draft/private antes de exposición pública.

---

## 11. SEO y analytics

### SEO

El sistema incluye:

- canonical URLs;
- Open Graph;
- Twitter metadata;
- JSON-LD / structured data;
- sitemap/robots behavior;
- `404/noindex` safeguards;
- SEO title/description editoriales;
- fallbacks automáticos cuando los campos SEO están vacíos.

Los valores editoriales manuales siempre tienen prioridad sobre defaults automáticos.

### Analytics/privacy

La foundation actual usa Google Analytics únicamente cuando:

1. Theme Studio contiene un GA4 Measurement ID válido; y
2. el visitante acepta analytics opcional.

No se carga Google tag antes del consentimiento. La preferencia puede rechazarse o cambiarse posteriormente.

GTM, Meta Pixel o scripts arbitrarios legacy no se ejecutan automáticamente como parte de esta foundation.

---

## 12. Seguridad

Controles principales:

- prepared statements para inputs de base de datos;
- CSRF para mutaciones;
- sesión administrativa centralizada;
- login rate limiting;
- TOTP compatible con Google Authenticator;
- AES-256-GCM para secretos TOTP;
- recovery codes hasheados;
- private settings protegidos;
- sanitización/allowlists en public APIs;
- drafts/private entities fuera de public delivery;
- stack traces y secretos fuera de respuestas públicas.

### Límites deliberados

No ejecutar sin confirmación explícita:

- SQL destructivo de producción;
- resets/seeds en producción;
- automatic restore/revert;
- Bulk Delete;
- cambios irreversibles de datos.

---

## 13. APIs

`api/public.php` es la allowlist principal de datos públicos. No debe crearse otra implementación pública divergente.

Endpoints/conceptos principales:

```text
/api/index.php/auth
/api/index.php/dashboard
/api/index.php/events
/api/index.php/artists
/api/index.php/sets
/api/index.php/media
/api/index.php/pages
/api/index.php/settings
/api/public.php
/api/hero-slider.php
/api/releases.php
/api/blog.php
/api/content-health.php
/api/admin-search.php
/api/bulk-actions.php
/api/admin-activity.php
```

Las mutaciones administrativas requieren autenticación y CSRF según corresponda.

---

## 14. Base de datos y migraciones

`database/schema.sql` es histórico/base. Los cambios nuevos de schema se realizan mediante migraciones explícitas.

Foundations existentes incluyen, entre otras:

```text
migration_content_core_01.sql
migration_releases_01.sql
migration_blog_01.sql
migration_seo_01.sql
migration_admin_activity_01.sql
```

Reglas:

- data-safe;
- idempotentes cuando sea práctico;
- source deploy y DB migration son procesos separados;
- mergear código **no significa** que una migración se haya aplicado en producción;
- nunca correr migraciones destructivas automáticamente contra producción.

---

## 15. Observabilidad y operaciones

### System Status v2

El control room de DISCADMIN muestra, según disponibilidad:

- health score;
- API/runtime/DB/security state;
- deployment SHA y source;
- content/database counts;
- storage administrado por BRVTAL;
- GitHub/source metrics;
- Content Health;
- recent admin activity;
- actionable diagnostics.

`config/deployment.php` resuelve el SHA en runtime mediante:

1. `BRVTAL_DEPLOY_COMMIT` si existe;
2. Git checkout local;
3. release/build fallback.

Esto evita commits artificiales de metadata.

### Backups Foundation

Implementado:

- backup manual autenticado de DB;
- private backup storage;
- manifest;
- timestamp;
- deploy SHA;
- size/checksum/components;
- history/status/download;
- activity logging.

**No existe one-click restore automático.** Esa omisión es deliberada por seguridad.

---

## 16. Testing

La estrategia completa vive en [`docs/TESTING.md`](docs/TESTING.md).

### Capas de validación

1. PHP syntax.
2. JavaScript syntax.
3. Contract tests.
4. Migration/idempotency checks.
5. MariaDB disposable integration tests.
6. Playwright browser tests.
7. Targeted WebKit/Safari regressions.
8. Production smoke separado cuando realmente se ejecuta.

### Comandos locales

```bash
npm run test:contracts
npm run test:integration
npm run test:e2e
npm test
```

La integración local exige una DB cuyo nombre empiece con `brvtal_test` y la flag de seguridad indicada en `docs/TESTING.md`.

---

## 17. CI / deploy

El workflow histórico se conserva en:

```text
.github/workflows/update-release-metadata.yml
```

Su nombre visible es **BRVTAL CI**.

### Triggers

- Pull Request → `main`;
- push → `main`;
- manual `workflow_dispatch`.

### Flujo obligatorio

```text
current green main
       ↓
feature/fix/docs branch
       ↓
implementation
       ↓
contracts + integration + browser tests
       ↓
Pull Request
       ↓
BRVTAL CI green
       ↓
squash merge
       ↓
BRVTAL CI on exact merged main SHA
       ↓
Hostinger Git auto-deploy
       ↓
production verification when performed
```

### Build / Deploy Summary

Cada run de BRVTAL CI publica un **GitHub Actions Job Summary** con contexto operativo, incluyendo:

- event (`pull_request`, `push`, manual);
- source branch / base branch;
- PR cuando aplica;
- source SHA y checkout SHA;
- commit subject/timestamp;
- actor;
- run/attempt;
- archivos cambiados;
- áreas del sistema afectadas;
- si el run es elegible para auto-deploy (`push` a `main`) o solo validación;
- resultado final de CI;
- runtime/tool versions disponibles.

El summary facilita leer cada deploy sin crear commits de metadata.

### Regla de metadata

**BRVTAL CI nunca debe reescribir `config/version.php` ni crear commits de versión por cada cambio.**

El SHA de deploy se resuelve en runtime mediante `config/deployment.php`.

---

## 18. Checklist de características solicitadas vs estado actual

Leyenda:

- `[x]` terminado / implementado y cubierto por el flujo actual;
- `[ ]` pendiente, incompleto o explícitamente diferido;
- `⚠` implementado pero con verificación operacional adicional pendiente.

### DISCADMIN / UX

- [x] Un solo shell/sidebar/session/workspace.
- [x] Sidebar responsive/mobile.
- [x] Listas administrativas usables en mobile.
- [x] Forms/dialogs consistentes y prevención de double-save.
- [x] Theme selector rápido directamente en sidebar.
- [x] Dark mode.
- [x] Light mode.
- [x] Glass / iOS-like mode.
- [x] Preferencia de appearance persistente.
- [x] Sesión administrativa extendida para evitar relogin frecuente.
- [x] Global Search.
- [x] Content Health.
- [x] Safe Bulk Actions.
- [ ] Bulk Delete — **deliberadamente no implementado**.
- [ ] RBAC complejo — diferido hasta existir una necesidad real multiusuario.

### Content / CMS

- [x] Events CRUD y lifecycle.
- [x] Event tickets.
- [x] Event lineup / roster / artist participation.
- [x] Artists CRUD.
- [x] Sets CRUD + relaciones/plataformas.
- [x] Releases / label catalog.
- [x] Blog + tags + relaciones.
- [x] CMS Pages.
- [x] Content Core guided workflow.
- [x] SEO fields + intelligent defaults.
- [x] Draft/private visibility rules.
- [x] Admin Activity append-only.
- [x] Editorial Version History read-only.
- [ ] Automatic restore/revert desde history — deliberadamente diferido.

### Hero Slider

- [x] Hero Manager dentro del DISCADMIN canónico.
- [x] Image slides.
- [x] Muted inline video slides.
- [x] Múltiples slides.
- [x] Autoplay / interval / pause controls.
- [x] Mobile-specific media override.
- [x] Desktop/mobile preview.
- [x] Permanent static hero fallback.
- [x] Visual layers: text/image/logo/CTA.
- [x] Drag/pointer/touch positioning.
- [x] Layer entrance animation + delay/duration.
- [x] Per-layer mobile position/width/media overrides.
- [x] Hide layer on mobile.
- [x] Duplicate slide.
- [x] Reduced-motion behavior.
- [x] Deferred loading de inactive slide images.
- [ ] Scheduled start/end publication.
- [ ] Full timeline editor tipo LayerSlider.
- [ ] Drag-and-drop slide ordering; v1 usa controles explícitos.

### Media

- [x] Media Library visual.
- [x] Media picker reutilizable.
- [x] Upload validation.
- [x] Original preservation.
- [x] WebP/context variants.
- [x] Focal point.
- [x] Crop/context preview.
- [x] Resolution/quality guidance.
- [x] Reference-aware delete protection.

### Public experience

- [x] Responsive public Home.
- [x] Mobile performance fallbacks para efectos pesados.
- [x] Non-blocking Google Fonts.
- [x] Mobile loader bypass.
- [x] Touch targets / keyboard focus/accessibility passes.
- [x] Public Events lifecycle rendering.
- [x] Archive histórico.
- [x] Archive year/search/relationship filters.
- [x] Archive filters shareable in URL + browser history.
- [x] Public Media discovery.
- [x] Media filters shareable in URL + browser history.
- [x] Related Content entre entidades.
- [x] Canonical public entity pages.
- [x] SEO/OG/Twitter/JSON-LD.
- [x] Analytics consent foundation.
- [ ] Medición periódica documentada de Core Web Vitals en producción.
- [ ] Registro formal de authenticated production smoke después de releases relevantes. ⚠

### Security

- [x] Centralized admin auth.
- [x] CSRF.
- [x] Prepared statement policy.
- [x] Login rate limiting.
- [x] TOTP / 2FA enrollment + challenge.
- [x] Encrypted TOTP secret storage.
- [x] Recovery codes.
- [x] Safari/WebKit login regression coverage.
- [x] Private settings protection.

### Operations / deploy

- [x] GitHub PR workflow.
- [x] BRVTAL CI.
- [x] MariaDB disposable integration tests.
- [x] Playwright browser tests.
- [x] Exact-main-SHA CI gate antes de siguiente branch.
- [x] GitHub `main` → Hostinger auto-deploy.
- [x] Runtime deployment SHA resolution.
- [x] System Status v2.
- [x] Backups Foundation v1.
- [x] Build/Deploy Job Summary en GitHub Actions.
- [ ] Automated one-click restore — deliberadamente no implementado.
- [ ] Backup restore rehearsal documentado en entorno seguro.
- [ ] Producción autenticada validada automáticamente — no se habilita hasta tener un método seguro/no destructivo.

### Futuro de producto

- [ ] Booking/community capabilities.
- [ ] Multi-admin/RBAC avanzado si el producto lo requiere.
- [ ] Richer relationship-driven public browsing.
- [ ] Mayor profundidad editorial/label según necesidades reales.

---

## 19. Deuda conocida / siguiente prioridad

Prioridades actuales:

1. public discovery y relationship-driven browsing más profundo;
2. performance/responsive polish basado en mediciones reales;
3. authenticated production smoke documentado;
4. backup recovery rehearsal seguro;
5. continuar estabilizando Content Core cuando se reproduzcan defectos reales;
6. mejoras incrementales del Hero Slider sin convertirlo en un page builder genérico.

Evitar trabajo prematuro en:

- Wix/Elementor-style generic builder;
- event-site builder arbitrario;
- large custom analytics product;
- Bulk Delete;
- automatic restore;
- RBAC complejo sin necesidad real.

---

## 20. Local setup

### Requisitos

- PHP 8.3+;
- PDO MySQL;
- MariaDB/MySQL-compatible server;
- Node.js para testing/tooling.

### Clone

```bash
git clone https://github.com/pl0n3r/brvtal.git
cd brvtal
```

### Config

```bash
cp config/config.example.php config/config.php
```

Configura DB y valores de seguridad localmente. Nunca commitear credenciales reales.

### Dependencies de test

```bash
npm install
npx playwright install chromium webkit
```

---

## 21. Runbook rápido

### Antes de desarrollar

1. leer `AGENTS.md`;
2. revisar este README;
3. revisar PRs abiertos;
4. revisar el último CI de `main`;
5. no abrir nueva branch hasta que el exact-main CI anterior esté verde.

### Antes de mergear

1. branch enfocada;
2. tests aplicables;
3. PR;
4. BRVTAL CI verde;
5. corregir en la misma PR si falla;
6. squash merge.

### Después de mergear

1. identificar el SHA exacto resultante;
2. verificar BRVTAL CI para ese SHA;
3. revisar el Build/Deploy Summary;
4. distinguir auto-deploy esperado de deploy comprobado;
5. hacer production smoke cuando el cambio lo amerite y sea seguro.

### Si hay una migración

No asumir que Hostinger la aplicó. Source deploy y DB migration son operaciones separadas.

### Si falla producción

Prioridad:

1. diagnosticar;
2. preservar datos;
3. evitar resets destructivos;
4. corregir mediante branch/PR;
5. usar restore solo mediante procedimiento explícito y seguro.

---

## 22. Fuentes de verdad

| Documento | Responsabilidad |
|---|---|
| `README.md` | Overview técnico, estado, checklist, operación |
| `docs/BRVTAL-SPEC.md` | Producto/arquitectura detallada y contratos funcionales |
| `docs/HERO-SLIDER.md` | Contrato específico Hero Slider |
| `docs/DISCADMIN-UX-AUDIT.md` | Deuda y criterios UX/responsive del admin |
| `docs/TESTING.md` | Estrategia y comandos de validación |
| `AGENTS.md` | Reglas cortas para futuras sesiones/agentes |
| Código + PRs recientes | Fuente final cuando contradicen documentación antigua |

---

## 23. Principios de producto

1. **Una plataforma, no herramientas desconectadas.**
2. **Las relaciones son structured data, no texto duplicado.**
3. **Drafts son first-class.**
4. **El CMS aconseja; no publica de forma autónoma.**
5. **Media es reutilizable y el original se preserva.**
6. **Public exposure es explícito y allowlisted.**
7. **Production SQL es deliberado y separado del source deploy.**
8. **Mobile es un target de primera clase.**
9. **CI demuestra código; no inventa validación de producción.**
10. **La observabilidad no debe generar commits de metadata.**
