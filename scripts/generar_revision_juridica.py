#!/usr/bin/env python3
"""Build the lawyer handoff folder from canonical privacy sources without changing legal texts."""
from __future__ import annotations
import argparse
import hashlib
import json
import re
import subprocess
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
FOLDER = "revision-juridica"
NAMES = [["aviso-privacidad.md","Aviso/autorización"],["canal-derechos.md","Canal de ejercicio de derechos"],["politica-tratamiento.md","Política de tratamiento"],["registro-tratamientos.md","Registro de operaciones y finalidades"],["retencion.md","Plazos de conservación"],["terminos-condiciones.md","Términos y condiciones"]]
INDEX_TEMPLATE = "Última actualización automática de las fuentes: {day} · SHA-256 de fuentes: `{sha}`\n\n# Carpeta de revisión jurídica · BRVTAL\n\n**Estado: `documented_not_legally_approved`.** Este paquete técnico requiere revisión del abogado: no implica aprobación jurídica, publicación, go-live ni uso de datos personales reales.\n\n## Procedencia\n\n- Proyecto `pl0n3r/brvtal`, versión declarada `{version}`.\n- Fuentes canónicas `docs/privacidad/` y `datos.yml`. Última modificación de contenido registrada: {day}; huella SHA-256 de las fuentes: `{sha}`.\n- Los seis documentos incluidos son copias byte a byte de sus fuentes. Las correcciones jurídicas se hacen en las fuentes, y luego se regenera.\n\n## Documentos que valida el abogado\n\n| Documento de esta carpeta | Fuente canónica | Qué debe confirmar el abogado |\n| --- | --- | --- |\n{docs_rows}| `paquete-completo.md` | Los seis anteriores | Concatenación íntegra y ordenada para entrega única. |\n\n## Información pendiente del responsable\n\n- [ ] Nombre o razón social del responsable\n- [ ] NIT/identificación del responsable\n- [ ] Domicilio y dirección de contacto\n- [ ] Correo para ejercer derechos\n- [ ] Finalidades, bases jurídicas, consentimientos y comunicaciones\n- [ ] Retención, supresión, transferencias y acuerdos con encargados\n- [ ] Condiciones contractuales, jurisdicción y atención de reclamaciones\n\n## Proveedores/subprocesadores declarados en el repositorio\n\nExtraídos de `datos.yml`; su presencia no demuestra que estén activos. Verificar operación real y contratos:\n\n{provider_rows}\n## Entrega y mantenimiento\n\nEl dueño puede extraer estos archivos y entregarlos al abogado; **no completar datos personales ni secretos en esta carpeta**. La validación jurídica se documenta fuera de este proceso.\n\n- Generar: `python3 scripts/generar_revision_juridica.py`\n- Verificar sin escribir: `python3 scripts/generar_revision_juridica.py --check`\n- Un workflow en main verifica las fuentes tras cada push relevante y diariamente; si hay desfase, registra un Issue para corregir por PR, sin escribir directamente en main.\n"
STAMP = re.compile(r"^Última actualización automática de las fuentes: (\d{4}-\d{2}-\d{2}) · SHA-256 de fuentes: `([a-f0-9]{64})`")


def source_fingerprint(root: Path) -> str:
    """Stable SHA-256 of canonical source Git blobs, independent of squash commit SHA."""
    names = sorted(["datos.yml"] + [f"docs/privacidad/{name}" for name, _ in NAMES])
    manifest = []
    for name in names:
        path = root / name
        if path.is_symlink() or not path.is_file():
            raise ValueError(f"Fuente de privacidad inválida: {name}")
        raw = path.read_bytes()
        blob = hashlib.sha1(b"blob " + str(len(raw)).encode("ascii") + b"\0" + raw).hexdigest()
        manifest.append(f"{name}\0{blob}\n")
    return hashlib.sha256("".join(manifest).encode("utf-8")).hexdigest()


def provenance(root: Path) -> tuple[str, str]:
    sha = source_fingerprint(root)
    index = root / FOLDER / "00-LEEME.md"
    if index.is_file() and not index.is_symlink():
        stamp = STAMP.match(index.read_text(encoding="utf-8"))
        if stamp and stamp.group(2) == sha:
            # Retain the original source-content date across squash/rebase commits.
            return sha, stamp.group(1)
    shallow = subprocess.run(["git", "rev-parse", "--is-shallow-repository"],
                             cwd=root, capture_output=True, text=True, check=True, timeout=20).stdout.strip()
    if shallow != "false":
        raise ValueError("Huella de fuentes no acreditada en historial shallow o desconocido")
    day = subprocess.run(["git", "log", "-1", "--format=%cs", "--",
                          "docs/privacidad", "datos.yml"],
                         cwd=root, capture_output=True, text=True, check=True, timeout=20).stdout.strip()
    if not re.fullmatch(r"\d{4}-\d{2}-\d{2}", day):
        raise ValueError("Fecha de fuentes no verificable")
    return sha, day

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
    payloads = expected(root, sha, day)
    folder = root / FOLDER
    # The lawyer handoff must contain only the generated, regular root files.
    # Refuse unknown entries and links before doing any writes (no auto-delete).
    if folder.is_symlink() or (folder.exists() and not folder.is_dir()):
        raise ValueError("Carpeta jurídica inválida: no es un directorio regular")
    if folder.is_dir():
        unknown = sorted(
            path.relative_to(folder).as_posix()
            for path in folder.rglob("*")
            if path.is_symlink() or path.is_dir()
            or path.parent != folder or path.name not in payloads
        )
        if unknown:
            raise ValueError("Entradas no previstas en carpeta jurídica: " + ", ".join(unknown))
    stale = []
    for filename, data in payloads.items():
        target = folder / filename
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
