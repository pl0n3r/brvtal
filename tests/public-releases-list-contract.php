<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/public_seo.php';
require_once __DIR__ . '/../config/public_i18n_routing.php';
require_once __DIR__ . '/../config/public_releases_listing.php';

function releases_index_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "RELEASES LIST CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

class BrvtalReleasesIndexStatement extends PDOStatement
{
    public function __construct(private readonly array $rows) {}
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
}
class BrvtalReleasesIndexDb extends PDO
{
    public function __construct(private readonly array $rows, private readonly bool $fail = false) {}
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        releases_index_expect(str_contains($query, "FROM releases WHERE status='published'"), 'catalog must query published releases only');
        releases_index_expect(str_contains($query, 'LIMIT 96'), 'catalog must have a bound');
        if ($this->fail) throw new PDOException('Simulated unavailable DB');
        return new BrvtalReleasesIndexStatement($this->rows);
    }
}

$rewrite = (string)file_get_contents(__DIR__ . '/../.htaccess');
$entry = (string)file_get_contents(__DIR__ . '/../index.php');
$home = (string)file_get_contents(__DIR__ . '/../config/public_home.php');
releases_index_expect(str_contains($rewrite, 'RewriteRule ^releases/?$ index.php?page=releases [L,QSA,NC]'), '/releases must reach dedicated renderer');
releases_index_expect(str_contains($rewrite, '^(events|artists|sets|releases|blog|pages)/([a-z0-9-]+)'), 'individual entity routes must remain');
releases_index_expect(str_contains($entry, "if (\$pageRoute === 'releases')"), 'index must dispatch real catalog');
releases_index_expect(str_contains($entry, 'http_response_code(503)'), 'database errors must fail closed');
releases_index_expect(str_contains($home, 'href="/releases"'), 'home must still link to catalog');
$route = brvtalPublicLocalizedRouteUrls('https://www.brvtal.com.co', 'es', '', '', 'releases');
releases_index_expect(($route['canonical'] ?? null) === 'https://www.brvtal.com.co/releases', 'canonical ES catalog must resolve');
releases_index_expect(brvtalPublicLocalizedRouteUrls('https://www.brvtal.com.co', 'en', '', '', 'releases') === null, 'English catalog must not be public');
$seo = brvtalPublicReleasesIndexSeo('https://www.brvtal.com.co');
$rows = [
    ['title'=>'GENESIS <script>alert(1)</script>', 'slug'=>'genesis-ep', 'release_type'=>'ep', 'artwork'=>'/uploads/release.webp', 'catalog_number'=>'BR-01', 'release_date'=>'2026-08-14'],
    ['title'=>'Bad route', 'slug'=>'../draft', 'artwork'=>'javascript:alert(1)'],
    ['title'=>'Valid without art', 'slug'=>'no-art', 'artwork'=>'javascript:alert(1)'],
];
$valid = brvtalPublicReleasesIndexRows(new BrvtalReleasesIndexDb($rows));
releases_index_expect(count($valid) === 2, 'invalid slugs must not appear in canonical index');
$html = brvtalPublicReleasesIndexPage($valid, $seo);
releases_index_expect(str_contains($html, '/releases/genesis-ep') && str_contains($html, '/releases/no-art'), 'cards must link to canonical details');
releases_index_expect(str_contains($html, 'GENESIS &lt;script&gt;alert(1)&lt;/script&gt;'), 'editorial title must be escaped');
releases_index_expect(!str_contains($html, '<script>alert(1)</script>') && !str_contains($html, 'src="javascript:'), 'no XSS markup or unsafe image URL');
releases_index_expect(str_contains($html, 'BR-01') && str_contains($html, '14.08.2026'), 'real release metadata must appear');
releases_index_expect(str_contains($html, 'https://www.brvtal.com.co/releases') && str_contains($html, 'CollectionPage'), 'page must advertise genuine canonical collection');
releases_index_expect(str_contains($html, 'cromo-page') && str_contains($html, '/js/public-cromo-sound.js'), 'Cromo shell must render');
$empty = brvtalPublicReleasesIndexPage([], $seo);
releases_index_expect(str_contains($empty, 'Aún no hay discos publicados') && !str_contains($empty, 'href="/releases/'), 'empty publication state must be explicit');
try { brvtalPublicReleasesIndexRows(new BrvtalReleasesIndexDb([], true)); releases_index_expect(false, 'unavailable DB must not be converted into empty 200'); }
catch (PDOException) { /* expected */ }
echo "Public releases index contract tests passed.\n";
