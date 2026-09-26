#!/usr/bin/env python3
"""BRVTAL work-coordination helper."""

from __future__ import annotations

import argparse
import contextlib
import json
import os
import re
import sys
import uuid
from datetime import datetime, timedelta, timezone
from dataclasses import dataclass
from typing import Any
from urllib.error import HTTPError
from urllib.parse import quote
from urllib.request import Request, urlopen


API_URL = os.getenv("GITHUB_API_URL", "https://api.github.com").rstrip("/")
TOKEN = os.getenv("GH_TOKEN") or os.getenv("GITHUB_TOKEN")
TRUSTED_MARKER_LOGIN = os.getenv(
    "BRVTAL_TRUSTED_MARKER_LOGIN",
    "github-actions[bot]",
)

ALLOWED_ASSOCIATIONS = {"OWNER", "MEMBER", "COLLABORATOR"}
RECOVERY_INACTIVITY = timedelta(minutes=30)
COORDINATION_COMMAND_RE = re.compile(
    r"^(?:/(?:take|force-release)|/(?:release|transfer|recover)\s+\S+)\s*$"
)

STATUS_AVAILABLE = "status: available"
STATUS_RESERVED = "status: reserved"
STATUS_REVIEW = "status: in review"
STATUS_COMPLETED = "status: completed"
STATUS_CANCELLED = "status: cancelled"
STATUS_BLOCKED = "status: blocked"

TAKE_NONE = "none"
TAKE_RECOVERED = "recovered"
TAKE_BLOCKED = "blocked"

STATUS_LABELS: dict[str, tuple[str, str]] = {
    STATUS_AVAILABLE: ("2DA44E", "Work is available for reservation."),
    STATUS_RESERVED: ("FBCA04", "Work is reserved by a session or agent."),
    STATUS_REVIEW: ("1D76DB", "Work has a Pull Request ready for review."),
    STATUS_COMPLETED: ("0E8A16", "Work is completed."),
    STATUS_CANCELLED: ("6E7781", "Work was closed without completion."),
    STATUS_BLOCKED: ("000000", "Work is blocked by a real dependency."),
}

BRANCH_RE = re.compile(r"^work/issue-(\d+)$")
CLOSING_RE = re.compile(r"(?im)\b(?:closes|fixes|resolves)\s+#(\d+)\b")
VERSION_TITLE_RE = re.compile(r"\(v\d+\.\d+\.\d+\)$")
RESERVATION_LINE_RE = re.compile(
    r"(?im)^Reservation:\s*([0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-"
    r"[89ab][0-9a-f]{3}-[0-9a-f]{12})\s*$"
)
RESERVATION_HIDDEN_RE = re.compile(
    r"<!--\s*brvtal-reservation-id:\s*([0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-"
    r"[89ab][0-9a-f]{3}-[0-9a-f]{12})\s*-->"
)
RESERVATION_RE = re.compile(r"<!-- brvtal-work-reservation (\{[^}]*\}) -->")
SESSION_RE = re.compile(
    r"^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-"
    r"[89ab][0-9a-f]{3}-[0-9a-f]{12}$"
)


class CoordinationError(RuntimeError):
    """BRVTAL work-coordination helper."""


@dataclass
class GitHubError(RuntimeError):
    """BRVTAL work-coordination helper."""

    status: int
    message: str

    def __str__(self) -> str:
        """BRVTAL work-coordination helper."""
        return f"GitHub API {self.status}: {self.message}"


