<?php
declare(strict_types=1);

/**
 * Public event lifecycle rules for BRVTAL.
 *
 * Drafts are never public. Public lifecycle states remain visible while future/current,
 * and naturally move into the archive after their event date. Explicit historical
 * states are archived only when the date is already historical or publication evidence
 * exists, preventing an accidentally archived unpublished future event from leaking.
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

function brvtal_public_partition_events(array $events, ?DateTimeImmutable $now = null): array
{
    $now ??= new DateTimeImmutable('now');
    $today = $now->setTime(0, 0, 0);
    $groups = brvtal_public_event_statuses();
    $visible = array_flip(brvtal_public_visible_event_statuses());
    $historicalStatuses = array_flip($groups['historical']);

    $active = [];
    $archive = [];

    foreach ($events as $event) {
        if (!is_array($event)) continue;
        $status = strtolower(trim((string)($event['status'] ?? '')));
        if (!isset($visible[$status])) continue;

        $eventDate = null;
        $rawDate = trim((string)($event['event_date'] ?? ''));
        if ($rawDate !== '') {
            try { $eventDate = new DateTimeImmutable($rawDate); }
            catch (Throwable) { $eventDate = null; }
        }

        $publishedAt = null;
        $rawPublishedAt = trim((string)($event['published_at'] ?? ''));
        if ($rawPublishedAt !== '') {
            try { $publishedAt = new DateTimeImmutable($rawPublishedAt); }
            catch (Throwable) { $publishedAt = null; }
        }

        $pastByDate = $eventDate !== null && $eventDate < $today;
        $explicitHistorical = isset($historicalStatuses[$status]);

        // A future/undated historical state is not public unless it was previously
        // published. Past events remain discoverable even when older records lack
        // published_at metadata.
        if ($explicitHistorical && !$pastByDate && $publishedAt === null) {
            continue;
        }

        $isHistorical = $explicitHistorical || $pastByDate;

        if ($isHistorical) {
            if (empty($event['archive_year']) && $eventDate !== null) {
                $event['archive_year'] = (int)$eventDate->format('Y');
            } elseif (isset($event['archive_year']) && $event['archive_year'] !== null) {
                $event['archive_year'] = (int)$event['archive_year'];
            }

            // Historical records remain discoverable, but stale purchase/payment CTAs do not.
            $event['ticket_url'] = null;
            $event['ticket_instructions'] = null;
            $event['ticket_qr'] = null;
            $event['ticket_types'] = [];
            $archive[] = $event;
            continue;
        }

        $active[] = $event;
    }

    usort($active, static function(array $a, array $b): int {
        $ad = (string)($a['event_date'] ?? '9999-12-31 23:59:59');
        $bd = (string)($b['event_date'] ?? '9999-12-31 23:59:59');
        return [$ad, (int)($a['sort_order'] ?? 0), (int)($a['id'] ?? 0)]
            <=> [$bd, (int)($b['sort_order'] ?? 0), (int)($b['id'] ?? 0)];
    });

    usort($archive, static function(array $a, array $b): int {
        $ad = (string)($a['event_date'] ?? '0000-00-00 00:00:00');
        $bd = (string)($b['event_date'] ?? '0000-00-00 00:00:00');
        return [$bd, (int)($b['id'] ?? 0)] <=> [$ad, (int)($a['id'] ?? 0)];
    });

    $years = [];
    foreach ($archive as $event) {
        $year = (int)($event['archive_year'] ?? 0);
        if ($year > 0) $years[$year] = true;
    }
    $years = array_keys($years);
    rsort($years, SORT_NUMERIC);

    return ['active' => $active, 'archive' => $archive, 'years' => $years];
}
