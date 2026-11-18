<?php
declare(strict_types=1);

function gs_it_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "GLOBAL SEARCH INTEGRATION FAILED: {$message}\n");
        exit(1);
    }
}

if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "BRVTAL global search integration skipped. Set BRVTAL_INTEGRATION_TESTS=1 to run.\n";
    exit(0);
}

$dbName = (string)(getenv('BRVTAL_TEST_DB_NAME') ?: '');
gs_it_assert((bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/', $dbName), 'test database name must start with brvtal_test');

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    (string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1'),
    (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306),
    $dbName
);
$pdo = new PDO($dsn, (string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root'), (string)(getenv('BRVTAL_TEST_DB_PASS') ?: ''), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES => false,
]);

$pdo->exec("CREATE TEMPORARY TABLE events (id INT AUTO_INCREMENT PRIMARY KEY,title VARCHAR(180),slug VARCHAR(190),city VARCHAR(120),venue VARCHAR(180),status VARCHAR(30)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TEMPORARY TABLE artists (id INT AUTO_INCREMENT PRIMARY KEY,name VARCHAR(180),slug VARCHAR(190),bio TEXT,status VARCHAR(30)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TEMPORARY TABLE sets_media (id INT AUTO_INCREMENT PRIMARY KEY,title VARCHAR(180),slug VARCHAR(190),description TEXT,external_url VARCHAR(700),platform VARCHAR(20),artist_id INT,status VARCHAR(30)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TEMPORARY TABLE media (id INT AUTO_INCREMENT PRIMARY KEY,type VARCHAR(20),title VARCHAR(180),file_path VARCHAR(500),mime_type VARCHAR(120),alt_text VARCHAR(255),status VARCHAR(30)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TEMPORARY TABLE pages (id INT AUTO_INCREMENT PRIMARY KEY,title VARCHAR(180),slug VARCHAR(190),locale VARCHAR(2),seo_title VARCHAR(190),seo_description VARCHAR(320),status VARCHAR(30)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TEMPORARY TABLE releases (id INT AUTO_INCREMENT PRIMARY KEY,title VARCHAR(180),slug VARCHAR(190),catalog_number VARCHAR(80),release_type VARCHAR(30),description TEXT,status VARCHAR(30)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
$pdo->exec("CREATE TEMPORARY TABLE blog_posts (id INT AUTO_INCREMENT PRIMARY KEY,title VARCHAR(220),slug VARCHAR(190),excerpt VARCHAR(700),body LONGTEXT,status VARCHAR(30)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->prepare('INSERT INTO events(title,slug,city,venue,status) VALUES(?,?,?,?,?)')->execute(['Genesis Search CI','genesis-search-ci','Pereira','La Perla','published']);
$eventId = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO artists(name,slug,bio,status) VALUES(?,?,?,?)')->execute(['PL0N3R Search CI','pl0n3r-search-ci','Industrial techno artist','published']);
$artistId = (int)$pdo->lastInsertId();
$pdo->prepare('INSERT INTO sets_media(title,slug,description,external_url,platform,artist_id,status) VALUES(?,?,?,?,?,?,?)')->execute(['Genesis Set','genesis-set','Recorded set','https://example.com/set','soundcloud',$artistId,'published']);
$pdo->prepare('INSERT INTO media(type,title,file_path,mime_type,alt_text,status) VALUES(?,?,?,?,?,?)')->execute(['image','Genesis Poster','/uploads/media/genesis.jpg','image/jpeg','Genesis event poster','published']);
$pdo->prepare('INSERT INTO pages(title,slug,locale,seo_title,seo_description,status) VALUES(?,?,?,?,?,?)')->execute(['Genesis Page','genesis-page','en','Genesis BRVTAL','Genesis page description','published']);
$pdo->prepare('INSERT INTO releases(title,slug,catalog_number,release_type,description,status) VALUES(?,?,?,?,?,?)')->execute(['Genesis Release','genesis-release','BRVTAL-GEN','single','Genesis label release','published']);
$pdo->prepare('INSERT INTO blog_posts(title,slug,excerpt,body,status) VALUES(?,?,?,?,?)')->execute(['Genesis Story','genesis-story','Genesis editorial','Long Genesis editorial body','published']);

$like = '%genesis%';
$queries = [
    'events' => ["SELECT id,title,CONCAT_WS(' · ',NULLIF(city,''),NULLIF(venue,'')) subtitle,status FROM events WHERE title LIKE ? ESCAPE '\\\\' OR slug LIKE ? ESCAPE '\\\\' OR city LIKE ? ESCAPE '\\\\' OR venue LIKE ? ESCAPE '\\\\' ORDER BY id DESC LIMIT 6", [$like,$like,$like,$like]],
    'artists' => ["SELECT id,name title,slug subtitle,status FROM artists WHERE name LIKE ? ESCAPE '\\\\' OR slug LIKE ? ESCAPE '\\\\' OR bio LIKE ? ESCAPE '\\\\' ORDER BY id DESC LIMIT 6", [$like,$like,$like]],
    'sets' => ["SELECT s.id,s.title,CONCAT_WS(' · ',NULLIF(a.name,''),NULLIF(s.platform,'')) subtitle,s.status FROM sets_media s LEFT JOIN artists a ON a.id=s.artist_id WHERE s.title LIKE ? ESCAPE '\\\\' OR s.slug LIKE ? ESCAPE '\\\\' OR s.description LIKE ? ESCAPE '\\\\' OR s.external_url LIKE ? ESCAPE '\\\\' ORDER BY s.id DESC LIMIT 6", [$like,$like,$like,$like]],
    'media' => ["SELECT id,COALESCE(NULLIF(title,''),file_path) title,CONCAT_WS(' · ',NULLIF(type,''),NULLIF(mime_type,'')) subtitle,status FROM media WHERE title LIKE ? ESCAPE '\\\\' OR alt_text LIKE ? ESCAPE '\\\\' OR file_path LIKE ? ESCAPE '\\\\' ORDER BY id DESC LIMIT 6", [$like,$like,$like]],
    'pages' => ["SELECT id,title,CONCAT_WS(' · ',NULLIF(locale,''),NULLIF(slug,'')) subtitle,status FROM pages WHERE title LIKE ? ESCAPE '\\\\' OR slug LIKE ? ESCAPE '\\\\' OR seo_title LIKE ? ESCAPE '\\\\' OR seo_description LIKE ? ESCAPE '\\\\' ORDER BY id DESC LIMIT 6", [$like,$like,$like,$like]],
    'releases' => ["SELECT id,title,CONCAT_WS(' · ',NULLIF(catalog_number,''),NULLIF(release_type,'')) subtitle,status FROM releases WHERE title LIKE ? ESCAPE '\\\\' OR slug LIKE ? ESCAPE '\\\\' OR catalog_number LIKE ? ESCAPE '\\\\' OR description LIKE ? ESCAPE '\\\\' ORDER BY id DESC LIMIT 6", [$like,$like,$like,$like]],
    'blog' => ["SELECT id,title,slug subtitle,status FROM blog_posts WHERE title LIKE ? ESCAPE '\\\\' OR slug LIKE ? ESCAPE '\\\\' OR excerpt LIKE ? ESCAPE '\\\\' OR body LIKE ? ESCAPE '\\\\' ORDER BY id DESC LIMIT 6", [$like,$like,$like,$like]],
];

foreach ($queries as $name => [$sql,$params]) {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
    if ($name === 'artists') {
        gs_it_assert(is_array($rows), 'artists query must execute successfully even when no Genesis match exists');
    } else {
        gs_it_assert(count($rows) >= 1, "{$name} query must find seeded Genesis content");
    }
}

$event = $pdo->prepare($queries['events'][0]);
$event->execute($queries['events'][1]);
$eventRow = $event->fetch();
gs_it_assert((int)$eventRow['id'] === $eventId, 'event global search must return the matching event');
gs_it_assert($eventRow['subtitle'] === 'Pereira · La Perla', 'event search subtitle must preserve city and venue context');

echo "BRVTAL Global Search MariaDB integration tests passed.\n";
