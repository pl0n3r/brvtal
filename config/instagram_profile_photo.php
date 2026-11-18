<?php
declare(strict_types=1);

/**
 * Official Meta Business Discovery only. This code never scrapes Instagram,
 * exposes tokens, or assumes personal profiles grant photo access.
 * No account is eligible unless the operator configures an explicit allowlist.
 */
function brvtalIgUsername(?string $url): ?string
{
    $url = trim((string)$url);
    if ($url === '') return null;
    $parts = parse_url($url);
    if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https') return null;
    $host = strtolower((string)($parts['host'] ?? ''));
    if (!in_array($host, ['instagram.com','www.instagram.com'], true)
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) return null;
    $path = trim((string)($parts['path'] ?? ''), '/');
    if (!preg_match('/^[A-Za-z0-9._]{1,30}$/D', $path)) return null;
    if (in_array(strtolower($path), ['p','reel','reels','stories','explore','accounts','direct'], true)) return null;
    return strtolower($path);
}

function brvtalIgConfiguredFor(string $username): bool
{
    if (!preg_match('/^[a-z0-9._]{1,30}$/D', $username)) return false;
    $id = (string)(getenv('BRVTAL_META_IG_BUSINESS_ID') ?: '');
    $token = (string)(getenv('BRVTAL_META_IG_ACCESS_TOKEN') ?: '');
    if ($id === '' || !ctype_digit($id) || $token === '') return false;
    $allowed = explode(',', (string)(getenv('BRVTAL_META_IG_ALLOWED_USERNAMES') ?: ''));
    return in_array($username, array_map(static fn($v) => strtolower(trim($v)), $allowed), true);
}

function brvtalIgPhotoEndpoint(?string $instagramUrl): ?string
{
    $name = brvtalIgUsername($instagramUrl);
    if ($name === null || !brvtalIgConfiguredFor($name)) return null;
    return '/api/instagram-profile-photo.php?username=' . rawurlencode($name);
}

function brvtalIgCachePaths(string $username): array
{
    // The parent of the web document root is not statically web-accessible.
    // Do not expose metadata/image bytes through /var/cache after allowlist removal.
    $root = dirname(__DIR__, 2) . '/brvtal-private/cache/instagram-profile-photos';
    $key = hash('sha256', $username);
    return [$root, $root . '/' . $key . '.json', $root . '/' . $key . '.image'];
}

function brvtalIgCacheRead(string $username): ?array
{
    [$root, $metaPath, $imagePath] = brvtalIgCachePaths($username);
    if (is_link($metaPath) || is_link($imagePath) || !is_file($metaPath) || !is_file($imagePath)) return null;
    $meta = json_decode((string)file_get_contents($metaPath), true);
    if (!is_array($meta) || !in_array($meta['mime'] ?? '', ['image/webp','image/jpeg','image/png'], true)
        || (int)($meta['width'] ?? 0) < 640 || (int)($meta['height'] ?? 0) < 640
        || (int)($meta['fetched_at'] ?? 0) <= 0
        || filesize($imagePath) <= 0 || filesize($imagePath) > 6291456) return null;
    return ['meta'=>$meta,'path'=>$imagePath];
}

function brvtalIgApprovedHost(string $host): bool
{
    if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) return false;
    return preg_match('/(?:^|\.)(?:fbcdn\.net|cdninstagram\.com)$/D', strtolower($host)) === 1;
}

function brvtalIgPinnedIp(string $host): ?string
{
    $records = dns_get_record($host, DNS_A);
    foreach ($records ?: [] as $record) {
        $ip = (string)($record['ip'] ?? '');
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return $ip;
        }
    }
    return null; // No arbitrary/private network fallback.
}

