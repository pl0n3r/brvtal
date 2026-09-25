from __future__ import annotations

import importlib.util
import json
import pathlib
import sys
import unittest
import urllib.error
from dataclasses import replace

ROOT = pathlib.Path(__file__).resolve().parents[1]
FACTORY = ROOT / "ops" / "factory"
sys.path.insert(0, str(FACTORY))
SPEC = importlib.util.spec_from_file_location(
    "hostinger_cutover", FACTORY / "hostinger_cutover.py"
)
assert SPEC and SPEC.loader
cutover = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = cutover
SPEC.loader.exec_module(cutover)


class Response:
    def __init__(self, payload=b"", status=200):
        self.payload = payload
        self.status = status

    def __enter__(self):
        return self

    def __exit__(self, *_args):
        return False

    def read(self, _limit=-1):
        return self.payload


class FakeApi:
    def __init__(self, settings, *, fail_method=None):
        self.settings = dict(settings)
        self.fail_method = fail_method
        self.calls = []
        self.seen_auth = []

    def __call__(self, request, timeout=0):
        method = request.get_method()
        self.calls.append(method)
        self.seen_auth.append(request.get_header("Authorization"))
        if method == self.fail_method:
            raise urllib.error.HTTPError(request.full_url, 503, "nope", {}, None)
        if method == "GET":
            return Response(json.dumps(self.settings).encode())
        if method == "PUT":
            self.settings = json.loads(request.data.decode())
            return Response()
        raise AssertionError(method)


class FakeTransport:
    def __init__(self, *, fail=False, active_after_failure=False):
        self.fail = fail
        self.active = False
        self.active_after_failure = active_after_failure
        self.calls = []
        self.restore_calls = 0

    def bootstrap(self, config, sha, version, origin):
        self.calls.append((config.user, sha, version, origin))
        if self.fail:
            self.active = self.active_after_failure
            raise cutover.transport.TransportError("simulated bootstrap failure")
        self.active = True

    def bootstrap_dispatcher_active(self, _config):
        return self.active

    def restore_bootstrap(self, _config, _sha):
        self.restore_calls += 1
        self.active = False


class Config:
    user = "u123456789"


def settings(**overrides):
    value = {
        "installation_uuid": "installation-123",
        "is_enabled": True,
        "owner": "pl0n3r",
        "repository": "brvtal",
        "branch": "main",
        "directory": "",
    }
    value.update(overrides)
    return value


