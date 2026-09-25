from pathlib import Path
import unittest

ROOT = Path(__file__).resolve().parents[1]
WORKFLOW = ROOT / ".github" / "workflows" / "update-release-metadata.yml"
SCRIPT = ROOT / "scripts" / "php-static-analysis.sh"


class StaticAnalysisCiTests(unittest.TestCase):
    def setUp(self) -> None:
        """Load the workflow and analysis script once per contract test."""
        self.workflow = WORKFLOW.read_text(encoding="utf-8")
        self.script = SCRIPT.read_text(encoding="utf-8")

    def test_phpstan_uses_exact_base_baseline(self) -> None:
        """Require exact base/head worktrees and JSON finding comparison."""
        self.assertIn("40-character base/head SHAs", self.script)
        self.assertIn('git worktree add --detach "$base_tree" "$base_sha"', self.script)
        self.assertIn('git worktree add --detach "$head_tree" "$head_sha"', self.script)
        self.assertIn('run_phpstan_json "$base_tree" "$base_report"', self.script)
        self.assertIn('run_phpstan_json "$head_tree" "$head_report"', self.script)
        self.assertIn("scripts/phpstan_diff.py", self.script)
        self.assertIn('git cat-file -e "${base_sha}^{commit}"', self.script)
        self.assertIn('git cat-file -e "${head_sha}^{commit}"', self.script)

    def test_rector_runs_only_on_changed_php(self) -> None:
        """Require Rector to receive only existing PHP files from exact HEAD."""
        self.assertIn(
            'git diff --name-only --diff-filter=ACMR "$base_sha" "$head_sha" -- \'*.php\'',
            self.script,
        )
        self.assertIn('[[ -f "$head_tree/$file" ]]', self.script)
        self.assertIn('(( ${#changed_php[@]} == 0 ))', self.script)
        self.assertIn('cd "$head_tree"', self.script)
        self.assertIn('rector process "${changed_php[@]}"', self.script)
        self.assertIn("--dry-run", self.script)

    def test_static_analysis_configs_are_repository_only(self) -> None:
        """Keep analysis configuration out of deploy-bound product scope."""
        scope = (ROOT / "scripts" / "ci-scope.sh").read_text(encoding="utf-8")
        self.assertIn("phpstan.neon|rector.php", scope)
        self.assertIn(
            'brvtal_ci_scope_add_area "Review/static analysis policy"; BRVTAL_SCOPE_RUN_PHP=true',
            scope,
        )

    def test_tools_are_pinned_without_permission_expansion(self) -> None:
        """Require pinned tool versions and preserve read-only workflow permissions."""
        self.assertIn("tools: phpstan:2.2.14, rector:2.6.7", self.workflow)
        self.assertIn("permissions:\n  contents: read\n\nconcurrency:", self.workflow)
        self.assertNotIn("continue-on-error: true", self.workflow)
        setup_at = self.workflow.index("Set up PHP 8.5")
        analysis_at = self.workflow.index("Run incremental PHPStan and Rector dry-run")
        self.assertLess(setup_at, analysis_at)


if __name__ == "__main__":
    unittest.main()
