<?php
declare(strict_types=1);

/**
 * Public structured-data integration against disposable MariaDB TEMPORARY tables.
 * Never reads or modifies real production data, and never creates persistent tables.
 */
require_once __DIR__ . '/../../config/public_seo.php';

function rich_schema_it_expect(bool $ok, string $message): void
{
    if (!$ok) {
        fwrite(STDERR, "PUBLIC RICH SCHEMA INTEGRATION FAILED: {$message}\n");
        exit(1);
    }
}
if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "BRVTAL rich schema integration skipped. Set BRVTAL_INTEGRATION_TESTS=1 to run.\n";
    exit(0);
}
$name = (string)(getenv('BRVTAL_TEST_DB_NAME') ?: '');
rich_schema_it_expect((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/D', $name), 'requires brvtal_test database');
$pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    (string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1'),
    (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306), $name),
    (string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root'),
    (string)(getenv('BRVTAL_TEST_DB_PASS') ?: ''), [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
foreach ([
    "CREATE TEMPORARY TABLE events (
        id INT PRIMARY KEY,slug VARCHAR(190),title VARCHAR(180),description TEXT,
        seo_title VARCHAR(190),seo_description TEXT,cover_image VARCHAR(500),
        status VARCHAR(30),event_date DATETIME NULL,published_at DATETIME NULL,
        venue VARCHAR(180),city VARCHAR(120))",
    "CREATE TEMPORARY TABLE artists (
        id INT PRIMARY KEY,slug VARCHAR(190),name VARCHAR(180),bio TEXT,
        seo_title VARCHAR(190),seo_description TEXT,photo VARCHAR(500),
        status VARCHAR(30),instagram_url VARCHAR(500),
        soundcloud_url VARCHAR(500),website_url VARCHAR(500))",
    "CREATE TEMPORARY TABLE event_artists (
        event_id INT,artist_id INT,lineup_order INT DEFAULT 0)",
    "CREATE TEMPORARY TABLE sets_media (
        id INT PRIMARY KEY,slug VARCHAR(190),title VARCHAR(180),
        description TEXT,seo_title VARCHAR(190),seo_description TEXT,
        cover_image VARCHAR(500),status VARCHAR(30),external_url VARCHAR(700),
        artist_id INT NULL)",
    "CREATE TEMPORARY TABLE releases (
        id INT PRIMARY KEY,slug VARCHAR(190),title VARCHAR(180),
        description TEXT,seo_title VARCHAR(190),seo_description TEXT,
        artwork VARCHAR(500),status VARCHAR(30),release_date DATE NULL,
        catalog_number VARCHAR(80),spotify_url VARCHAR(700),soundcloud_url VARCHAR(700),
        bandcamp_url VARCHAR(700),youtube_url VARCHAR(700),beatport_url VARCHAR(700))",
    "CREATE TEMPORARY TABLE release_artists (
        release_id INT,artist_id INT,sort_order INT DEFAULT 0)",
    "CREATE TEMPORARY TABLE blog_posts (
        id INT PRIMARY KEY,slug VARCHAR(190),title VARCHAR(180),
        excerpt TEXT,seo_title VARCHAR(190),seo_description TEXT,
        cover_image VARCHAR(500),status VARCHAR(30),
        published_at DATETIME NULL,updated_at DATETIME NULL)",
] as $ddl) $pdo->exec($ddl);

