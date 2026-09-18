<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/public_page.php';

function contextual_transmissions_it_expect(bool $condition, string $message): void
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
contextual_transmissions_it_expect(
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

$pdo->exec("INSERT INTO blog_posts
    (id,title,slug,cover_image,published_at,updated_at,status)
    VALUES
    (1,'ARTIST SIGNAL','artist-signal','',NOW(),NOW(),'published'),
    (2,'SET SIGNAL','set-signal','',NOW(),NOW(),'published'),
    (3,'RELEASE SIGNAL','release-signal','',NOW(),NOW(),'published'),
    (4,'EVENT SIGNAL','event-signal','',NOW(),NOW(),'published'),
    (5,'DRAFT SIGNAL','draft-signal','',NULL,NOW(),'draft'),
    (6,'UNRELATED SIGNAL','unrelated-signal','',NOW(),NOW(),'published')");

$pdo->exec("INSERT INTO blog_post_relations(post_id,related_type,related_id,sort_order) VALUES
    (1,'artist',101,1),
    (2,'set',202,1),
    (3,'release',303,1),
    (4,'event',404,1),
    (5,'artist',101,2),
    (6,'artist',999,1)");

$cases = [
    ['artists', 101, 'ARTIST SIGNAL'],
    ['sets', 202, 'SET SIGNAL'],
    ['releases', 303, 'RELEASE SIGNAL'],
    ['events', 404, 'EVENT SIGNAL'],
];

foreach ($cases as [$routeType, $entityId, $expectedTitle]) {
    $rows = brvtal_public_transmissions_for_entity($pdo, $routeType, $entityId);
    contextual_transmissions_it_expect(
        count($rows) === 1,
        "{$routeType} must expose exactly one explicitly related published Transmission"
    );
    contextual_transmissions_it_expect(
        ($rows[0]['title'] ?? '') === $expectedTitle,
        "{$routeType} must expose the correct published Transmission"
    );
    contextual_transmissions_it_expect(
        ($rows[0]['route_type'] ?? '') === 'blog',
        "{$routeType} Transmission must preserve canonical Blog route type"
    );
}

contextual_transmissions_it_expect(
    brvtal_public_transmissions_for_entity($pdo, 'artists', 999) === [['title' => 'UNRELATED SIGNAL','slug' => 'unrelated-signal','image' => '','meta' => date('d.m.Y'),'route_type' => 'blog']]
    || count(brvtal_public_transmissions_for_entity($pdo, 'artists', 999)) === 1,
    'entity-specific relation lookup must not bleed records from other IDs'
);
contextual_transmissions_it_expect(
    brvtal_public_transmissions_for_entity($pdo, 'pages', 101) === [],
    'unsupported entity types must remain outside contextual Transmissions'
);
contextual_transmissions_it_expect(
    brvtal_public_transmissions_for_entity($pdo, 'artists', 0) === [],
    'invalid entity IDs must fail closed'
);

$artistRows = brvtal_public_transmissions_for_entity($pdo, 'artists', 101);
contextual_transmissions_it_expect(
    !in_array('DRAFT SIGNAL', array_column($artistRows, 'title'), true),
    'draft Blog posts must never surface through inverse relations'
);

echo "Contextual Transmissions MariaDB integration passed.\n";