class GitHub:
    """BRVTAL work-coordination helper."""

    def __init__(self, repo: str, token: str | None = None) -> None:
        """BRVTAL work-coordination helper."""
        self.repo = repo
        self.token = token or TOKEN
        if not self.token:
            raise CoordinationError("GH_TOKEN/GITHUB_TOKEN is required to query GitHub.")

    def request(
        self,
        method: str,
        path: str,
        payload: Any | None = None,
        allow: tuple[int, ...] = (),
    ) -> Any:
        """BRVTAL work-coordination helper."""
        url = f"{API_URL}{path}"
        body = None if payload is None else json.dumps(payload).encode("utf-8")
        headers = {
            "Accept": "application/vnd.github+json",
            "Authorization": f"Bearer {self.token}",
            "X-GitHub-Api-Version": "2022-11-28",
            "User-Agent": "brvtal-work-coordination",
        }
        if body is not None:
            headers["Content-Type"] = "application/json"
        request = Request(url, data=body, headers=headers, method=method)
        try:
            with urlopen(request, timeout=30) as response:
                raw = response.read()
                return None if not raw else json.loads(raw.decode("utf-8"))
        except HTTPError as exc:
            raw = exc.read().decode("utf-8", errors="replace")
            if exc.code in allow:
                return None
            try:
                message = json.loads(raw).get("message", raw)
            except json.JSONDecodeError:
                message = raw
            raise GitHubError(exc.code, str(message)) from exc

    def paginate(self, path: str) -> list[dict[str, Any]]:
        """BRVTAL work-coordination helper."""
        page = 1
        items: list[dict[str, Any]] = []
        while True:
            separator = "&" if "?" in path else "?"
            payload = self.request("GET", f"{path}{separator}per_page=100&page={page}")
            if not isinstance(payload, list):
                break
            items.extend(item for item in payload if isinstance(item, dict))
            if len(payload) < 100:
                break
            page += 1
        return items

    def issue(self, number: int) -> dict[str, Any]:
        """BRVTAL work-coordination helper."""
        payload = self.request("GET", f"/repos/{self.repo}/issues/{number}")
        if not isinstance(payload, dict):
            raise CoordinationError(f"Unable to read Issue #{number}.")
        return payload

    def pull(self, number: int) -> dict[str, Any]:
        """BRVTAL work-coordination helper."""
        payload = self.request("GET", f"/repos/{self.repo}/pulls/{number}")
        if not isinstance(payload, dict):
            raise CoordinationError(f"Unable to read PR #{number}.")
        return payload

    def comment(self, issue_number: int, body: str) -> None:
        """BRVTAL work-coordination helper."""
        self.request(
            "POST",
            f"/repos/{self.repo}/issues/{issue_number}/comments",
            {"body": body},
        )

    def ensure_label(self, name: str, color: str, description: str) -> None:
        """BRVTAL work-coordination helper."""
        encoded = quote(name, safe="")
        current = self.request(
            "GET",
            f"/repos/{self.repo}/labels/{encoded}",
            allow=(404,),
        )
        if current is None:
            self.request(
                "POST",
                f"/repos/{self.repo}/labels",
                {"name": name, "color": color, "description": description},
            )

    def ensure_status_labels(self) -> None:
        """BRVTAL work-coordination helper."""
        for name, (color, description) in STATUS_LABELS.items():
            self.ensure_label(name, color, description)

    def add_labels(self, issue_number: int, labels: list[str]) -> None:
        """BRVTAL work-coordination helper."""
        if labels:
            self.request(
                "POST",
                f"/repos/{self.repo}/issues/{issue_number}/labels",
                {"labels": labels},
            )

    def remove_label(self, issue_number: int, label: str) -> None:
        """BRVTAL work-coordination helper."""
        encoded = quote(label, safe="")
        self.request(
            "DELETE",
            f"/repos/{self.repo}/issues/{issue_number}/labels/{encoded}",
            allow=(404,),
        )

    def set_status(self, issue_number: int, status: str | None) -> None:
        """BRVTAL work-coordination helper."""
        self.ensure_status_labels()
        for label in STATUS_LABELS:
            self.remove_label(issue_number, label)
        if status:
            self.add_labels(issue_number, [status])

    def branch_sha(self, branch: str) -> str | None:
        """BRVTAL work-coordination helper."""
        encoded = quote(branch, safe="/")
        payload = self.request(
            "GET",
            f"/repos/{self.repo}/git/ref/heads/{encoded}",
            allow=(404,),
        )
        if not isinstance(payload, dict):
            return None
        obj = payload.get("object")
        return str(obj.get("sha")) if isinstance(obj, dict) and obj.get("sha") else None

    def branch_commit_time(self, branch: str) -> datetime | None:
        """Return the timestamp of the commit currently at the branch head."""
        sha = self.branch_sha(branch)
        if not sha:
            return None
        payload = self.request("GET", f"/repos/{self.repo}/commits/{sha}")
        if not isinstance(payload, dict):
            return None
        commit = payload.get("commit")
        if not isinstance(commit, dict):
            return None
        for key in ("committer", "author"):
            identity = commit.get(key)
            if isinstance(identity, dict):
                parsed = parse_github_timestamp(identity.get("date"))
                if parsed is not None:
                    return parsed
        return None

    def create_branch(self, branch: str, sha: str) -> bool:
        """BRVTAL work-coordination helper."""
        try:
            self.request(
                "POST",
                f"/repos/{self.repo}/git/refs",
                {"ref": f"refs/heads/{branch}", "sha": sha},
            )
        except GitHubError as exc:
            if exc.status == 422:
                return False
            raise
        return True

    def delete_branch(self, branch: str) -> None:
        """BRVTAL work-coordination helper."""
        encoded = quote(branch, safe="/")
        try:
            self.request(
                "DELETE",
                f"/repos/{self.repo}/git/refs/heads/{encoded}",
                allow=(404,),
            )
        except GitHubError as exc:
            if exc.status != 422:
                raise
            if self.branch_sha(branch) is not None:
                raise

    def issue_comments(self, issue_number: int) -> list[dict[str, Any]]:
        """BRVTAL work-coordination helper."""
        return self.paginate(f"/repos/{self.repo}/issues/{issue_number}/comments")

    def open_pulls(self) -> list[dict[str, Any]]:
        """BRVTAL work-coordination helper."""
        return self.paginate(f"/repos/{self.repo}/pulls?state=open")

    def open_issues(self) -> list[dict[str, Any]]:
        """Return open Issues only; GitHub's Issues endpoint also includes PRs."""
        return [
            item
            for item in self.paginate(f"/repos/{self.repo}/issues?state=open")
            if not item.get("pull_request")
        ]

    def compare_files(self, base_sha: str, head_sha: str) -> set[str]:
        """Return changed paths between two repository commits."""
        payload = self.request(
            "GET",
            f"/repos/{self.repo}/compare/{base_sha}...{head_sha}",
        )
        if not isinstance(payload, dict):
            return set()
        files = payload.get("files")
        if not isinstance(files, list):
            return set()
        return {
            str(item["filename"])
            for item in files
            if isinstance(item, dict) and isinstance(item.get("filename"), str)
        }

    def pull_files(self, number: int) -> set[str]:
        """BRVTAL work-coordination helper."""
        files = self.paginate(f"/repos/{self.repo}/pulls/{number}/files")
        return {
            str(item["filename"])
            for item in files
            if isinstance(item.get("filename"), str)
        }

    def close_pull(self, number: int) -> None:
        """BRVTAL work-coordination helper."""
        self.request(
            "PATCH",
            f"/repos/{self.repo}/pulls/{number}",
            {"state": "closed"},
        )

    def update_pull_body(self, number: int, body: str) -> None:
        """Keep reservation metadata synchronized when ownership is recovered."""
        self.request(
            "PATCH",
            f"/repos/{self.repo}/pulls/{number}",
            {"body": body},
        )

    def assign_strict(self, issue_number: int, login: str) -> None:
        """Assign recovery ownership without swallowing GitHub failures."""
        self.request(
            "POST",
            f"/repos/{self.repo}/issues/{issue_number}/assignees",
            {"assignees": [login]},
        )

    def unassign_strict(self, issue_number: int, login: str) -> None:
        """Remove recovery ownership without swallowing GitHub failures."""
        self.request(
            "DELETE",
            f"/repos/{self.repo}/issues/{issue_number}/assignees",
            {"assignees": [login]},
        )

    def try_assign(self, issue_number: int, login: str) -> None:
        """BRVTAL work-coordination helper."""
        self.request(
            "POST",
            f"/repos/{self.repo}/issues/{issue_number}/assignees",
            {"assignees": [login]},
            allow=(404, 422),
        )

    def try_unassign(self, issue_number: int, login: str) -> None:
        """BRVTAL work-coordination helper."""
        self.request(
            "DELETE",
            f"/repos/{self.repo}/issues/{issue_number}/assignees",
            {"assignees": [login]},
            allow=(404, 422),
        )


def issue_from_branch(branch: str) -> int | None:
    """BRVTAL work-coordination helper."""
    match = BRANCH_RE.fullmatch(branch)
    return int(match.group(1)) if match else None


def closing_issues(body: str) -> set[int]:
    """BRVTAL work-coordination helper."""
    return {int(value) for value in CLOSING_RE.findall(body or "")}


