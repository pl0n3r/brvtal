<p align="center">
  <img src="assets/brvtal-logo-640.webp" alt="BRVTAL — Rave till Grave" width="190">
</p>

# BRVTAL — Último deploy

<p align="center">
  <strong>Connected Memories · explicit cultural relationships</strong>
</p>

<p align="center">
  <a href="https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml">
    <img alt="BRVTAL CI" src="https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg">
  </a>
</p>

> Este README representa **solo el deploy actual**. Se reemplaza en el siguiente deploy y no funciona como changelog acumulativo.

## Estado del deploy

| Señal | Estado | Evidencia |
| --- | --- | --- |
| Base exacta | ✅ **VALIDATED IN CODE** | `main` `20701278d410404fd6f9d65b57e3162fc126f540` · BRVTAL CI #1079 |
| Alcance | 🧠 **#499** | relaciones explícitas desde Memory curada |
| Real stack | 🧪 **E2E admin** | login + persistencia + lectura pública con usuario CI aislado |
| Migración | ⚪ **Pendiente de producción** | `migration_memory_relations_01.sql` no se ejecuta automáticamente |
| Producción | ⚪ **No validada por este PR** | CI verde no equivale a validación de producción |

## Flujo de entrega

```mermaid
flowchart LR
    A["PR + snapshot exacto"] --> B["BRVTAL CI"]
    A --> C["Sonar"]
    A --> D["CodeRabbit"]
    B --> E{"Gates verdes"}
    C --> E
    D --> E
    E --> F["Squash merge"]
    F --> G["CI del SHA exacto de main"]
    G --> H["Deploy automático"]
    H --> I["Migración producción separada"]
```

## Qué se hizo

- #499 conecta **Memories curadas** con Event, Artist, Set y Release mediante relaciones explícitas; Media Library sigue siendo almacenamiento y nunca adquiere contexto cultural por inferencia.
- Se añade `memory_relations` como migración aditiva/idempotente con FK a `memories`, tipos allowlisted, deduplicación y orden server-side.
- El editor vive dentro de **MEDIA → Memories**, renderiza catálogos de relaciones de forma lazy y preserva vínculos existentes si una fuente falla temporalmente.
- Memory + relaciones se guardan transaccionalmente con auth/CSRF y validación real de los destinos.
- El listado admin carga relaciones por lote para evitar N+1 y la detección de schema se memoiza sin ocultar una migración aplicada durante el proceso.
- `api/public.php` elimina relaciones hacia entidades no públicas antes de entregar IDs, slugs o etiquetas.
- Home Memories muestra enlaces de contexto canónicos y CONNECTED incorpora Memory edges sin crear una quinta pestaña.
- Las páginas canónicas de Event/Artist/Set/Release muestran únicamente Memories publicadas y relacionadas de forma estructurada.
- La cobertura incluye contratos, MariaDB y Playwright real-stack autenticado con el **usuario E2E/CI**, incluido cleanup estricto de fixtures.
- El estado pre-migración tiene cobertura ejecutable: lecturas degradan de forma segura y escrituras fallan cerradas.
- El código puede desplegarse antes de la migración sin romper Memories: el editor se degrada honestamente hasta que la tabla exista.

## Archivos modificados en este deploy

