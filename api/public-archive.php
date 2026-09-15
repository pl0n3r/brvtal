<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/public_visibility.php';

/**
 * Public event lifecycle partitioning for BRVTAL.
 *
 * Drafts are never public. Public lifecycle states remain visible while future/current,
 * and naturally move into the archive after their event date. Explicit historical
 * states are archived only when the date is already historical or publication evidence
 * exists, preventing an accidentally archived unpublished future event from leaking.
 */
function brvtal_public_partition_events(array $events, ?DateTimeImmutable $now = null): array
{
    $now ??= new DateTimeImmutable('now');
    $today = $now->setTime(0, 0, 0);
    $groups = brvtal_public_event_statuses();
    $historicalStatuses = array_flip($groups['historical']);

    $active = [];
    $archive = [];

    foreach ($events as $event) {
        if (!is_array($event) || !brvtal_public_event_is_visible($event, $now)) continue;
        $status = strtolower(trim((string)($event['status'] ?? '')));
        $eventDate = brvtal_public_event_datetime($event['event_date'] ?? null);
        $pastByDate = $eventDate !== null && $eventDate < $today;
        $explicitHistorical = isset($historicalStatuses[$status]);
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