def reservation_from_pr_body(body: str) -> str | None:
    """BRVTAL work-coordination helper."""
    value = body or ""
    match = RESERVATION_HIDDEN_RE.search(value) or RESERVATION_LINE_RE.search(value)
    return match.group(1).lower() if match else None


def replace_pr_reservation(body: str, reservation_id: str) -> str:
    """Synchronize visible and hidden PR reservation metadata."""
    reservation_id = reservation_id.lower()
    value = body or ""
    visible = f"Reservation: {reservation_id}"
    hidden = f"<!-- brvtal-reservation-id: {reservation_id} -->"

    if RESERVATION_LINE_RE.search(value):
        value = RESERVATION_LINE_RE.sub(visible, value, count=1)
    else:
        value = value.rstrip() + f"\n\n{visible}\n"

    if RESERVATION_HIDDEN_RE.search(value):
        value = RESERVATION_HIDDEN_RE.sub(hidden, value, count=1)
    else:
        value = value.rstrip() + f"\n\n{hidden}\n"
    return value


def new_reservation_id() -> str:
    """BRVTAL work-coordination helper."""
    return str(uuid.uuid4())


def parse_github_timestamp(value: Any) -> datetime | None:
    """Parse GitHub ISO timestamps as timezone-aware UTC datetimes."""
    if not isinstance(value, str) or not value:
        return None
    try:
        parsed = datetime.fromisoformat(value.replace("Z", "+00:00"))
    except ValueError:
        return None
    if parsed.tzinfo is None:
        parsed = parsed.replace(tzinfo=timezone.utc)
    return parsed.astimezone(timezone.utc)


def reservation_marker(
    owner: str,
    reservation_id: str,
    branch: str,
    active: bool,
    reason: str,
) -> str:
    """BRVTAL work-coordination helper."""
    payload = json.dumps(
        {
            "version": 1,
            "owner": owner,
            "reservation_id": reservation_id.lower(),
            "branch": branch,
            "active": active,
            "reason": reason,
        },
        separators=(",", ":"),
        sort_keys=True,
    )
    return f"<!-- brvtal-work-reservation {payload} -->"


def valid_reservation_payload(value: Any) -> bool:
    """BRVTAL work-coordination helper."""
    if not isinstance(value, dict):
        return False
    required = {
        "version",
        "owner",
        "reservation_id",
        "branch",
        "active",
        "reason",
    }
    if set(value) != required:
        return False
    if value.get("version") != 1:
        return False
    if not isinstance(value.get("owner"), str) or not value["owner"]:
        return False
    reservation_id = value.get("reservation_id")
    if not isinstance(reservation_id, str) or not SESSION_RE.fullmatch(
        reservation_id.lower()
    ):
        return False
    branch = value.get("branch")
    if not isinstance(branch, str) or BRANCH_RE.fullmatch(branch) is None:
        return False
    if not isinstance(value.get("active"), bool):
        return False
    return isinstance(value.get("reason"), str) and bool(value["reason"])


def reservation_from_text(text: str) -> dict[str, Any] | None:
    """BRVTAL work-coordination helper."""
    latest: dict[str, Any] | None = None
    for match in RESERVATION_RE.finditer(text or ""):
        try:
            parsed = json.loads(match.group(1))
        except json.JSONDecodeError:
            continue
        if valid_reservation_payload(parsed):
            latest = parsed
    return latest


def latest_reservation_record(
    comments: list[dict[str, Any]],
    trusted_login: str = TRUSTED_MARKER_LOGIN,
) -> tuple[dict[str, Any], datetime | None] | None:
    """Return the latest trusted reservation plus its authoritative marker time."""
    latest: tuple[dict[str, Any], datetime | None] | None = None
    for comment in comments:
        user = comment.get("user")
        if not isinstance(user, dict) or user.get("login") != trusted_login:
            continue
        parsed = reservation_from_text(str(comment.get("body") or ""))
        if parsed is not None:
            latest = (parsed, parse_github_timestamp(comment.get("created_at")))
    return latest


def latest_reservation(
    comments: list[dict[str, Any]],
    trusted_login: str = TRUSTED_MARKER_LOGIN,
) -> dict[str, Any] | None:
    """BRVTAL work-coordination helper."""
    record = latest_reservation_record(comments, trusted_login)
    return record[0] if record is not None else None


def latest_qualifying_human_activity(
    comments: list[dict[str, Any]],
) -> datetime | None:
    """Return the latest human implementation comment, excluding coordination noise."""
    latest: datetime | None = None
    for comment in comments:
        user = comment.get("user")
        if not isinstance(user, dict):
            continue
        login = str(user.get("login") or "")
        if user.get("type") == "Bot" or login.endswith("[bot]"):
            continue
        body = str(comment.get("body") or "").strip()
        if COORDINATION_COMMAND_RE.fullmatch(body):
            continue
        created_at = parse_github_timestamp(comment.get("created_at"))
        if created_at is not None and (latest is None or created_at > latest):
            latest = created_at
    return latest


def reservation_is_inactive(
    api: GitHub,
    issue_number: int,
    branch: str,
    comments: list[dict[str, Any]],
    reservation_started_at: datetime | None,
) -> bool:
    """Fail closed unless the reservation has had no useful activity for 30 minutes."""
    if reservation_started_at is None:
        raise CoordinationError(
            f"Cannot recover Issue #{issue_number}: reservation timestamp is missing."
        )
    branch_time = api.branch_commit_time(branch)
    if branch_time is None:
        raise CoordinationError(
            f"Cannot recover Issue #{issue_number}: branch activity timestamp is unavailable."
        )
    activity = [reservation_started_at, branch_time]
    human_activity = latest_qualifying_human_activity(comments)
    if human_activity is not None:
        activity.append(human_activity)
    last_activity = max(activity)
    return datetime.now(timezone.utc) - last_activity >= RECOVERY_INACTIVITY


NON_BLOCKING_SHARED_FILES = {"README.md"}


def file_overlaps(
    current_files: set[str],
    others: dict[int, set[str]],
) -> dict[int, list[str]]:
    """Return only implementation collisions; transient shared snapshots do not block."""
    blocking_current = current_files - NON_BLOCKING_SHARED_FILES
    collisions: dict[int, list[str]] = {}
    for pr_number, files in others.items():
        overlap = sorted(blocking_current & (files - NON_BLOCKING_SHARED_FILES))
        if overlap:
            collisions[pr_number] = overlap
    return collisions


