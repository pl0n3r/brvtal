#!/usr/bin/env python3
"""BRVTAL work-coordination helper."""

from __future__ import annotations

import unittest
from pathlib import Path
from datetime import datetime, timedelta, timezone

from scripts.work_coordinator import (
    CoordinationError,
    GitHub,
    GitHubError,
    STATUS_AVAILABLE,
    STATUS_BLOCKED,
    STATUS_CANCELLED,
    STATUS_COMPLETED,
    STATUS_RESERVED,
    STATUS_REVIEW,
    active_reservation,
    authorized,
    closing_issues,
    file_overlaps,
    issue_from_branch,
    label_names,
    latest_reservation,
    parse_comment_command,
    process_comment,
    recover_work,
    release_work,
    reservation_from_pr_body,
    reservation_marker,
    reserve_work,
    transfer_work,
    update_issue_label_state,
    update_issue_state,
    update_pr_state,
    validate_pull,
)


BOT = "github-actions[bot]"
SESSION_A = "11111111-1111-4111-8111-111111111111"
SESSION_B = "22222222-2222-4222-8222-222222222222"


class FakeGitHub:
    """BRVTAL work-coordination helper."""

    def __init__(self) -> None:
        """BRVTAL work-coordination helper."""
        self.repo = "pl0n3r/brvtal"
        self.branches = {"main": "abc123"}
        self.branch_commit_times = {
            "abc123": datetime.now(timezone.utc) - timedelta(hours=2)
        }
        self.issue_data = {
            "number": 12,
            "state": "open",
            "state_reason": None,
            "labels": [{"name": STATUS_AVAILABLE}],
            "assignees": [],
        }
        self.issues: dict[int, dict] = {12: self.issue_data}
        self.comments: list[dict] = []
        self.comments_by_issue: dict[int, list[dict]] = {12: self.comments}
        self.pulls: dict[int, dict] = {}
        self.pull_files_map: dict[int, set[str]] = {}
        self.status_history: list[str | None] = []
        self.assignees: set[str] = set()
        self.assignees_by_issue: dict[int, set[str]] = {12: self.assignees}
        self.compare_files_map: dict[tuple[str, str], set[str]] = {}
        self.fail_comment = False
        self.fail_delete_branch = False
        self.fail_assign = False
        self.fail_unassign = False
        self.fail_update_pull = False
        self.fail_rollback_comment = False
        self.fail_verify_after_recover = False

    def issue(self, number: int) -> dict:
        """BRVTAL work-coordination helper."""
        return self.issues[number]

    def pull(self, number: int) -> dict:
        """BRVTAL work-coordination helper."""
        return self.pulls[number]

    def comment(self, issue_number: int, body: str) -> None:
        """BRVTAL work-coordination helper."""
        if self.fail_comment:
            raise CoordinationError("simulated comment failure")
        if self.fail_rollback_comment and "recover-rollback" in body:
            raise CoordinationError("simulated rollback marker failure")
        self.comments_by_issue.setdefault(issue_number, []).append(
            {
                "body": body,
                "user": {"login": BOT, "type": "Bot"},
                "created_at": datetime.now(timezone.utc).isoformat(),
            }
        )

    def set_status(self, issue_number: int, status: str | None) -> None:
        """BRVTAL work-coordination helper."""
        issue = self.issues[issue_number]
        current = [
            item
            for item in issue["labels"]
            if not str(item["name"]).startswith("status: ")
        ]
        if status:
            current.append({"name": status})
        issue["labels"] = current
        self.status_history.append(status)

    def branch_sha(self, branch: str) -> str | None:
        """BRVTAL work-coordination helper."""
        return self.branches.get(branch)

    def branch_commit_time(self, branch: str) -> datetime | None:
        """BRVTAL work-coordination helper."""
        sha = self.branches.get(branch)
        return self.branch_commit_times.get(sha) if sha else None

    def create_branch(self, branch: str, sha: str) -> bool:
        """BRVTAL work-coordination helper."""
        if branch in self.branches:
            return False
        self.branches[branch] = sha
        return True

    def delete_branch(self, branch: str) -> None:
        """BRVTAL work-coordination helper."""
        if self.fail_delete_branch:
            raise CoordinationError("simulated delete failure")
        self.branches.pop(branch, None)

    def issue_comments(self, issue_number: int) -> list[dict]:
        """BRVTAL work-coordination helper."""
        comments = self.comments_by_issue.get(issue_number, [])
        if (
            self.fail_verify_after_recover
            and comments
            and '"reason":"recover"' in str(comments[-1].get("body") or "")
        ):
            raise CoordinationError("simulated post-marker verification failure")
        return list(comments)

    def open_pulls(self) -> list[dict]:
        """BRVTAL work-coordination helper."""
        return [
            pull
            for pull in self.pulls.values()
            if pull.get("state", "open") == "open"
        ]

    def open_issues(self) -> list[dict]:
        """BRVTAL work-coordination helper."""
        return [
            issue for issue in self.issues.values()
            if issue.get("state") == "open" and not issue.get("pull_request")
        ]

    def compare_files(self, base_sha: str, head_sha: str) -> set[str]:
        """BRVTAL work-coordination helper."""
        return set(self.compare_files_map.get((base_sha, head_sha), set()))

    def pull_files(self, number: int) -> set[str]:
        """BRVTAL work-coordination helper."""
        return set(self.pull_files_map.get(number, set()))

    def close_pull(self, number: int) -> None:
        """BRVTAL work-coordination helper."""
        self.pulls[number]["state"] = "closed"

    def update_pull_body(self, number: int, body: str) -> None:
        """BRVTAL work-coordination helper."""
        if self.fail_update_pull:
            raise CoordinationError("simulated pull update failure")
        self.pulls[number]["body"] = body

    def _sync_assignees(self, issue_number: int) -> set[str]:
        assigned = self.assignees_by_issue.setdefault(issue_number, set())
        self.issues[issue_number]["assignees"] = [
            {"login": value} for value in sorted(assigned)
        ]
        return assigned

    def assign_strict(self, issue_number: int, login: str) -> None:
        """BRVTAL work-coordination helper."""
        if self.fail_assign:
            raise CoordinationError("simulated strict assign failure")
        assigned = self.assignees_by_issue.setdefault(issue_number, set())
        assigned.add(login)
        self._sync_assignees(issue_number)

    def unassign_strict(self, issue_number: int, login: str) -> None:
        """BRVTAL work-coordination helper."""
        if self.fail_unassign:
            raise CoordinationError("simulated strict unassign failure")
        assigned = self.assignees_by_issue.setdefault(issue_number, set())
        assigned.discard(login)
        self._sync_assignees(issue_number)

    def try_assign(self, issue_number: int, login: str) -> None:
        """BRVTAL work-coordination helper."""
        assigned = self.assignees_by_issue.setdefault(issue_number, set())
        assigned.add(login)
        self._sync_assignees(issue_number)

    def try_unassign(self, issue_number: int, login: str) -> None:
        """BRVTAL work-coordination helper."""
        if self.fail_unassign:
            raise CoordinationError("simulated unassign failure")
        assigned = self.assignees_by_issue.setdefault(issue_number, set())
        assigned.discard(login)
        self._sync_assignees(issue_number)