function brvtalIgCurl(string $url, array $headers = [], ?string $pin = null): ?array
{
    if (!function_exists('curl_init')) return null;
    $ch = curl_init($url);
    if ($ch === false) return null;
    $options = [
        CURLOPT_RETURNTRANSFER=>false, CURLOPT_FOLLOWLOCATION=>false,
        // Never use inherited CONNECT proxies: they can bypass CURLOPT_RESOLVE.
        CURLOPT_PROXY=>'', CURLOPT_NOPROXY=>'*',
        CURLOPT_CONNECTTIMEOUT=>3, CURLOPT_TIMEOUT=>7,
        CURLOPT_SSL_VERIFYPEER=>true, CURLOPT_SSL_VERIFYHOST=>2,
        CURLOPT_PROTOCOLS=>CURLPROTO_HTTPS, CURLOPT_REDIR_PROTOCOLS=>CURLPROTO_HTTPS,
        CURLOPT_USERAGENT=>'BRVTAL-Artist-Profile/1.0', CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_MAXREDIRS=>0,
    ];
    if ($pin !== null) {
        $host = (string)parse_url($url, PHP_URL_HOST);
        $options[CURLOPT_RESOLVE] = [$host . ':443:' . $pin];
    }
    // Stop oversized transfers before buffering them into PHP memory.
    $bytes = '';
    $options[CURLOPT_WRITEFUNCTION] = static function ($handle, string $chunk) use (&$bytes): int {
        if (strlen($bytes) + strlen($chunk) > 6291456) return 0;
        $bytes .= $chunk;
        return strlen($chunk);
    };
    curl_setopt_array($ch, $options);
    $received = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    $mime = strtolower(trim(explode(';', (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE))[0]));
    if ($received !== true || $status !== 200 || strlen($bytes) > 6291456) return null;
    return ['bytes'=>$bytes, 'mime'=>$mime];
}

function brvtalIgFetchOfficial(string $username): ?array
{
    if (!brvtalIgConfiguredFor($username)) return null;
    $id = (string)getenv('BRVTAL_META_IG_BUSINESS_ID');
    $token = (string)getenv('BRVTAL_META_IG_ACCESS_TOKEN');
    $version = (string)(getenv('BRVTAL_META_IG_GRAPH_VERSION') ?: 'v24.0');
    if (!preg_match('/^v[0-9]{1,2}\.[0-9]{1,2}$/D', $version)) return null;
    $fields = 'business_discovery.username(' . $username . '){username,profile_picture_url}';
    $graph = 'https://graph.facebook.com/' . $version . '/' . $id . '?fields=' . rawurlencode($fields);
    $response = brvtalIgCurl($graph, ['Authorization: Bearer ' . $token, 'Accept: application/json']);
    if ($response === null) return null;
    $data = json_decode($response['bytes'], true);
    $discovery = is_array($data) ? ($data['business_discovery'] ?? null) : null;
    if (!is_array($discovery) || strtolower((string)($discovery['username'] ?? '')) !== $username) return null;
    $url = (string)($discovery['profile_picture_url'] ?? '');
    $parts = parse_url($url);
    if (!is_array($parts) || ($parts['scheme'] ?? '') !== 'https'
        || isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) return null;
    $host = strtolower((string)($parts['host'] ?? ''));
    if (!brvtalIgApprovedHost($host)) return null;
    $ip = brvtalIgPinnedIp($host);
    if ($ip === null) return null;
    $photo = brvtalIgCurl($url, ['Accept: image/webp,image/jpeg,image/png'], $ip);
    if ($photo === null || !in_array($photo['mime'], ['image/webp','image/jpeg','image/png'], true)) return null;
    $info = @getimagesizefromstring($photo['bytes']);
    if (!is_array($info) || (int)$info[0] < 640 || (int)$info[1] < 640
        || (int)$info[0] * (int)$info[1] > 40000000
        || ($info['mime'] ?? '') !== $photo['mime']) return null;
    return ['bytes'=>$photo['bytes'],'mime'=>$photo['mime'],'width'=>(int)$info[0],'height'=>(int)$info[1]];
}

