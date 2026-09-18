<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/public_visibility.php';
require_once __DIR__ . '/../../config/public_seo.php';
require_once __DIR__ . '/../../config/public_page.php';

function contextualTransmissionsItExpect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "CONTEXTUAL TRANSMISSIONS INTEGRATION FAILED: {$message}\n");
        exit(1);
    }
}

if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') {
    echo "Contextual Transmissions integration skipped. Set BRVTAL_INTEGRATION_TESTS=1 to run.\n";
    exit(0);
}

$dbName = (string)(getenv('BRVTAL_TEST_DB_NAME') ?: '');
contextualTransmissionsItExpect(
    (bool)preg_match('/^brvtal_test[a-zA-Z0-9_]*$/', $dbName),
    'test database name must start with brvtal_test'
);

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
    (string)(getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1'),
    (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306),
    $dbName
);
$pdo = new PDO(
    $dsn,
    (string)(getenv('BRVTAL_TEST_DB_USER') ?: 'root'),
    (string)(getenv('BRVTAL_TEST_DB_PASS') ?: ''),
    [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]
);

$pdo->exec("CREATE TEMPORARY TABLE artists (
    id INT PRIMARY KEY,
    name VARCHAR(180) NOT NULL,
    slug VARCHAR(190) NOT NULL,
    photo VARCHAR(500) NULL,
    status VARCHAR(30) NOT NULL,
    instagram_url VARCHAR(500) NULL,
    soundcloud_url VARCHAR(500) NULL,
    website_url VARCHAR(500) NULL,
    collective_status VARCHAR(30) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TEMPORARY TABLE events (
    id INT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    slug VARCHAR(190) NOT NULL,
    cover_image VARCHAR(500) NULL,
    status VARCHAR(30) NOT NULL,
    event_date DATETIME NULL,
    published_at DATETIME NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TEMPORARY TABLE event_artists (
    event_id INT NOT NULL,
    artist_id INT NOT NULL,
    lineup_order INT NOT NULL DEFAULT 0,
    role VARCHAR(80) NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TEMPORARY TABLE sets_media (
    id INT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    slug VARCHAR(190) NOT NULL,
    cover_image VARCHAR(500) NULL,
    platform VARCHAR(30) NOT NULL,
    external_url VARCHAR(700) NULL,
    embed_url VARCHAR(700) NULL,
    artist_id INT NULL,
    event_id INT NULL,
    status VARCHAR(30) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at DATETIME NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TEMPORARY TABLE releases (
    id INT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    slug VARCHAR(190) NOT NULL,
    artwork VARCHAR(500) NULL,
    release_type VARCHAR(40) NULL,
    catalog_number VARCHAR(120) NULL,
    release_date DATE NULL,
    spotify_url VARCHAR(500) NULL,
    soundcloud_url VARCHAR(500) NULL,
    bandcamp_url VARCHAR(500) NULL,
    youtube_url VARCHAR(500) NULL,
    beatport_url VARCHAR(500) NULL,
    status VARCHAR(30) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TEMPORARY TABLE release_artists (
    release_id INT NOT NULL,
    artist_id INT NOT NULL,
    role VARCHAR(80) NULL,
    sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TEMPORARY TABLE blog_posts (
    id INT PRIMARY KEY,
    title VARCHAR(180) NOT NULL,
    slug VARCHAR(190) NOT NULL,
    cover_image VARCHAR(500) NULL,
    published_at DATETIME NULL,
    updated_at DATETIME NOT NULL,
    status VARCHAR(30) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TEMPORARY TABLE blog_post_relations (
    post_id INT NOT NULL,
    related_type VARCHAR(30) NOT NULL,
    related_id INT NOT NULL,
    sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TEMPORARY TABLE memory_relations (
    memory_id INT NOT NULL,
    related_type VARCHAR(30) NOT NULL,
    related_id INT NOT NULL,
    sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TEMPORARY TABLE memories (
    id INT PRIMARY KEY,
    media_id INT NOT NULL,
    title VARCHAR(180) NOT NULL,
    context VARCHAR(320) NULL,
    status VARCHAR(30) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("CREATE TEMPORARY TABLE media (
    id INT PRIMARY KEY,
    type VARCHAR(30) NOT NULL,
    file_path VARCHAR(500) NOT NULL,
    alt_text VARCHAR(255) NULL,
    status VARCHAR(30) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

$pdo->exec("INSERT INTO artists
    (id,name,slug,photo,status,instagram_url,soundcloud_url,website_url,collective_status)
    VALUES (101,'ARTIST FIXTURE','artist-fixture','','published','','','','active')");

$pdo->exec("INSERT INTO sets_media
    (id,title,slug,cover_image,platform,external_url,embed_url,artist_id,event_id,status,sort_order,created_at)
    VALUES (202,'SET FIXTURE','set-fixture','','soundcloud','https://example.com/set','',NULL,NULL,'published',1,NOW())");

$pdo->exec("INSERT INTO releases
    (id,title,slug,artwork,release_type,catalog_number,release_date,spotify_url,soundcloud_url,bandcamp_url,youtube_url,beatport_url,status,sort_order)
    VALUES (303,'RELEASE FIXTURE','release-fixture','','ep','BRV303',CURRENT_DATE,'','','','','','published',1)");

$pdo->exec("INSERT INTO blog_posts
    (id,title,slug,cover_image,published_at,updated_at,status)
    VALUES
    (1,'ARTIST SIGNAL','artist-signal','',NOW(),NOW(),'published'),
    (2,'SET SIGNAL','set-signal','',NOW(),NOW(),'published'),
    (3,'RELEASE SIGNAL','release-signal','',NOW(),NOW(),'published'),
    (4,'DRAFT SIGNAL','draft-signal','',NULL,NOW(),'draft'),
    (5,'UNRELATED SIGNAL','unrelated-signal','',NOW(),NOW(),'published')");

$pdo->exec("INSERT INTO blog_post_relations(post_id,related_type,related_id,sort_order) VALUES
    (1,'artist',101,1),
    (2,'set',202,1),
    (3,'release',303,1),
    (4,'artist',101,2),
    (5,'artist',999,1)");

$cases = [
    [
        'entity' => [
            'id' => 101,
            'route_type' => 'artists',
            'title' => 'ARTIST FIXTURE',
            'description' => 'Artist fixture description',
            'slug' => 'artist-fixture',
        ],
        'expected' => 'ARTIST SIGNAL',
    ],
    [
        'entity' => [
            'id' => 202,
            'route_type' => 'sets',
            'title' => 'SET FIXTURE',
            'description' => 'Set fixture description',
            'slug' => 'set-fixture',
        ],
        'expected' => 'SET SIGNAL',
    ],
    [
        'entity' => [
            'id' => 303,
            'route_type' => 'releases',
            'title' => 'RELEASE FIXTURE',
            'description' => 'Release fixture description',
            'slug' => 'release-fixture',
        ],
        'expected' => 'RELEASE SIGNAL',
    ],
];

foreach ($cases as $case) {
    $entity = $case['entity'];
    $expectedTitle = $case['expected'];
    $routeType = (string)$entity['route_type'];
    $page = brvtal_public_page_data($pdo, $entity);
    $rows = $page['related']['TRANSMISSIONS'] ?? [];

    contextualTransmissionsItExpect(
        count($rows) === 1,
        "{$routeType} page-data must expose exactly one explicitly related published Transmission"
    );
    contextualTransmissionsItExpect(
        ($rows[0]['title'] ?? '') === $expectedTitle,
        "{$routeType} page-data must expose the correct published Transmission"
    );
    contextualTransmissionsItExpect(
        ($rows[0]['route_type'] ?? '') === 'blog',
        "{$routeType} Transmission must preserve canonical Blog route type"
    );

    $seo = [
        'title' => $entity['title'] . ' — BRVTAL',
        'description' => $entity['description'],
        'canonical' => 'https://www.brvtal.com.co/' . $routeType . '/' . $entity['slug'],
        'image' => '',
        'schema' => ['@type' => 'WebPage'],
    ];
    $html = brvtal_public_entity_page($page, $seo);

    contextualTransmissionsItExpect(
        str_contains($html, 'TRANSMISSIONS / 01'),
        "{$routeType} canonical page must render its Transmission section"
    );
    contextualTransmissionsItExpect(
        str_contains($html, $expectedTitle),
        "{$routeType} canonical page must render the correct Transmission"
    );
    contextualTransmissionsItExpect(
        !str_contains($html, 'DRAFT SIGNAL'),
        "{$routeType} canonical page must never render draft Blog posts"
    );
}

contextualTransmissionsItExpect(
    brvtalPublicTransmissionRelationType('pages') === null,
    'unsupported entity types must remain outside contextual Transmissions'
);
contextualTransmissionsItExpect(
    brvtalPublicTransmissionRelationType('artists') === 'artist',
    'supported route types must preserve their explicit singular relation mapping'
);

echo "Contextual Transmissions MariaDB integration passed.\n";