def add_active_reservation(
    api: FakeGitHub,
    owner: str = "pl0n3r",
    reservation_id: str = SESSION_A,
    age_minutes: int = 60,
    issue_number: int = 12,
    branch_sha: str = "abc123",
) -> None:
    """BRVTAL work-coordination helper."""
    branch = f"work/issue-{issue_number}"
    api.branches[branch] = branch_sha
    api.branch_commit_times.setdefault(
        branch_sha,
        datetime.now(timezone.utc) - timedelta(hours=2),
    )
    api.set_status(issue_number, STATUS_RESERVED)
    api.try_assign(issue_number, owner)
    api.comments_by_issue.setdefault(issue_number, []).append(
        {
            "user": {"login": BOT, "type": "Bot"},
            "created_at": (
                datetime.now(timezone.utc) - timedelta(minutes=age_minutes)
            ).isoformat(),
            "body": reservation_marker(
                owner,
                reservation_id,
                branch,
                True,
                "take",
            ),
        }
    )



class BranchCleanupTests(unittest.TestCase):
    """BRVTAL work-coordination helper."""

    def test_delete_branch_existing_succeeds_without_extra_lookup(self) -> None:
        """BRVTAL work-coordination helper."""
        api = GitHub("pl0n3r/brvtal", "test-token")
        calls: list[str] = []

        def request(method, path, payload=None, allow=()):
            calls.append(method)
            return None

        api.request = request  # type: ignore[method-assign]
        api.delete_branch("work/issue-19")
        self.assertEqual(calls, ["DELETE"])

    def test_delete_branch_ignores_422_only_when_reference_is_gone(self) -> None:
        """BRVTAL work-coordination helper."""
        api = GitHub("pl0n3r/brvtal", "test-token")

        def request(method, path, payload=None, allow=()):
            if method == "DELETE":
                raise GitHubError(422, "Reference does not exist")
            return None

        api.request = request  # type: ignore[method-assign]
        api.delete_branch("work/issue-19")

    def test_delete_branch_keeps_422_when_reference_still_exists(self) -> None:
        """BRVTAL work-coordination helper."""
        api = GitHub("pl0n3r/brvtal", "test-token")

        def request(method, path, payload=None, allow=()):
            if method == "DELETE":
                raise GitHubError(422, "Validation Failed")
            return {"object": {"sha": "abc123"}}

        api.request = request  # type: ignore[method-assign]
        with self.assertRaises(GitHubError):
            api.delete_branch("work/issue-19")


