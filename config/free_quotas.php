<?php
declare(strict_types=1);

/**
 * Read-only free-tier observations. Unknown sources remain UNKNOWN.
 * The existing DeepL transport owns fail-closed budget enforcement.
 */
function brvtalFreeQuotaSnapshot(
    ?callable $env = null,
    ?string $deeplLedger = null,
    ?callable $clock = null
): array {
    $env ??= static function (string $key): ?string {
        $value = getenv($key);
        return $value === false ? null : $value;
    };
    $now = (int)($clock ?? time(...))();
    $zone = new DateTimeZone('America/Bogota');
    $thresholdRaw = $env('BRVTAL_FREE_QUOTA_WARNING_PERCENT');
    $threshold = is_string($thresholdRaw) && preg_match('/^[1-9][0-9]?$/D', $thresholdRaw)
        ? (int)$thresholdRaw : 80;
    if ($threshold > 99) $threshold = 80;

    $number = static function (?string $raw): ?int {
        return is_string($raw) && preg_match('/^(0|[1-9][0-9]{0,12})$/D', $raw)
            && (float)$raw <= PHP_INT_MAX ? (int)$raw : null;
    };
    $timestamp = static function (?string $raw) use ($now): ?int {
        if (!is_string($raw) || !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $raw)) return null;
        $value = strtotime($raw);
        return is_int($value) && $value <= $now && $value >= 0 ? $value : null;
    };
    $make = static function (string $id, string $label, string $unit, ?int $used, ?int $limit,
        ?int $measured, string $source) use ($now, $zone, $threshold): array {
        $known = $used !== null && $used >= 0 && $limit !== null && $limit > 0
            && $measured !== null && $measured >= 0 && $measured <= $now;
        $percent = $known ? round(100 * $used / $limit, 1) : null;
        $state = !$known ? 'UNKNOWN' : ($used >= $limit ? 'EXHAUSTED' : (
            $percent >= $threshold ? 'WARNING' : 'OK'
        ));
        return [
            'id' => $id, 'label' => $label, 'unit' => $unit,
            'used' => $known ? $used : null, 'limit' => $known ? $limit : null,
            'percent' => $percent, 'status' => $state,
            'measured_at' => $known ? (new DateTimeImmutable('@' . $measured))
                ->setTimezone($zone)->format('g:i A, d/m/Y') : 'UNKNOWN',
            'source' => $known ? $source : 'UNKNOWN',
        ];
    };

    $deeplLimit = $number($env('BRVTAL_DEEPL_MONTHLY_CHARS'));
    if ($deeplLimit === null || $deeplLimit < 1 || $deeplLimit > 9999999) $deeplLimit = 300000;
    $deeplFile = $deeplLedger ?? dirname(__DIR__) . '/storage/deepl-usage.json';
    $deeplUsed = null;
    $deeplMeasured = null;
    if (is_file($deeplFile) && is_readable($deeplFile)) {
        $raw = @file_get_contents($deeplFile);
        $record = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($record) && array_keys($record) === ['month', 'chars']
            && ($record['month'] ?? null) === gmdate('Y-m', $now)
            && is_int($record['chars']) && $record['chars'] >= 0) {
            $mtime = @filemtime($deeplFile);
            if (is_int($mtime) && $mtime <= $now) {
                $deeplUsed = $record['chars'];
                $deeplMeasured = $mtime;
            }
        }
    }

    $rows = [$make('deepl', 'DeepL Free', 'caracteres / mes', $deeplUsed,
        $deeplLimit, $deeplMeasured, 'CONTADOR LOCAL')];
    foreach ([
        ['mail', 'Correo gratuito', 'mensajes'],
        ['backups', 'Copias gratuitas', 'copias'],
        ['hosting', 'Hosting', 'bytes'],
    ] as [$id, $label, $unit]) {
        $prefix = 'BRVTAL_FREE_' . strtoupper($id) . '_';
        $rows[] = $make($id, $label, $unit,
            $number($env($prefix . 'USED')), $number($env($prefix . 'LIMIT')),
            $timestamp($env($prefix . 'MEASURED_AT')), 'MEDICIÓN CONFIGURADA');
    }
    return [
        'warning_threshold_percent' => $threshold,
        'generated_at' => (new DateTimeImmutable('@' . $now))->setTimezone($zone)
            ->format('g:i A, d/m/Y'),
        'timezone' => 'America/Bogota',
        'rows' => $rows,
        'warnings' => array_values(array_map(static fn(array $row): string => $row['id'],
            array_filter($rows, static fn(array $row): bool => in_array($row['status'], ['WARNING', 'EXHAUSTED'], true)))),
    ];
}