- `AGENTS.md` — registra que las relaciones pertenecen a Memory curada.
- `README.md` — snapshot visual exacto del deploy y panorama pendiente.
- `api/memories.php` — CRUD transaccional de Memory + relaciones y carga batcheada.
- `api/public.php` — sanitiza relaciones contra los pools públicos finales.
- `config/memory_relations.php` — modelo, validación, sanitización y consultas compartidas.
- `config/public_page.php` — Memories explícitas en páginas canónicas y enlaces internos seguros.
- `css/public-memories.css` — contexto cultural responsive/táctil.
- `database/migration_memory_relations_01.sql` — migración aditiva/idempotente.
- `database/schema.sql` — incorpora `memory_relations` al esquema base.
- `discadmin/memories.css` — UI accesible del editor de relaciones.
- `discadmin/memories.js` — edición lazy y preservación segura de relaciones.
- `js/public-media.js` — enlaces públicos de contexto y búsqueda enriquecida.
- `js/related-content.js` — Memory edges dentro de CONNECTED sin nueva capa.
- `package.json` — integra la nueva prueba MariaDB.
- `scripts/ci-scope.sh` — clasifica cualquier spec `*-real-stack` para el gate autenticado.
- `tests/e2e/discadmin-memories.spec.mjs` — edición aislada y render lazy de relaciones.
- `tests/e2e/memory-relations-real-stack.spec.mjs` — flujo autenticado completo con usuario E2E y cleanup estricto.
- `tests/e2e/public-media.spec.mjs` — contexto público y touch targets.
- `tests/e2e/public-related-content.spec.mjs` — Memory edges y cuatro tabs de CONNECTED.
- `tests/e2e/run-content-core-real-stack.sh` — aplica migraciones en DB descartable y ejecuta el nuevo smoke.
- `tests/event-record-contract.php` — exige Memories estructuradas, no inferidas.
- `tests/integration/memory-relations.php` — persistencia, rollback, privacidad, pre-migración y cascade en MariaDB.
- `tests/memory-relations-contract.php` — contrato de arquitectura/seguridad/entrega pública.

## Validación

- Base exacta: `20701278d410404fd6f9d65b57e3162fc126f540`, validada por BRVTAL CI #1079.
- #502 ya forma parte de esa base; su refactor de Archive y fallback de imagen se preservan sin mezclarlos con el alcance de #499.
- El PR debe volver a pasar **BRVTAL CI / validate**, SonarQube Cloud y CodeRabbit sobre el SHA reconciliado.
- El gate real-stack usa el usuario E2E/CI descartable, no credenciales personales ni datos de producción.
- Tras squash merge se verificará BRVTAL CI sobre el SHA exacto resultante de `main`.
- La migración de producción permanece separada y requiere la frontera operativa habitual.
- Ningún resultado de CI implica por sí solo **VALIDATED IN PRODUCTION**.

## Qué sigue

1. Resolver cualquier finding válido del nuevo ciclo CI/Sonar/CodeRabbit.
2. Squash merge con gates verdes y verificar BRVTAL CI del SHA exacto de `main`.
3. Mantener `migration_memory_relations_01.sql` sin ejecución automática en producción.
4. Continuar #398 usando únicamente relaciones estructuradas reales.
5. Continuar #451 con el siguiente slice de riesgo después de #500 y #502.

## Panorama general pendiente

| Frente | Estado / siguiente foco |
| --- | --- |
| 🗃️ **Archivo cultural** | #398 · #499 conecta Memories; profundizar cross-discovery solo con relaciones reales |
| 🧪 **Sonar / calidad** | #451 · #500 y #502 integrados; continuar siguiente slice de riesgo |
| 🎛️ **Apariencia** | #149 — completar Light en módulos modernos |
| 🖼️ **Hero Slider** | #221 integridad editorial · #480 regresión visual desktop |
| 🔐 **Seguridad editorial** | #257, #216, #174, #193 |
| 🔎 **SEO / entrega pública** | #182, #214, #204, #272 → #390 · #479 alt · #481 IndexNow |
| 📈 **Analytics** | #427 — completar eventos `brvtal_*` dentro de GTM/GA4 |
| ✍️ **Content / edición** | #224, #252 |
| 🧾 **Activity / operaciones** | #232, #195 |
| 📚 **Bulk Actions** | #275 — registros >500 sin falsa exhaustividad |
| 🧭 **DISCADMIN / Theme** | #348, #351 |
| 🌐 **Idioma** | #212 — español canónico + inglés automático por fases |
| 💾 **Backups** | #389 — scheduling seguro + Drive opcional |

---

<p align="center"><sub>BRVTAL · Rave till Grave · snapshot operativo, no historial acumulativo</sub></p>
