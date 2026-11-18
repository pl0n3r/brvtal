from pathlib import Path
import re
import unittest


ROOT = Path(__file__).resolve().parents[1]


class DiscadminLoginHitAreaTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.security_css = (ROOT / "discadmin" / "security.css").read_text(encoding="utf-8")
        cls.index_php = (ROOT / "discadmin" / "index-core.php").read_text(encoding="utf-8")

    def recovery_rule(self):
        match = re.search(r"\.login-recovery\s*\{([^}]*)\}", self.security_css)
        self.assertIsNotNone(match, "The recovery link must have a dedicated CSS rule")
        return match.group(1)

    def test_recovery_link_responsive_hitbox(self):
        rule = self.recovery_rule()
        self.assertRegex(rule, r"\bdisplay\s*:\s*flex\b")
        self.assertRegex(rule, r"\bmin-width\s*:\s*44px\b")
        self.assertRegex(rule, r"\bmin-height\s*:\s*44px\b")
        self.assertRegex(rule, r"\bpadding\s*:\s*12px\s+8px\b")
        self.assertIn("align-items:center", rule)
        self.assertIn("justify-content:center", rule)
        self.assertRegex(
            self.index_php,
            r'<a class="login-recovery" href="/discadmin/forgot-password\.php">',
        )

    def test_keyboard_focus_and_recovery_contract(self):
        self.assertRegex(
            self.security_css,
            r"\.login-recovery:focus-visible\s*\{[^}]*outline\s*:\s*2px\s+solid",
        )
        anchor = re.search(
            r'(<a class="login-recovery"[^>]*>.*?</a>)',
            self.index_php,
            flags=re.DOTALL,
        )
        self.assertIsNotNone(anchor)
        self.assertIn('href="/discadmin/forgot-password.php"', anchor.group(1))
        self.assertNotIn("tabindex=", anchor.group(1), "A native link must retain its native keyboard order")

    def test_no_auth_semantic_regressions(self):
        self.assertIn('<form class="loginbox" onsubmit="login(event)">', self.index_php)
        self.assertIn('id="admin-login-email" name="email" type="email" required', self.index_php)
        self.assertIn('id="admin-login-password" name="password" type="password" required', self.index_php)
        self.assertIn("async function login(e)", self.index_php)
        self.assertIn("req('/auth',{method:'POST'", self.index_php)
        self.assertIn("if(csrf)h['X-CSRF-Token']=csrf;", self.index_php)
        self.assertIn('/discadmin/totp-login.js?', self.index_php)
        self.assertIn('/discadmin/security.css?', self.index_php)
        self.assertIn('href="/discadmin/forgot-password.php"', self.index_php)


if __name__ == "__main__":
    unittest.main()
