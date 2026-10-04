<?php
declare(strict_types=1);

require_once __DIR__ . '/public_visibility.php';

/**
 * Validate the virtual Event publication-schedule input before persistence.
 *
 * publish_at is never a database column and never grants direct control over
 * published_at. A future not-before boundary is allowed only while the final
 * lifecycle is active/public and only before the Event has already crossed a
 * public boundary.
 *
 * @return array{error:string,field:string}|null
 */
function brvtal_event_publish_at_error(
    array $before,
    array $patch,
    ?DateTimeImmutable $now = null
): ?array {
    if (!array_key_exists('publish_at', $patch)) return null;

    $raw = trim((string)($patch['publish_at'] ?? ''));
    if ($raw === '') return null;

    $clock = brvtal_public_event_policy_now($now);
    $requested = brvtal_public_event_datetime($raw);
    if ($requested === null) {
        return ['error' => 'INVALID_DATE', 'field' => 'publish_at'];
    }

    $nextStatus = strtolower(trim((string)($patch['status'] ?? $before['status'] ?? 'draft')));
    if (!in_array($nextStatus, brvtal_public_event_statuses()['active'], true)) {
        return ['error' => 'EVENT_PUBLISH_AT_REQUIRES_PUBLIC_STATUS', 'field' => 'publish_at'];
    }

    if ($requested <= $clock) {
        return ['error' => 'EVENT_PUBLISH_AT_MUST_BE_FUTURE', 'field' => 'publish_at'];
    }

    if ($before !== [] && brvtal_public_event_is_visible($before, $clock)) {
        return ['error' => 'EVENT_ALREADY_PUBLIC_CANNOT_SCHEDULE', 'field' => 'publish_at'];
    }

    return null;
}

/**
 * Apply server-owned lifecycle timestamps for an Event mutation.
 *
 * The caller provides the persisted row before the mutation (empty for create)
 * and the editable patch. Direct lifecycle timestamp writes are removed from
 * client control. The virtual publish_at input may request a future not-before
 * boundary; it is consumed here and persisted only as published_at.
 */
function brvtal_event_lifecycle_patch(array $before, array $patch, ?DateTimeImmutable $now = null): array
{
    foreach (['published_at', 'cancelled_at', 'finished_at'] as $field) {
        unset($patch[$field]);
    }

    $clock = brvtal_public_event_policy_now($now);
    $scheduleProvided = array_key_exists('publish_at', $patch);
    $scheduleRaw = trim((string)($patch['publish_at'] ?? ''));

    $scheduleError = brvtal_event_publish_at_error($before, $patch, $clock);
    if ($scheduleError !== null) {
        throw new InvalidArgumentException($scheduleError['error']);
    }
    unset($patch['publish_at']);

    $hasStatusMutation = array_key_exists('status', $patch);
    if (!$hasStatusMutation && !$scheduleProvided) {
        return $patch;
    }

    $nextStatus = strtolower(trim((string)($patch['status'] ?? $before['status'] ?? 'draft')));
    $previousStatus = strtolower(trim((string)($before['status'] ?? 'draft')));
    $groups = brvtal_public_event_statuses();
    $activeStatuses = $groups['active'];
    $historicalStatuses = $groups['historical'];
    $timestamp = $clock->format('Y-m-d H:i:s');

    $publishedAt = trim((string)($before['published_at'] ?? ''));
    $publishedDate = brvtal_public_event_datetime($publishedAt);
    $wasScheduled = $publishedDate !== null && $publishedDate > $clock;
    $wasPublic = $before !== [] && brvtal_public_event_is_visible($before, $clock);

    if ($scheduleProvided && $scheduleRaw !== '') {
        $requested = brvtal_public_event_datetime($scheduleRaw);
        if ($requested === null) {
            throw new InvalidArgumentException('INVALID_DATE');
        }
        $publishedAt = $requested->format('Y-m-d H:i:s');
        $patch['published_at'] = $publishedAt;
    } elseif ($scheduleProvided && $wasScheduled) {
        if (in_array($nextStatus, $activeStatuses, true)) {
            // Explicitly clearing a future schedule means publish now.
            $publishedAt = $timestamp;
            $patch['published_at'] = $publishedAt;
        } else {
            // Leaving the active lifecycle before the boundary cancels a
            // schedule that never became public.
            $publishedAt = '';
            $patch['published_at'] = null;
        }
    } elseif (!$scheduleProvided && $wasScheduled && !in_array($nextStatus, $activeStatuses, true)) {
        $publishedAt = '';
        $patch['published_at'] = null;
    }

    if (in_array($nextStatus, $activeStatuses, true) && $publishedAt === '') {
        $patch['published_at'] = $timestamp;
        $publishedAt = $timestamp;
    }

    // A transition from an actually public Event into history must retain proof
    // that it crossed the public boundary. Future schedules are not proof.
    if (in_array($nextStatus, $historicalStatuses, true) && $publishedAt === '' && $wasPublic) {
        $patch['published_at'] = $timestamp;
        $publishedAt = $timestamp;
    }

    if ($nextStatus === 'cancelled' && trim((string)($before['cancelled_at'] ?? '')) === '') {
        $patch['cancelled_at'] = $timestamp;
    }

    if ($nextStatus === 'finished' && trim((string)($before['finished_at'] ?? '')) === '') {
        $patch['finished_at'] = $timestamp;
    }

    return $patch;
}