class CoordinationTests(unittest.TestCase):
    """BRVTAL work-coordination helper."""

    def test_issue_from_branch(self) -> None:
        """BRVTAL work-coordination helper."""
        self.assertEqual(issue_from_branch("work/issue-12"), 12)
        self.assertEqual(issue_from_branch("work/issue-999"), 999)
        self.assertIsNone(issue_from_branch("feature/algo"))
        self.assertIsNone(issue_from_branch("work/issue-x"))

    def test_closing_issues(self) -> None:
        """BRVTAL work-coordination helper."""
        body = "Closes #12\nFixes #18\nresolves #21"
        self.assertEqual(closing_issues(body), {12, 18, 21})

    def test_reservation_from_pr_body(self) -> None:
        """BRVTAL work-coordination helper."""
        self.assertEqual(
            reservation_from_pr_body(f"Closes #12\nReservation: {SESSION_A}"),
            SESSION_A,
        )
        self.assertEqual(
            reservation_from_pr_body(
                f"Closes #12\n<!-- brvtal-reservation-id: {SESSION_A} -->"
            ),
            SESSION_A,
        )
        self.assertIsNone(reservation_from_pr_body("Closes #12"))

    def test_authorized_associations(self) -> None:
        """BRVTAL work-coordination helper."""
        self.assertTrue(authorized("OWNER"))
        self.assertTrue(authorized("MEMBER"))
        self.assertTrue(authorized("COLLABORATOR"))
        self.assertFalse(authorized("NONE"))
        self.assertFalse(authorized("CONTRIBUTOR"))

    def test_markers_from_untrusted_users_are_ignored(self) -> None:
        """BRVTAL work-coordination helper."""
        marker = reservation_marker(
            "outsider",
            SESSION_A,
            "work/issue-12",
            False,
            "falso",
        )
        comments = [{"body": marker, "user": {"login": "outsider"}}]
        self.assertIsNone(latest_reservation(comments))

    def test_issue_body_marker_is_not_trusted(self) -> None:
        """BRVTAL work-coordination helper."""
        api = FakeGitHub()
        api.issue_data["body"] = reservation_marker(
            "outsider",
            SESSION_B,
            "work/issue-12",
            True,
            "falso",
        )

        self.assertIsNone(active_reservation(api, 12))

    def test_latest_reservation_prefers_last_trusted_marker(self) -> None:
        """BRVTAL work-coordination helper."""
        first = reservation_marker(
            "agent-a",
            SESSION_A,
            "work/issue-12",
            True,
            "take",
        )
        second = reservation_marker(
            "agent-a",
            SESSION_A,
            "work/issue-12",
            False,
            "release",
        )
        comments = [
            {"body": first, "user": {"login": BOT}},
            {"body": second, "user": {"login": BOT}},
        ]
        latest = latest_reservation(comments)
        self.assertIsNotNone(latest)
        assert latest is not None
        self.assertFalse(latest["active"])

    def test_reserve_work_creates_atomic_lock_and_session(self) -> None:
        """BRVTAL work-coordination helper."""
        api = FakeGitHub()
        session = reserve_work(api, 12, "pl0n3r", "OWNER")
        self.assertIsNotNone(session)
        self.assertIn("work/issue-12", api.branches)
        self.assertIn("pl0n3r", api.assignees)
        self.assertEqual(api.status_history[-1], STATUS_RESERVED)
        reservation = active_reservation(api, 12)
        self.assertIsNotNone(reservation)
        assert reservation is not None
        self.assertEqual(reservation["reservation_id"], session)
        self.assertEqual(len(api.comments), 1)
        self.assertTrue(api.comments[0]["body"].startswith("<!-- brvtal-work-reservation "))
        self.assertNotIn("Work is reserved", api.comments[0]["body"])

    def test_label_reserved_without_marker_does_not_create_authority(self) -> None:
        """A manual label must never mint a branch, assignee or trusted marker."""
        api = FakeGitHub()
        api.issue_data["labels"].append({"name": STATUS_RESERVED})

        update_issue_label_state(api, 12, "pl0n3r", STATUS_RESERVED)

        self.assertNotIn("work/issue-12", api.branches)
        self.assertEqual(api.comments, [])
        self.assertEqual(api.assignees, set())
        self.assertIsNone(active_reservation(api, 12))
        self.assertIn(STATUS_AVAILABLE, label_names(api.issue_data))
        self.assertNotIn(STATUS_RESERVED, label_names(api.issue_data))

    def test_label_reserved_restores_blocked_state_without_authority(self) -> None:
        """A blocked Issue remains blocked when reserved is applied manually."""
        api = FakeGitHub()
        api.issue_data["labels"] = [
            {"name": STATUS_BLOCKED},
            {"name": STATUS_RESERVED},
        ]

        update_issue_label_state(api, 12, "pl0n3r", STATUS_RESERVED)

        self.assertNotIn("work/issue-12", api.branches)
        self.assertEqual(api.status_history[-1], STATUS_BLOCKED)
        self.assertEqual(api.comments, [])

    def test_label_reserved_keeps_trusted_existing_reservation_visible(self) -> None:
        """A trusted marker plus canonical branch may synchronize its visible label."""
        api = FakeGitHub()
        add_active_reservation(api, owner="agent-a", age_minutes=5)
        reservation = active_reservation(api, 12)
        self.assertIsNotNone(reservation)
        assert reservation is not None

        update_issue_label_state(api, 12, "pl0n3r", STATUS_RESERVED)

        self.assertEqual(api.status_history[-1], STATUS_RESERVED)
        self.assertEqual(active_reservation(api, 12)["reservation_id"], reservation["reservation_id"])
        self.assertEqual(len(api.comments), 1)

    def test_label_reserved_branch_without_marker_does_not_grant_authority(self) -> None:
        """An orphan branch alone is never enough to trust a manual reserved label."""
        api = FakeGitHub()
        api.issue_data["labels"].append({"name": STATUS_RESERVED})
        api.branches["work/issue-12"] = "orphan-sha"

        update_issue_label_state(api, 12, "pl0n3r", STATUS_RESERVED)

        self.assertIn("work/issue-12", api.branches)
        self.assertIsNone(active_reservation(api, 12))
        self.assertIn(STATUS_AVAILABLE, label_names(api.issue_data))
        self.assertNotIn(STATUS_RESERVED, label_names(api.issue_data))

    def test_label_reserved_restores_available_when_other_issue_is_active(self) -> None:
        """A manual label cannot create a second repository work line."""
        api = FakeGitHub()
        add_active_reservation(api, owner="agent-a", age_minutes=5)
        api.issues[13] = {
            "number": 13,
            "state": "open",
            "state_reason": None,
            "labels": [{"name": STATUS_RESERVED}],
            "assignees": [],
        }
        api.comments_by_issue[13] = []
        api.assignees_by_issue[13] = set()

        update_issue_label_state(api, 13, "pl0n3r", STATUS_RESERVED)

        self.assertIn(STATUS_AVAILABLE, label_names(api.issues[13]))
        self.assertNotIn(STATUS_RESERVED, label_names(api.issues[13]))
        self.assertNotIn("work/issue-13", api.branches)
        self.assertEqual(api.comments_by_issue[13], [])
        self.assertEqual(api.assignees_by_issue[13], set())
        self.assertIsNotNone(active_reservation(api, 12))

    def test_label_available_cannot_release_another_session(self) -> None:
        """BRVTAL work-coordination helper."""
        api = FakeGitHub()
        add_active_reservation(api)
        api.issue_data["labels"].append({"name": STATUS_AVAILABLE})

        update_issue_label_state(api, 12, "pl0n3r", STATUS_AVAILABLE)

        self.assertIn("work/issue-12", api.branches)
        reservation = active_reservation(api, 12)
        self.assertIsNotNone(reservation)
        self.assertEqual(reservation["reservation_id"], SESSION_A)

    def test_second_reservation_cannot_win_same_branch(self) -> None:
        """BRVTAL work-coordination helper."""
        api = FakeGitHub()
        api.branches["work/issue-12"] = "abc123"
        result = reserve_work(api, 12, "pl0n3r", "OWNER")
        self.assertIsNone(result)
        self.assertEqual(api.status_history, [])
        self.assertIsNone(active_reservation(api, 12))

    def test_blocked_issue_cannot_be_reserved(self) -> None:
        """BRVTAL work-coordination helper."""
        api = FakeGitHub()
        api.issue_data["labels"] = [{"name": STATUS_BLOCKED}]
        result = reserve_work(api, 12, "pl0n3r", "OWNER")
        self.assertIsNone(result)
        self.assertNotIn("work/issue-12", api.branches)

    def test_reservation_rolls_back_if_marker_fails(self) -> None:
        """BRVTAL work-coordination helper."""
        api = FakeGitHub()
        api.fail_comment = True
        with self.assertRaises(CoordinationError):
            reserve_work(api, 12, "pl0n3r", "OWNER")
        self.assertNotIn("work/issue-12", api.branches)
        self.assertEqual(api.status_history[-1], STATUS_AVAILABLE)

    def test_reservation_rollback_preserves_original_error_and_continues_cleanup(self) -> None:
        """BRVTAL work-coordination helper."""
        api = FakeGitHub()
        api.fail_comment = True
        api.fail_delete_branch = True
        api.fail_unassign = True
        with self.assertRaisesRegex(CoordinationError, "simulated comment failure"):
            reserve_work(api, 12, "pl0n3r", "OWNER")
        self.assertIn("work/issue-12", api.branches)
        self.assertEqual(api.status_history[-1], STATUS_AVAILABLE)

    def test_wrong_session_cannot_release(self) -> None:
        """BRVTAL work-coordination helper."""
        api = FakeGitHub()
        add_active_reservation(api)
        release_work(api, 12, "pl0n3r", "OWNER", SESSION_B, False)
        self.assertIn("work/issue-12", api.branches)
        self.assertIsNotNone(active_reservation(api, 12))

    def test_release_marks_inactive_before_available(self) -> None:
        """BRVTAL work-coordination helper."""
        api = FakeGitHub()
        add_active_reservation(api)
        release_work(api, 12, "pl0n3r", "OWNER", SESSION_A, False)
        self.assertNotIn("work/issue-12", api.branches)
        latest = latest_reservation(api.comments)
        self.assertIsNotNone(latest)
        assert latest is not None
        self.assertFalse(latest["active"])
        self.assertEqual(api.status_history[-1], STATUS_AVAILABLE)

    def test_transfer_invalidates_previous_session(self) -> None:
        """BRVTAL work-coordination helper."""
        api = FakeGitHub()
        add_active_reservation(api)
        new_id = transfer_work(api, 12, "pl0n3r", "OWNER", SESSION_A)
        self.assertIsNotNone(new_id)
        self.assertNotEqual(new_id, SESSION_A)
        reservation = active_reservation(api, 12)
        assert reservation is not None
        self.assertEqual(reservation["reservation_id"], new_id)

    def test_recover_work_keeps_branch_and_pr_while_rotating_owner(self) -> None:
        """Recovery must reuse the exact work line and synchronize its reservation metadata."""
        api = FakeGitHub()
        add_active_reservation(api, owner="agent-a")
        api.pulls[15] = {
            "number": 15,
            "state": "open",
            "draft": False,
            "body": (
                f"Closes #12\n\nReservation: {SESSION_A}\n\n"
                f"<!-- brvtal-reservation-id: {SESSION_A} -->"
            ),
            "head": {"ref": "work/issue-12", "repo": {"full_name": "pl0n3r/brvtal"}},
            "base": {"ref": "main"},
        }

        new_id = recover_work(api, 12, "pl0n3r", "OWNER", SESSION_A)

        self.assertIsNotNone(new_id)
        self.assertEqual(api.branches["work/issue-12"], "abc123")
        self.assertEqual(api.pulls[15]["state"], "open")
        self.assertIn("pl0n3r", api.assignees)
        self.assertNotIn("agent-a", api.assignees)
        reservation = active_reservation(api, 12)
        assert reservation is not None
        self.assertEqual(reservation["owner"], "pl0n3r")
        self.assertEqual(reservation["reservation_id"], new_id)
        self.assertIn(f"Reservation: {new_id}", api.pulls[15]["body"])
        self.assertIn(f"brvtal-reservation-id: {new_id}", api.pulls[15]["body"])
        self.assertEqual(api.status_history[-1], STATUS_RESERVED)

    def test_recover_work_rejects_recent_reservation(self) -> None:
        """A reservation must age past the inactivity window before takeover."""
        api = FakeGitHub()
        add_active_reservation(api, owner="agent-a", age_minutes=5)

        result = recover_work(api, 12, "pl0n3r", "OWNER", SESSION_A)

        self.assertIsNone(result)
        self.assertIn("agent-a", api.assignees)
        self.assertNotIn("pl0n3r", api.assignees)

    def test_recover_work_rejects_recent_branch_commit(self) -> None:
        """Recent implementation commits keep the current owner authoritative."""
        api = FakeGitHub()
        add_active_reservation(api, owner="agent-a")
        api.branch_commit_times["abc123"] = datetime.now(timezone.utc) - timedelta(minutes=5)

        result = recover_work(api, 12, "pl0n3r", "OWNER", SESSION_A)

        self.assertIsNone(result)
        self.assertIn("agent-a", api.assignees)
        self.assertNotIn("pl0n3r", api.assignees)

    def test_recover_work_rejects_recent_human_activity(self) -> None:
        """Useful human Issue activity resets the recovery inactivity clock."""
        api = FakeGitHub()
        add_active_reservation(api, owner="agent-a")
        api.comments.append(
            {
                "user": {"login": "agent-a", "type": "User"},
                "body": "Sigo implementando el cambio de coordinación.",
                "created_at": (
                    datetime.now(timezone.utc) - timedelta(minutes=5)
                ).isoformat(),
            }
        )

        result = recover_work(api, 12, "pl0n3r", "OWNER", SESSION_A)

        self.assertIsNone(result)
        self.assertIn("agent-a", api.assignees)
        self.assertNotIn("pl0n3r", api.assignees)

    def test_recover_work_ignores_recent_coordination_command(self) -> None:
        """Coordination commands do not fake implementation activity."""
        api = FakeGitHub()
        add_active_reservation(api, owner="agent-a")
        api.comments.append(
            {
                "user": {"login": "agent-a", "type": "User"},
                "body": f"/transfer {SESSION_A}",
                "created_at": datetime.now(timezone.utc).isoformat(),
            }
        )

        result = recover_work(api, 12, "pl0n3r", "OWNER", SESSION_A)

        self.assertIsNotNone(result)
        self.assertIn("pl0n3r", api.assignees)
        self.assertNotIn("agent-a", api.assignees)

    def test_recover_work_rejects_invalid_existing_pr_contract(self) -> None:
        """Recovery must never rewrite a PR whose ownership contract is ambiguous."""
        variants = {
            "fork": lambda pull: pull["head"].update(
                {"repo": {"full_name": "other/fork"}}
            ),
            "base": lambda pull: pull.update({"base": {"ref": "release"}}),
            "closing": lambda pull: pull.update(
                {
                    "body": (
                        f"Reservation: {SESSION_A}\n"
                        f"<!-- brvtal-reservation-id: {SESSION_A} -->"
                    )
                }
            ),
            "reservation": lambda pull: pull.update(
                {
                    "body": (
                        f"Closes #12\nReservation: {SESSION_B}\n"
                        f"<!-- brvtal-reservation-id: {SESSION_B} -->"
                    )
                }
            ),
        }
        for name, mutate in variants.items():
            with self.subTest(name=name):
                api = FakeGitHub()
                add_active_reservation(api, owner="agent-a")
                pull = {
                    "number": 15,
                    "state": "open",
                    "body": (
                        f"Closes #12\nReservation: {SESSION_A}\n"
                        f"<!-- brvtal-reservation-id: {SESSION_A} -->"
                    ),
                    "head": {
                        "ref": "work/issue-12",
                        "repo": {"full_name": "pl0n3r/brvtal"},
                    },
                    "base": {"ref": "main"},
                }
                mutate(pull)
                api.pulls[15] = pull
                before = pull["body"]

                with self.assertRaises(CoordinationError):
                    recover_work(api, 12, "pl0n3r", "OWNER", SESSION_A)

                self.assertEqual(api.pulls[15]["body"], before)
                reservation = active_reservation(api, 12)
                assert reservation is not None
                self.assertEqual(reservation["reservation_id"], SESSION_A)

    def test_recover_work_blocks_issue_when_authority_rollback_fails(self) -> None:
        """An incomplete rollback must leave the Issue explicitly fail-closed."""
        api = FakeGitHub()
        add_active_reservation(api, owner="agent-a")
        api.pulls[15] = {
            "number": 15,
            "state": "open",
            "body": (
                f"Closes #12\nReservation: {SESSION_A}\n"
                f"<!-- brvtal-reservation-id: {SESSION_A} -->"
            ),
            "head": {
                "ref": "work/issue-12",
                "repo": {"full_name": "pl0n3r/brvtal"},
            },
            "base": {"ref": "main"},
        }
        api.fail_verify_after_recover = True
        api.fail_rollback_comment = True

        with self.assertRaisesRegex(CoordinationError, "rollback was incomplete"):
            recover_work(api, 12, "pl0n3r", "OWNER", SESSION_A)

        self.assertEqual(api.status_history[-1], STATUS_BLOCKED)

    def test_take_recovers_oldest_inactive_work_before_new_issue(self) -> None:
        """The normal take path must drain compatible stale work before opening a new line."""
        api = FakeGitHub()
        api.issues[20] = {
            "number": 20,
            "state": "open",
            "state_reason": None,
            "labels": [{"name": STATUS_RESERVED}],
            "assignees": [],
        }
        api.comments_by_issue[20] = []
        api.assignees_by_issue[20] = set()
        add_active_reservation(
            api,
            owner="agent-a",
            issue_number=20,
            branch_sha="stale20",
            age_minutes=90,
        )

        process_comment(api, 12, "pl0n3r", "OWNER", "/take")

        self.assertNotIn("work/issue-12", api.branches)
        recovered = active_reservation(api, 20)
        assert recovered is not None
        self.assertEqual(recovered["owner"], "pl0n3r")
        self.assertNotEqual(recovered["reservation_id"], SESSION_A)
        self.assertIn("pl0n3r", api.assignees_by_issue[20])
        self.assertNotIn("agent-a", api.assignees_by_issue[20])

    def test_take_blocks_when_another_recent_reservation_is_active(self) -> None:
        """A healthy active line must block a second repository reservation."""
        api = FakeGitHub()
        api.issues[20] = {
            "number": 20,
            "state": "open",
            "state_reason": None,
            "labels": [{"name": STATUS_RESERVED}],
            "assignees": [],
        }
        api.comments_by_issue[20] = []
        api.assignees_by_issue[20] = set()
        add_active_reservation(
            api,
            owner="agent-a",
            issue_number=20,
            branch_sha="recent20",
            age_minutes=5,
        )

        with self.assertRaisesRegex(CoordinationError, "active or unsafe reservation"):
            process_comment(api, 12, "pl0n3r", "OWNER", "/take")

        self.assertNotIn("work/issue-12", api.branches)
        self.assertEqual(label_names(api.issue(12)), {STATUS_AVAILABLE})

    def test_take_blocks_when_stale_reservation_is_incompatible(self) -> None:
        """Unsafe stale authority cannot fall through into a second line."""
        api = FakeGitHub()
        api.issues[20] = {
            "number": 20,
            "state": "open",
            "state_reason": None,
            "labels": [{"name": STATUS_RESERVED}],
            "assignees": [],
        }
        api.comments_by_issue[20] = []
        api.assignees_by_issue[20] = set()
        add_active_reservation(
            api,
            owner="agent-a",
            issue_number=20,
            branch_sha="stale20",
            age_minutes=90,
        )
        api.pulls[25] = {
            "number": 25,
            "state": "open",
            "body": (
                f"Closes #20\nReservation: {SESSION_A}\n"
                f"<!-- brvtal-reservation-id: {SESSION_A} -->"
            ),
            "head": {
                "ref": "work/issue-20",
                "repo": {"full_name": "pl0n3r/brvtal"},
            },
            "base": {"ref": "release"},
        }

        with self.assertRaisesRegex(CoordinationError, "active or unsafe reservation"):
            process_comment(api, 12, "pl0n3r", "OWNER", "/take")

        self.assertNotIn("work/issue-12", api.branches)
        self.assertIsNotNone(active_reservation(api, 20))

    def test_released_reservation_allows_next_issue_take(self) -> None:
        """Once the previous line is released, the next Issue may reserve."""
        api = FakeGitHub()
        api.issues[20] = {
            "number": 20,
            "state": "open",
            "state_reason": None,
            "labels": [{"name": STATUS_RESERVED}],
            "assignees": [],
        }
        api.comments_by_issue[20] = []
        api.assignees_by_issue[20] = set()
        add_active_reservation(
            api,
            owner="pl0n3r",
            issue_number=20,
            branch_sha="line20",
            age_minutes=5,
        )
        release_work(api, 20, "pl0n3r", "OWNER", SESSION_A, False)

        process_comment(api, 12, "pl0n3r", "OWNER", "/take")

        self.assertIn("work/issue-12", api.branches)
        self.assertIsNotNone(active_reservation(api, 12))

    def test_reserve_work_direct_call_blocks_other_active_issue(self) -> None:
        """Internal callers cannot bypass the repository-wide ownership guard."""
        api = FakeGitHub()
        api.issues[20] = {
            "number": 20,
            "state": "open",
            "state_reason": None,
            "labels": [{"name": STATUS_RESERVED}],
            "assignees": [],
        }
        api.comments_by_issue[20] = []
        api.assignees_by_issue[20] = set()
        add_active_reservation(
            api,
            owner="agent-a",
            issue_number=20,
            branch_sha="recent20",
            age_minutes=5,
        )

        with self.assertRaisesRegex(CoordinationError, "active reservation already exists"):
            reserve_work(api, 12, "pl0n3r", "OWNER")

        self.assertNotIn("work/issue-12", api.branches)

    def test_workflow_serializes_all_coordination_mutations_repository_wide(self) -> None:
        """Workflow concurrency must not vary by Issue or Pull Request."""
        workflow = Path(".github/workflows/work-coordination.yml").read_text(
            encoding="utf-8"
        )
        self.assertIn("group: brvtal-work-coordination", workflow)
        self.assertIn("cancel-in-progress: false", workflow)
        self.assertIn("queue: max", workflow)
        self.assertNotIn("github.event.issue.number &&", workflow)
        self.assertNotIn("github.event.pull_request.head.ref", workflow)

    def test_recover_work_rotates_same_owner_session_when_stale(self) -> None:
        """Recovery also serves a new agent session using the same GitHub actor."""
        api = FakeGitHub()
        add_active_reservation(api, owner="pl0n3r")

        new_id = recover_work(api, 12, "pl0n3r", "OWNER", SESSION_A)

        self.assertIsNotNone(new_id)
        self.assertNotEqual(new_id, SESSION_A)
        self.assertIn("pl0n3r", api.assignees)

    def test_recover_work_rejects_wrong_session_without_mutation(self) -> None:
        """A stale-looking Issue cannot be stolen without the exact active UUID."""
        api = FakeGitHub()
        add_active_reservation(api, owner="agent-a")
        before_comments = list(api.comments)

        result = recover_work(api, 12, "pl0n3r", "OWNER", SESSION_B)

        self.assertIsNone(result)
        self.assertEqual(api.comments, before_comments)
        self.assertIn("agent-a", api.assignees)
        self.assertNotIn("pl0n3r", api.assignees)

    def test_recover_work_rolls_back_if_pr_metadata_cannot_move(self) -> None:
        """Failed PR metadata rotation must leave the previous reservation authoritative."""
        api = FakeGitHub()
        add_active_reservation(api, owner="agent-a")
        api.pulls[15] = {
            "number": 15,
            "state": "open",
            "body": f"Closes #12\nReservation: {SESSION_A}",
            "head": {"ref": "work/issue-12", "repo": {"full_name": "pl0n3r/brvtal"}},
            "base": {"ref": "main"},
        }
        api.fail_update_pull = True

        with self.assertRaisesRegex(CoordinationError, "simulated pull update failure"):
            recover_work(api, 12, "pl0n3r", "OWNER", SESSION_A)

        reservation = active_reservation(api, 12)
        assert reservation is not None
        self.assertEqual(reservation["owner"], "agent-a")
        self.assertEqual(reservation["reservation_id"], SESSION_A)
        self.assertNotIn("pl0n3r", api.assignees)
        self.assertIn("agent-a", api.assignees)

    def test_parse_comment_commands(self) -> None:
        """BRVTAL work-coordination helper."""
        self.assertEqual(parse_comment_command("/take"), ("take", None))
        self.assertEqual(
            parse_comment_command(f"/release {SESSION_A}"),
            ("release", SESSION_A),
        )
        self.assertEqual(
            parse_comment_command(f"/transfer {SESSION_A}"),
            ("transfer", SESSION_A),
        )
        self.assertEqual(
            parse_comment_command(f"/recover {SESSION_A}"),
            ("recover", SESSION_A),
        )
        with self.assertRaises(CoordinationError):
            parse_comment_command("/release no-es-uuid")

    def test_pr_opened_ready_moves_to_review(self) -> None:
        """BRVTAL work-coordination helper."""
        api = FakeGitHub()
        add_active_reservation(api)
        api.pulls[15] = {
            "number": 15,
            "state": "open",
            "draft": False,
            "head": {"ref": "work/issue-12", "repo": {"full_name": "pl0n3r/brvtal"}},
            "base": {"ref": "main"},
            "merged": False,
        }
        update_pr_state(api, 15, "opened")
        self.assertEqual(api.status_history[-1], STATUS_REVIEW)

    def test_pr_close_without_merge_releases(self) -> None:
        """BRVTAL work-coordination helper."""
        api = FakeGitHub()
        add_active_reservation(api)
        api.pulls[15] = {
            "number": 15,
            "state": "closed",
            "draft": False,
            "head": {"ref": "work/issue-12", "repo": {"full_name": "pl0n3r/brvtal"}},
            "base": {"ref": "main"},
            "merged": False,
        }
        update_pr_state(api, 15, "closed")
        self.assertNotIn("work/issue-12", api.branches)
        self.assertEqual(api.status_history[-1], STATUS_AVAILABLE)

    def test_pr_close_preserves_cancelled_issue_state(self) -> None:
        """BRVTAL work-coordination helper."""
        api = FakeGitHub()
        add_active_reservation(api)
        api.issue_data["state"] = "closed"
        api.issue_data["state_reason"] = "not_planned"
        api.set_status(12, STATUS_CANCELLED)
        api.pulls[15] = {
            "number": 15,
            "state": "closed",
            "draft": False,
            "head": {"ref": "work/issue-12", "repo": {"full_name": "pl0n3r/brvtal"}},
            "base": {"ref": "main"},
            "merged": False,
        }
        update_pr_state(api, 15, "closed")
        self.assertNotIn("work/issue-12", api.branches)
        self.assertEqual(api.status_history[-1], STATUS_CANCELLED)

    def test_issue_close_cleans_branch_and_completes(self) -> None:
        """BRVTAL work-coordination helper."""
        api = FakeGitHub()
        add_active_reservation(api)
        api.issue_data["state"] = "closed"
        update_issue_state(api, 12, "closed")
        self.assertNotIn("work/issue-12", api.branches)
        self.assertEqual(api.status_history[-1], STATUS_COMPLETED)

    def test_validate_pull_requires_main(self) -> None:
        """BRVTAL work-coordination helper."""
        api = FakeGitHub()
        api.pulls[15] = {
            "number": 15,
            "state": "open",
            "draft": False,
            "body": "Closes #12",
            "head": {"ref": "work/issue-12", "repo": {"full_name": "pl0n3r/brvtal"}},
            "base": {"ref": "other-branch"},
        }
        with self.assertRaises(CoordinationError):
            validate_pull(api, 15, False)

    def test_validate_pull_checks_active_session(self) -> None:
        """BRVTAL work-coordination helper."""
        api = FakeGitHub()
        add_active_reservation(api)
        api.pulls[15] = {
            "number": 15,
            "state": "open",
            "draft": False,
            "body": f"Closes #12\n<!-- brvtal-reservation-id: {SESSION_A} -->",
            "head": {"ref": "work/issue-12", "repo": {"full_name": "pl0n3r/brvtal"}},
            "base": {"ref": "main"},
        }
        api.pull_files_map[15] = {"src/a.php"}
        validate_pull(api, 15, True)

        api.pulls[15]["body"] = (
            f"Closes #12\n<!-- brvtal-reservation-id: {SESSION_B} -->"
        )
        with self.assertRaises(CoordinationError):
            validate_pull(api, 15, True)

    def test_file_overlap_detects_only_blocking_collisions(self) -> None:
        """Transient README snapshots must not serialize otherwise independent PRs."""
        current = {"src/a.php", "README.md", "src/b.php"}
        others = {
            10: {"src/a.php", "src/x.php", "README.md"},
            11: {"docs/other.md"},
            12: {"README.md"},
        }
        self.assertEqual(
            file_overlaps(current, others),
            {10: ["src/a.php"]},
        )

    def test_readme_only_overlap_is_non_blocking(self) -> None:
        """Every PR rewrites README, so README-only overlap must remain parallel-safe."""
        self.assertEqual(
            file_overlaps({"README.md"}, {20: {"README.md"}}),
            {},
        )

    def test_validate_pull_rejects_open_pr_overlap(self) -> None:
        """BRVTAL work-coordination helper."""
        api = FakeGitHub()
        add_active_reservation(api)
        api.pulls[15] = {
            "number": 15,
            "state": "open",
            "draft": False,
            "body": f"Closes #12\n<!-- brvtal-reservation-id: {SESSION_A} -->",
            "head": {"ref": "work/issue-12", "repo": {"full_name": "pl0n3r/brvtal"}},
            "base": {"ref": "main"},
        }
        api.pulls[20] = {
            "number": 20,
            "state": "open",
            "draft": False,
            "body": "Closes #20",
            "head": {"ref": "work/issue-20"},
            "base": {"ref": "main"},
        }
        api.pull_files_map[15] = {"src/a.php"}
        api.pull_files_map[20] = {"src/a.php"}
        with self.assertRaises(CoordinationError):
            validate_pull(api, 15, True)