class HostingerCutoverTests(unittest.TestCase):
    token = "TEST_SECRET_TOKEN"
    sha = "a" * 40
    version = "0.1.53"

    def client(self, fake):
        return cutover.HostingerGitClient(self.token, Config.user, opener=fake)

    def test_success_disables_git_before_bootstrap(self):
        api = FakeApi(settings())
        transport = FakeTransport()
        final = cutover.run_cutover(
            self.client(api), Config(), self.sha, self.version, transport_api=transport
        )
        self.assertFalse(final.is_enabled)
        self.assertEqual(api.calls, ["GET", "PUT", "GET", "GET"])
        self.assertEqual(len(transport.calls), 1)
        self.assertTrue(all(value == f"Bearer {self.token}" for value in api.seen_auth))

    def test_already_disabled_is_idempotent(self):
        api = FakeApi(settings(is_enabled=False))
        transport = FakeTransport()
        final = cutover.run_cutover(
            self.client(api), Config(), self.sha, self.version, transport_api=transport
        )
        self.assertFalse(final.is_enabled)
        self.assertEqual(api.calls, ["GET", "GET", "GET"])

    def test_bootstrap_failure_restores_previous_git_authority(self):
        api = FakeApi(settings())
        transport = FakeTransport(fail=True, active_after_failure=True)
        with self.assertRaisesRegex(cutover.CutoverError, "authority was restored"):
            cutover.run_cutover(
                self.client(api), Config(), self.sha, self.version,
                transport_api=transport,
            )
        self.assertEqual(transport.restore_calls, 1)
        self.assertTrue(api.settings["is_enabled"])
        self.assertEqual(api.calls, ["GET", "PUT", "GET", "PUT", "GET"])

    def test_settings_mismatch_fails_before_mutation(self):
        for override in (
            {"owner": "other"},
            {"repository": "other"},
            {"branch": "dev"},
            {"directory": "../escape"},
        ):
            api = FakeApi(settings(**override))
            with self.assertRaises(cutover.CutoverError):
                cutover.run_cutover(
                    self.client(api), Config(), self.sha, self.version,
                    transport_api=FakeTransport(),
                )
            self.assertEqual(api.calls, ["GET"])

    def test_api_failure_is_sanitized_and_does_not_expose_token(self):
        api = FakeApi(settings(), fail_method="GET")
        with self.assertRaises(cutover.CutoverError) as raised:
            cutover.run_cutover(
                self.client(api), Config(), self.sha, self.version,
                transport_api=FakeTransport(),
            )
        self.assertNotIn(self.token, str(raised.exception))
        self.assertIn("HTTP 503", str(raised.exception))

    def test_disable_failure_never_starts_bootstrap(self):
        api = FakeApi(settings(), fail_method="PUT")
        transport = FakeTransport()
        with self.assertRaises(cutover.CutoverError):
            cutover.run_cutover(
                self.client(api), Config(), self.sha, self.version,
                transport_api=transport,
            )
        self.assertEqual(transport.calls, [])

    def test_restore_failure_fails_closed(self):
        class FailSecondPut(FakeApi):
            def __init__(self):
                super().__init__(settings())
                self.puts = 0

            def __call__(self, request, timeout=0):
                if request.get_method() == "PUT":
                    self.puts += 1
                    if self.puts == 2:
                        raise urllib.error.HTTPError(
                            request.full_url, 503, "nope", {}, None
                        )
                return super().__call__(request, timeout)

        api = FailSecondPut()
        with self.assertRaisesRegex(cutover.CutoverError, "could not be restored"):
            cutover.run_cutover(
                self.client(api), Config(), self.sha, self.version,
                transport_api=FakeTransport(fail=True),
            )

    def test_post_bootstrap_api_failure_restores_layout_before_git_authority(self):
        class FailFinalGet(FakeApi):
            def __init__(self):
                super().__init__(settings())
                self.gets = 0

            def __call__(self, request, timeout=0):
                if request.get_method() == "GET":
                    self.gets += 1
                    if self.gets == 3:
                        raise urllib.error.HTTPError(
                            request.full_url, 503, "nope", {}, None
                        )
                return super().__call__(request, timeout)

        api = FailFinalGet()
        transport = FakeTransport()
        with self.assertRaisesRegex(cutover.CutoverError, "authority was restored"):
            cutover.run_cutover(
                self.client(api), Config(), self.sha, self.version,
                transport_api=transport,
            )
        self.assertEqual(transport.restore_calls, 1)
        self.assertFalse(transport.active)
        self.assertTrue(api.settings["is_enabled"])

    def test_failed_layout_restore_keeps_git_disabled(self):
        class BrokenRestore(FakeTransport):
            def restore_bootstrap(self, _config, _sha):
                raise cutover.transport.TransportError("restore failed")

        api = FakeApi(settings())
        transport = BrokenRestore(fail=True, active_after_failure=True)
        with self.assertRaisesRegex(cutover.CutoverError, "Git remains disabled"):
            cutover.run_cutover(
                self.client(api), Config(), self.sha, self.version,
                transport_api=transport,
            )
        self.assertFalse(api.settings["is_enabled"])

    def test_schema_drift_fails_closed(self):
        raw = settings()
        raw["extra"] = "unexpected"
        api = FakeApi(raw)
        with self.assertRaisesRegex(cutover.CutoverError, "schema is unexpected"):
            self.client(api).get()


if __name__ == "__main__":
    unittest.main()
