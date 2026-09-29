"""Regresiones de adopción de README Contract v1 en BRVTAL."""
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
AGENTS = ROOT / "AGENTS.md"

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

    def test_readme_has_contract_v1_anatomy(self):
        readme = self.readme()
        self.assertTrue(readme.startswith("# BRVTAL"))
        for heading in REQUIRED_SECTIONS:
            self.assertEqual(readme.count(f"## {heading}"), 1, heading)
        self.assertIn("underground", readme.lower())
        self.assertIn("DISCADMIN", readme)

    def test_derived_blocks_fail_closed_without_evidence(self):
        readme = self.readme()
        pairs = (
            ("<!-- factory:status:start -->", "<!-- factory:status:end -->"),
            (
                "<!-- factory:progress-readiness:start -->",
                "<!-- factory:progress-readiness:end -->",
            ),
        )
        for start, end in pairs:
            self.assertEqual(readme.count(start), 1)
            self.assertEqual(readme.count(end), 1)
            block = readme.split(start, 1)[1].split(end, 1)[0]
            self.assertIn("UNKNOWN", block)
            self.assertNotIn("GREEN", block)
            self.assertNotIn("DEGRADED", block)

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
        self.assertIn("https://github.com/pl0n3r/brvtal/issues/746", queue)

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

    def test_legacy_dashboard_is_retired_and_agents_follow_contract_v1(self):
        self.assertFalse(LEGACY_DASHBOARD.exists())
        ci = BRVTAL_CI.read_text(encoding="utf-8")
        self.assertNotIn("readme-dashboard.py", ci)
        self.assertNotIn("Verify README matches this deploy exactly", ci)
        agents = AGENTS.read_text(encoding="utf-8")
        self.assertIn("README Contract v1", agents)
        self.assertNotIn(
            "Every PR targeting `main` must keep `README.md` synchronized with the exact PR diff",
            agents,
        )
        self.assertNotIn("Refresh exact README snapshot", agents)


if __name__ == "__main__":
    unittest.main()
