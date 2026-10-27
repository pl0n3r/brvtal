Última actualización automática de las fuentes: 2026-09-29 · SHA de origen: `122f9c5b4bd97f3823534a0f844ebaed738aad12`

# Carpeta de revisión jurídica · BRVTAL

**Estado: `documented_not_legally_approved`.** Este paquete técnico requiere revisión del abogado: no implica aprobación jurídica, publicación, go-live ni uso de datos personales reales.

## Procedencia

- Proyecto `pl0n3r/brvtal`, versión declarada `0.1.152`.
- Fuentes canónicas `docs/privacidad/` y `datos.yml`. Última modificación de esas fuentes: 2026-09-29, commit `122f9c5b4bd97f3823534a0f844ebaed738aad12`.
- Los seis documentos incluidos son copias byte a byte de sus fuentes. Las correcciones jurídicas se hacen en las fuentes, y luego se regenera.

## Documentos que valida el abogado

| Documento de esta carpeta | Fuente canónica | Qué debe confirmar el abogado |
| --- | --- | --- |
| `aviso-privacidad.md` | `docs/privacidad/aviso-privacidad.md` | Aviso/autorización: corregir o aprobar su redacción. |
| `canal-derechos.md` | `docs/privacidad/canal-derechos.md` | Canal de ejercicio de derechos: corregir o aprobar su redacción. |
| `politica-tratamiento.md` | `docs/privacidad/politica-tratamiento.md` | Política de tratamiento: corregir o aprobar su redacción. |
| `registro-tratamientos.md` | `docs/privacidad/registro-tratamientos.md` | Registro de operaciones y finalidades: corregir o aprobar su redacción. |
| `retencion.md` | `docs/privacidad/retencion.md` | Plazos de conservación: corregir o aprobar su redacción. |
| `terminos-condiciones.md` | `docs/privacidad/terminos-condiciones.md` | Términos y condiciones: corregir o aprobar su redacción. |
| `paquete-completo.md` | Los seis anteriores | Concatenación íntegra y ordenada para entrega única. |

## Información pendiente del responsable

- [ ] Nombre o razón social del responsable
- [ ] NIT/identificación del responsable
- [ ] Domicilio y dirección de contacto
- [ ] Correo para ejercer derechos
- [ ] Finalidades, bases jurídicas, consentimientos y comunicaciones
- [ ] Retención, supresión, transferencias y acuerdos con encargados
- [ ] Condiciones contractuales, jurisdicción y atención de reclamaciones

## Proveedores/subprocesadores declarados en el repositorio

Extraídos de `datos.yml`; su presencia no demuestra que estén activos. Verificar operación real y contratos:

- `google_analytics`: declarado; confirmar relación operativa y contractual.
- `smtp_mail_provider`: declarado; confirmar relación operativa y contractual.

## Entrega y mantenimiento

El dueño puede extraer estos archivos y entregarlos al abogado; **no completar datos personales ni secretos en esta carpeta**. La validación jurídica se documenta fuera de este proceso.

- Generar: `python3 scripts/generar_revision_juridica.py`
- Verificar sin escribir: `python3 scripts/generar_revision_juridica.py --check`
- Un workflow en main verifica las fuentes tras cada push relevante y diariamente; si hay desfase, registra un Issue para corregir por PR, sin escribir directamente en main.
