<p align="center">
  <img src="assets/brvtal-logo-640.webp" alt="BRVTAL — Rave till Grave" width="190">
</p>

# BRVTAL — Último deploy

<p align="center"><strong>Cultural archive · contextual TRANSMISSIONS</strong></p>

<p align="center">
  <a href="https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml"><img alt="BRVTAL CI" src="https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg"></a>
</p>

> Este README representa **solo el deploy actual**. Se reemplaza en el siguiente deploy y no funciona como changelog acumulativo.

## Estado del deploy

| Señal | Estado | Evidencia |
| --- | --- | --- |
| Base exacta | ✅ **VALIDATED IN CODE** | `main` `50b8a6b7253c000565f23d16e9e5355b660b7806` · exact-main BRVTAL CI verde |
| Alcance | 🔎 **#511 / parent #398** | inverse TRANSMISSIONS on canonical entity pages |
| Relaciones | 🧬 **EXPLICIT ONLY** | `blog_post_relations` |
| Visibilidad | 🔒 **PUBLISHED ONLY** | drafts/private posts excluded |
| Producción | ⚪ **No validada** | CI no equivale a validación de producción |

## Flujo de entrega

```mermaid
flowchart LR
    A["PR + snapshot exacto"] --> B["BRVTAL CI"]
    A --> C["Sonar"]
    B --> D["Head estable"]
    C --> D
    D --> E["CodeRabbit full review"]
    E --> F["Squash merge"]
    F --> G["CI del SHA exacto de main"]
```

## Qué se hizo

- Centraliza la resolución inversa de Blog/TRANSMISSIONS para Event, Artist, Set y Release.
- Solo usa relaciones explícitas `blog_post_relations`; no infiere conexiones.
- Mantiene Event Record y extiende el mismo comportamiento a Artist, Set y Release.
- Las tarjetas navegan a la URL canónica `/blog/{slug}`.
- Los Blog drafts quedan excluidos por el query de publicación.
- TRANSMISSIONS y Memories se ensamblan en un único bloque cultural compartido para evitar divergencias entre entidades.
- La cobertura nueva es behavior-first: mapping/render ejecutable + integración MariaDB.
- Se elimina del Event Record una comprobación frágil que dependía del SQL literal anterior.

## Archivos modificados en este deploy

- `AGENTS.md` — contrato durable de relaciones inversas TRANSMISSIONS.
- `README.md` — snapshot exacto del deploy #511.
- `config/public_page.php` — helper central y entrega contextual a entidades canónicas.
- `package.json` — incorpora la integración MariaDB al suite canónico.
- `tests/contextual-transmissions-contract.php` — contrato ejecutable de mapping/render.
- `tests/event-record-contract.php` — elimina aserciones frágiles sobre SQL literal.
- `tests/integration/contextual-transmissions.php` — publicación/relaciones reales en MariaDB.

## Validación

- Base exacta `50b8a6b7253c000565f23d16e9e5355b660b7806`: exact-main BRVTAL CI verde tras #510.
- PHP 8.5 debe ejecutar el contrato behavior-first.
- MariaDB debe probar Event/Artist/Set/Release, draft exclusion y aislamiento por entity ID.
- Sonar debe mantenerse en cero issues nuevos accionables.
- La revisión CodeRabbit profunda se solicita únicamente sobre el head estable.
- Tras squash merge se verificará BRVTAL CI sobre el SHA exacto de `main`.
- No se declara **VALIDATED IN PRODUCTION** desde CI.

## Qué sigue

1. Cerrar #511 / PR #512 con revisión final del head estable y exact-main CI.
2. Implementar #527 — transformar README en un dashboard profesional de desarrollo.
3. Continuar Phase 1 del roadmap #533, de quick wins hacia cambios más complejos.

## Panorama general pendiente

| Frente | Estado / siguiente foco |
| --- | --- |
| 📊 **Development dashboard** | #527 · siguiente prioridad después de cerrar este PR |
| 🗺️ **Roadmap activo** | #533 · ejecución ordenada de fácil/bajo riesgo → arquitectura compleja |
| ⚡ **Quick wins Admin** | #520, #521, #526, #522, #479, #517, #221 |
| 🧩 **Dashboard configurable** | #513 · fase posterior: módulos, drag/drop, resize y Analytics reales |
| 🗃️ **Archivo cultural** | #398 · continuar por fases después de la estabilización priorizada |
| 🎛️ **Apariencia** | #149 + #514 · Light/Glass completos y Admin premium/legible |
| 🖼️ **Hero Slider** | #221 integridad editorial · #480 regresión visual desktop |
| 🔐 **Seguridad editorial** | #257, #216, #174, #193 |
| 📈 **Analytics** | #427 · completar eventos `brvtal_*` en GTM/GA4 |
| ✍️ **Content / edición** | #224, #252 |
| 🧾 **Activity / operaciones** | #232, #195 |
| 📚 **Bulk Actions** | #275 · registros >500 sin falsa exhaustividad |
| 🌐 **Idioma** | #212 · español canónico + inglés automático por fases |
| 💾 **Backups** | #389 · scheduling seguro + Drive opcional |

---

<p align="center"><sub>BRVTAL · Rave till Grave · deploy snapshot</sub></p>
