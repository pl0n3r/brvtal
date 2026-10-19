#!/usr/bin/env python3
"""Build the lawyer handoff folder from canonical privacy sources without changing legal texts."""
from __future__ import annotations
import argparse
import json
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FOLDER = "revision-juridica"
NAMES = [["aviso-privacidad.md","Aviso/autorización"],["canal-derechos.md","Canal de ejercicio de derechos"],["politica-tratamiento.md","Política de tratamiento"],["registro-tratamientos.md","Registro de operaciones y finalidades"],["retencion.md","Plazos de conservación"],["terminos-condiciones.md","Términos y condiciones"]]
INDEX_TEMPLATE = "Última actualización automática de las fuentes: {day} · SHA de origen: `{sha}`\n\n# Carpeta de revisión jurídica · BRVTAL\n\n**Estado: `documented_not_legally_approved`.** Este paquete técnico requiere revisión del abogado: no implica aprobación jurídica, publicación, go-live ni uso de datos personales reales.\n\n## Procedencia\n\n- Proyecto `pl0n3r/brvtal`, versión declarada `{version}`.\n- Fuentes canónicas `docs/privacidad/` y `datos.yml`. Última modificación de esas fuentes: {day}, commit `{sha}`.\n- Los seis documentos incluidos son copias byte a byte de sus fuentes. Las correcciones jurídicas se hacen en las fuentes, y luego se regenera.\n\n## Documentos que valida el abogado\n\n| Documento de esta carpeta | Fuente canónica | Qué debe confirmar el abogado |\n| --- | --- | --- |\n{docs_rows}| `paquete-completo.md` | Los seis anteriores | Concatenación íntegra y ordenada para entrega única. |\n\n## Información pendiente del responsable\n\n- [ ] Nombre o razón social del responsable\n- [ ] NIT/identificación del responsable\n- [ ] Domicilio y dirección de contacto\n- [ ] Correo para ejercer derechos\n- [ ] Finalidades, bases jurídicas, consentimientos y comunicaciones\n- [ ] Retención, supresión, transferencias y acuerdos con encargados\n- [ ] Condiciones contractuales, jurisdicción y atención de reclamaciones\n\n## Proveedores/subprocesadores declarados en el repositorio\n\nExtraídos de `datos.yml`; su presencia no demuestra que estén activos. Verificar operación real y contratos:\n\n{provider_rows}\n## Entrega y mantenimiento\n\nEl dueño puede extraer estos archivos y entregarlos al abogado; **no completar datos personales ni secretos en esta carpeta**. La validación jurídica se documenta fuera de este proceso.\n\n- Generar: `python3 scripts/generar_revision_juridica.py`\n- Verificar sin escribir: `python3 scripts/generar_revision_juridica.py --check`\n- Un workflow en main verifica las fuentes tras cada push relevante y diariamente; si hay desfase, registra un Issue para corregir por PR, sin escribir directamente en main.\n"
STAMP = re.compile(r"^Última actualización automática de las fuentes: (\d{4}-\d{2}-\d{2}) · SHA de origen: `([a-f0-9]{40})`")


def provenance(root: Path) -> tuple[str, str]:
    shallow = subprocess.run(["git", "rev-parse", "--is-shallow-repository"],
                             cwd=root, capture_output=True, text=True, check=True, timeout=20).stdout.strip()
    if shallow == "true":
        # Depth-1 PR merge refs cannot locate past source commits; verify
        # current source bytes independently below, never guess the SHA.
        first = (root / FOLDER / "00-LEEME.md").read_text(encoding="utf-8")
        match = STAMP.match(first)
        if not match:
            raise ValueError("Origen no acreditado en checkout shallow")
        return match.group(2), match.group(1)
    lines = subprocess.run(["git", "log", "-1", "--format=%H%n%cs", "--", "docs/privacidad", "datos.yml"],
                           cwd=root, capture_output=True, text=True, check=True, timeout=20).stdout.strip().splitlines()
    if len(lines) != 2 or not re.fullmatch(r"[0-9a-f]{40}", lines[0]) or not re.fullmatch(r"\d{4}-\d{2}-\d{2}", lines[1]):
        raise ValueError("SHA/fecha no verificable")
    return lines[0], lines[1]


def version(root: Path) -> str:
    match = re.search(r"BRVTAL_APP_VERSION\s*=\s*'([^']+)'",
                      (root / "config/version.php").read_text(encoding="utf-8"))
    if not match:
        raise ValueError("Version de BRVTAL ausente")
    return match.group(1)


def expected(root: Path, sha: str, day: str) -> dict[str, bytes]:
    data = json.loads((root / "datos.yml").read_text(encoding="utf-8"))
    providers = {name for item in data["treatments"] for name in item.get("providers", [])}
    if not all(isinstance(p, str) and p for p in providers):
        raise ValueError("Proveedor inválido")
    bt = chr(96)
    rows = "".join(f"| {bt}{name}{bt} | {bt}docs/privacidad/{name}{bt} | {desc}: corregir o aprobar su redacción. |\n"
                   for name, desc in NAMES)
    entries = "".join(f"- {bt}{p}{bt}: declarado; confirmar relación operativa y contractual.\n"
                      for p in sorted(providers))
    intro = (INDEX_TEMPLATE.replace("{day}", day).replace("{sha}", sha)
             .replace("{version}", version(root))
             .replace("{docs_rows}", rows).replace("{provider_rows}", entries))
    result = {"00-LEEME.md": intro.encode("utf-8")}
    bundle = ("# Paquete completo para revisión jurídica · BRVTAL\n\n"
              "Estado: `documented_not_legally_approved` (sin aprobación jurídica).\n\n").encode("utf-8")
    for filename, _ in NAMES:
        raw = (root / "docs/privacidad" / filename).read_bytes()
        result[filename] = raw
        bundle += f"---\n\n## docs/privacidad/{filename}\n\n".encode("utf-8") + raw + b"\n\n"
    result["paquete-completo.md"] = bundle
    return result


def synchronize(root: Path, check: bool) -> list[str]:
    sha, day = provenance(root)
    stale = []
    for filename, data in expected(root, sha, day).items():
        target = root / FOLDER / filename
        if not target.is_file() or target.read_bytes() != data:
            stale.append(filename)
            if not check:
                target.parent.mkdir(parents=True, exist_ok=True)
                target.write_bytes(data)
    return stale


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--check", action="store_true")
    args = parser.parse_args()
    try:
        stale = synchronize(ROOT, args.check)
    except (ValueError, KeyError, TypeError, OSError, subprocess.SubprocessError) as error:
        print(f"Error de revisión jurídica: {error}", file=sys.stderr)
        return 2
    if stale:
        print("Desfase detectado: " + ", ".join(stale))
    return 1 if args.check and stale else 0


if __name__ == "__main__":
    raise SystemExit(main())