def label_names(issue: dict[str, Any]) -> set[str]:
    """BRVTAL work-coordination helper."""
    result: set[str] = set()
    for label in issue.get("labels", []):
        if isinstance(label, dict) and isinstance(label.get("name"), str):
            result.add(label["name"])
    return result


def authorized(association: str) -> bool:
    """BRVTAL work-coordination helper."""
    return association.upper() in ALLOWED_ASSOCIATIONS


def active_reservation(api: GitHub, issue_number: int) -> dict[str, Any] | None:
    """BRVTAL work-coordination helper."""
    reservation = latest_reservation(api.issue_comments(issue_number))
    if not reservation or not reservation["active"]:
        return None
    return reservation


def assignee_logins(issue: dict[str, Any]) -> set[str]:
    """Return the normalized assignee login set for an Issue."""
    return {
        str(item["login"])
        for item in (issue.get("assignees") or [])
        if isinstance(item, dict) and isinstance(item.get("login"), str)
    }


def pull_head_repo(pull: dict[str, Any]) -> str | None:
    """Return the full repository name for a PR head."""
    head = pull.get("head")
    if not isinstance(head, dict):
        return None
    head_repo = head.get("repo")
    if not isinstance(head_repo, dict):
        return None
    full_name = head_repo.get("full_name")
    return str(full_name) if isinstance(full_name, str) else None


def open_pulls_for_branch(api: GitHub, branch: str) -> list[int]:
    """BRVTAL work-coordination helper."""
    result: list[int] = []
    for pull in api.open_pulls():
        head = pull.get("head")
        if isinstance(head, dict) and head.get("ref") == branch:
            number = pull.get("number")
            if isinstance(number, int):
                result.append(number)
    return result


def publish_reservation(
    api: GitHub,
    issue_number: int,
    owner: str,
    reservation_id: str,
    branch: str,
    active: bool,
    reason: str,
    message: str,
) -> None:
    """BRVTAL work-coordination helper."""
    api.comment(
        issue_number,
        f"{reservation_marker(owner, reservation_id, branch, active, reason)}\n"
        f"{message}",
    )


def reserve_work(
    api: GitHub,
    issue_number: int,
    actor: str,
    association: str,
) -> str | None:
    """BRVTAL work-coordination helper."""
    if not authorized(association):
        raise CoordinationError(
            f"@{actor} is not authorized to reserve work."
        )

    issue = api.issue(issue_number)
    if issue.get("pull_request"):
        raise CoordinationError("Reservations apply to Issues, not Pull Requests.")
    if issue.get("state") != "open":
        raise CoordinationError(f"Issue #{issue_number} is not open.")

    labels = label_names(issue)
    if labels & {STATUS_BLOCKED, STATUS_REVIEW, STATUS_COMPLETED, STATUS_CANCELLED}:
        return None

    other_active = [
        number
        for number, _reservation, _started_at, _comments in active_reservation_records(api)
        if number != issue_number
    ]
    if other_active:
        rendered = ", ".join(f"#{number}" for number in sorted(other_active))
        raise CoordinationError(
            f"Cannot reserve Issue #{issue_number}: active reservation already exists on {rendered}."
        )

    branch = f"work/issue-{issue_number}"
    main_sha = api.branch_sha("main")
    if not main_sha:
        raise CoordinationError("Unable to resolve the current main SHA.")

    if not api.create_branch(branch, main_sha):
        return None

    reservation_id = new_reservation_id()
    try:
        api.set_status(issue_number, STATUS_RESERVED)
        api.try_assign(issue_number, actor)
        api.comment(
            issue_number,
            reservation_marker(
                actor,
                reservation_id,
                branch,
                True,
                "take",
            ),
        )
    except Exception:
        with contextlib.suppress(Exception):
            api.delete_branch(branch)
        with contextlib.suppress(Exception):
            api.try_unassign(issue_number, actor)
        with contextlib.suppress(Exception):
            api.set_status(issue_number, STATUS_AVAILABLE)
        raise

    print(
        f"Reservation granted: Issue #{issue_number} -> {branch} "
        f"(@{actor}, {reservation_id})"
    )
    return reservation_id


def transfer_work(
    api: GitHub,
    issue_number: int,
    actor: str,
    association: str,
    reservation_id: str,
) -> str | None:
    """BRVTAL work-coordination helper."""
    if not authorized(association):
        raise CoordinationError(
            f"@{actor} is not authorized to transfer work."
        )
    current = active_reservation(api, issue_number)
    if not current:
        return None
    if current["owner"] != actor or current["reservation_id"] != reservation_id.lower():
        return None

    new_id = new_reservation_id()
    api.comment(
        issue_number,
        reservation_marker(
            actor,
            new_id,
            current["branch"],
            True,
            "transfer",
        ),
    )
    print(f"Reservation transferred: Issue #{issue_number} -> {new_id}")
    return new_id



def recovery_context(
    api: GitHub,
    issue_number: int,
    actor: str,
    association: str,
    reservation_id: str,
) -> tuple[dict[str, Any], dict[str, Any], str] | None:
    """Validate recovery ownership and return the immutable recovery context."""
    if not authorized(association):
        raise CoordinationError(
            f"@{actor} is not authorized to recover work."
        )

    comments = api.issue_comments(issue_number)
    record = latest_reservation_record(comments)
    if record is None:
        return None
    current, reservation_started_at = record
    if not current["active"] or current["reservation_id"] != reservation_id.lower():
        return None
    issue = api.issue(issue_number)
    if issue.get("state") != "open":
        return None
    if not (label_names(issue) & {STATUS_RESERVED, STATUS_REVIEW}):
        return None

    branch = str(current["branch"])
    if api.branch_sha(branch) is None:
        raise CoordinationError(
            f"Cannot recover Issue #{issue_number}: canonical branch {branch} is missing."
        )
    if not reservation_is_inactive(
        api,
        issue_number,
        branch,
        comments,
        reservation_started_at,
    ):
        return None
    return current, issue, branch


