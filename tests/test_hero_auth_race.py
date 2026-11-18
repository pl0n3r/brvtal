import unittest
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


class HeroAuthRaceTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.auth = (ROOT / "discadmin/admin-auth-boundary.js").read_text(encoding="utf-8")
        cls.dashboard = (ROOT / "discadmin/dashboard-v2.js").read_text(encoding="utf-8")
        cls.index_core = (ROOT / "discadmin/index-core.php").read_text(encoding="utf-8")
        cls.hero = (ROOT / "discadmin/hero-slider.js").read_text(encoding="utf-8")
        cls.auth_e2e = (ROOT / "tests/e2e/discadmin-auth-cache.spec.mjs").read_text(encoding="utf-8")
        cls.dashboard_e2e = (
            ROOT / "tests/e2e/discadmin-dashboard-v2-authority.spec.mjs"
        ).read_text(encoding="utf-8")
        cls.ia_e2e = (
            ROOT / "tests/e2e/discadmin-information-architecture.spec.mjs"
        ).read_text(encoding="utf-8")

    def test_dashboard_to_hero_race_is_regressed(self):
        self.assertIn("await window.BRVTALDashboardV2.mount();", self.index_core)
        self.assertIn("if (mountPromise) return mountPromise;", self.dashboard)
        self.assertIn("return pending;", self.dashboard)
        self.assertIn("function invalidate()", self.dashboard)
        self.assertIn("window.BRVTALDashboardV2?.invalidate?.();", self.index_core)
        self.assertIn("if(mounted!==true)return false;", self.index_core)
        self.assertIn(
            "successful Dashboard navigation resolves only after canonical active nav is synchronized",
            self.ia_e2e,
        )
        self.assertIn(
            "Dashboard V2 invalidates a pending mount across an auth session boundary",
            self.dashboard_e2e,
        )
        self.assertIn(
            "Dashboard V2 mount promise settles only after protected sources finish",
            self.dashboard_e2e,
        )

    def test_revalidation_diagnostics_distinguish_attempt_success_failure(self):
        for field in (
            "revalidationAttempted",
            "revalidationSucceeded",
            "revalidationFailed",
        ):
            self.assertIn(field, self.auth)
        for field in (
            "authRevalidationAttempted",
            "authRevalidationSucceeded",
            "authRevalidationFailed",
        ):
            self.assertIn(field, self.hero)

    def test_forced_auth_revalidation_is_single_flight(self):
        self.assertIn("if (authPromise) return authPromise;", self.auth)
        self.assertNotIn("if (!force && authPromise) return authPromise;", self.auth)
        self.assertIn(
            "concurrent admin GET 401 responses share one forced auth revalidation",
            self.auth_e2e,
        )

    def test_mutation_401_remains_non_retriable(self):
        self.assertIn("if (requestMethod(input, init) !== 'GET')", self.auth)
        self.assertIn("expireSession();", self.auth)
        self.assertIn(
            "admin mutation 401 is never revalidated or retried",
            self.auth_e2e,
        )


if __name__ == "__main__":
    unittest.main()
