<?php
declare(strict_types=1);

require_once __DIR__ . '/public_visibility.php';

/**
 * Apply server-owned lifecycle timestamps for an Event mutation.
 *
 * The caller provides the persisted row before the mutation (empty for create)
 * and the editable patch. Lifecycle timestamps are intentionally removed from
 * client control and derived from the status transition instead.
 */
function brvtal_event_lifecycle_patch(array $before, array $patch, ?DateTimeImmutable $now = null): array
{
    foreach (['published_at', 'cancelled_at', 'finished_at'] as $field) {
        unset($patch[$field]);
    }

    if (!array_key_exists('status', $patch)) {
        return $patch;
    }

    $nextStatus = strtolower(trim((string)$patch['status']));
    $previousStatus = strtolower(trim((string)($before['status'] ?? 'draft')));
    $groups = brvtal_public_event_statuses();
    $activeStatuses = $groups['active'];
    $historicalStatuses = $groups['historical'];
    $timestamp = ($now ?? new DateTimeImmutable('now'))->format('Y-m-d H:i:s');

    $publishedAt = trim((string)($before['published_at'] ?? ''));
    $wasPublic = in_array($previousStatus, $activeStatuses, true) || $publishedAt !== '';

    if (in_array($nextStatus, $activeStatuses, true) && $publishedAt === '') {
        $patch['published_at'] = $timestamp;
        $publishedAt = $timestamp;
    }

    // A transition from an active/public Event into history must retain proof
    // that it had already been public, even when legacy data missed published_at.
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