class BrvtalCoordinationExtensionsTests(unittest.TestCase):
    """BRVTAL work-coordination helper."""

    def test_unlabelled_legacy_issue_can_be_reserved_atomically(self) -> None:
        """BRVTAL work-coordination helper."""
        api = FakeGitHub()
        api.issue_data["labels"] = []
        session = reserve_work(api, 12, "pl0n3r", "OWNER")
        self.assertIsNotNone(session)
        self.assertIn("work/issue-12", api.branches)
        self.assertEqual(api.status_history[-1], STATUS_RESERVED)

    def test_deploy_bound_pr_requires_version_in_title(self) -> None:
        """BRVTAL work-coordination helper."""
        api = FakeGitHub()
        api.branches["work/issue-12"] = "abc123"
        api.pulls[7] = {
            "number": 7, "state": "open", "title": "infra: missing version",
            "body": "Closes #12", "base": {"ref": "main"}, "head": {"ref": "work/issue-12", "repo": {"full_name": "pl0n3r/brvtal"}},
        }
        api.pull_files_map[7] = {"config/version.php"}
        with self.assertRaises(CoordinationError):
            validate_pull(api, 7, False)
        api.pulls[7]["title"] = "infra: coordinated delivery (v0.1.22)"
        validate_pull(api, 7, False)

if __name__ == "__main__":
    unittest.main()
