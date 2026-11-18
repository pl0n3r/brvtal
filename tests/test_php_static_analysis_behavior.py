from __future__ import annotations

import os
from pathlib import Path
import shutil
import subprocess
import tempfile
import textwrap
import unittest

ROOT = Path(__file__).resolve().parents[1]


def run(cmd: list[str], cwd: Path, env: dict[str, str] | None = None) -> subprocess.CompletedProcess[str]:
    """Run a command in the fixture repository and capture text output."""
    return subprocess.run(cmd, cwd=cwd, env=env, text=True, capture_output=True, check=False)


class PhpStaticAnalysisBehaviorTests(unittest.TestCase):
    def test_exact_commits_drive_phpstan_and_rector(self) -> None:
        """Exercise the shell gate with stub tools and verify exact Git identities."""
        with tempfile.TemporaryDirectory() as directory:
            repo = Path(directory)
            (repo / "scripts").mkdir()
            (repo / "bin").mkdir()
            shutil.copy(ROOT / "scripts" / "php-static-analysis.sh", repo / "scripts" / "php-static-analysis.sh")
            shutil.copy(ROOT / "scripts" / "phpstan_diff.py", repo / "scripts" / "phpstan_diff.py")
            (repo / "phpstan.neon").write_text("parameters:\n  level: 1\n", encoding="utf-8")
            (repo / "rector.php").write_text("<?php return null;\n", encoding="utf-8")
            (repo / "app.php").write_text("<?php\nfunction value(): int { return 1; }\n", encoding="utf-8")
            (repo / "unchanged.php").write_text("<?php\n", encoding="utf-8")

            self.assertEqual(run(["git", "init", "-b", "main"], repo).returncode, 0)
            self.assertEqual(run(["git", "config", "user.email", "ci@example.invalid"], repo).returncode, 0)
            self.assertEqual(run(["git", "config", "user.name", "CI Fixture"], repo).returncode, 0)
            self.assertEqual(run(["git", "add", "."], repo).returncode, 0)
            self.assertEqual(run(["git", "commit", "-m", "base"], repo).returncode, 0)
            base_sha = run(["git", "rev-parse", "HEAD"], repo).stdout.strip()

            (repo / "app.php").write_text("<?php\nfunction value(): int { return 2; }\n", encoding="utf-8")
            self.assertEqual(run(["git", "add", "app.php"], repo).returncode, 0)
            self.assertEqual(run(["git", "commit", "-m", "head"], repo).returncode, 0)
            head_sha = run(["git", "rev-parse", "HEAD"], repo).stdout.strip()

            phpstan_log = repo / "phpstan.log"
            rector_log = repo / "rector.log"
            (repo / "bin" / "phpstan").write_text(textwrap.dedent("""\
#!/usr/bin/env bash
set -euo pipefail
printf '%s|%s\\n' "$PWD" "$(git rev-parse HEAD)" >> "$PHPSTAN_LOG"
printf '%s\\n' '{"totals":{"errors":0,"file_errors":0},"files":{},"errors":[]}'
"""), encoding="utf-8")
            (repo / "bin" / "rector").write_text(textwrap.dedent("""\
#!/usr/bin/env bash
set -euo pipefail
printf '%s|%s|%s\\n' "$PWD" "$(git rev-parse HEAD)" "$*" >> "$RECTOR_LOG"
"""), encoding="utf-8")
            (repo / "bin" / "phpstan").chmod(0o755)
            (repo / "bin" / "rector").chmod(0o755)

            env = os.environ.copy()
            env["PATH"] = str(repo / "bin") + os.pathsep + env["PATH"]
            env["PHPSTAN_LOG"] = str(phpstan_log)
            env["RECTOR_LOG"] = str(rector_log)

            result = run(["bash", "scripts/php-static-analysis.sh", base_sha, head_sha], repo, env)
            self.assertEqual(result.returncode, 0, result.stderr)

            phpstan_entries = [line.split("|", 1) for line in phpstan_log.read_text().splitlines()]
            self.assertEqual([entry[1] for entry in phpstan_entries], [base_sha, head_sha])

            rector_entry = rector_log.read_text().strip().split("|", 2)
            self.assertEqual(rector_entry[1], head_sha)
            self.assertIn("process app.php", rector_entry[2])
            self.assertNotIn("unchanged.php", rector_entry[2])

    def test_invalid_sha_fails_before_tools_run(self) -> None:
        """Reject malformed Git identities before invoking external analyzers."""
        with tempfile.TemporaryDirectory() as directory:
            repo = Path(directory)
            (repo / "scripts").mkdir()
            shutil.copy(ROOT / "scripts" / "php-static-analysis.sh", repo / "scripts" / "php-static-analysis.sh")
            self.assertEqual(run(["git", "init", "-b", "main"], repo).returncode, 0)
            result = run(["bash", "scripts/php-static-analysis.sh", "bad", "also-bad"], repo)
            self.assertEqual(result.returncode, 2)
            self.assertIn("40-character", result.stderr)


if __name__ == "__main__":
    unittest.main()
