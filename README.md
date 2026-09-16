# BRVTAL — Último deploy

[![BRVTAL CI](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml/badge.svg)](https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml)

Este README es un **snapshot operativo de solo el deploy actual**. El contexto durable vive en `AGENTS.md` y `docs/`.

## Qué se hizo

- CodeRabbit queda versionado como capa de revisión **advisory** para PRs a `main`, con `AGENTS.md` como guía canónica y reglas específicas para seguridad, API, DISCADMIN, tests, CI y migraciones.
- SonarQube Cloud queda configurado para su análisis automático ya conectado a GitHub: separa source/tests, excluye material privado/binario y evita duplicar el scanner dentro de BRVTAL CI.
- La suite local deja de mantener listas paralelas: `test:contracts` usa el mismo auto-discovery PHP 8.5 que CI y `test:integration` reúne las integraciones que CI antes añadía manualmente.
- La selección diff-aware usa un clasificador ejecutable compartido por CI y tests; APIs/config pública activan Chromium + backend/real-stack, `api/hero-slider.php` queda incluido y cambios del propio clasificador ejecutan la matriz completa.
- Contact deja de confiar en `CF-Connecting-IP` salvo peer explícitamente confiable, mueve su estado a `storage/rate_limits/` y bloquea `.json` legacy en `storage/`.
- Password y TOTP comparten almacenamiento de rate limits con lock estable y reemplazo atómico: la escritura completa y el flush se validan antes del rename, evitando truncar un estado válido ante fallos de persistencia.
- Los contratos ejecutan fallos concurrentes reales de password/TOTP para demostrar que no se pierden intentos, y verifican reset y umbrales de bloqueo.
- El contrato de recovery ahora valida los triggers contra el mismo clasificador ejecutable que consume CI, eliminando una aserción textual obsoleta del workflow.
- Production Performance diferencia una regresión medida de un runner sin conectividad: si producción no es alcanzable, el resultado queda **INCONCLUSIVE** y se omite la medición.

## Archivos modificados en este deploy

- `.coderabbit.yaml` — política de revisión automática advisory y path instructions de BRVTAL.
- `.github/workflows/production-performance.yml` — clasifica falta de conectividad como inconclusa y omite mediciones no ejecutables.
- `.github/workflows/update-release-metadata.yml` — consume el clasificador diff-aware compartido y reutiliza la suite canónica de integración.
- `.sonarcloud.properties` — scope del análisis automático de SonarQube Cloud sin scanner CI duplicado.
- `README.md` — snapshot operativo exacto de este deploy.
- `api/contact.php` — pasa la configuración al resolver el bucket de rate limit.
- `config/config.example.php` — documenta la allowlist opcional de proxies confiables.
- `config/password_rate_limit.php` — usa almacenamiento atómico y lock estable para fuerza bruta de password.
- `config/public_contact.php` — valida proxy/IP y guarda el limiter bajo el subtree protegido.
- `config/rate_limit_store.php` — persistencia reusable con short-write/flush checks y rename atómico.
- `config/totp_auth.php` — consume el limiter 2FA aislado y resetea el scope de login tras éxito.
- `config/totp_rate_limit.php` — limiter 2FA testeable y concurrent-safe fuera del bootstrap de autenticación.
- `discadmin/totp-api.php` — resetea el scope de rate limit tras deshabilitar 2FA correctamente.
- `docs/TESTING.md` — documenta CodeRabbit + CI, suites canónicas, gates y semántica de performance.
- `package.json` — alinea comandos locales de contratos e integración con CI.
- `scripts/ci-scope.sh` — clasificador ejecutable único para los gates diff-aware.
- `storage/.htaccess` — niega acceso HTTP a JSON de estado legacy.
- `tests/auth-rate-limit-contract.php` — regresión ejecutable de concurrencia, atomicidad, bloqueo y reset.
- `tests/backup-recovery-rehearsal-contract.php` — valida recovery contra el clasificador compartido en vez de duplicar reglas del workflow.
- `tests/ci-scope-contract.php` — ejecuta el clasificador y protege gates, CodeRabbit, Sonar automático y performance inconclusa.
- `tests/e2e/discadmin-password-login-rate-limit.spec.mjs` — alinea la regresión browser con el store atómico compartido.
- `tests/public-contact-contract.php` — cubre spoofing de headers, CIDR confiable y storage privado.

## Validación

- Base exacta: `main` `f61b3e990ea9ee6225defd61b6fe27656b939097`, con `BRVTAL CI / validate` en verde antes de iniciar.
- No había PRs abiertos al crear `test/review-hardening`.
- Issues cubiertos por las regresiones/correcciones: `#263`, `#363`, `#364` y `#367`.
- Se preserva ONE SHELL / ONE SIDEBAR / ONE SESSION / ONE CENTRAL WORKSPACE.
- CodeRabbit permanece no bloqueante; `BRVTAL CI / validate` sigue siendo la frontera automatizada de merge.
- La primera revisión CodeRabbit emitió 4 findings accionables: browser gate de Hero Slider, persistencia fail-closed, concurrencia ejecutable de auth y classifier CI ejecutable. Los cuatro fueron incorporados en la misma rama.
- Un primer head posterior falló `fast` porque el contrato de recovery seguía buscando reglas ya extraídas del workflow; el contrato fue alineado al clasificador compartido en la misma rama.
- SonarQube Cloud ya está conectado mediante su GitHub App; el repositorio no añade una segunda ejecución de scanner.
- Pendiente en este snapshot: re-review CodeRabbit + SonarQube Cloud + matriz BRVTAL CI sobre el head final y, tras squash merge, `validate` del SHA exacto de `main`.
- CI verde significará **VALIDATED IN CODE**. No se declarará **VALIDATED IN PRODUCTION** sin comprobación del deploy real.

## Qué sigue

1. Resolver cualquier finding nuevo válido de CodeRabbit, SonarQube Cloud o BRVTAL CI en la misma rama.
2. Hacer squash merge solo con `BRVTAL CI / validate` verde y revisar las señales externas antes del merge.
3. Verificar `BRVTAL CI / validate` del SHA exacto resultante en `main`.
4. Activar en GitHub repository settings la protección/ruleset que exija `BRVTAL CI / validate` cuando se disponga de una conexión con permisos de Administration.

## Contexto durable

- Bootstrap canónico: `AGENTS.md`.
- Estrategia de validación: `docs/TESTING.md`.
- Issues abordados: `#263`, `#363`, `#364`, `#367`.
