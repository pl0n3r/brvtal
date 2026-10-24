# Reconciliación de Issues abiertos — BRVTAL · 24 de octubre de 2026

<!-- brvtal-reconciliation-snapshot:2026-10-24:main:4edbf3fdb4302f3c1034e27f007ba09638cfeebc -->

## Alcance y regla de cierre

Corte reproducible de los **10 issues abiertos** (sin contar los 5 PR abiertos) en `pl0n3r/brvtal`, comprobados en el repositorio autenticado el 24-10-2026. Base exacta: [main@4edbf3fd](https://github.com/pl0n3r/brvtal/commit/4edbf3fdb4302f3c1034e27f007ba09638cfeebc). Cada fila declara la decisión, un enlace a evidencia y la siguiente condición verificable. Esta tabla **no** es una orden de cerrar tickets; tras la fusión del PR que entrega esta tabla el corte seguirá siendo histórico, no un contador en tiempo real.

**Criterio estricto para `CERRAR`**: únicamente si la entrega correspondiente está integrada en el `main` exacto vigente, cumple todos los AC (incluidos los operativos o visuales que apliquen), y la validación CI exigida está acreditada. Cuando falta cualquier premisa, **mantener abierto** y documentar el desbloqueo real. No cerrar una épica porque una hoja subordinada quedó fusionada. No usar `workflow success` como sustituto de D-043/producción.

**Puerta transversal vigente:** [#1009](https://github.com/pl0n3r/brvtal/issues/1009) registra que el [ruleset #25587532](https://github.com/pl0n3r/brvtal/rules/25587532) sí está `active` y protege `main` (PR obligatorio; sin deletion, force-push ni bypass), pero solo requiere `SonarCloud Code Analysis`/12526. Falta exigir **`validate`/15368**, sin suprimir SonarCloud; hasta completar y releer el enforcement, D-069 veta la fusión autónoma. También hay discrepancia **`datos.yml.phase=construccion`** frente a **`readme/project.json.phase=live`**, que no debe corregirse inventando autorización.

## Decisiones por issue

| Issue | Estado en el corte | Decisión | Evidencia verificable | Próxima acción / autoridad |
| --- | --- | --- | --- | --- |
| #1016 | available → reserva V3 solicitada | ENTREGAR PR; NO CERRAR antes de fusión y AC | [Contrato AC-01 y dos paths](https://github.com/pl0n3r/brvtal/issues/1016) | Documentar las diez filas, ejecutar `tests/test_reconciliacion_issues_20261024.py`, obtener PR con la reserva V3 auténtica. Merge solo tras #1009 y gates exact-HEAD; el cierre del issue requiere fusión efectiva. |
| #1014 | in review; [PR #1015](https://github.com/pl0n3r/brvtal/pull/1015) DRAFT | MANTENER ABIERTO: aceptación de README preparada, no integrada | [Issue #1014 / contrato de adopción README v2](https://github.com/pl0n3r/brvtal/issues/1014) | Titular de la V3 completa comprobación de fase, coordinación, CI y protección #1009; solo entonces fusionar el PR, sin reabrir trabajo paralelo. |
| #1009 | blocked; protección parcial | ACTUALIZAR: AC-01 protegido, AC-02–04 pendientes | [Regla requerida y contexto exacto](https://github.com/pl0n3r/brvtal/issues/1009) | Administrador autorizado agrega `validate` de GitHub Actions/15368 y conserva SonarCloud/12526, PR obligatorio, anti-force/delete y bypass vacío; releer ruleset. |
| #987 | in review; [PR #1008](https://github.com/pl0n3r/brvtal/pull/1008) DRAFT | MANTENER ABIERTO: duraciones CMS sin fusión | [Issue #987 / aceptación de duraciones auténticas](https://github.com/pl0n3r/brvtal/issues/987) | Titular de la reserva V3 valida el alcance/duración real sin inventar tiempos; resolver CI y la puerta #1009 antes de fusionar; no cerrar la hoja por PR abierto. |
| #985 | blocked; WebP roto tras replay | MANTENER ABIERTO: bug reproducido, sin PR propio | [Reproducción y claims de versión](https://github.com/pl0n3r/brvtal/issues/985) | Liberar serialmente `config/version.php` y `package.json` que ocupa #973; coordinar nueva V3, reparar fallback y probar Playwright. #1009 sigue siendo gate de merge. |
| #975 | blocked; placeholders CMS Concept 05 | MANTENER ABIERTO: AC-01–03 y dependencias pendientes | [Medios CMS y evidencia 390/1440](https://github.com/pl0n3r/brvtal/issues/975) | Completar hojas anteriores, registrar medios originales sintéticos de manera legítima y verificar reemplazo CMS/capturas; no fabricar aceptación visual ni publicar datos. |
| #974 | blocked; shell/nav/footer Concept 05 | MANTENER ABIERTO: AC-01–04 y dependencias pendientes | [Referencia visual y claims exclusivos](https://github.com/pl0n3r/brvtal/issues/974) | Finalizar dependencia #973, recuperar disponibilidad de rutas compartidas; crear reserva válida y adjuntar pruebas/capturas a 390/1440 antes de fusión. |
| #973 | in review; [PR #1012](https://github.com/pl0n3r/brvtal/pull/1012) DRAFT | MANTENER ABIERTO: AC-03 visual sin cumplir | [Criterios Journal 06 / Connected 07](https://github.com/pl0n3r/brvtal/issues/973) | Completar captura real 1440/390 comparada con referencia y resolver diferencias visuales dentro de V3 vigente; conservar otros claims y gate #1009. |
| #533 | blocked; épica de ejecución | MANTENER ABIERTO: roadmap con hojas pendientes | [Roadmap y relaciones de dependencias](https://github.com/pl0n3r/brvtal/issues/533) | Mantener desglose/estado del roadmap y reconciliarlo al integrar sus hojas; no cerrar por merges aislados mientras haya requisitos sin acreditar. |
| #212 | blocked; internacionalización real | MANTENER ABIERTO: transporte fake no equivale a DeepL activo | [Selección DeepL y condición humana/operativa](https://github.com/pl0n3r/brvtal/issues/212) | Puerta específica [#920](https://github.com/pl0n3r/brvtal/issues/920): autorización/credenciales, privacidad, costes, kill switch, rollout y evidencia real antes de declarar traducción activa; sin bypass. |

## PRs abiertos y exclusividad

En el corte había **5 PR abiertos**: [#1015](https://github.com/pl0n3r/brvtal/pull/1015), [#1012](https://github.com/pl0n3r/brvtal/pull/1012), [#1008](https://github.com/pl0n3r/brvtal/pull/1008) y Dependabot [#999](https://github.com/pl0n3r/brvtal/pull/999), [#998](https://github.com/pl0n3r/brvtal/pull/998). Los tres primeros pertenecen a reservas V3 de otras líneas y **no se modifican** desde #1016. Los PR de Dependabot tampoco constituyen trabajo completado de los diez issues de producto/calidad. Las rutas exclusivas de #1016 son únicamente esta tabla y su prueba de aceptación; ningún cambio de runtime, fases, imágenes, CMS, datos personales, protección o publicación.

## Evidencia de la reconciliación

- Inventario de GitHub: [issues abiertos](https://github.com/pl0n3r/brvtal/issues?q=is%3Aissue%20is%3Aopen) y [PRs abiertos](https://github.com/pl0n3r/brvtal/pulls?q=is%3Apr%20is%3Aopen).
- Gobierno: [interruptor Factory #767](https://github.com/pl0n3r/Factory/issues/767) en RUNNING y [freeze #921](https://github.com/pl0n3r/Factory/issues/921) cerrado en el corte.
- Aceptación ejecutable: `python3 -m unittest -v tests.test_reconciliacion_issues_20261024.ReconciliacionIssuesTests.test_acceptance`. El método exige 10 filas únicas, referencias por issue, evidencia de GitHub y siguiente acción para cada decisión.
- **Ningún Issue se ha cerrado por efecto de esta reconciliación.** Cada cierre posterior requiere un nuevo preflight exact-main, aceptación y CI antes de mutar su estado.
