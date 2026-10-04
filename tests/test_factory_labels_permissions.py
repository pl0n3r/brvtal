import re
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = (ROOT / ".github/workflows/factory-labels.yml").read_text(encoding="utf-8")


def job_permissions(name: str) -> set[str]:
    match = re.search(
        rf"(?ms)^  {re.escape(name)}:\n(?P<body>.*?)(?=^  [a-z][a-z0-9-]*:\n|\Z)",
        WORKFLOW,
    )
    if match is None:
        raise AssertionError(f"Missing job {name}")
    permissions = re.search(
        r"(?ms)^    permissions:\n(?P<permissions>(?:^      [^\n]+\n)+)",
        match.group("body"),
    )
    if permissions is None:
        raise AssertionError(f"Missing permissions for {name}")
    return {line.strip() for line in permissions.group("permissions").splitlines()}


class FactoryLabelsPermissionsTests(unittest.TestCase):
    def test_only_pr_validation_requests_pull_requests_write(self):
        self.assertEqual(
            job_permissions("validate-pr"),
            {"contents: read", "issues: write", "pull-requests: write"},
        )
        for name in ("sync", "validate-issue", "sweep"):
            self.assertNotIn("pull-requests: write", job_permissions(name))

    def test_non_pr_jobs_remain_read_only_and_factory_v1_is_preserved(self):
        read_only = {"contents: read", "issues: write", "pull-requests: read"}
        general = "uses: pl0n3r/factory/.github/workflows/etiquetas.yml@v1"
        pr_split = (
            "uses: pl0n3r/factory/.github/workflows/etiquetas-pr.yml@"
            "a2a2350b8ce686fda5aa06f49cd0e9accaa9ed98"
        )
        for name in ("sync", "validate-issue", "sweep"):
            self.assertEqual(job_permissions(name), read_only)
        self.assertEqual(WORKFLOW.count(general), 3)
        self.assertEqual(WORKFLOW.count(pr_split), 1)
        self.assertNotIn("@main", WORKFLOW)

        validate_pr = re.search(
            r"(?ms)^  validate-pr:\n(?P<body>.*?)(?=^  sweep:\n)",
            WORKFLOW,
        )
        self.assertIsNotNone(validate_pr)
        self.assertIn(pr_split, validate_pr.group("body"))
        self.assertNotIn(general, validate_pr.group("body"))

        for forbidden in (
            "contents: write",
            "actions: write",
            "checks: write",
            "id-token: write",
            "secrets: inherit",
        ):
            self.assertNotIn(forbidden, WORKFLOW)


if __name__ == "__main__":
    unittest.main()
