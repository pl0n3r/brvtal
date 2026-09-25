from __future__ import annotations
import importlib.util, json, pathlib, sys, unittest, urllib.error

ROOT = pathlib.Path(__file__).resolve().parents[1]
FACTORY = ROOT / "ops" / "factory"
sys.path.insert(0, str(FACTORY))
SPEC = importlib.util.spec_from_file_location("hostinger_cutover", FACTORY / "hostinger_cutover.py")
assert SPEC and SPEC.loader
cutover = importlib.util.module_from_spec(SPEC)
sys.modules[SPEC.name] = cutover
SPEC.loader.exec_module(cutover)


def settings(**changes):
    value = dict(
        installation_uuid="installation-123", is_enabled=True,
        owner="pl0n3r", repository="brvtal", branch="main", directory="",
    )
    value.update(changes)
    return value


class Response:
    status = 200
    def __init__(self, payload=b""): self.payload = payload
    def __enter__(self): return self
    def __exit__(self, *_): return False
    def read(self, _limit=-1): return self.payload


class FakeApi:
    def __init__(self, state=None, fail=None):
        self.state = dict(state or settings())
        self.fail = fail
        self.calls = []
        self.auth = []
        self.counts = {}

    def __call__(self, request, timeout=0):
        method = request.get_method()
        self.calls.append(method)
        self.auth.append(request.get_header("Authorization"))
        self.counts[method] = self.counts.get(method, 0) + 1
        if self.fail == (method, self.counts[method]):
            raise urllib.error.HTTPError(request.full_url, 503, "nope", {}, None)
        if method == "GET":
            return Response(json.dumps(self.state).encode())
        if method == "PUT":
            self.state = json.loads(request.data.decode())
            return Response()
        raise AssertionError(method)


class FakeTransport:
    def __init__(self, fail=False, active_on_fail=False, restore_fails=False):
        self.fail, self.active = fail, False
        self.active_on_fail, self.restore_fails = active_on_fail, restore_fails
        self.bootstrap_calls, self.restore_calls = 0, 0

    def bootstrap(self, *_args):
        self.bootstrap_calls += 1
        if self.fail:
            self.active = self.active_on_fail
            raise cutover.transport.TransportError("bootstrap failed")
        self.active = True

    def bootstrap_dispatcher_active(self, _config): return self.active

    def restore_bootstrap(self, *_args):
        self.restore_calls += 1
        if self.restore_fails:
            raise cutover.transport.TransportError("restore failed")
        self.active = False


class Config:
    user = "u123456789"


class CutoverTests(unittest.TestCase):
    token, sha, version = "TEST_SECRET_TOKEN", "a" * 40, "0.1.53"

    def client(self, api):
        return cutover.HostingerGitClient(self.token, Config.user, opener=api)

    def run(self, api, transport):
        return cutover.run_cutover(
            self.client(api), Config(), self.sha, self.version, transport_api=transport
        )

    def test_success_and_disabled_idempotency(self):
        for enabled, expected_calls in (
            (True, ["GET", "PUT", "GET", "GET"]),
            (False, ["GET", "GET", "GET"]),
        ):
            with self.subTest(enabled=enabled):
                api, transport = FakeApi(settings(is_enabled=enabled)), FakeTransport()
                self.assertFalse(self.run(api, transport).is_enabled)
                self.assertEqual(api.calls, expected_calls)
                self.assertEqual(transport.bootstrap_calls, 1)
                self.assertTrue(all(v == f"Bearer {self.token}" for v in api.auth))

    def test_unexpected_settings_fail_before_mutation(self):
        for change in (
            dict(owner="other"), dict(repository="other"),
            dict(branch="dev"), dict(directory="../escape"),
        ):
            with self.subTest(change=change):
                api = FakeApi(settings(**change))
                with self.assertRaises(cutover.CutoverError):
                    self.run(api, FakeTransport())
                self.assertEqual(api.calls, ["GET"])

    def test_api_error_is_sanitized(self):
        api = FakeApi(fail=("GET", 1))
        with self.assertRaises(cutover.CutoverError) as raised:
            self.run(api, FakeTransport())
        self.assertIn("HTTP 503", str(raised.exception))
        self.assertNotIn(self.token, str(raised.exception))

    def test_bootstrap_failure_restores_layout_then_git(self):
        api = FakeApi()
        transport = FakeTransport(fail=True, active_on_fail=True)
        with self.assertRaisesRegex(cutover.CutoverError, "authority was restored"):
            self.run(api, transport)
        self.assertEqual(transport.restore_calls, 1)
        self.assertTrue(api.state["is_enabled"])

    def test_post_bootstrap_api_failure_restores_both(self):
        api, transport = FakeApi(fail=("GET", 3)), FakeTransport()
        with self.assertRaisesRegex(cutover.CutoverError, "authority was restored"):
            self.run(api, transport)
        self.assertEqual(transport.restore_calls, 1)
        self.assertFalse(transport.active)
        self.assertTrue(api.state["is_enabled"])

    def test_failed_layout_restore_keeps_git_disabled(self):
        api = FakeApi()
        transport = FakeTransport(fail=True, active_on_fail=True, restore_fails=True)
        with self.assertRaisesRegex(cutover.CutoverError, "Git remains disabled"):
            self.run(api, transport)
        self.assertFalse(api.state["is_enabled"])

    def test_schema_drift_and_disable_failure_fail_closed(self):
        drift = settings(extra="unexpected")
        with self.assertRaisesRegex(cutover.CutoverError, "schema is unexpected"):
            self.client(FakeApi(drift)).get()
        api, transport = FakeApi(fail=("PUT", 1)), FakeTransport()
        with self.assertRaises(cutover.CutoverError):
            self.run(api, transport)
        self.assertEqual(transport.bootstrap_calls, 0)


if __name__ == "__main__":
    unittest.main()
