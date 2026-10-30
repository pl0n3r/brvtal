<?php
declare(strict_types=1);

/** Read-only local quota observations. Provider balances not measured are UNKNOWN. */
function brvtalFreeQuotaEnvironment(string $key, ?array $sources): ?string
{
    if ($sources !== null) {
        $value = $sources[$key] ?? null;
        return is_string($value) ? $value : null;
    }
    $value = getenv($key);
    return $value === false ? null : $value;
}

function brvtalFreeQuotaInteger(?string $raw): ?int
{
    return is_string($raw)
        && preg_match('/^(0|[1-9][0-9]{0,12})$/D', $raw) === 1
        && (float)$raw <= PHP_INT_MAX ? (int)$raw : null;
}

function brvtalFreeQuotaTimestamp(?string $raw, int $now): ?int
{
    if (!is_string($raw)
        || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:Z|[+-]\d{2}:\d{2})$/D', $raw) !== 1
    ) {
        return null;
    }
    $value = strtotime($raw);
    return is_int($value) && $value <= $now && $value >= 0 ? $value : null;
}

function brvtalFreeQuotaRow(
    string $id,
    string $label,
    string $unit,
    ?int $used,
    ?int $limit,
    ?int $measured,
    string $source,
    int $now,
    DateTimeZone $zone,
    int $threshold
): array {
    $known = $used !== null && $used >= 0 && $limit !== null && $limit > 0
        && $measured !== null && $measured >= 0 && $measured <= $now;
    $percent = $known ? round(100 * $used / $limit, 1) : null;
    $state = !$known ? 'UNKNOWN' : ($used >= $limit ? 'EXHAUSTED' : (
        $percent >= $threshold ? 'WARNING' : 'OK'
    ));
    return [
        'id' => $id,
        'label' => $label,
        'unit' => $unit,
        'used' => $known ? $used : null,
        'limit' => $known ? $limit : null,
        'percent' => $percent,
        'status' => $state,
        'measured_at' => $known
            ? (new DateTimeImmutable('@' . $measured))->setTimezone($zone)->format('g:i A, d/m/Y')
            : 'UNKNOWN',
        'source' => $known ? $source : 'UNKNOWN',
    ];
}

/**
 * @param array<string,string>|null $sources In-memory operator readings for synthetic tests.
 * @return array<string,mixed>
 */
function brvtalFreeQuotaSnapshot(
    ?array $sources = null,
    ?string $deeplLedger = null,
    ?int $nowOverride = null
): array {
    $now = $nowOverride ?? time();
    $zone = new DateTimeZone('America/Bogota');
    $thresholdRaw = brvtalFreeQuotaEnvironment('BRVTAL_FREE_QUOTA_WARNING_PERCENT', $sources);
    $threshold = is_string($thresholdRaw) && preg_match('/^[1-9][0-9]?$/D', $thresholdRaw)
        ? (int)$thresholdRaw : 80;
    if ($threshold > 99) {
        $threshold = 80;
    }

    $deeplLimit = brvtalFreeQuotaInteger(brvtalFreeQuotaEnvironment('BRVTAL_DEEPL_MONTHLY_CHARS', $sources));
    if ($deeplLimit === null || $deeplLimit < 1 || $deeplLimit > 9999999) {
        $deeplLimit = 300000;
    }
    $deeplFile = $deeplLedger ?? dirname(__DIR__) . '/storage/deepl-usage.json';
    $deeplUsed = null;
    $deeplMeasured = null;
    if (is_file($deeplFile) && is_readable($deeplFile)) {
        $raw = @file_get_contents($deeplFile);
        $record = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($record) && array_keys($record) === ['month', 'chars']
            && ($record['month'] ?? null) === gmdate('Y-m', $now)
            && is_int($record['chars']) && $record['chars'] >= 0
        ) {
            $mtime = @filemtime($deeplFile);
            if (is_int($mtime) && $mtime <= $now) {
                $deeplUsed = $record['chars'];
                $deeplMeasured = $mtime;
            }
        }
    }
    $rows = [brvtalFreeQuotaRow(
        'deepl', 'DeepL Free', 'caracteres / mes', $deeplUsed,
        $deeplLimit, $deeplMeasured, 'CONTADOR LOCAL', $now, $zone, $threshold
    )];
    foreach ([
        ['mail', 'Correo gratuito', 'mensajes'],
        ['backups', 'Copias gratuitas', 'copias'],
        ['hosting', 'Hosting', 'bytes'],
    ] as [$id, $label, $unit]) {
        $prefix = 'BRVTAL_FREE_' . strtoupper($id) . '_';
        $rows[] = brvtalFreeQuotaRow(
            $id, $label, $unit,
            brvtalFreeQuotaInteger(brvtalFreeQuotaEnvironment($prefix . 'USED', $sources)),
            brvtalFreeQuotaInteger(brvtalFreeQuotaEnvironment($prefix . 'LIMIT', $sources)),
            brvtalFreeQuotaTimestamp(brvtalFreeQuotaEnvironment($prefix . 'MEASURED_AT', $sources), $now),
            'MEDICIÓN CONFIGURADA', $now, $zone, $threshold
        );
    }
    $warnings = [];
    foreach ($rows as $row) {
        if (in_array($row['status'], ['WARNING', 'EXHAUSTED'], true)) {
            $warnings[] = $row['id'];
        }
    }
    return [
        'warning_threshold_percent' => $threshold,
        'generated_at' => (new DateTimeImmutable('@' . $now))->setTimezone($zone)->format('g:i A, d/m/Y'),
        'timezone' => 'America/Bogota',
        'rows' => $rows,
        'warnings' => $warnings,
    ];
}
