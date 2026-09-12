<?php
declare(strict_types=1);

/**
 * BRVTAL integration test for real MariaDB persistence.
 *
 * Safety model:
 * - Requires BRVTAL_INTEGRATION_TESTS=1.
 * - Requires a database name beginning with brvtal_test.
 * - Uses TEMPORARY tables only; the script does not drop or alter persistent tables.
 */

require_once __DIR__ . '/../../config/media.php';

function brvtal_it_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "INTEGRATION FAILED: {$message}\n");
        exit(1);
    }
}

if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "BRVTAL integration tests skipped. Set BRVTAL_INTEGRATION_TESTS=1 to run.\n";
    exit(0);
}

$dbName = (string)(getenv('BRVTAL_TEST_DB_NAME') ?: '');
brvtal_it_assert((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/', $dbName), 'test database name must start with brvtal_test');

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    (string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1'),
    (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306),
    $dbName,
    (string)(getenv('BRVTAL_TEST_DB_CHARSET') ?: 'utf8mb4')
);

$pdo = new PDO($dsn, (string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root'), (string)(getenv('BRVTAL_TEST_DB_PASS') ?: ''), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$pdo->exec("CREATE TEMPORARY TABLE media (
    id INT AUTO_INCREMENT PRIMARY KEY,
    type VARCHAR(20) NOT NULL,
    title VARCHAR(180) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    mime_type VARCHAR(120) NULL,
    file_size INT DEFAULT 0,
    alt_text VARCHAR(255) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'published',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TEMPORARY TABLE events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    slug VARCHAR(190) NOT NULL,
    event_date DATETIME NULL,
    venue VARCHAR(180) NULL,
    city VARCHAR(120) NULL,
    description TEXT NULL,
    cover_image VARCHAR(500) NULL,
    ticket_qr VARCHAR(500) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'draft',
    sort_order INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TEMPORARY TABLE artists (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(180) NOT NULL,
    slug VARCHAR(190) NOT NULL,
    bio TEXT NULL,
    photo VARCHAR(500) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'draft',
    sort_order INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TEMPORARY TABLE event_artists (
    event_id INT NOT NULL,
    artist_id INT NOT NULL,
    lineup_order INT NOT NULL DEFAULT 0,
    role VARCHAR(80) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TEMPORARY TABLE sets_media (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    cover_image VARCHAR(500) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'draft'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TEMPORARY TABLE event_ticket_types (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_id INT NOT NULL,
    name VARCHAR(180) NOT NULL,
    qr_image VARCHAR(500) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'active',
    sort_order INT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TEMPORARY TABLE pages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    content_json MEDIUMTEXT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'draft'
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TEMPORARY TABLE settings (
    setting_key VARCHAR(190) PRIMARY KEY,
    setting_value MEDIUMTEXT NULL,
    is_json TINYINT DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TEMPORARY TABLE releases (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    slug VARCHAR(190) NOT NULL,
    release_type VARCHAR(30) NOT NULL DEFAULT 'single',
    catalog_number VARCHAR(80) NULL,
    release_date DATE NULL,
    description TEXT NULL,
    artwork VARCHAR(500) NULL,
    spotify_url VARCHAR(700) NULL,
    soundcloud_url VARCHAR(700) NULL,
    bandcamp_url VARCHAR(700) NULL,
    youtube_url VARCHAR(700) NULL,
    beatport_url VARCHAR(700) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'draft',
    featured TINYINT NOT NULL DEFAULT 0,
    sort_order INT NOT NULL DEFAULT 0,
    published_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TEMPORARY TABLE release_artists (
    release_id INT NOT NULL,
    artist_id INT NOT NULL,
    role VARCHAR(80) NULL,
    sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TEMPORARY TABLE blog_posts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(220) NOT NULL,
    slug VARCHAR(190) NOT NULL,
    excerpt VARCHAR(700) NULL,
    body LONGTEXT NULL,
    cover_image VARCHAR(500) NULL,
    seo_title VARCHAR(190) NULL,
    seo_description VARCHAR(320) NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'draft',
    featured TINYINT NOT NULL DEFAULT 0,
    published_at DATETIME NULL,
    sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TEMPORARY TABLE blog_tags (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    slug VARCHAR(120) NOT NULL UNIQUE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TEMPORARY TABLE blog_post_tags (
    post_id INT NOT NULL,
    tag_id INT NOT NULL,
    PRIMARY KEY(post_id,tag_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TEMPORARY TABLE blog_post_relations (
    post_id INT NOT NULL,
    related_type VARCHAR(30) NOT NULL,
    related_id INT NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    PRIMARY KEY(post_id,related_type,related_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$mediaPath = '/uploads/media/ci/test-poster.jpg';
$unusedPath = '/uploads/media/ci/unused.jpg';

$st = $pdo->prepare('INSERT INTO media(type,title,file_path,mime_type,file_size,alt_text,status) VALUES(?,?,?,?,?,?,?)');
$st->execute(['image', 'CI poster', $mediaPath, 'image/jpeg', 123456, 'CI poster alt', 'published']);
$mediaId = (int)$pdo->lastInsertId();
$st->execute(['image', 'Unused poster', $unusedPath, 'image/jpeg', 123, '', 'published']);
$unusedMediaId = (int)$pdo->lastInsertId();

$pdo->prepare('INSERT INTO events(title,slug,event_date,venue,city,description,cover_image,ticket_qr,status) VALUES(?,?,?,?,?,?,?,?,?)')
    ->execute(['Genesis CI', 'genesis-ci', '2026-09-11 21:00:00', 'La Perla', 'Pereira', 'Integration test', '', '', 'draft']);
$eventId = (int)$pdo->lastInsertId();
$pdo->prepare('UPDATE events SET cover_image=?, ticket_qr=?, status=? WHERE id=?')->execute([$mediaPath, $mediaPath, 'published', $eventId]);
$event = $pdo->query('SELECT cover_image,ticket_qr,status FROM events WHERE id=' . $eventId)->fetch();
brvtal_it_assert($event['cover_image'] === $mediaPath, 'event cover_image must persist');
brvtal_it_assert($event['ticket_qr'] === $mediaPath, 'event ticket_qr must persist');
brvtal_it_assert($event['status'] === 'published', 'event status must persist');

$pdo->prepare('INSERT INTO artists(name,slug,bio,photo,status) VALUES(?,?,?,?,?)')
    ->execute(['PL0N3R CI', 'pl0n3r-ci', 'Integration artist', '', 'draft']);
$artistId = (int)$pdo->lastInsertId();
$pdo->prepare('UPDATE artists SET photo=?, status=? WHERE id=?')->execute([$mediaPath, 'published', $artistId]);
$artist = $pdo->query('SELECT photo,status FROM artists WHERE id=' . $artistId)->fetch();
brvtal_it_assert($artist['photo'] === $mediaPath, 'artist photo must persist');
brvtal_it_assert($artist['status'] === 'published', 'artist status must persist');

$pdo->prepare('INSERT INTO event_artists(event_id,artist_id,lineup_order,role) VALUES(?,?,?,?)')
    ->execute([$eventId, $artistId, 2, 'Live Set']);
$lineup = $pdo->query('SELECT lineup_order,role FROM event_artists WHERE event_id=' . $eventId . ' AND artist_id=' . $artistId)->fetch();
brvtal_it_assert((int)$lineup['lineup_order'] === 2, 'lineup_order must persist');
brvtal_it_assert($lineup['role'] === 'Live Set', 'lineup role must persist');

$pdo->prepare('INSERT INTO event_ticket_types(event_id,name,qr_image,status,sort_order) VALUES(?,?,?,?,?)')
    ->execute([$eventId, 'Preventa CI', $mediaPath, 'active', 1]);
$ticket = $pdo->query('SELECT qr_image,status,sort_order FROM event_ticket_types WHERE event_id=' . $eventId)->fetch();
brvtal_it_assert($ticket['qr_image'] === $mediaPath, 'ticket QR media path must persist');
brvtal_it_assert($ticket['status'] === 'active', 'ticket status must persist');

$pdo->prepare('INSERT INTO releases(title,slug,release_type,catalog_number,release_date,description,artwork,spotify_url,status,featured,sort_order,published_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)')
    ->execute(['BRVTAL CI Release', 'brvtal-ci-release', 'single', 'BRVTAL-CI-001', '2026-09-11', 'Integration release', $mediaPath, 'https://open.spotify.com/test', 'published', 1, 3, '2026-09-11 20:00:00']);
$releaseId = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO release_artists(release_id,artist_id,role,sort_order) VALUES(?,?,?,?)')->execute([$releaseId, $artistId, 'Primary', 0]);
$release = $pdo->query('SELECT artwork,status,featured,catalog_number FROM releases WHERE id=' . $releaseId)->fetch();
$releaseArtist = $pdo->query('SELECT artist_id,role,sort_order FROM release_artists WHERE release_id=' . $releaseId)->fetch();
brvtal_it_assert($release['artwork'] === $mediaPath, 'release artwork must persist');
brvtal_it_assert($release['status'] === 'published', 'release status must persist');
brvtal_it_assert((int)$release['featured'] === 1, 'release featured flag must persist');
brvtal_it_assert($release['catalog_number'] === 'BRVTAL-CI-001', 'release catalog number must persist');
brvtal_it_assert((int)$releaseArtist['artist_id'] === $artistId, 'release artist relation must persist');
brvtal_it_assert($releaseArtist['role'] === 'Primary', 'release artist role must persist');

$pdo->prepare('INSERT INTO blog_posts(title,slug,excerpt,body,cover_image,seo_title,seo_description,status,featured,published_at,sort_order) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
    ->execute(['BRVTAL CI Story', 'brvtal-ci-story', 'Editorial excerpt', 'Long-form editorial body', $mediaPath, 'BRVTAL CI Story', 'Editorial SEO description', 'published', 1, '2026-09-11 20:30:00', 2]);
$postId = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO blog_tags(name,slug) VALUES(?,?)')->execute(['Hard Techno', 'hard-techno']);
$tagId = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO blog_post_tags(post_id,tag_id) VALUES(?,?)')->execute([$postId,$tagId]);
$pdo->prepare('INSERT INTO blog_post_relations(post_id,related_type,related_id,sort_order) VALUES(?,?,?,?)')->execute([$postId,'release',$releaseId,0]);
$pdo->prepare('INSERT INTO blog_post_relations(post_id,related_type,related_id,sort_order) VALUES(?,?,?,?)')->execute([$postId,'artist',$artistId,1]);
$post = $pdo->query('SELECT cover_image,status,featured,seo_title FROM blog_posts WHERE id=' . $postId)->fetch();
$postTags = $pdo->query('SELECT t.name,t.slug FROM blog_post_tags pt JOIN blog_tags t ON t.id=pt.tag_id WHERE pt.post_id=' . $postId)->fetchAll();
$postRelations = $pdo->query('SELECT related_type,related_id,sort_order FROM blog_post_relations WHERE post_id=' . $postId . ' ORDER BY sort_order')->fetchAll();
brvtal_it_assert($post['cover_image'] === $mediaPath, 'blog cover image must persist');
brvtal_it_assert($post['status'] === 'published', 'blog status must persist');
brvtal_it_assert((int)$post['featured'] === 1, 'blog featured flag must persist');
brvtal_it_assert($post['seo_title'] === 'BRVTAL CI Story', 'blog SEO title must persist');
brvtal_it_assert(count($postTags) === 1 && $postTags[0]['slug'] === 'hard-techno', 'blog tag relation must persist');
brvtal_it_assert(count($postRelations) === 2, 'blog related content must persist');
brvtal_it_assert($postRelations[0]['related_type'] === 'release' && (int)$postRelations[0]['related_id'] === $releaseId, 'blog release relation must persist');
brvtal_it_assert($postRelations[1]['related_type'] === 'artist' && (int)$postRelations[1]['related_id'] === $artistId, 'blog artist relation must persist');

$pdo->prepare('INSERT INTO sets_media(title,cover_image,status) VALUES(?,?,?)')->execute(['CI Set', $mediaPath, 'published']);
$pdo->prepare('INSERT INTO pages(title,content_json,status) VALUES(?,?,?)')->execute(['CI Page', json_encode(['hero' => $mediaPath], JSON_UNESCAPED_SLASHES), 'published']);
$pdo->prepare('INSERT INTO settings(setting_key,setting_value,is_json) VALUES(?,?,?)')->execute(['theme.ci', json_encode(['image' => $mediaPath], JSON_UNESCAPED_SLASHES), 1]);

$media = $pdo->query('SELECT * FROM media WHERE id=' . $mediaId)->fetch();
$usage = brvtal_media_usage($pdo, $media);
$resources = array_values(array_unique(array_map(static fn(array $row): string => (string)$row['resource'], $usage)));
sort($resources);

foreach (['ARTIST', 'EVENT', 'EVENT QR', 'PAGE', 'RELEASE', 'SET', 'SETTING', 'TICKET QR'] as $expected) {
    brvtal_it_assert(in_array($expected, $resources, true), "media usage must include {$expected}");
}

$unused = $pdo->query('SELECT * FROM media WHERE id=' . $unusedMediaId)->fetch();
brvtal_it_assert(brvtal_media_usage($pdo, $unused) === [], 'unused media must report no references');

echo "BRVTAL MariaDB integration tests passed.\n";
