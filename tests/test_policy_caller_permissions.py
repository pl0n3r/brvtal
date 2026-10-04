import unittest
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github" / "workflows" / "factory-policy.yml"


def _mapping_block(lines: list[str], header: str, indent: int) -> dict[str, str]:
    prefix = " " * indent + header
    try:
        start = lines.index(prefix) + 1
    except ValueError as exc:
        raise AssertionError(f"Falta bloque {header}") from exc

    result: dict[str, str] = {}
    entry_indent = " " * (indent + 2)
    for line in lines[start:]:
        if not line.strip():
            continue
        current_indent = len(line) - len(line.lstrip(" "))
        if current_indent <= indent:
            break
        if current_indent != indent + 2:
            continue
        stripped = line[len(entry_indent):]
        if ":" not in stripped:
            continue
        key, value = stripped.split(":", 1)
        result[key.strip()] = value.strip()
    return result


class PolicyCallerPermissionsTests(unittest.TestCase):
    def setUp(self) -> None:
        self.text = WORKFLOW.read_text(encoding="utf-8")
        self.lines = self.text.splitlines()

    def _policy_permissions(self) -> dict[str, str]:
        policy_index = self.lines.index("  policy:")
        permissions_index = self.lines.index("    permissions:", policy_index)
        result: dict[str, str] = {}
        for line in self.lines[permissions_index + 1:]:
            if not line.strip():
                continue
            indent = len(line) - len(line.lstrip(" "))
            if indent <= 4:
                break
            if indent != 6 or ":" not in line:
                continue
            key, value = line.strip().split(":", 1)
            result[key.strip()] = value.strip()
        return result

    def test_policy_caller_grants_exact_reusable_permissions(self) -> None:
        self.assertEqual(
            self._policy_permissions(),
            {
                "contents": "read",
                "pull-requests": "read",
                "issues": "write",
                "checks": "read",
            },
        )

    def test_policy_caller_does_not_expand_other_permissions(self) -> None:
        self.assertEqual(
            _mapping_block(self.lines, "permissions:", 0),
            {
                "contents": "read",
                "pull-requests": "read",
            },
        )
        write_lines = [
            line.strip()
            for line in self.lines
            if line.strip().endswith(": write")
        ]
        self.assertEqual(write_lines, ["issues: write"])
        self.assertNotIn("checks: write", self.text)
        self.assertNotIn("contents: write", self.text)
        self.assertNotIn("pull-requests: write", self.text)

    def test_policy_caller_keeps_factory_v1_and_existing_contract(self) -> None:
        self.assertIn("types: [opened, synchronize, reopened, edited]", self.text)
        self.assertIn(
            "uses: pl0n3r/factory/.github/workflows/politica.yml@v1",
            self.text,
        )
        self.assertIn("with:", self.text)
        self.assertIn(
            "pr_number: ${{ github.event.pull_request.number }}",
            self.text,
        )
        self.assertEqual(self.text.count("uses: "), 1)


if __name__ == "__main__":
    unittest.main()
