<?php
declare(strict_types=1);

/** Public display-only clock: keep native timezone and all stored/ISO datetimes unchanged. */
function brvtalPublicTimeFormat(DateTimeInterface|int $value): string
{
    return is_int($value) ? date('g:i a', $value) : $value->format('g:i a');
}

/** Public date stays d.m.Y; only the visitor-facing time changes to 12 hours. */
function brvtalPublicDateTimeFormat(DateTimeInterface|int $value): string
{
    $day = is_int($value) ? date('d.m.Y', $value) : $value->format('d.m.Y');
    return $day . ' / ' . brvtalPublicTimeFormat($value);
}

/** Timetable keeps its own event timezone and shows day prefixes when a slot crosses dates. */
function brvtalPublicTimeRange(DateTimeInterface $start, DateTimeInterface $end): string
{
    $sameDate = $start->format('Y-m-d') === $end->format('Y-m-d');
    $from = ($sameDate ? '' : $start->format('d.m ')) . brvtalPublicTimeFormat($start);
    $to = ($sameDate ? '' : $end->format('d.m ')) . brvtalPublicTimeFormat($end);
    return $from . '–' . $to;
}