def recovery_pull_context(
    api: GitHub,
    issue_number: int,
    branch: str,
    reservation_id: str,
) -> tuple[int | None, str | None]:
    """Resolve and validate the optional PR attached to recovered work."""
    pull_numbers = open_pulls_for_branch(api, branch)
    if len(pull_numbers) > 1:
        raise CoordinationError(
            f"Cannot recover Issue #{issue_number}: multiple open PRs own {branch}."
        )
    if not pull_numbers:
        return None, None

    pull_number = pull_numbers[0]
    pull = api.pull(pull_number)
    head = pull.get("head")
    base = pull.get("base")
    body = str(pull.get("body") or "")
    errors: list[str] = []

    if pull_head_repo(pull) != api.repo:
        errors.append("the existing PR head repository does not match this repository")
    if not isinstance(head, dict) or head.get("ref") != branch:
        errors.append("the existing PR no longer uses the canonical reserved branch")
    if not isinstance(base, dict) or base.get("ref") != "main":
        errors.append("the existing PR does not target main")
    if issue_number not in closing_issues(body):
        errors.append(f"the existing PR does not close Issue #{issue_number}")
    if reservation_from_pr_body(body) != reservation_id.lower():
        errors.append("the existing PR reservation UUID does not match the active marker")

    if errors:
        raise CoordinationError(
            f"Cannot recover Issue #{issue_number}: " + "; ".join(errors) + "."
        )
    return pull_number, body


@dataclass
class RecoveryMutationState:
    """Track completed ownership mutations so rollback can be exact."""
    actor_was_assigned: bool
    previous_owner_was_assigned: bool
    actor_assigned: bool = False
    previous_owner_unassigned: bool = False
    pr_updated: bool = False
    marker_published: bool = False


def recovery_collision_errors(
    api: GitHub,
    branch: str,
    pull_number: int | None,
) -> list[str]:
    """Detect changed-file collisions before stale work changes owner."""
    if pull_number is not None:
        current_files = api.pull_files(pull_number)
    else:
        main_sha = api.branch_sha("main")
        branch_sha = api.branch_sha(branch)
        if not main_sha or not branch_sha:
            return ["unable to resolve branch commits for compatibility"]
        current_files = api.compare_files(main_sha, branch_sha)

    others: dict[int, set[str]] = {}
    for pull in api.open_pulls():
        other_number = pull.get("number")
        if not isinstance(other_number, int) or other_number == pull_number:
            continue
        base = pull.get("base")
        if isinstance(base, dict) and base.get("ref") != "main":
            continue
        others[other_number] = api.pull_files(other_number)

    return [
        f"collision with PR #{number}: {', '.join(files)}"
        for number, files in file_overlaps(current_files, others).items()
    ]


def verify_recovery_assignees(
    api: GitHub,
    issue_number: int,
    actor: str,
    previous_owner: str,
) -> None:
    """Require the final assignee state before publishing new authority."""
    assigned = assignee_logins(api.issue(issue_number))
    if actor not in assigned:
        raise CoordinationError(
            f"Recovery assignee @{actor} is not assigned to Issue #{issue_number}."
        )
    if previous_owner != actor and previous_owner in assigned:
        raise CoordinationError(
            f"Previous owner @{previous_owner} is still assigned to Issue #{issue_number}."
        )


def attempt_recovery_rollback(
    failures: list[str],
    label: str,
    action: Any,
) -> None:
    """Run one rollback action and preserve its failure for fail-closed handling."""
    try:
        action()
    except Exception as exc:
        failures.append(f"{label}: {exc}")


def rollback_recovery(
    api: GitHub,
    issue_number: int,
    actor: str,
    previous_owner: str,
    reservation_id: str,
    branch: str,
    pull_number: int | None,
    previous_body: str | None,
    state: RecoveryMutationState,
) -> list[str]:
    """Restore previous authority and report every rollback failure."""
    failures: list[str] = []

    if state.pr_updated and pull_number is not None and previous_body is not None:
        attempt_recovery_rollback(
            failures,
            "restore PR body",
            lambda: api.update_pull_body(pull_number, previous_body),
        )

    if state.marker_published:
        attempt_recovery_rollback(
            failures,
            "restore reservation authority",
            lambda: api.comment(
                issue_number,
                reservation_marker(
                    previous_owner,
                    reservation_id,
                    branch,
                    True,
                    "recover-rollback",
                ),
            ),
        )

    if state.previous_owner_unassigned and previous_owner != actor:
        attempt_recovery_rollback(
            failures,
            "restore previous assignee",
            lambda: api.assign_strict(issue_number, previous_owner),
        )

    if state.actor_assigned and not state.actor_was_assigned:
        attempt_recovery_rollback(
            failures,
            "remove recovery assignee",
            lambda: api.unassign_strict(issue_number, actor),
        )

    if failures:
        attempt_recovery_rollback(
            failures,
            "set fail-closed status",
            lambda: api.set_status(issue_number, STATUS_BLOCKED),
        )
    return failures


def recover_work(
    api: GitHub,
    issue_number: int,
    actor: str,
    association: str,
    reservation_id: str,
) -> str | None:
    """Atomically transfer stale work without replacing its branch or PR."""
    context = recovery_context(
        api,
        issue_number,
        actor,
        association,
        reservation_id,
    )
    if context is None:
        return None

    current, issue, branch = context
    previous_owner = str(current["owner"])
    pull_number, previous_body = recovery_pull_context(
        api,
        issue_number,
        branch,
        reservation_id,
    )
    collisions = recovery_collision_errors(api, branch, pull_number)
    if collisions:
        raise CoordinationError(
            f"Cannot recover Issue #{issue_number}: " + "; ".join(collisions) + "."
        )

    previous_assignees = assignee_logins(issue)
    state = RecoveryMutationState(
        actor_was_assigned=actor in previous_assignees,
        previous_owner_was_assigned=previous_owner in previous_assignees,
    )
    new_id = new_reservation_id()

    try:
        api.assign_strict(issue_number, actor)
        state.actor_assigned = True

        if pull_number is not None and previous_body is not None:
            api.update_pull_body(
                pull_number,
                replace_pr_reservation(previous_body, new_id),
            )
            state.pr_updated = True

        if previous_owner != actor and state.previous_owner_was_assigned:
            api.unassign_strict(issue_number, previous_owner)
            state.previous_owner_unassigned = True

        verify_recovery_assignees(
            api,
            issue_number,
            actor,
            previous_owner,
        )

        api.comment(
            issue_number,
            reservation_marker(actor, new_id, branch, True, "recover"),
        )
        state.marker_published = True

        verified = active_reservation(api, issue_number)
        if (
            not verified
            or verified["owner"] != actor
            or verified["reservation_id"] != new_id
        ):
            raise CoordinationError(
                f"Recovery authority verification failed for Issue #{issue_number}."
            )
    except Exception as exc:
        rollback_failures = rollback_recovery(
            api,
            issue_number,
            actor,
            previous_owner,
            reservation_id,
            branch,
            pull_number,
            previous_body,
            state,
        )
        if rollback_failures:
            details = "; ".join(rollback_failures)
            raise CoordinationError(
                f"Recovery failed for Issue #{issue_number} and rollback was incomplete: "
                f"{details}"
            ) from exc
        raise

    print(
        f"Reservation recovered: Issue #{issue_number} keeps {branch}; "
        f"@{previous_owner} -> @{actor} ({new_id})"
    )
    return new_id


