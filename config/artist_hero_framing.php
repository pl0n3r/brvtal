<?php
declare(strict_types=1);

/** Per-artist, profile-only framing; Home cards keep using the original photo. */
function brvtalArtistHeroDefault(): array
{
    $crop = ['zoom' => 1.0, 'x' => 50.0, 'y' => 50.0];
    return ['desktop' => $crop, 'mobile' => $crop];
}

/** Reject unexpected keys and unbounded geometry before storing or rendering CSS. */
function brvtalArtistHeroValidate(mixed $value): ?array
{
    if (!is_array($value) || count($value) !== 2
        || !isset($value['desktop'], $value['mobile'])) {
        return null;
    }
    $result = [];
    foreach (['desktop', 'mobile'] as $viewport) {
        $crop = $value[$viewport] ?? null;
        if (!is_array($crop) || count($crop) !== 3) {
            return null;
        }
        $result[$viewport] = [];
        foreach (['zoom', 'x', 'y'] as $field) {
            $number = $crop[$field] ?? null;
            if (!is_int($number) && !is_float($number)) {
                return null;
            }
            $minimum = $field === 'zoom' ? 0.8 : 0.0;
            $maximum = $field === 'zoom' ? 2.2 : 100.0;
            if (!is_finite((float)$number) || $number < $minimum || $number > $maximum) {
                return null;
            }
            $result[$viewport][$field] = round((float)$number, 2);
        }
    }
    return $result;
}

function brvtalArtistHeroSettingKey(int $artistId): string
{
    return 'artist.hero.' . $artistId;
}

function brvtalArtistHeroDecode(mixed $value): array
{
    if (!is_string($value) || $value === '') {
        return brvtalArtistHeroDefault();
    }
    return brvtalArtistHeroValidate(json_decode($value, true)) ?? brvtalArtistHeroDefault();
}

function brvtalArtistHeroRead(PDO $pdo, int $artistId): array
{
    if ($artistId < 1) {
        return brvtalArtistHeroDefault();
    }
    $statement = $pdo->prepare('SELECT setting_value FROM settings WHERE setting_key=? AND is_json=1 LIMIT 1');
    $statement->execute([brvtalArtistHeroSettingKey($artistId)]);
    return brvtalArtistHeroDecode($statement->fetchColumn());
}

/** Use a single settings query for collection views and preserve the API's row shape. */
function brvtalArtistHeroEnrich(PDO $pdo, array $rows): array
{
    $single = array_key_exists('id', $rows);
    $items = $single ? [$rows] : $rows;
    $ids = array_values(array_unique(array_filter(array_map(
        static fn(array $row): int => (int)($row['id'] ?? 0),
        $items
    ), static fn(int $id): bool => $id > 0)));
    $indexed = [];
    if ($ids) {
        $keys = array_map('brvtalArtistHeroSettingKey', $ids);
        $marks = implode(',', array_fill(0, count($keys), '?'));
        $statement = $pdo->prepare(
            "SELECT setting_key,setting_value FROM settings WHERE is_json=1 AND setting_key IN ({$marks})"
        );
        $statement->execute($keys);
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $setting) {
            $indexed[$setting['setting_key']] = brvtalArtistHeroDecode($setting['setting_value']);
        }
    }
    foreach ($items as &$row) {
        $key = brvtalArtistHeroSettingKey((int)($row['id'] ?? 0));
        $row['hero_framing'] = $indexed[$key] ?? brvtalArtistHeroDefault();
    }
    unset($row);
    return $single ? ($items[0] ?? $rows) : $items;
}

function brvtalArtistHeroWrite(PDO $pdo, int $artistId, array $framing): void
{
    $key = brvtalArtistHeroSettingKey($artistId);
    if ($framing === brvtalArtistHeroDefault()) {
        $statement = $pdo->prepare('DELETE FROM settings WHERE setting_key=?');
        $statement->execute([$key]);
        return;
    }
    $sqlite = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite';
    $statement = $pdo->prepare(
        'INSERT INTO settings(setting_key,setting_value,is_json) VALUES(?,?,1) ' .
        ($sqlite
            ? 'ON CONFLICT(setting_key) DO UPDATE SET setting_value=excluded.setting_value,is_json=1'
            : 'ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value),is_json=1')
    );
    $statement->execute([$key, json_encode($framing, JSON_THROW_ON_ERROR)]);
}

/** Same safe numeric values are consumed by the editor preview and profile hero CSS. */
function brvtalArtistHeroCssVariables(array $framing): string
{
    $validated = brvtalArtistHeroValidate($framing) ?? brvtalArtistHeroDefault();
    $parts = [];
    foreach ($validated as $viewport => $crop) {
        $parts[] = '--artist-hero-' . $viewport . '-zoom:' . $crop['zoom'];
        $parts[] = '--artist-hero-' . $viewport . '-x:' . $crop['x'] . '%';
        $parts[] = '--artist-hero-' . $viewport . '-y:' . $crop['y'] . '%';
    }
    return implode(';', $parts);
}

/** Preserve source aspect ratio on Artist profiles instead of relying on a pre-cropped hero. */
function brvtalArtistHeroImageUrl(string $original, string $renderedUrl): string
{
    if ($original === '') {
        return $renderedUrl;
    }
    $url = html_entity_decode($renderedUrl, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $parts = parse_url($url);
    $base = isset($parts['scheme'], $parts['host'])
        ? $parts['scheme'] . '://' . $parts['host']
        : 'https://www.brvtal.com.co';
    return brvtal_public_absolute_url(brvtal_public_media_variant($original, 'display'), $base);
}
