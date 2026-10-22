"""Regresiones de adopción de README Contract v2 en BRVTAL."""
from __future__ import annotations

import json
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
README = ROOT / "README.md"
METADATA = ROOT / "readme" / "project.json"
WORKFLOW = ROOT / ".github" / "workflows" / "readme-contract.yml"
BRVTAL_CI = ROOT / ".github" / "workflows" / "update-release-metadata.yml"
LEGACY_DASHBOARD = ROOT / "scripts" / "readme-dashboard.py"

REQUIRED_METADATA = {"name", "tagline", "role", "phase", "roadmap", "stack"}
FORBIDDEN_OPERATIONAL = {
    "main_sha", "version", "ci", "release", "health", "smoke",
    "quality", "active_issue", "active_pr", "last_release",
}
REQUIRED_SECTIONS = (
    "Operational Cockpit",
    "Work Queue",
    "Qué hace el producto",
    "Arquitectura en 60 segundos",
    "Stack e infraestructura",
    "Ciclo de entrega",
    "Calidad y seguridad",
    "Roadmap y fuentes de verdad",
    "Desarrollo local",
    "Mapa de la fábrica",
)


class ReadmeContractAdoptionTests(unittest.TestCase):
    def readme(self) -> str:
        return README.read_text(encoding="utf-8")

    def test_project_metadata_is_stable_and_complete(self):
        metadata = json.loads(METADATA.read_text(encoding="utf-8"))
        self.assertEqual(set(metadata), REQUIRED_METADATA)
        self.assertFalse(set(metadata) & FORBIDDEN_OPERATIONAL)
        self.assertEqual(metadata["name"], "BRVTAL")
        self.assertEqual(metadata["role"], "product")
        self.assertEqual(metadata["phase"], "live")
        for value in metadata.values():
            self.assertIsInstance(value, str)
            self.assertTrue(value.strip())

    def test_readme_has_contract_v2_anatomy(self):
        readme = self.readme()
        self.assertTrue(readme.startswith("# BRVTAL"))
        for heading in REQUIRED_SECTIONS:
            self.assertEqual(readme.count(f"## {heading}"), 1, heading)
        positions = [readme.index(f"## {heading}") for heading in REQUIRED_SECTIONS]
        self.assertEqual(positions, sorted(positions), "secciones en orden canónico")
        self.assertIn("underground", readme.lower())
        self.assertIn("DISCADMIN", readme)

    def test_readme_is_contract_v2_without_unknown_blocks(self):
        readme = self.readme()
        cockpit = readme.split("## Operational Cockpit", 1)[1].split("\n## ", 1)[0]
        for stale in (
            "UNKNOWN",
            "factory:status",
            "factory:progress-readiness",
            "Progress + Readiness",
        ):
            self.assertNotIn(stale, readme)
        self.assertIn(
            "https://github.com/pl0n3r/brvtal/actions/workflows/update-release-metadata.yml",
            cockpit,
        )
        self.assertIn(
            "https://github.com/pl0n3r/brvtal/releases", cockpit
        )
        self.assertIn("https://control.condorapp.com.co/", cockpit)
        self.assertIn("badge.svg?branch=main", cockpit)
        self.assertIn("img.shields.io/github/v/release/pl0n3r/brvtal", cockpit)
        self.assertNotIn("| Señal | Estado |", cockpit)

    def test_consumer_workflow_uses_factory_v1(self):
        workflow = WORKFLOW.read_text(encoding="utf-8")
        self.assertIn(
            "uses: pl0n3r/factory/.github/workflows/readme.yml@v1",
            workflow,
        )
        self.assertIn("readme_path: README.md", workflow)
        self.assertIn("metadata_path: readme/project.json", workflow)
        self.assertIn("permissions:\n  contents: read", workflow)
        self.assertNotIn("@main", workflow)

    def test_work_queue_links_canonical_roadmap(self):
        readme = self.readme()
        queue = readme.split("## Work Queue", 1)[1].split("\n## ", 1)[0]
        for lane in ("NOW", "NEXT", "LATER", "BLOCKED"):
            self.assertIn(f"**{lane}:**", queue)
        self.assertIn("https://github.com/pl0n3r/brvtal/issues/533", queue)
        self.assertIn("https://github.com/pl0n3r/brvtal/issues?q=is%3Aissue", queue)
        self.assertIn("https://github.com/pl0n3r/brvtal/pulls?q=is%3Apr", queue)
        self.assertIn("status%3A%20blocked", queue)
        self.assertIn("status%3A%20available", queue)
        self.assertNotIn("https://github.com/pl0n3r/brvtal/issues/1014", queue,
                         "No publicar como NOW el Issue que cierra este mismo PR")

    def test_factory_map_preserves_roles(self):
        factory_map = self.readme().split("## Mapa de la fábrica", 1)[1]
        for expected in (
            "**Factory:** governance/kit",
            "**ControlBot:** control plane; repositorio público, panel de acceso restringido.",
            "**FactoryRunner:** execution plane autónomo",
            "**Condor / GrindFlow / BRVTAL:** productos",
            "**AutoFactory:** herramienta local/manual",
        ):
            self.assertIn(expected, factory_map)

    def test_legacy_dashboard_is_retired(self):
        self.assertFalse(LEGACY_DASHBOARD.exists())
        ci = BRVTAL_CI.read_text(encoding="utf-8")
        self.assertNotIn("readme-dashboard.py", ci)
        self.assertNotIn("Verify README matches this deploy exactly", ci)


if __name__ == "__main__":
    unittest.main()
