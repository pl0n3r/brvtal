from __future__ import annotations

import unittest

from scripts.ci_retry import is_transient_failure, validate_options


class CiRetryTests(unittest.TestCase):
    def test_only_known_transient_signals_retry(self) -> None:
        self.assertTrue(is_transient_failure(1, "HTTP 503 upstream unavailable"))
        self.assertTrue(is_transient_failure(75, "temporary failure"))
        self.assertFalse(is_transient_failure(1, "assertion failed"))
        self.assertTrue(
            is_transient_failure(75, "anything", exit_code_only=True)
        )
        self.assertFalse(
            is_transient_failure(
                1,
                "HTTP 503 upstream unavailable",
                exit_code_only=True,
            )
        )

    def test_limits_are_bounded(self) -> None:
        validate_options(["echo", "ok"], 5, 30)
        with self.assertRaises(ValueError):
            validate_options(["echo"], 6, 1)
        with self.assertRaises(ValueError):
            validate_options([], 1, 1)