def release_permission(
    api: GitHub,
    issue_number: int,
    actor: str,
    association: str,
    reservation_id: str | None,
    force: bool,
) -> tuple[dict[str, Any] | None, bool]:
    """BRVTAL work-coordination helper."""
    if not authorized(association):
        raise CoordinationError(
            f"@{actor} is not authorized to release work."
        )

    current = active_reservation(api, issue_number)
    if force:
        repo_owner = api.repo.split("/", 1)[0]
        if actor != repo_owner:
            raise CoordinationError(
                f"Only @{repo_owner} may execute /force-release."
            )
        return current, True

    if not current:
        return None, False

    same_session = (
        current["owner"] == actor
        and reservation_id is not None
        and current["reservation_id"] == reservation_id.lower()
    )
    if not same_session:
        return current, False
    return current, True


def close_pulls_before_release(
    api: GitHub,
    branch: str,
    force: bool,
) -> bool:
    """BRVTAL work-coordination helper."""
    open_pulls = open_pulls_for_branch(api, branch)
    if open_pulls and not force:
        return False
    for pr_number in open_pulls:
        api.close_pull(pr_number)
    return True


def expose_issue_after_release(api: GitHub, issue_number: int) -> None:
    """BRVTAL work-coordination helper."""
    issue = api.issue(issue_number)
    if (
        issue.get("state") == "open"
        and STATUS_BLOCKED not in label_names(issue)
    ):
        api.set_status(issue_number, STATUS_AVAILABLE)


def release_work(
    api: GitHub,
    issue_number: int,
    actor: str,
    association: str,
    reservation_id: str | None,
    force: bool,
) -> None:
    """BRVTAL work-coordination helper."""
    current, allowed = release_permission(
        api,
        issue_number,
        actor,
        association,
        reservation_id,
        force,
    )
    if not allowed:
        return

    branch = (
        str(current["branch"])
        if current
        else f"work/issue-{issue_number}"
    )
    if not close_pulls_before_release(
        api,
        branch,
        force,
    ):
        return

    owner = str(current["owner"]) if current else actor
    session = (
        str(current["reservation_id"])
        if current
        else new_reservation_id()
    )
    api.delete_branch(branch)
    api.comment(
        issue_number,
        reservation_marker(
            owner,
            session,
            branch,
            False,
            "force-release" if force else "release",
        ),
    )
    expose_issue_after_release(api, issue_number)
    if current:
        api.try_unassign(issue_number, owner)
    print(f"Reservation released: Issue #{issue_number}")


def sync_review_status(
    api: GitHub,
    issue_number: int,
    pull: dict[str, Any],
    action: str,
    current: dict[str, Any] | None,
) -> bool:
    """BRVTAL work-coordination helper."""
    review_actions = {"opened", "ready_for_review", "converted_to_draft"}
    if action not in review_actions:
        return False

    issue = api.issue(issue_number)
    if not current or issue.get("state") != "open":
        return True
    if action == "opened" and pull.get("draft"):
        return True

    target = (
        STATUS_RESERVED
        if action == "converted_to_draft"
        else STATUS_REVIEW
    )
    api.set_status(issue_number, target)
    return True


def close_pr_reservation(
    api: GitHub,
    issue_number: int,
    branch: str,
    pull: dict[str, Any],
    current: dict[str, Any] | None,
) -> None:
    """BRVTAL work-coordination helper."""
    api.delete_branch(branch)
    merged = bool(pull.get("merged"))

    if current:
        api.comment(
            issue_number,
            reservation_marker(
                str(current["owner"]),
                str(current["reservation_id"]),
                branch,
                False,
                "pr-merged" if merged else "pr-closed-unmerged",
            ),
        )
        api.try_unassign(issue_number, str(current["owner"]))

    issue = api.issue(issue_number)
    if (
        issue.get("state") == "closed"
        and issue.get("state_reason") == "not_planned"
    ):
        return
    if merged or issue.get("state") != "open":
        api.set_status(issue_number, STATUS_COMPLETED)
    elif STATUS_BLOCKED not in label_names(issue):
        api.set_status(issue_number, STATUS_AVAILABLE)


def update_pr_state(api: GitHub, pr_number: int, action: str) -> None:
    """BRVTAL work-coordination helper."""
    pull = api.pull(pr_number)
    head = pull.get("head")
    branch = str(head.get("ref") or "") if isinstance(head, dict) else ""
    issue_number = issue_from_branch(branch)
    if issue_number is None:
        return

    current = active_reservation(api, issue_number)
    if sync_review_status(api, issue_number, pull, action, current):
        return
    if action != "closed":
        return
    close_pr_reservation(
        api,
        issue_number,
        branch,
        pull,
        current,
    )

def update_issue_label_state(
    api: GitHub,
    issue_number: int,
    actor: str,
    label: str,
) -> None:
    """Synchronize the visible reserved label without creating authority."""
    if actor == TRUSTED_MARKER_LOGIN or label != STATUS_RESERVED:
        return

    branch = f"work/issue-{issue_number}"
    reservation = active_reservation(api, issue_number)
    if (
        reservation is not None
        and reservation.get("branch") == branch
        and api.branch_sha(branch) is not None
    ):
        api.set_status(issue_number, STATUS_RESERVED)
        return

    # A human-applied label is not an authorization primitive. Only explicit
    # coordination commands may create/rotate a trusted marker, branch or owner.
    labels = label_names(api.issue(issue_number))
    api.set_status(
        issue_number,
        STATUS_BLOCKED if STATUS_BLOCKED in labels else STATUS_AVAILABLE,
    )