function brvtalIgCacheRefresh(string $username): ?array
{
    if (!brvtalIgConfiguredFor($username)) return null;
    $cached = brvtalIgCacheRead($username);
    if ($cached !== null && time() - (int)$cached['meta']['fetched_at'] < 86400) return $cached;

    [$root, $metaPath, $imagePath] = brvtalIgCachePaths($username);
    if (is_link($root) || (!is_dir($root) && !@mkdir($root, 0750, true))) return $cached;

    // Nonblocking per-account lock: concurrent HTTP requests immediately reuse
    // the last approved photo instead of consuming PHP workers on Meta calls.
    $key = hash('sha256', $username);
    $lockPath = $root . '/' . $key . '.lock';
    $retryPath = $root . '/' . $key . '.retry';
    if (is_link($lockPath) || is_link($retryPath)) return $cached;
    $lock = @fopen($lockPath, 'c+');
    if ($lock === false) return $cached;
    if (!@flock($lock, LOCK_EX | LOCK_NB)) {
        fclose($lock);
        return $cached;
    }
    try {
        // Re-read while locked: another worker may have refreshed it.
        $cached = brvtalIgCacheRead($username) ?? $cached;
        if ($cached !== null && time() - (int)$cached['meta']['fetched_at'] < 86400) return $cached;
        $retryAt = is_file($retryPath) ? (int)trim((string)@file_get_contents($retryPath)) : 0;
        if ($retryAt > time()) return $cached;
        // Record a shared five-minute retry ceiling before hitting Meta. A crash
        // or transient outage cannot immediately trigger a worker stampede.
        if (@file_put_contents($retryPath, (string)(time() + 300), LOCK_EX) === false) return $cached;
        $photo = brvtalIgFetchOfficial($username);
        if ($photo === null) return $cached; // Preserve last approved image during outages.

        $meta = [
            'mime'=>$photo['mime'], 'width'=>$photo['width'], 'height'=>$photo['height'],
            'fetched_at'=>time(), 'sha256'=>hash('sha256', $photo['bytes']),
        ];
        $tmpImage = tempnam($root, 'ig-image-');
        $tmpMeta = tempnam($root, 'ig-meta-');
        if ($tmpImage === false || $tmpMeta === false) {
            if (is_string($tmpImage)) @unlink($tmpImage);
            if (is_string($tmpMeta)) @unlink($tmpMeta);
            return $cached;
        }
        try {
            if (file_put_contents($tmpImage, $photo['bytes']) === false
                || file_put_contents($tmpMeta, json_encode($meta, JSON_THROW_ON_ERROR)) === false
                || !rename($tmpImage, $imagePath) || !rename($tmpMeta, $metaPath)) return $cached;
            @chmod($imagePath, 0640);
            @chmod($metaPath, 0640);
        } finally {
            if (is_file($tmpImage)) @unlink($tmpImage);
            if (is_file($tmpMeta)) @unlink($tmpMeta);
        }
        @unlink($retryPath);
        return brvtalIgCacheRead($username) ?? $cached;
    } finally {
        @flock($lock, LOCK_UN);
        fclose($lock);
    }
}

function brvtalIgEnrichArtist(array $artist): array
{
    $artist['photo_cms'] = $artist['photo'] ?? null;
    $artist['instagram_photo_url'] = brvtalIgPhotoEndpoint((string)($artist['instagram_url'] ?? ''));
    if ($artist['instagram_photo_url'] !== null) {
        $username = brvtalIgUsername((string)$artist['instagram_url']);
        $cached = $username === null ? null : brvtalIgCacheRead($username);
        if ($cached !== null && min((int)$cached['meta']['width'], (int)$cached['meta']['height']) >= 1024) {
            // A proven large original can replace the profile hero; never a tiny avatar.
            $artist['photo'] = $artist['instagram_photo_url'];
        }
    }
    return $artist;
}
