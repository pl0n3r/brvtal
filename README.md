# BRVTAL Platform

> **RAVE TILL GRAVE**  
> Manual técnico, mapa de arquitectura y tablero operativo del software BRVTAL.

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)
![PHP](https://img.shields.io/badge/PHP-8.3%2B-777BB4?logo=php&logoColor=white)
![MariaDB](https://img.shields.io/badge/MariaDB-11.x-003545?logo=mariadb&logoColor=white)
![Status](https://img.shields.io/badge/status-active_development-111111)

Este README es la referencia humana del proyecto: describe qué software existe, cómo está conectado, qué ya está terminado, qué sigue pendiente y cómo interpretar cada build/deploy. Para una sesión nueva de IA, `AGENTS.md` es el bootstrap canónico.

> **Regla de mantenimiento:** si una PR cambia una capacidad importante o una decisión de arquitectura, actualiza el checklist/estado correspondiente aquí y el contexto durable en `AGENTS.md`.

---

## 1. Snapshot operativo

| Dato | Valor |
|---|---|
| Producción | `https://www.brvtal.com.co` |
| Administración | `https://www.brvtal.com.co/discadmin` |
| Repositorio | `pl0n3r/brvtal` |
| Branch canónica | `main` |
| Hosting | Hostinger shared / LiteSpeed |
| Backend | PHP 8.3+ |
| Base de datos | MariaDB / MySQL-compatible |
| Frontend público | HTML + CSS + JavaScript vanilla |
| Admin | DISCADMIN propietario |
| Browser tests | Playwright / Chromium + WebKit donde aplica |
| CI | GitHub Actions — `BRVTAL CI` |
| Deploy | `main` → integración Git de Hostinger |
| Idioma público | English |
| Reglas para IA | [`AGENTS.md`](AGENTS.md) |

**Host canónico:** toda referencia operativa, documentación, validación de producción y medición PageSpeed debe usar `www.brvtal.com.co`; el host sin `www` no es la URL canónica del proyecto.

### Estados usados

| Estado | Significado |
|---|---|
| **IMPLEMENTED** | El código existe. |
| **VALIDATED IN CODE** | CI/tests pasaron. |
| **DEPLOYED** | Hostinger recibió el source. |
| **VALIDATED IN PRODUCTION** | El comportamiento real fue comprobado en producción. |

CI verde no equivale por sí solo a validación en producción.

---

## 2. Qué es BRVTAL

BRVTAL es una plataforma digital propia para operar un colectivo/label de música electrónica. Combina:

- sitio público art-directed;
- gestión de Events, Artists, Sets y Releases;
- Blog y CMS Pages;
- Media Library reutilizable;
- Archive y relaciones públicas entre entidades;
- Hero / Slider Manager visual;
- seguridad administrativa con sesión, CSRF y 2FA;
- Search, Content Health, Bulk Actions y Activity;
- System Status y backups manuales seguros;
- SEO/structured data;
- analytics opcional con consentimiento;
- CI, pruebas y deploy automático a Hostinger.

DISCADMIN debe conservar siempre:

**ONE SHELL / ONE SIDEBAR / ONE SESSION / ONE CENTRAL WORKSPACE**

---

## 3. Arquitectura general

```mermaid
flowchart LR
    VISITOR[Public visitor] --> HOME[index.php / public Home]
    VISITOR --> ENTITY[Public entity routes]
    HOME --> PUBAPI[Public read APIs]
    ENTITY --> PUBAPI
    PUBAPI --> DB[(MariaDB)]

    ADMIN[Administrator] --> DISC[/discadmin]
    DISC --> AUTH[Session + CSRF + optional TOTP]
    AUTH --> ADMINAPI[Protected PHP APIs]
    ADMINAPI --> DB
    DISC --> MEDIA[Media Library / Media Engine]
    MEDIA --> UPLOADS[(uploads)]

    GITHUB[GitHub main] --> CI[BRVTAL CI]
    GITHUB --> HOST[Hostinger Git auto-deploy]
    HOST --> PROD[www.brvtal.com.co]
```

### Separación de responsabilidades

**Public web**

- renderiza solamente contenido público;
- consume allowlists server-side;
- mantiene fallbacks estáticos cuando son necesarios;
- prioriza mobile, accesibilidad y performance.

**DISCADMIN**

- es una sola aplicación administrativa;
- maneja CRUD/editorial, media y herramientas técnicas;
- usa una sesión/autenticación central;
- protege mutaciones con CSRF;
- conserva drafts y lifecycle states.

**MariaDB**

- fuente de verdad de contenido/settings persistentes;
- migraciones explícitas y separadas del source deploy.

**GitHub / Hostinger**

- GitHub valida source;
- `main` es la rama de deploy rutinario;
- Hostinger despliega desde Git;
- FTP no es el flujo normal.

---

## 4. Stack y restricciones

| Capa | Tecnología / decisión |
|---|---|
| Backend | PHP 8.3+ |
| Database | MariaDB / MySQL-compatible SQL |
| Public frontend | HTML, CSS, vanilla JS |
| Admin frontend | DISCADMIN + módulos JS/CSS en el mismo shell |
| Auth | PHP sessions + CSRF + rate limiting + TOTP |
| Media | PHP filesystem/media engine + browser picker |
| Testing | PHP contracts + MariaDB integration + Playwright |
| CI | GitHub Actions |
| Hosting | Hostinger shared / LiteSpeed |
| Deployment | GitHub `main` → Hostinger Git integration |

Producción no debe requerir un servidor Node permanente, Docker runtime, workers long-running ni SSH deploy.

---

## 5. Mapa del repositorio

```text
.github/       CI / GitHub Actions
api/           APIs públicas y protegidas
assets/        assets estáticos
config/        bootstrap/auth/deployment/media config
css/           frontend público
database/      schema + migraciones
discadmin/     shell y módulos DISCADMIN
docs/          especificaciones profundas
js/            JavaScript público
scripts/       mantenimiento
storage/       runtime/private storage
tests/         contracts, integración y Playwright
uploads/       media administrada
index.php      router/delivery público
```

---

## 6. DISCADMIN

### Modelo mental actual

El admin se organiza por **destinos**, no por nombres internos de arquitectura:

- **Dashboard**
- **CONTENT** — Events, Artists, Releases, Sets, Blog, Pages
- **MEDIA** — Media Library, Hero Slider
- **SITE** — Theme Studio, Settings
- **SYSTEM** — Security / 2FA, System Status, Backups, Activity cuando aplica

Cross-cutting como Global Search, Content Health, Bulk Actions y Appearance mejoran la aplicación sin convertirse en destinos principales innecesarios.

### Events

**Events es la única entrada visible para administrar eventos.**

Internamente reutiliza el workflow que históricamente se llamó Content Core, pero el usuario no tiene que decidir entre dos editores. Desde Events se gestiona en un mismo flujo:

- identidad;
- descripción/cover/accent;
- fecha y lugar;
- lifecycle;
- ticket instructions / URLs / QR;
- ticket types;
- lineup / participación de artistas.

### Artists

Artists contiene perfiles/identidad y ofrece **Collective Status** como sub-workflow para:

- miembro activo;
- alumni;
- orden del colectivo;
- fechas de entrada/salida.

Esto evita exponer Content Core como módulo separado.

### Content Core

Content Core sigue existiendo como **infraestructura interna de workflows guiados**, no como opción principal del sidebar. No debe volver a duplicar Events/Artists como concepto visible salvo una decisión explícita futura.

### Apariencia y sesión

Appearance directo en sidebar:

- Dark;
- Light;
- Glass.

Sesión administrativa actual:

- ~7 días idle;
- ~30 días máximo desde login;
- cookie persistente/renovable;
- `HttpOnly`;
- `SameSite=Strict`;
- CSRF;
- regeneración de session ID.

---

## 7. Hero Slider Manager

Manager propio inspirado en LayerSlider, no un page builder genérico.

Implementado:

- image/video slides;
- múltiples slides;
- mobile asset override;
- autoplay/interval/pause;
- desktop/mobile preview;
- static hero fallback;
- layers text/image/logo/CTA;
- drag pointer/touch;
- entrance animation, delay/duration;
- mobile X/Y/width/media overrides;
- hide-on-mobile;
- duplicate slide;
- reduced motion;
- inactive-image deferral;
- endpoint sanitization/allowlist.

Pendiente:

- scheduled start/end;
- full timeline tipo LayerSlider;
- drag-and-drop de orden si sigue usando controles explícitos.

Contrato: [`docs/HERO-SLIDER.md`](docs/HERO-SLIDER.md).

---

## 8. Modelo de contenido

Entidades principales:

- Events;
- Artists;
- Sets;
- Releases;
- Pages;
- Blog;
- Media;
- Settings.

Relaciones importantes:

- Event ↔ Artists;
- Event ↔ Sets;
- Artist ↔ Sets;
- Release ↔ Artists;
- Blog ↔ entidades relacionadas;
- Media ↔ múltiples entidades.

Las relaciones son structured data, no texto duplicado. Drafts son first-class y la publicación requiere validación más fuerte que guardar.

**Incidencia de producción — Releases (2026-09-13):** al editar un lanzamiento y guardar una fecha válida en **Release date**, DISCADMIN puede responder `INTERNAL_ERROR`. El lanzamiento permanece publicado, pero la fecha no se persiste y el catálogo muestra `DATE TBD`. Se reprodujo con `UMBRAL 03` (`BRVTAL003`). Corregirlo en una PR acotada con regresión que cree y actualice una fecha `YYYY-MM-DD` por el flujo autenticado UI/API, sin exponer trazas, y verificarlo en el administrador de producción tras el despliegue.

---

## 9. Media Library / Media Engine

Implementado:

- picker reutilizable;
- upload validation;
- metadata y normalización de paths;
- original preservation;
- WebP/context variants donde aplica;
- focal point;
- crop/context preview;
- resolution/quality guidance;
- reference-aware deletion protection.

La eliminación de media referenciada nunca debe romper contenido silenciosamente.

---

## 10. Public delivery

Rutas canónicas:

```text
/events/{slug}
/artists/{slug}
/sets/{slug}
/releases/{slug}
/blog/{slug}
/pages/{slug}
```

### Home

Hero Slider puede sustituir el primer hero solo cuando hay configuración válida. El hero estático permanece como fallback permanente.

El Home canónico usa un **runtime loader adaptativo**. En pointer coarse/mobile y en `prefers-reduced-motion`, el navegador no descarga GSAP, ScrollTrigger ni Lenis; carga directamente el runtime de contenido. En desktop/fine-pointer con motion permitido conserva el stack visual completo. `app.js → archive.js → public-media.js` mantiene orden determinista y continúa sin bloquear el contenido si falla el CDN opcional de motion.

Esta es una optimización validable en código; no sustituye una medición real de Core Web Vitals en producción.

### Archive / Media Discovery

Archive soporta año, texto y relaciones. Media soporta búsqueda y tipo. Ambos persisten filtros en URL y restauran estado mediante Back/Forward.

### Related Content

Events, Artists, Sets y Releases se relacionan mediante datos reales; el servidor elimina drafts/private relations antes de exposición pública. CONNECTED permite seleccionar las cuatro capas y navegar Set → Artist/Event y Release → Artists sin degradar Sets/Releases a enlaces terminales.

---

## 11. SEO y analytics

SEO incluye:

- canonical;
- Open Graph;
- Twitter metadata;
- JSON-LD;
- sitemap/robots;
- 404/noindex safeguards;
- SEO title/description;
- defaults automáticos con prioridad para valores editoriales.

Analytics Foundation carga GA4 únicamente con Measurement ID válido y consentimiento del visitante.

---

## 12. Seguridad

Controles principales:

- prepared statements;
- CSRF;
- auth/session centralizada;
- login rate limiting;
- TOTP / 2FA;
- AES-256-GCM para secretos TOTP;
- recovery codes hasheados;
- private settings protegidos;
- allowlists públicas;
- drafts/private fuera del public delivery;
- no stack traces/secrets públicos.

No ejecutar sin confirmación explícita:

- SQL destructivo de producción;
- resets/seeds;
- bulk delete;
- automatic restore/revert;
- acciones irreversibles de datos.

---

## 13. APIs

`api/public.php` es la allowlist pública canónica.

Principales endpoints/conceptos:

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

Mutaciones administrativas requieren auth + CSRF según corresponda.

---

## 14. Base de datos y migraciones

`database/schema.sql` es base/histórico. Cambios nuevos usan migraciones explícitas, data-safe e idempotentes cuando sea práctico.

Mergear source **no significa** que una migración se ejecutó en producción.

Nunca correr migraciones destructivas automáticamente contra producción.

---

## 15. Observabilidad y operaciones

### System Status v2

Puede mostrar runtime/API/DB/security, deploy SHA/source, counts, storage, Content Health y Activity.

`config/deployment.php` resuelve identidad del deploy en runtime; no se generan commits artificiales de versión.

### Backups Foundation

Implementado:

- backup manual autenticado;
- private storage;
- manifest;
- timestamp;
- deploy SHA;
- size/checksum/components;
- history/status/download;
- activity log.

No existe one-click restore automático.

---

## 16. Testing

La validación está dividida para dar feedback temprano sin perder la cobertura completa de `main`:

1. **Fast** — PHP syntax, JavaScript syntax y contratos; siempre corre.
2. **Database** — migraciones/idempotencia + MariaDB integration; path-aware en PRs.
3. **Chromium** — Playwright general; path-aware en PRs.
4. **Real-stack** — PHP + MariaDB + Chromium autenticado para superficies admin/backend relevantes.
5. **WebKit TOTP** — regresión Safari/WebKit enfocada en auth/2FA; path-aware en PRs.
6. **Exact `main`** — ejecuta las cinco capas siempre, sin importar los paths del merge.
7. **Production smoke** — separado y solo cuenta cuando realmente se ejecuta.

Los browsers y descargas npm usan caches de GitHub Actions donde aplica. El real-stack reutiliza un cliente MySQL/MariaDB compatible disponible en el runner en vez de reemplazar paquetes innecesariamente.

```bash
npm run test:contracts
npm run test:integration
npm run test:e2e
npm test
```

Detalles: [`docs/TESTING.md`](docs/TESTING.md).

---

## 17. CI / deploy

Workflow histórico:

```text
.github/workflows/update-release-metadata.yml
```

Nombre visible: **BRVTAL CI**.

### Topología de fast feedback

```text
plan
  ↓
fast (siempre)
  ├── database      ┐
  ├── chromium      ├── en paralelo según paths del PR
  ├── real-stack    │
  └── webkit-totp   ┘
          ↓
       validate
```

`validate` permanece como check final estable. En pull requests, el planner evita gates caros que no aportan señal para los archivos modificados. En `push` a `main` y `workflow_dispatch`, todos los gates son obligatorios.

Flujo obligatorio:

```text
green main
  ↓
focused branch
  ↓
implementation + targeted tests
  ↓
batched branch update
  ↓
Pull Request
  ↓
path-aware BRVTAL CI → validate green
  ↓
squash merge
  ↓
full BRVTAL CI on exact merged main SHA
  ↓
Hostinger Git auto-deploy
  ↓
production verification when actually performed
```

Para trabajo asistido por IA, los cambios relacionados se agrupan antes del push cuando sea práctico. Esto evita iniciar y cancelar múltiples runs por micro-ediciones consecutivas. Los fallos de CI se corrigen en la misma PR y el exact-main gate nunca se omite por velocidad.

### Build / Deploy Summary

Cada run publica GitHub Actions Job Summaries con, cuando aplica:

- event/ref/PR;
- source SHA y checkout SHA;
- commit subject/timestamp;
- actor;
- archivos cambiados;
- áreas afectadas;
- deploy eligibility;
- scope de validación seleccionado;
- resultado de Fast / MariaDB / Chromium / Real-stack / WebKit;
- nota explícita de que CI no prueba el deploy de Hostinger ni producción.

No se reescribe `config/version.php` ni se crean commits de metadata por deploy.

---

## 18. Checklist de características solicitadas vs estado actual

Leyenda: `[x]` implementado; `[ ]` pendiente/diferido; `⚠` requiere verificación operacional adicional.

### DISCADMIN / UX

- [x] Un solo shell/sidebar/session/workspace.
- [x] Sidebar responsive/mobile.
- [x] Navegación simplificada por destinos: Content / Media / Site / System.
- [x] Content Core oculto como concepto interno.
- [x] Events como entrada única al workflow guiado de eventos.
- [x] Artists → Collective Status para roster/lifecycle del colectivo.
- [x] Forms/dialogs consistentes y double-save protection.
- [x] Dark / Light / Glass directo en sidebar.
- [x] Preferencia de appearance persistente.
- [x] Sesión administrativa extendida.
- [x] Global Search.
- [x] Content Health.
- [x] Safe Bulk Actions.
- [ ] Bulk Delete — deliberadamente no implementado.
- [ ] RBAC complejo — diferido hasta necesidad real.

### Content / CMS

- [x] Events lifecycle.
- [x] Event tickets.
- [x] Event lineup / artist participation.
- [x] Artists CRUD.
- [x] Sets CRUD + relaciones/plataformas.
- [x] Releases / label catalog.
- [x] Blog + tags + relaciones.
- [x] CMS Pages.
- [x] Guided event workflow reutilizado internamente.
- [x] SEO fields + defaults.
- [x] Draft/private visibility rules.
- [x] Admin Activity append-only.
- [x] Editorial Version History read-only.
- [ ] Automatic restore/revert — diferido.

### Hero Slider

- [x] Hero Manager en DISCADMIN.
- [x] Image/video slides.
- [x] Múltiples slides.
- [x] Autoplay/interval/pause.
- [x] Mobile-specific media override.
- [x] Desktop/mobile preview.
- [x] Static fallback.
- [x] Layers text/image/logo/CTA.
- [x] Drag pointer/touch.
- [x] Entrance animation + delay/duration.
- [x] Mobile layer overrides/hide.
- [x] Duplicate slide.
- [x] Reduced motion.
- [x] Deferred inactive images.
- [ ] Scheduled start/end.
- [ ] Full timeline editor.
- [ ] Drag-and-drop slide ordering si sigue pendiente.

### Media

- [x] Media Library visual.
- [x] Reusable picker.
- [x] Upload validation.
- [x] Original preservation.
- [x] WebP/context variants.
- [x] Focal point / crop preview.
- [x] Resolution guidance.
- [x] Reference-aware delete protection.

### Public experience

- [x] Responsive Home.
- [x] Mobile performance fallbacks.
- [x] Mobile/reduced-motion evita descargar GSAP / ScrollTrigger / Lenis y conserva el runtime de contenido.
- [x] Fallback de contenido si falla el CDN opcional de motion.
- [x] Non-blocking Google Fonts.
- [x] Mobile loader bypass.
- [x] Keyboard/touch accessibility passes.
- [x] Event lifecycle rendering.
- [x] Archive histórico.
- [x] Archive search/year/relationship filters.
- [x] Archive URL state + browser history.
- [x] Public Media discovery.
- [x] Media URL state + browser history.
- [x] Related Content.
- [x] CONNECTED navegable en Artists / Events / Sets / Releases.
- [x] Canonical entity pages.
- [x] SEO/OG/Twitter/JSON-LD.
- [x] Analytics consent foundation.
- [ ] Mayor profundidad Archive/Media mediante relaciones reales cuando aporte valor.
- [ ] Core Web Vitals production measurements documentadas.
- [ ] Authenticated production smoke formal. ⚠

### Security

- [x] Centralized admin auth.
- [x] CSRF.
- [x] Prepared statement policy.
- [x] Login rate limiting.
- [x] TOTP / 2FA.
- [x] Encrypted TOTP secrets.
- [x] Recovery codes.
- [x] WebKit login regression coverage.
- [x] Private settings protection.

### Operations / deploy

- [x] GitHub PR workflow.
- [x] BRVTAL CI.
- [x] Fast syntax/contract gate siempre activo.
- [x] PR CI path-aware con gates caros selectivos.
- [x] Database / Chromium / Real-stack / WebKit separados para paralelismo.
- [x] Cache de npm/browsers en CI.
- [x] MariaDB integration tests.
- [x] Playwright browser tests.
- [x] Exact-main-SHA full CI gate.
- [x] Check agregado final `validate` estable.
- [x] GitHub `main` → Hostinger auto-deploy.
- [x] Runtime deploy SHA resolution.
- [x] System Status v2.
- [x] Backups Foundation v1.
- [x] Build/Deploy Job Summary.
- [ ] One-click restore — deliberadamente no implementado.
- [ ] Backup restore rehearsal en entorno seguro.

### Futuro de producto

- [ ] Booking/community.
- [ ] Multi-admin/RBAC avanzado si aparece necesidad real.
- [ ] Mayor profundidad editorial/label según uso real.

---

## 19. Siguiente prioridad

1. profundizar Archive/Media solo donde existan relaciones estructuradas reales y útiles;
2. continuar performance/responsive polish basado en mediciones reales; el runtime adaptativo ya elimina dependencias motion innecesarias en mobile/reduced-motion, pero CWV de producción sigue sin documentar;
3. authenticated production smoke seguro/documentado;
4. backup recovery rehearsal aislado;
5. seguir simplificando DISCADMIN solo cuando haya fricción concreta;
6. Hero Slider incremental sin convertirlo en page builder genérico.

Evitar trabajo prematuro en Wix/Elementor-style builders, Bulk Delete, automatic restore y RBAC complejo.

---

## 20. Runbook rápido

### Antes de desarrollar

1. leer `AGENTS.md`;
2. revisar PRs abiertos y CI de `main`;
3. no abrir nueva branch hasta tener exact-main CI verde.

### Antes de abrir/actualizar PR

1. branch enfocada;
2. completar el conjunto lógico de cambios antes de hacer push cuando sea práctico;
3. ejecutar/inspeccionar los tests más dirigidos disponibles;
4. evitar micro-pushes que solo reinician CI sin aportar una nueva hipótesis.

### Antes de mergear

1. tests aplicables;
2. PR;
3. check final `validate` verde;
4. squash merge.

### Después de mergear

1. obtener SHA exacto;
2. verificar el full BRVTAL CI sobre ese SHA;
3. revisar Build / Deploy Summary;
4. distinguir auto-deploy esperado de deploy comprobado;
5. hacer production smoke cuando sea seguro y realmente necesario.

### Si hay migración

Source deploy y DB migration son operaciones separadas.

---

## 21. Fuentes de verdad

| Fuente | Responsabilidad |
|---|---|
| Código + PRs recientes | Estado final cuando contradice prose antigua |
| `AGENTS.md` | Bootstrap canónico y decisiones durables para IA |
| `README.md` | Manual técnico/checklist humano |
| `docs/BRVTAL-SPEC.md` | Arquitectura/producto profundo |
| `docs/HERO-SLIDER.md` | Contrato Hero Slider |
| `docs/DISCADMIN-UX-AUDIT.md` | UX/responsive debt/history |
| `docs/TESTING.md` | Estrategia de pruebas |

---

## 22. Principios de producto

1. Una plataforma, no herramientas desconectadas.
2. El menú expresa tareas del usuario, no arquitectura interna.
3. Relaciones = structured data.
4. Drafts son first-class.
5. El CMS aconseja; no publica autónomamente.
6. Media es reutilizable y preserva originales.
7. Public exposure es explícito/allowlisted.
8. Production SQL es deliberado y separado del source deploy.
9. Mobile es target de primera clase.
10. CI demuestra código; no inventa validación de producción.
11. Observabilidad no genera commits de metadata.
12. Fast feedback en PR no reemplaza la validación completa del SHA exacto de `main`.