def update_issue_state(api: GitHub, issue_number: int, action: str) -> None:
    """BRVTAL work-coordination helper."""
    issue = api.issue(issue_number)
    branch = f"work/issue-{issue_number}"
    current = active_reservation(api, issue_number)

    if action in {"opened", "reopened"}:
        if current and api.branch_sha(branch):
            api.set_status(issue_number, STATUS_RESERVED)
        elif STATUS_BLOCKED not in label_names(issue):
            api.set_status(issue_number, STATUS_AVAILABLE)
        return
    if action != "closed":
        return

    for pr_number in open_pulls_for_branch(api, branch):
        api.close_pull(pr_number)
    api.delete_branch(branch)

    if current:
        api.comment(
            issue_number,
            reservation_marker(
                str(current["owner"]),
                str(current["reservation_id"]),
                branch,
                False,
                "issue-closed",
            ),
        )
        api.try_unassign(issue_number, str(current["owner"]))

    state_reason = issue.get("state_reason")
    status = STATUS_CANCELLED if state_reason == "not_planned" else STATUS_COMPLETED
    api.set_status(issue_number, status)



def reservation_validation_errors(
    api: GitHub,
    issue: dict[str, Any],
    issue_number: int,
    branch: str,
    body: str,
) -> list[str]:
    """BRVTAL work-coordination helper."""
    errors: list[str] = []
    labels = label_names(issue)
    if not ({STATUS_RESERVED, STATUS_REVIEW} & labels):
        errors.append(
            f"Issue #{issue_number} does not have a visible active reservation."
        )
    if api.branch_sha(branch) is None:
        errors.append(f"Reserved branch {branch} does not exist.")

    reservation = active_reservation(api, issue_number)
    if not reservation:
        errors.append(
            f"Issue #{issue_number} does not have a trusted active reservation marker."
        )
        return errors

    if reservation["branch"] != branch:
        errors.append(
            f"Reservation marker points to {reservation['branch']}, not {branch}."
        )
    if reservation_from_pr_body(body) != reservation["reservation_id"]:
        errors.append(
            "The PR must declare the active session through hidden reservation metadata."
        )
    return errors


def issue_contract_errors(
    api: GitHub,
    issue_number: int,
    branch: str,
    body: str,
    require_reservation: bool,
) -> list[str]:
    """BRVTAL work-coordination helper."""
    errors: list[str] = []
    if issue_number not in closing_issues(body):
        errors.append(
            f"The PR must include Closes #{issue_number} (or Fixes/Resolves) in the body."
        )

    issue = api.issue(issue_number)
    if issue.get("state") != "open":
        errors.append(f"Issue #{issue_number} must remain open while the PR is open.")
    if require_reservation:
        errors.extend(
            reservation_validation_errors(
                api,
                issue,
                issue_number,
                branch,
                body,
            )
        )
    return errors


def collision_validation_errors(
    api: GitHub,
    pr_number: int,
) -> list[str]:
    """BRVTAL work-coordination helper."""
    current_files = api.pull_files(pr_number)
    others: dict[int, set[str]] = {}
    for other in api.open_pulls():
        other_number = other.get("number")
        if not isinstance(other_number, int) or other_number == pr_number:
            continue
        other_base = other.get("base")
        if isinstance(other_base, dict) and other_base.get("ref") != "main":
            continue
        others[other_number] = api.pull_files(other_number)

    errors: list[str] = []
    for other_pr, files in file_overlaps(current_files, others).items():
        rendered = ", ".join(files)
        errors.append(
            f"Collision with PR #{other_pr}: both modify {rendered}."
        )
    return errors


def validate_pull(
    api: GitHub,
    pr_number: int,
    require_reservation: bool,
) -> None:
    """BRVTAL work-coordination helper."""
    pull = api.pull(pr_number)
    base = pull.get("base")
    head = pull.get("head")
    branch = str(head.get("ref") or "") if isinstance(head, dict) else ""
    issue_number = issue_from_branch(branch)
    errors: list[str] = []

    if not isinstance(base, dict) or base.get("ref") != "main":
        errors.append("The PR base branch must be main.")

    if issue_number is None:
        errors.append("The PR branch must use canonical format work/issue-N.")
    else:
        body = str(pull.get("body") or "")
        errors.extend(
            issue_contract_errors(
                api,
                issue_number,
                branch,
                body,
                require_reservation,
            )
        )

    changed_files = api.pull_files(pr_number)
    if "config/version.php" in changed_files and not VERSION_TITLE_RE.search(str(pull.get("title") or "")):
        errors.append("Deploy-bound PR titles must end with (vX.Y.Z).")

    errors.extend(collision_validation_errors(api, pr_number))
    if errors:
        raise CoordinationError("\n".join(f"- {error}" for error in errors))

    mode = "reservation required" if require_reservation else "bootstrap"
    print(
        f"Coordination valid for PR #{pr_number} "
        f"({mode}); no blocking file overlap with other open PRs."
    )

def inactive_recovery_candidate(
    api: GitHub,
    issue: dict[str, Any],
) -> tuple[datetime, int, str] | None:
    """Return sortable stale reservation metadata when an Issue is recoverable."""
    number = issue.get("number")
    if not isinstance(number, int) or issue.get("state") != "open":
        return None
    labels = label_names(issue)
    if STATUS_BLOCKED in labels or not ({STATUS_RESERVED, STATUS_REVIEW} & labels):
        return None

    comments = api.issue_comments(number)
    record = latest_reservation_record(comments)
    if record is None:
        return None
    reservation, started_at = record
    branch = f"work/issue-{number}"
    if (
        not reservation["active"]
        or reservation["branch"] != branch
        or started_at is None
        or api.branch_sha(branch) is None
    ):
        return None
    try:
        inactive = reservation_is_inactive(
            api,
            number,
            branch,
            comments,
            started_at,
        )
    except CoordinationError:
        return None
    if not inactive:
        return None
    return started_at, number, str(reservation["reservation_id"])


def active_reservation_records(
    api: GitHub,
) -> list[tuple[int, dict[str, Any], datetime | None, list[dict[str, Any]]]]:
    """Return every trusted active reservation on an open Issue."""
    records: list[
        tuple[int, dict[str, Any], datetime | None, list[dict[str, Any]]]
    ] = []
    for issue in api.open_issues():
        number = issue.get("number")
        if not isinstance(number, int):
            continue
        comments = api.issue_comments(number)
        record = latest_reservation_record(comments)
        if record is None:
            continue
        reservation, started_at = record
        if reservation["active"]:
            records.append((number, reservation, started_at, comments))
    return records


