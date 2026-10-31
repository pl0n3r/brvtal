"""Registro fail-closed de imágenes versionadas: BRVTAL #1356.

Este contrato supervisa imágenes estáticas bajo assets/. No sustituye un control
transaccional en Media Library para uploads/ del servidor: esa operación exige
una hoja/claim propio antes de permitir publicación de imágenes dinámicas.
"""
from __future__ import annotations

import copy
import json
import re
import unittest
from pathlib import Path, PurePosixPath

ROOT = Path(__file__).resolve().parents[1]
REGISTRY = ROOT / 'config/derechos_imagenes.json'
IMAGE_EXTENSIONS = {'.avif', '.gif', '.jpeg', '.jpg', '.png', '.svg', '.webp'}
APPROVED_PERMISSIONS = {'propia', 'licencia', 'autorizacion_por_escrito'}
ALLOWED_PERMISSIONS = APPROVED_PERMISSIONS | {'pendiente'}
REQUIRED_FIELDS = {'archivo', 'autor', 'fuente', 'tipo_permiso', 'estado', 'evidencia', 'nota'}
UNKNOWN = {'', 'pendiente', 'pendiente_de_acreditar', 'desconocido', 'no_verificado', 'n/a'}


def public_image_paths(root: Path) -> set[str]:
    """Todas las imágenes estáticas entregables; no cuentan referencias en docs/."""
    assets = root / 'assets'
    if not assets.is_dir() or assets.is_symlink():
        raise ValueError('Missing or unsafe assets directory')
    results: set[str] = set()
    for candidate in assets.rglob('*'):
        if candidate.suffix.lower() not in IMAGE_EXTENSIONS:
            continue
        if candidate.is_symlink():
            raise ValueError(f'Symlink image forbidden: {candidate}')
        if candidate.is_file():
            results.add(candidate.relative_to(root).as_posix())
    return results


def audit_registry(registry: dict, images: set[str], *, live: bool = False) -> list[str]:
    errors: list[str] = []
    if not isinstance(registry, dict) or registry.get('version') != 1:
        return ['Invalid rights registry version']
    if registry.get('fase') not in {'construccion', 'live'}:
        errors.append('Missing or unknown phase')
    if registry.get('fase') == 'live':
        live = True
    rows = registry.get('imagenes')
    if not isinstance(rows, list):
        return errors + ['Missing image records']
    covered: set[str] = set()
    for position, row in enumerate(rows):
        if not isinstance(row, dict) or set(row) != REQUIRED_FIELDS:
            errors.append(f'Invalid fields for image record #{position}')
            continue
        name = row['archivo']
        if not isinstance(name, str) or not name.startswith('assets/') or '\\' in name or '\x00' in name:
            errors.append(f'Invalid path in record #{position}')
            continue
        p = PurePosixPath(name)
        if '..' in p.parts or '.' in p.parts or p.suffix.lower() not in IMAGE_EXTENSIONS or p.as_posix() != name:
            errors.append(f'Unsafe image path: {name}')
            continue
        if name in covered:
            errors.append(f'Duplicate image record: {name}')
        covered.add(name)
        author, source = row['autor'], row['fuente']
        permission, state, evidence, note = row['tipo_permiso'], row['estado'], row['evidencia'], row['nota']
        if not isinstance(author, str) or not author.strip():
            errors.append(f'Author field missing: {name}')
        if not isinstance(source, str) or not source.strip():
            errors.append(f'Source field missing: {name}')
        if permission not in ALLOWED_PERMISSIONS:
            errors.append(f'Invalid permission type: {name}')
        if state not in {'aprobado', 'provisional'}:
            errors.append(f'Invalid rights status: {name}')
        if not isinstance(note, str) or not note.strip():
            errors.append(f'Missing rights rationale: {name}')
        if isinstance(evidence, str):
            if not re.fullmatch(r'(?:docs|revision-juridica)/[a-zA-Z0-9/_-]+\.(?:md|pdf)', evidence) or '..' in PurePosixPath(evidence).parts:
                errors.append(f'Unsafe evidence reference: {name}')
        elif evidence is not None:
            errors.append(f'Invalid evidence format: {name}')
        is_placeholder = '/placeholders/' in name
        if state == 'aprobado':
            if is_placeholder:
                errors.append(f'Placeholder cannot be approved: {name}')
            if permission not in APPROVED_PERMISSIONS:
                errors.append(f'Approved without license/authorization: {name}')
            if not isinstance(author, str) or author.strip().lower() in UNKNOWN:
                errors.append(f'Approved without verified author: {name}')
            if not isinstance(source, str) or source.strip().lower() in UNKNOWN:
                errors.append(f'Approved without verified source: {name}')
            if not isinstance(evidence, str) or not evidence:
                errors.append(f'Approved without evidence: {name}')
        if state == 'provisional' and permission != 'pendiente':
            errors.append(f'Provisional with claimed permission: {name}')
        if live and state != 'aprobado':
            errors.append(f'Unlicensed/provisional asset blocks live: {name}')
    for missing in sorted(images - covered):
        errors.append(f'Missing rights record: {missing}')
    for extra in sorted(covered - images):
        errors.append(f'Stale or nonexistent asset registration: {extra}')
    return errors


class DerechosImagenesTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls) -> None:
        cls.registry = json.loads(REGISTRY.read_text(encoding='utf-8'))
        cls.images = public_image_paths(ROOT)

    def test_criterio_principal(self) -> None:
        """Abarca 100% de las imágenes versionadas con registro explícito."""
        self.assertTrue(self.images)
        self.assertEqual([], audit_registry(self.registry, self.images))

    def test_falla_si_falta_registro(self) -> None:
        sample = copy.deepcopy(self.registry)
        sample['imagenes'].pop()
        self.assertTrue(any('Missing rights record' in e for e in audit_registry(sample, self.images)))

    def test_no_aprueba_licencias_inventadas(self) -> None:
        sample = copy.deepcopy(self.registry)
        row = sample['imagenes'][0]
        row['estado'] = 'aprobado'
        row['tipo_permiso'] = 'propia'
        self.assertTrue(any('Approved without' in e for e in audit_registry(sample, self.images)))

    def test_placeholder_sigue_provisional(self) -> None:
        sample = copy.deepcopy(self.registry)
        row = next(x for x in sample['imagenes'] if '/placeholders/' in x['archivo'])
        row.update(estado='aprobado', tipo_permiso='licencia', autor='Atribuido', fuente='Archivo', evidencia='docs/licencia.md')
        self.assertTrue(any('Placeholder cannot be approved' in e for e in audit_registry(sample, self.images)))

    def test_live_falla_con_provisionales(self) -> None:
        findings = audit_registry(self.registry, self.images, live=True)
        self.assertTrue(any('blocks live' in f for f in findings))
        self.assertEqual(sum(x['estado'] != 'aprobado' for x in self.registry['imagenes']), sum('blocks live' in f for f in findings))

    def test_rechaza_camino_fuera_assets(self) -> None:
        sample = copy.deepcopy(self.registry)
        sample['imagenes'][0]['archivo'] = 'assets/../docs/private.png'
        self.assertTrue(any('Unsafe image path' in e for e in audit_registry(sample, self.images)))

    def test_autorizacion_acreditada_en_fixture(self) -> None:
        path = 'assets/test.png'
        fixture = {'version': 1, 'fase': 'live', 'imagenes': [{
            'archivo': path, 'autor': 'Autor documental', 'fuente': 'Fuente documental',
            'tipo_permiso': 'autorizacion_por_escrito', 'estado': 'aprobado',
            'evidencia': 'docs/autorizacion.md', 'nota': 'Fixture exclusivamente sintético',
        }]}
        self.assertEqual([], audit_registry(fixture, {path}, live=True))


if __name__ == '__main__':
    unittest.main()
