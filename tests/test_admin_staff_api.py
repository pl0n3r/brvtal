import subprocess
import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class AdminStaffApiContractTests(unittest.TestCase):
    def run_contract(self, mode: str) -> None:
        result = subprocess.run(
            ["php", str(ROOT / "tests" / "admin-staff-ops-contract.php"), mode],
            cwd=ROOT,
            text=True,
            capture_output=True,
            check=False,
        )
        self.assertEqual(result.returncode, 0, result.stdout + result.stderr)

    def test_signed_staff_only_contract(self):
        self.run_contract("ac01")

    def test_audit_idempotency_and_fail_closed_mutations(self):
        self.run_contract("ac02")

    def test_unconfigured_ops_surface_is_404_and_secret_free(self):
        self.run_contract("ac03")


if __name__ == "__main__":
    unittest.main()