def recover_oldest_inactive_before_take(
    api: GitHub,
    actor: str,
    association: str,
) -> str:
    """Recover one stale line, block on owned work, or permit a new reservation."""
    active = active_reservation_records(api)
    if not active:
        return TAKE_NONE

    stale: list[tuple[datetime, int, str]] = []
    for issue_number, reservation, started_at, comments in active:
        issue = api.issue(issue_number)
        branch = f"work/issue-{issue_number}"
        labels = label_names(issue)

        if (
            reservation["branch"] != branch
            or not ({STATUS_RESERVED, STATUS_REVIEW} & labels)
            or api.branch_sha(branch) is None
        ):
            print(
                f"Active reservation on Issue #{issue_number} is inconsistent; "
                "new work is blocked.",
                file=sys.stderr,
            )
            return TAKE_BLOCKED

        try:
            inactive = reservation_is_inactive(
                api,
                issue_number,
                branch,
                comments,
                started_at,
            )
        except CoordinationError as exc:
            print(
                f"Cannot prove reservation state for Issue #{issue_number}: {exc}",
                file=sys.stderr,
            )
            return TAKE_BLOCKED

        if not inactive:
            print(
                f"Active reservation on Issue #{issue_number} blocks new work.",
                file=sys.stderr,
            )
            return TAKE_BLOCKED

        assert started_at is not None
        stale.append(
            (started_at, issue_number, str(reservation["reservation_id"]))
        )

    if len(stale) > 1:
        rendered = ", ".join(f"#{number}" for _started, number, _rid in sorted(stale))
        print(
            f"Multiple stale active reservations exist ({rendered}); "
            "new work is blocked until authority is reconciled.",
            file=sys.stderr,
        )
        return TAKE_BLOCKED

    started_at, issue_number, reservation_id = stale[0]
    _ = started_at
    try:
        recovered = recover_work(
            api,
            issue_number,
            actor,
            association,
            reservation_id,
        )
    except CoordinationError as exc:
        print(
            f"Cannot safely recover inactive Issue #{issue_number}: {exc}",
            file=sys.stderr,
        )
        return TAKE_BLOCKED

    if recovered is None:
        print(
            f"Inactive Issue #{issue_number} still owns work but could not be recovered.",
            file=sys.stderr,
        )
        return TAKE_BLOCKED

    print(
        f"Recovery-first selected inactive Issue #{issue_number} "
        "before reserving new work."
    )
    return TAKE_RECOVERED

def parse_comment_command(body: str) -> tuple[str, str | None]:
    """BRVTAL work-coordination helper."""
    value = body.strip()
    if value == "/take":
        return "take", None
    if value == "/force-release":
        return "force-release", None

    for prefix, command in (
        ("/release ", "release"),
        ("/transfer ", "transfer"),
        ("/recover ", "recover"),
    ):
        if value.startswith(prefix):
            session = value[len(prefix):].strip().lower()
            if not SESSION_RE.fullmatch(session):
                raise CoordinationError(
                    f"Command {command} requires a valid reservation UUID."
                )
            return command, session
    raise CoordinationError("Unknown work-coordination command.")


def process_comment(
    api: GitHub,
    issue_number: int,
    actor: str,
    association: str,
    body: str,
) -> None:
    """BRVTAL work-coordination helper."""
    command, reservation_id = parse_comment_command(body)
    if command == "take":
        take_result = recover_oldest_inactive_before_take(api, actor, association)
        if take_result == TAKE_NONE:
            reserve_work(api, issue_number, actor, association)
        elif take_result == TAKE_BLOCKED:
            raise CoordinationError(
                "Cannot reserve new work while another active or unsafe reservation exists."
            )
    elif command == "release":
        release_work(
            api,
            issue_number,
            actor,
            association,
            reservation_id,
            False,
        )
    elif command == "force-release":
        release_work(api, issue_number, actor, association, None, True)
    elif command == "transfer":
        assert reservation_id is not None
        transfer_work(
            api,
            issue_number,
            actor,
            association,
            reservation_id,
        )
    elif command == "recover":
        assert reservation_id is not None
        recover_work(
            api,
            issue_number,
            actor,
            association,
            reservation_id,
        )


def build_parser() -> argparse.ArgumentParser:
    """BRVTAL work-coordination helper."""
    parser = argparse.ArgumentParser(description="BRVTAL multi-agent work coordination")
    sub = parser.add_subparsers(dest="command", required=True)

    comment = sub.add_parser("comment")
    comment.add_argument("--repo", required=True)
    comment.add_argument("--issue", required=True, type=int)
    comment.add_argument("--actor", required=True)
    comment.add_argument("--association", required=True)
    comment.add_argument("--body", required=True)

    pr_event = sub.add_parser("pr-event")
    pr_event.add_argument("--repo", required=True)
    pr_event.add_argument("--pr", required=True, type=int)
    pr_event.add_argument("--action", required=True)

    issue_event = sub.add_parser("issue-event")
    issue_event.add_argument("--repo", required=True)
    issue_event.add_argument("--issue", required=True, type=int)
    issue_event.add_argument("--action", required=True)

    label_event = sub.add_parser("label-event")
    label_event.add_argument("--repo", required=True)
    label_event.add_argument("--issue", required=True, type=int)
    label_event.add_argument("--actor", required=True)
    label_event.add_argument("--label", required=True)

    validate = sub.add_parser("validate-pr")
    validate.add_argument("--repo", required=True)
    validate.add_argument("--pr", required=True, type=int)
    validate.add_argument("--require-reservation", action="store_true")

    return parser


def main() -> int:
    """BRVTAL work-coordination helper."""
    parser = build_parser()
    args = parser.parse_args()
    try:
        api = GitHub(args.repo)
        if args.command == "comment":
            process_comment(
                api,
                args.issue,
                args.actor,
                args.association,
                args.body,
            )
        elif args.command == "pr-event":
            update_pr_state(api, args.pr, args.action)
        elif args.command == "issue-event":
            update_issue_state(api, args.issue, args.action)
        elif args.command == "label-event":
            update_issue_label_state(api, args.issue, args.actor, args.label)
        elif args.command == "validate-pr":
            validate_pull(api, args.pr, args.require_reservation)
        else:
            parser.error("Unsupported command.")
    except (CoordinationError, GitHubError) as exc:
        print(f"::error::{exc}", file=sys.stderr)
        return 1
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
