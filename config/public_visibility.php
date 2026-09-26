<?php
declare(strict_types=1);

/**
 * Canonical public visibility policy for Event lifecycle states.
 *
 * Keep this as the single source of truth for every public query that can
 * expose an Event directly or through a relationship.
 *
 * @return array{active:list<string>,historical:list<string>}
 */
// TEMPORARY RECTOR DIAGNOSTIC #653: discard probe marker before any merge.
function brvtal_public_event_statuses(): array
{
    return [
        'active' => ['published', 'upcoming', 'tickets_available', 'last_tickets', 'sold_out'],
        'historical' => ['finished', 'archived', 'cancelled'],
    ];
}

/**
 * Return every lifecycle status that is eligible for public evaluation.
 *
 * @return list<string>
 */
function brvtal_public_visible_event_statuses(): array
{
    $groups = brvtal_public_event_statuses();
    return array_values(array_unique(array_merge($groups['active'], $groups['historical'])));
}

/**
 * Parse one lifecycle timestamp without allowing malformed editorial data to throw.
 */
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
 * Resolve the clock used by public Event policy decisions.
 *
 * When callers do not inject a clock, the first value created in the request is
 * reused by lifecycle, archive and ticket checks. This prevents a request that
 * crosses midnight from classifying one Event with two different calendar days.
 */
function brvtal_public_event_policy_now(?DateTimeImmutable $now = null): DateTimeImmutable
{
    static $requestNow = null;
    if ($now !== null) return $now;
    if (!$requestNow instanceof DateTimeImmutable) {
        $requestNow = new DateTimeImmutable('now');
    }
    return $requestNow;
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

    $now = brvtal_public_event_policy_now($now);
    $today = $now->setTime(0, 0, 0);
    $eventDate = brvtal_public_event_datetime($event['event_date'] ?? null);
    $publishedAt = brvtal_public_event_datetime($event['published_at'] ?? null);
    $pastByDate = $eventDate !== null && $eventDate < $today;

    return $pastByDate || $publishedAt !== null;
}

/**
 * Canonical presentation/archive classification for a public Event.
 *
 * A public Event is historical when its explicit lifecycle is historical or
 * its date is already before today. This intentionally matches the public
 * Archive partition so canonical Event pages and archive discovery can never
 * disagree about whether the same night is an active experience or a record.
 */
function brvtal_public_event_is_historical(array $event, ?DateTimeImmutable $now = null): bool
{
    $now = brvtal_public_event_policy_now($now);
    if (!brvtal_public_event_is_visible($event, $now)) return false;

    $status = strtolower(trim((string)($event['status'] ?? '')));
    if (in_array($status, brvtal_public_event_statuses()['historical'], true)) return true;

    $eventDate = brvtal_public_event_datetime($event['event_date'] ?? null);
    return $eventDate !== null && $eventDate < $now->setTime(0, 0, 0);
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
    $now = brvtal_public_event_policy_now($now);
    $status = strtolower(trim((string)($event['status'] ?? '')));
    if (!in_array($status, brvtal_public_event_statuses()['active'], true)) return false;
    if (!brvtal_public_event_is_visible($event, $now)) return false;

    $eventDate = brvtal_public_event_datetime($event['event_date'] ?? null);
    if ($eventDate === null) return true;

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

    $now = brvtal_public_event_policy_now($now);

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

/**
 * Build a prepared-statement placeholder list for a non-empty value set.
 *
 * @param list<mixed> $values
 */
function brvtal_public_sql_placeholders(array $values): string
{
    if ($values === []) {
        throw new InvalidArgumentException('PUBLIC_SQL_VALUES_REQUIRED');
    }

    return implode(',', array_fill(0, count($values), '?'));
}
