# BRVTAL #1427 — Retirada reversible de contenido de prueba

**Estado:** preparación documental; **NO ejecutado en DISCADMIN ni validado en producción después de un cambio**. Esta guía no es un certificado de cumplimiento de AC-01/AC-02.

**Autoridad:** [issue #1427](https://github.com/pl0n3r/brvtal/issues/1427), decisión explícita del propietario del 2026-11-03 (comentario de `pl0n3r`, «se autoriza despublicar (archivar) los registros de prueba»). La autorización **no** permite borrar registros/archivos ni alterar los que estén enlazados a eventos o artistas. Credenciales y datos privados nunca se publican.

## Inventario público previo (lectura sin escrituras, 2026-11-03)

| Candidato observado | URL pública | Evidencia | ID y estado anterior en MySQL |
| --- | --- | --- | --- |
| DUMMY SET 01 // BRVTAL CORE | https://www.brvtal.com.co/sets/dummy-set-01 | Visible en home, ficha y sitemap ES/EN | **Sin comprobar en DISCADMIN** |
| DUMMY SET 02 // GENESIS | https://www.brvtal.com.co/sets/dummy-set-02 | Visible en home y sitemap ES/EN; confirmar si es registro de prueba y sus relaciones | **Sin comprobar en DISCADMIN** |
| sample-set-one | https://www.brvtal.com.co/sets/sample-set-one | La ruta directa devolvió 404; el issue indica este slug en producción: buscar en DISCADMIN sin asumir que existe o está publicado | **Sin comprobar en DISCADMIN** |
| UMBRAL 03 NUCLEO | https://www.brvtal.com.co/sets/umbral-03-nucleo | Es contenido ficticio de demostración en home/sitemap, pero tiene potencial vínculo con evento/artista: **no tocar si hay cualquier relación** | **Sin comprobar en DISCADMIN** |

El sitemap público observado fue https://www.brvtal.com.co/sitemap.xml; contenía `/sets/dummy-set-01` y `/sets/dummy-set-02` en español e inglés. La ruta índice `/sets` devolvió 404 en la lectura pública; esto no prueba ausencia de los registros ni permite concluir que el estado vacío funciona. La API pública y los IDs/estados originales no se han verificado. Los resultados pueden cambiar antes de ejecutar.

## Procedimiento de ejecución protegido

1. Acceder a **https://www.brvtal.com.co/discadmin** con una sesión administrativa autorizada; no extraer ni escribir tokens o contraseñas en GitHub. Revisar los módulos de sonido/sets disponibles y anotar el endpoint de consulta pública realmente usado por el sitio, sin inventar una ruta.
2. Localizar los candidatos por **título y slug exactos**. Para cada registro, confirmar **ID**, **slug**, **título**, **estado original** y relaciones a evento/artista. Registrar estos datos no secretos en el issue **antes** de cualquier edición y obtener constancia del mismo conjunto tras el cambio.
3. **Si está enlazado a un evento o artista, no modificar ese registro.** Reportarlo en el issue con su ID/título y relación para que el propietario decida cómo proceder. No desvincular, no borrar, no sustituir contenido real.
4. Para registros inequívocamente de prueba y **sin relaciones protegidas**, usar exclusivamente el control de **despublicar/archivar** de DISCADMIN. Guardar uno por uno y comprobar el estado resultante, sin SQL destructivo ni eliminación de archivos. Una edición sin estado anterior verificable está prohibida.
5. Validar en **español e inglés**: home, sección SOUND, enlaces/detalles `/sets/<slug>`, índice de biblioteca donde exista, solicitudes a la API pública real y sitemap. Las fichas archivadas no deben filtrarse por API ni figurar en sitemap; no interpretar un 404 genérico del índice como éxito de la biblioteca.
6. Si la biblioteca queda sin registros, comprobar navegación, accesibilidad, ausencia de errores HTTP/JS y estado vacío correcto. Si se rompe, **crear un issue separado** con pasos de reproducción; no ampliar la intervención fuera de #1427.
7. Escribir en [#1427](https://github.com/pl0n3r/brvtal/issues/1427) el inventario ejecutado (ID, título, slug, estado anterior, estado posterior, vínculos verificados), evidencia HTTP/API/sitemap y un procedimiento de reversión. No marcar criterios de aceptación hasta tener evidencia real de cada uno.

### Reversión

Desde DISCADMIN, buscar cada **ID** modificado, verificar que el registro sigue sin asociaciones inesperadas y devolverle **exactamente su estado original documentado**. Comprobar su exposición pública después de la reversión. Si no se conoce el estado original o existen cambios ajenos posteriores, **no efectuar reversión automática**: escalar al propietario para evitar sobrescribir datos recientes.

## Entrega y aceptación

- [ ] AC-01: ausencia de todos los registros de prueba autorizados en home, biblioteca, API pública y sitemap ES/EN, sin alterar los relacionados.
- [ ] AC-02: estado vacío funcional, o hallazgo documentado en un issue distinto.
- [ ] AC-03: `factory-ci / Validar` terminal **success** sobre el HEAD exacto del PR.
- [ ] Evidencia de los IDs/estados originales y posteriores y de la reversión registrada en #1427.

**Importante:** un PR que solo añada esta documentación **no** completa la retirada de contenido; no debe fusionarse como cierre de #1427 hasta que las comprobaciones de producción estén satisfechas y se cumplan las puertas de `PLAN-AGENTES.md`.
