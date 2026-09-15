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

function brvtal_public_sql_placeholders(array $values): string
{
    if ($values === []) {
        throw new InvalidArgumentException('PUBLIC_SQL_VALUES_REQUIRED');
    }

    return implode(',', array_fill(0, count($values), '?'));
}
