"""Validar limpieza repetible de respaldos parciales sin borrar un backup válido."""
from pathlib import Path
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]


class BackupsSafeCleanupTests(unittest.TestCase):
    def test_missing_part_cleanup_is_idempotent(self):
        source = (ROOT / "config/backups.php").read_text()
        self.assertIn("function brvtal_backup_unlink_if_exists", source)
        self.assertIn("BACKUP_TARGET_EXISTS", source)
        self.assertIn("BACKUP_ID_COLLISION", source)
        self.assertIn("MEDIA_ARCHIVE_TARGET_EXISTS", source)
        script = """
            require 'config/backups.php';
            $dir = sys_get_temp_dir() . '/brvtal-1655-' . bin2hex(random_bytes(6));
            if (!mkdir($dir, 0700)) { exit(2); }
            $valid = $dir . '/valid.database.sql';
            $part = $valid . '.part';
            file_put_contents($valid, "VALID_BACKUP_DATA");
            try {
                // Retry a missing .part without warnings; leave completed backup intact.
                brvtal_backup_unlink_if_exists($part);
                brvtal_backup_unlink_if_exists($part);
                if (file_get_contents($valid) !== "VALID_BACKUP_DATA") { exit(3); }
                file_put_contents($part, "PARTIAL_ONLY");
                brvtal_backup_unlink_if_exists($part);
                brvtal_backup_unlink_if_exists($part);
                if (file_exists($part)) { exit(4); }
                if (file_get_contents($valid) !== "VALID_BACKUP_DATA") { exit(5); }
                // Existing finalized target must be rejected before opening PDO.
                $pdo = (new ReflectionClass(PDO::class))->newInstanceWithoutConstructor();
                try {
                    brvtal_backup_export_database($pdo, $valid);
                    exit(6);
                } catch (RuntimeException $e) {
                    if ($e->getMessage() !== 'BACKUP_TARGET_EXISTS') { exit(7); }
                }
                if (file_get_contents($valid) !== "VALID_BACKUP_DATA") { exit(8); }
            } finally {
                if (is_file($part)) { unlink($part); }
                if (is_file($valid)) { unlink($valid); }
                rmdir($dir);
            }
        """
        result = subprocess.run(
            ["php", "-d", "error_reporting=32767", "-d", "display_errors=1", "-r", script],
            cwd=ROOT, text=True, capture_output=True, timeout=20, check=False,
        )
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertNotRegex(result.stdout + result.stderr, r"(?i)warning:|deprecated|fatal error")


if __name__ == "__main__":
    unittest.main()
