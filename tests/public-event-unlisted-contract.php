<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/public_home.php';
require_once __DIR__ . '/../api/public-archive.php';
require_once __DIR__ . '/../config/public_sitemap.php';
require_once __DIR__ . '/../config/public_seo.php';
require_once __DIR__ . '/../config/indexnow.php';

function unlisted_expect(bool $ok, string $message): void
{
    if (!$ok) throw new RuntimeException('UNLISTED EVENT CONTRACT: ' . $message);
}

$now = new DateTimeImmutable('2026-11-26 12:00:00');
$public = ['id'=>1, 'slug'=>'public-night', 'title'=>'Public night', 'status'=>'published',
    'event_date'=>'2099-12-01 21:00:00', 'visibility'=>'public', 'featured'=>0,
    'description'=>'Synthetic event', 'image'=>'', 'schema_type'=>'MusicEvent', 'route_type'=>'events'];
$unlisted = array_replace($public, ['id'=>2, 'slug'=>'unlisted-night', 'visibility'=>'unlisted', 'featured'=>1]);
$pastPublic = array_replace($public, ['id'=>3, 'slug'=>'past-public', 'event_date'=>'2000-01-01']);
$pastUnlisted = array_replace($pastPublic, ['id'=>4, 'slug'=>'past-unlisted', 'visibility'=>'unlisted']);
$draft = array_replace($unlisted, ['status'=>'draft']);

unlisted_expect(brvtal_public_event_is_visible($unlisted, $now), 'direct URL lifecycle still permits unlisted');
unlisted_expect(!brvtal_public_event_is_visible($draft, $now), 'unlisted does not publish drafts');
unlisted_expect(!brvtal_public_event_is_listed($unlisted, $now), 'unlisted cannot enter discovery');
unlisted_expect(brvtal_public_event_is_listed($public, $now), 'public event remains listed');
unlisted_expect(!brvtal_public_event_is_listed(array_replace($public, ['visibility'=>null]), $now), 'invalid visibility fails closed');
unlisted_expect(brvtal_public_select_next_experience([$unlisted, $public], $now) === $public, 'home skips featured unlisted');
unlisted_expect(brvtal_public_select_next_experience([$unlisted], $now) === null, 'home hides all-unlisted pool');
$partition = brvtal_public_partition_events([$public, $unlisted, $pastPublic, $pastUnlisted], $now);
unlisted_expect(array_column($partition['active'], 'id') === [1], 'API active pool excludes unlisted');
unlisted_expect(array_column($partition['archive'], 'id') === [3], 'archive excludes historical unlisted');

$base = BRVTAL_SITEMAP_CANONICAL_ORIGIN;
$sitemap = brvtalPublicSitemapLocalizedContentUrls($base, 'events', [$public, $unlisted, $pastPublic, $pastUnlisted], true);
unlisted_expect(array_column($sitemap, 0) === [$base.'/events/public-night', $base.'/events/past-public'], 'sitemap excludes both unlisted lifecycle cases');

// Exercise the real slug resolver and its SQL projection against synthetic rows.
class UnlistedEventStatement extends PDOStatement
{
    private array $parameters = [];
    public function __construct(private array $rows) {}
    public function execute(?array $params = null): bool { $this->parameters = $params ?? []; return true; }
    public function fetchColumn(int $column = 0): mixed { return $this->rows[0]['visibility'] ?? false; }
    public function fetch(int $mode = PDO::FETCH_DEFAULT, int $cursorOrientation = PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        foreach ($this->rows as $row) {
            if (($row['slug'] ?? null) === ($this->parameters[0] ?? null)) return $row;
        }
        return false;
    }
    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array { return $this->rows; }
}
class UnlistedEventPDO extends PDO
{
    public function __construct(private array $events) {}
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        unlisted_expect(str_contains($query, 'SHOW COLUMNS FROM events'), 'only visibility schema probe expected');
        return new UnlistedEventStatement([['visibility'=>'visibility']]);
    }
    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        if (str_contains($query, 'FROM `events`')) {
            unlisted_expect(str_contains($query, 'visibility'), 'slug resolver must hydrate visibility');
            return new UnlistedEventStatement($this->events);
        }
        return new UnlistedEventStatement([]);
    }
}
$pdo = new UnlistedEventPDO([$public, $unlisted, array_replace($draft, ['slug'=>'draft-night'])]);
$direct = brvtal_public_seo_entity($pdo, 'events', 'unlisted-night');
unlisted_expect($direct !== null && $direct['slug'] === 'unlisted-night', 'canonical direct slug resolves');
unlisted_expect(brvtal_public_seo_entity($pdo, 'events', 'draft-night') === null, 'draft slug remains inaccessible');
$seo = brvtal_public_seo_document($direct, $base);
$tags = brvtal_public_seo_tags($seo);
unlisted_expect($seo['canonical'] === $base.'/events/unlisted-night', 'canonical direct URL preserved');
unlisted_expect(str_contains($tags, 'name="robots" content="noindex,nofollow"'), 'direct response noindex and nofollow');
unlisted_expect(!str_contains($tags, 'application/ld+json'), 'unlisted event has no structured-data script');
$publicTags = brvtal_public_seo_tags(brvtal_public_seo_document($public, $base));
unlisted_expect(!str_contains($publicTags, 'noindex') && str_contains($publicTags, 'MusicEvent'), 'public SEO unchanged');

unlisted_expect(brvtalIndexNowChangeUrls('events', null, $unlisted) === [], 'never announce a new unlisted URL');
unlisted_expect(brvtalIndexNowChangeUrls('events', $unlisted, $pastUnlisted) === [], 'never announce unlisted edits');
unlisted_expect(brvtalIndexNowChangeUrls('events', $public, $unlisted) === [$base.'/events/public-night', $base.'/'], 'only previously public URL notified on transition');
$api = (string)file_get_contents(__DIR__ . '/../api/public.php');
unlisted_expect(str_contains($api, '$eventVisibilitySelect = brvtal_public_event_visibility_select($pdo)') &&
    str_contains($api, 'status,sort_order,{$eventVisibilitySelect}') &&
    str_contains($api, 'brvtal_public_partition_events($allEvents)'), 'actual API projects visibility into tested partition');
$home = (string)file_get_contents(__DIR__ . '/../config/public_home.php');
unlisted_expect(str_contains($home, 'sort_order,{$visibilitySelect}'), 'actual home query projects visibility');
$sitemapSource = (string)file_get_contents(__DIR__ . '/../sitemap.php');
unlisted_expect(str_contains($sitemapSource, 'brvtal_public_event_visibility_select(db())'), 'actual sitemap projects visibility');

echo "BRVTAL event unlisted contract passed.\n";
