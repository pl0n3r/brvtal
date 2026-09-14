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

function brvtal_public_sql_placeholders(array $values): string
{
    if ($values === []) {
        throw new InvalidArgumentException('PUBLIC_SQL_VALUES_REQUIRED');
    }

    return implode(',', array_fill(0, count($values), '?'));
}
