<?php
declare(strict_types=1);

/**
 * #2089: read-only owner-declared incident policy, not channel activation.
 *
 * Mutating callers MUST enter a transaction and acquire the Event mutex
 * BEFORE Order/Attempt/entry locks. This function takes a locking read of
 * Event as a defensive prerequisite and then a current locking read of the
 * authoritative owner decision. No editorial status or refunds flag is
 * authority. "allowed" only means no incident decision: each channel must
 * still enforce its own sale/entry rules. "unknown" MUST fail closed.
 *
 * Never infer a refund, revoke an existing ticket, send mail or call Wompi.
 * The owner decides if/when an incident is subsequently resolved.
 *
 * @return array{state:'allowed'|'blocked'|'unknown',cause:?string}
 */
function brvtal_ticket_event_incident_policy(PDO $db, int $eventId): array
{
    $unknown = ['state'=>'unknown', 'cause'=>null];
    if ($eventId < 1) return $unknown;

    try {
        if (!$db->inTransaction()) return $unknown;
        $lock = $db->prepare('SELECT id FROM events WHERE id=? FOR UPDATE');
        if (!$lock || !$lock->execute([$eventId])) return $unknown;
        $found = $lock->fetchColumn();
        if ($lock->errorCode() !== '00000' || $found === false) return $unknown;

        // A current locking read prevents REPEATABLE READ snapshot drift.
        // The Event mutex serializes owner declaration with this decision.
        $read = $db->prepare('SELECT cause FROM ticket_refund_event_decisions
            WHERE event_id=? LIMIT 1 FOR UPDATE');
        if (!$read || !$read->execute([$eventId])) return $unknown;
        $row = $read->fetch(PDO::FETCH_ASSOC);
        if ($read->errorCode() !== '00000') return $unknown;
        if ($row === false) return ['state'=>'allowed', 'cause'=>null];
        $cause = $row['cause'] ?? null;
        if (!in_array($cause, ['cancelled','postponed'], true)) return $unknown;
        return ['state'=>'blocked', 'cause'=>$cause];
    } catch (PDOException) {
        // Missing additive migration, unavailable DB, lock timeout: UNKNOWN.
        // Do not leak credentials, SQL errors or any buyer/event details.
        return $unknown;
    }
}
