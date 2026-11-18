"""Regresiones PHP 8.5 y compatibilidad de cron CLI desde PHP 8.2 (#1655)."""
from pathlib import Path
import re
import subprocess
import unittest

ROOT = Path(__file__).resolve().parents[1]


def php(script: str) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        ["php", "-d", "error_reporting=32767", "-d", "display_errors=1", "-r", script],
        cwd=ROOT, text=True, capture_output=True, timeout=20, check=False,
    )


class PHP85CompatibilityTests(unittest.TestCase):
    def test_indexnow_no_deprecated_curl_close(self):
        source = (ROOT / "config/indexnow.php").read_text()
        self.assertNotRegex(source, r"\bcurl_close\s*\(")
        for operation in ("curl_exec($curl)", "curl_getinfo($curl", "curl_error($curl)", "unset($curl)"):
            self.assertIn(operation, source)
        # Localhost-only transport exercises CurlHandle (if installed), never an external endpoint.
        script = """
            require 'config/indexnow.php';
            brvtalIndexNowSubmit([
                'endpoint' => 'http://127.0.0.1:1/indexnow',
                'host' => 'localhost',
                'key' => 'synthetickey1234',
                'keyLocation' => 'http://127.0.0.1/indexnow-key.txt',
                'urls' => ['http://localhost/fixture']
            ]);
        """
        result = php(script)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertNotRegex(result.stdout + result.stderr, r"(?i)deprecated|fatal error|warning:")

    def test_gd_cleanup_no_deprecated_imagedestroy(self):
        source = (ROOT / "config/media.php").read_text()
        self.assertNotRegex(source, r"\bimagedestroy\s*\(")
        self.assertIn("unset($canvas)", source)
        self.assertIn("unset($source)", source)
        # Runtime actually creates a WebP with GD where CI has the extension.
        script = """
            require 'config/media.php';
            if (!extension_loaded('gd') || !function_exists('imagewebp')) { exit(0); }
            $source = imagecreatetruecolor(6, 6);
            $path = tempnam(sys_get_temp_dir(), 'brvtal-1655-webp-');
            try {
                if (!brvtalMediaWriteVariant($source, 6, 6, $path, 3, 3)) { exit(3); }
                if (!is_file($path) || filesize($path) < 5) { exit(4); }
            } finally {
                unset($source);
                if (is_file($path)) { unlink($path); }
            }
        """
        result = php(script)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertNotRegex(result.stdout + result.stderr, r"(?i)deprecated|fatal error|warning:")

    def test_cli_php82_no_php84_array_all(self):
        source = (ROOT / "config/seo_workspace.php").read_text()
        for function in ("array_all", "array_any", "array_find", "array_find_key"):
            self.assertNotRegex(source, rf"\b{function}\s*\(")
        self.assertIn("foreach", source)
        script = """
            require 'config/seo_workspace.php';
            if (!brvtalSeoWorkspaceRootImagePathIsSafe('/uploads/cover.webp')) { exit(2); }
            if (brvtalSeoWorkspaceRootImagePathIsSafe('/uploads/../private')) { exit(3); }
            if (brvtalSeoWorkspaceRootImagePathIsSafe('/uploads/cover.webp?token=1')) { exit(4); }
            if (brvtalSeoWorkspaceRootImagePathIsSafe('//unsafe.example/img.webp')) { exit(5); }
        """
        result = php(script)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertNotRegex(result.stdout + result.stderr, r"(?i)deprecated|fatal error|warning:")


if __name__ == "__main__":
    unittest.main()
