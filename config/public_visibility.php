<?php
declare(strict_types=1);

/**
 * Canonical public visibility policy for Event lifecycle states.
 *
 * Keep this as the single source of truth for every public query that can
 * expose an Event directly or through a relationship.
 */
function brvtal_public_event_statuses(): array
{
    return [
        'active' => ['published', 'upcoming', 'tickets_available', 'last_tickets', 'sold_out'],
        'historical' => ['finished', 'archived', 'cancelled'],
    ];
}

function brvtal_public_visible_event_statuses(): array
{
    $groups = brvtal_public_event_statuses();
    return array_values(array_unique(array_merge($groups['active'], $groups['historical'])));
}

function brvtal_public_event_datetime(mixed $value): ?DateTimeImmutable
{
    $raw = trim((string)$value);
    if ($raw === '') return null;
    try {
        return new DateTimeImmutable($raw);
    } catch (Throwable) {
        return null;
    }
}

/**
 * Public presentation mode for the canonical Event URL.
 *
 * Historical lifecycle states are always records once visible. An Event whose
 * date has already passed also becomes a record even if editorial status has
 * not been advanced yet, so stale lifecycle bookkeeping cannot leave an old
 * night looking commercially active.
 */
function brvtal_public_event_is_historical(array $event, ?DateTimeImmutable $now = null): bool
{
    $status = strtolower(trim((string)($event['status'] ?? '')));
    if (in_array($status, brvtal_public_event_statuses()['historical'], true)) return true;

    $eventDate = brvtal_public_event_datetime($event['event_date'] ?? null);
    if ($eventDate === null) return false;

    $now ??= new DateTimeImmutable('now');
    return $eventDate < $now->setTime(0, 0, 0);
}

/**
 * Decide whether one Event may be exposed on any public surface.
 *
 * Active lifecycle states are public. Historical states are public when the
 * event date is already in the past, or when published_at proves the Event
 * was intentionally public before it moved into a historical state. This
 * keeps future/undated historical drafts from leaking through direct routes,
 * relationships or the sitemap while preserving old records that predate
 * lifecycle timestamps.
 */
function brvtal_public_event_is_visible(array $event, ?DateTimeImmutable $now = null): bool
{
    $status = strtolower(trim((string)($event['status'] ?? '')));
    if (!in_array($status, brvtal_public_visible_event_statuses(), true)) return false;

    $historical = brvtal_public_event_statuses()['historical'];
    if (!in_array($status, $historical, true)) return true;

    $now ??= new DateTimeImmutable('now');
    $today = $now->setTime(0, 0, 0);
    $eventDate = brvtal_public_event_datetime($event['event_date'] ?? null);
    $publishedAt = brvtal_public_event_datetime($event['published_at'] ?? null);
    $pastByDate = $eventDate !== null && $eventDate < $today;

    return $pastByDate || $publishedAt !== null;
}

/**
 * Pages are publicly deliverable only when they are published in the single
 * locale currently supported by the BRVTAL public frontend.
 */
function brvtal_public_page_is_visible(array $page): bool
{
    $status = strtolower(trim((string)($page['status'] ?? '')));
    $locale = strtolower(trim((string)($page['locale'] ?? '')));
    return $status === 'published' && $locale === 'en';
}

/**
 * Commercial ticket actions are public only while the Event remains in an
 * active lifecycle state and has not passed its event date. Historical or
 * past Events remain discoverable when allowed above, but never keep stale
 * purchase CTAs merely because ticket data still exists in the database.
 */
function brvtal_public_event_allows_ticketing(array $event, ?DateTimeImmutable $now = null): bool
{
    $status = strtolower(trim((string)($event['status'] ?? '')));
    if (!in_array($status, brvtal_public_event_statuses()['active'], true)) return false;
    if (!brvtal_public_event_is_visible($event, $now)) return false;

    $eventDate = brvtal_public_event_datetime($event['event_date'] ?? null);
    if ($eventDate === null) return true;

    $now ??= new DateTimeImmutable('now');
    return $eventDate >= $now->setTime(0, 0, 0);
}

/**
 * Ticket Types are public only while their own lifecycle and optional
 * availability window are valid. Window boundaries are inclusive. A malformed
 * non-empty boundary fails closed so broken configuration cannot expose a
 * commercial offer unexpectedly.
 */
function brvtal_public_ticket_type_is_available(array $ticket, ?DateTimeImmutable $now = null): bool
{
    $status = strtolower(trim((string)($ticket['status'] ?? '')));
    if (!in_array($status, ['active', 'sold_out'], true)) return false;

    $now ??= new DateTimeImmutable('now');

    $fromRaw = trim((string)($ticket['available_from'] ?? ''));
    if ($fromRaw !== '') {
        $availableFrom = brvtal_public_event_datetime($fromRaw);
        if ($availableFrom === null || $availableFrom > $now) return false;
    }

    $untilRaw = trim((string)($ticket['available_until'] ?? ''));
    if ($untilRaw !== '') {
        $availableUntil = brvtal_public_event_datetime($untilRaw);
        if ($availableUntil === null || $availableUntil < $now) return false;
    }

    return true;
}

function brvtal_public_sql_placeholders(array $values): string
{
    if ($values === []) {
        throw new InvalidArgumentException('PUBLIC_SQL_VALUES_REQUIRED');
    }

    return implode(',', array_fill(0, count($values), '?'));
}
