<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/public_robots.php';

/** Fail when the dynamic robots contract drifts. */
function public_robots_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "PUBLIC ROBOTS CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

public_robots_expect(
    brvtal_public_robots_host_is_canonical('www.brvtal.com.co'),
    'The canonical production host must be indexable.'
);
public_robots_expect(
    brvtal_public_robots_host_is_canonical('www.brvtal.com.co:443'),
    'The canonical host with the default HTTPS port must remain indexable.'
);
foreach ([
    'brvtal.com.co',
    'www.brvtal.com.co:80',
    'preview.brvtal.com.co',
    'www.brvtal.com.co.evil.test',
    '',
] as $host) {
    public_robots_expect(
        !brvtal_public_robots_host_is_canonical($host),
        "Non-canonical host {$host} must never inherit the production crawl policy."
    );
}

$production = brvtal_public_robots_response('www.brvtal.com.co');
public_robots_expect(
    $production['headers'] === [
        'Content-Type: text/plain; charset=utf-8',
        'Cache-Control: public, max-age=300, stale-while-revalidate=3600',
        'Vary: Host',
    ],
    'Production robots responses must be cacheable briefly and vary by Host.'
);
foreach ([
    'Allow: /',
    'Allow: /api/public.php',
    'Disallow: /discadmin/',
    'Disallow: /api/',
    'Disallow: /config/',
    'Disallow: /database/',
    'Disallow: /storage/',
    'Disallow: /.private/',
    'Disallow: /tests/',
    'Disallow: /scripts/',
    'Sitemap: https://www.brvtal.com.co/sitemap.xml',
] as $rule) {
    public_robots_expect(
        str_contains($production['body'], $rule),
        "Production robots policy must contain: {$rule}"
    );
}
public_robots_expect(
    !str_contains($production['body'], 'Disallow: /assets/')
        && !str_contains($production['body'], 'Disallow: /css/')
        && !str_contains($production['body'], 'Disallow: /js/')
        && !str_contains($production['body'], 'Disallow: /uploads/'),
    'Crawler-visible assets and public media must stay crawlable.'
);

$preview = brvtal_public_robots_response('preview.brvtal.com.co');
public_robots_expect(
    $preview['headers'] === [
        'Content-Type: text/plain; charset=utf-8',
        'Cache-Control: no-store',
        'Vary: Host',
    ],
    'Non-production robots responses must never be shared-cacheable.'
);
public_robots_expect(
    $preview['body'] === "User-agent: *\nDisallow: /\n",
    'Non-canonical hosts must block crawling entirely.'
);
public_robots_expect(
    !str_contains($preview['body'], 'Sitemap:'),
    'Non-canonical hosts must not advertise the production sitemap.'
);

$htaccess = (string) file_get_contents(__DIR__ . '/../.htaccess');
$canonicalRobotsRewrite = str_contains(
    $htaccess,
    'RewriteCond %{THE_REQUEST} \\s/+robots\\.php(?:[?\\s]) [NC]'
) && str_contains(
    $htaccess,
    'RewriteRule ^robots\\.php$ /robots.txt [R=301,L,NE]'
) && str_contains(
    $htaccess,
    'RewriteRule ^robots\\.txt$ robots.php [L,QSA]'
);
public_robots_expect(
    $canonicalRobotsRewrite,
    'Direct PHP robots requests must redirect to /robots.txt and render internally from robots.php.'
);
public_robots_expect(
    !is_file(__DIR__ . '/../robots.txt'),
    'The old static robots.txt must not shadow the automatic endpoint.'
);

echo "Public robots contract passed.\n";
