<?php
declare(strict_types=1);

/**
 * Read one JSON-backed setting without exposing the generic settings table.
 * Invalid/missing JSON fails closed to an empty array so public delivery keeps
 * its existing static fallbacks.
 */
function brvtal_config_setting_json(PDO $pdo, string $key): array
{
    if (!preg_match('/^[a-zA-Z0-9_.-]{1,120}$/', $key)) return [];
    try {
        $statement = $pdo->prepare('SELECT setting_value,is_json FROM settings WHERE setting_key=? LIMIT 1');
        $statement->execute([$key]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)($row['is_json'] ?? 0) !== 1) return [];
        $decoded = json_decode((string)($row['setting_value'] ?? ''), true);
        return is_array($decoded) ? $decoded : [];
    } catch (Throwable) {
        return [];
    }
}

function brvtal_config_setting_text(PDO $pdo, string $key): string
{
    if (!preg_match('/^[a-zA-Z0-9_.-]{1,120}$/', $key)) return '';
    try {
        $statement = $pdo->prepare('SELECT setting_value,is_json FROM settings WHERE setting_key=? LIMIT 1');
        $statement->execute([$key]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);
        if (!$row || (int)($row['is_json'] ?? 0) === 1) return '';
        return trim((string)($row['setting_value'] ?? ''));
    } catch (Throwable) {
        return '';
    }
}