$pdo->exec("INSERT INTO events VALUES
    (1,'night','Real Night','Public copy',NULL,NULL,'/cover.jpg','published',
     '2026-10-14 22:30:00','2026-08-01 12:00:00','Club Example','Pereira'),
    (2,'secret','Private Night','Secret',NULL,NULL,'','draft',
     '2026-10-16 22:00:00',NULL,'Secret','Pereira'),
    (3,'undated','Undated Night','Public',NULL,NULL,'','published',
     NULL,'2026-08-01 12:00:00','Club Example','Pereira')");
$pdo->exec("INSERT INTO artists VALUES
    (1,'public-artist','Public Artist','Bio',NULL,NULL,'','published',
     'https://instagram.com/real',NULL,NULL),
    (2,'draft-artist','Draft Artist','Private',NULL,NULL,'','draft',
     'https://instagram.com/private',NULL,NULL)");
$pdo->exec('INSERT INTO event_artists VALUES (1,2,0),(1,1,1)');
$pdo->exec("INSERT INTO sets_media VALUES
    (1,'real-set','Real Set','Recording',NULL,NULL,'','published',
     'https://soundcloud.com/example/recording',1),
    (2,'private-performer-set','Orphan Set','Recording',NULL,NULL,'','published',
     'javascript:alert(1)',2)");
$pdo->exec("INSERT INTO releases (id,slug,title,description,seo_title,seo_description,
    artwork,status,release_date,catalog_number) VALUES
    (1,'real-release','Real Release','Album',NULL,NULL,'','published',
     '2026-09-12','BRVTAL-CI-001')");
$pdo->exec('INSERT INTO release_artists VALUES (1,2,0),(1,1,1)');
$pdo->exec("INSERT INTO blog_posts VALUES
    (1,'real-blog','Real Blog','Editorial',NULL,NULL,'','published',
     '2026-09-20 10:00:00','2026-09-22 14:00:00')");
$base = 'https://www.brvtal.com.co';

$event = brvtal_public_seo_entity($pdo, 'events', 'night');
rich_schema_it_expect(is_array($event), 'public event should resolve');
$eventSchema = brvtal_public_seo_document($event, $base)['schema'];
rich_schema_it_expect($eventSchema['@type'] === 'MusicEvent', 'event type must be enriched');
rich_schema_it_expect($eventSchema['startDate'] === '2026-10-14T22:30:00', 'event must hydrate DATE/TIME');
rich_schema_it_expect($eventSchema['location']['address']['addressLocality'] === 'Pereira', 'event must hydrate city');
rich_schema_it_expect(count($eventSchema['performer']) === 1, 'draft performer must never leak into JSON-LD');
rich_schema_it_expect($eventSchema['performer'][0]['name'] === 'Public Artist', 'published performer expected');
rich_schema_it_expect(brvtal_public_seo_entity($pdo, 'events', 'secret') === null, 'draft event cannot resolve');
$undated = brvtal_public_seo_entity($pdo, 'events', 'undated');
rich_schema_it_expect($undated !== null && brvtal_public_seo_document($undated, $base)['schema']['@type'] === 'WebPage',
    'undated public event must degrade to ordinary WebPage');

$artist = brvtal_public_seo_entity($pdo, 'artists', 'public-artist');
rich_schema_it_expect(is_array($artist) && brvtal_public_seo_document($artist, $base)['schema']['sameAs'] === ['https://instagram.com/real'],
    'published artist identity should hydrate from DB');
rich_schema_it_expect(brvtal_public_seo_entity($pdo, 'artists', 'draft-artist') === null, 'draft artist must not resolve');
$set = brvtal_public_seo_entity($pdo, 'sets', 'real-set');
rich_schema_it_expect(is_array($set) && brvtal_public_seo_document($set, $base)['schema']['byArtist'][0]['name'] === 'Public Artist',
    'set must hydrate its published artist');
$orphan = brvtal_public_seo_entity($pdo, 'sets', 'private-performer-set');
rich_schema_it_expect(is_array($orphan) && brvtal_public_seo_document($orphan, $base)['schema']['@type'] === 'WebPage',
    'draft artist and unsafe URL must not produce MusicRecording/identity');

$release = brvtal_public_seo_entity($pdo, 'releases', 'real-release');
rich_schema_it_expect(is_array($release), 'published release must resolve');
$releaseSchema = brvtal_public_seo_document($release, $base)['schema'];
rich_schema_it_expect($releaseSchema['datePublished'] === '2026-09-12', 'release must hydrate actual date');
rich_schema_it_expect(count($releaseSchema['byArtist']) === 1, 'release must exclude unpublished artists');
rich_schema_it_expect($releaseSchema['identifier'] === 'BRVTAL-CI-001', 'release must hydrate catalog number');

$blog = brvtal_public_seo_entity($pdo, 'blog', 'real-blog');
rich_schema_it_expect(is_array($blog), 'published Blog must resolve');
$blogSchema = brvtal_public_seo_document($blog, $base)['schema'];
rich_schema_it_expect($blogSchema['datePublished'] === '2026-09-20T10:00:00', 'blog published_at must hydrate');
rich_schema_it_expect($blogSchema['dateModified'] === '2026-09-22T14:00:00', 'blog updated_at must hydrate');

echo "BRVTAL public rich-schema MariaDB integration tests passed.\n";
