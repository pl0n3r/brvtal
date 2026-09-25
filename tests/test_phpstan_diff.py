from collections import Counter
from io import StringIO
from pathlib import Path
import json
import tempfile
import unittest
import sys

sys.path.insert(0, str(Path(__file__).resolve().parents[1] / "scripts"))
from phpstan_diff import introduced, read_payload


def report(path: str, messages: list[dict]) -> dict:
    """Build one small PHPStan JSON report for comparison tests."""
    return {
        "totals": {"errors": 0, "file_errors": len(messages)},
        "files": {path: {"errors": len(messages), "messages": messages}},
        "errors": [],
    }


class PhpstanDiffTests(unittest.TestCase):
    def test_existing_finding_is_not_new_when_line_moves(self) -> None:
        """Ignore line movement when file, rule and message are unchanged."""
        with tempfile.TemporaryDirectory() as base_dir, tempfile.TemporaryDirectory() as head_dir:
            base_path = str(Path(base_dir) / "api" / "example.php")
            head_path = str(Path(head_dir) / "api" / "example.php")
            base = report(base_path, [{"message": "Legacy error", "line": 10, "identifier": "return.missing"}])
            head = report(head_path, [{"message": "Legacy error", "line": 99, "identifier": "return.missing"}])
            self.assertEqual(introduced(base, head, Path(base_dir), Path(head_dir)), Counter())

    def test_additional_duplicate_is_new(self) -> None:
        """Count an additional copy of an inherited finding as new debt."""
        with tempfile.TemporaryDirectory() as base_dir, tempfile.TemporaryDirectory() as head_dir:
            base_path = str(Path(base_dir) / "api" / "example.php")
            head_path = str(Path(head_dir) / "api" / "example.php")
            item = {"message": "Same error", "identifier": "argument.type"}
            base = report(base_path, [item])
            head = report(head_path, [item, item])
            delta = introduced(base, head, Path(base_dir), Path(head_dir))
            self.assertEqual(sum(delta.values()), 1)

    def test_new_rule_message_is_reported(self) -> None:
        """Report a finding introduced only in HEAD."""
        with tempfile.TemporaryDirectory() as base_dir, tempfile.TemporaryDirectory() as head_dir:
            base = {"files": {}, "errors": []}
            head_path = str(Path(head_dir) / "config" / "new.php")
            head = report(head_path, [{"message": "New error", "identifier": "method.notFound"}])
            delta = introduced(base, head, Path(base_dir), Path(head_dir))
            self.assertEqual(delta, Counter({("config/new.php", "method.notFound", "New error"): 1}))

    def test_global_errors_are_compared_as_multiset(self) -> None:
        """Compare non-file PHPStan errors without losing multiplicity."""
        base = {"files": {}, "errors": ["config warning"]}
        head = {"files": {}, "errors": ["config warning", "new global error"]}
        delta = introduced(base, head, Path("/base"), Path("/head"))
        self.assertEqual(delta, Counter({("<global>", "", "new global error"): 1}))

    def test_payload_is_read_from_stdin_envelope(self) -> None:
        """Parse base/head reports from data rather than user-supplied file paths."""
        payload = {"base": {"files": {}, "errors": []}, "head": {"files": {}, "errors": []}}
        base, head = read_payload(StringIO(json.dumps(payload)))
        self.assertEqual(base, payload["base"])
        self.assertEqual(head, payload["head"])


if __name__ == "__main__":
    unittest.main()
