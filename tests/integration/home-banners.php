<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/home_banners.php';
if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1') { echo "Home banners integration skipped\n"; exit(0); }
$name = (string)(getenv('BRVTAL_TEST_DB_NAME') ?: '');
if (!preg_match('/^brvtal_test[a-zA-Z0-9_]*$/', $name)) throw new RuntimeException('Use a disposable brvtal_test database.');
$pdo = new PDO(sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', getenv('BRVTAL_TEST_DB_HOST') ?: '127.0.0.1', (int)(getenv('BRVTAL_TEST_DB_PORT') ?: 3306), $name), getenv('BRVTAL_TEST_DB_USER') ?: 'root', getenv('BRVTAL_TEST_DB_PASS') ?: '', [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$pdo->exec('CREATE TEMPORARY TABLE settings (setting_key VARCHAR(120) PRIMARY KEY, setting_value LONGTEXT) ENGINE=InnoDB');
$pdo->exec('CREATE TEMPORARY TABLE media (file_path VARCHAR(500), type VARCHAR(30), status VARCHAR(30)) ENGINE=InnoDB');
$media = $pdo->prepare('INSERT INTO media VALUES (?,?,?)');
$media->execute(['/uploads/media/2026/09/public.png', 'image', 'published']);
$media->execute(['/uploads/media/2026/09/draft.png', 'image', 'draft']);
$slides = [
    ['enabled'=>true,'title'=>'VISIBLE','image'=>'/uploads/media/2026/09/public.png','url'=>'/events/sample'],
    ['enabled'=>true,'title'=>'PRIVATE IMAGE','image'=>'/uploads/media/2026/09/draft.png'],
    ['enabled'=>true,'title'=>'EXTERNAL IMAGE','image'=>'https://example.com/other.png'],
    ['enabled'=>false,'title'=>'DISABLED','image'=>'/uploads/media/2026/09/public.png'],
];
$st = $pdo->prepare('INSERT INTO settings VALUES (?,?)');
$st->execute(['home.hero.slides', json_encode($slides)]);
$result = brvtal_home_banner_slides($pdo);
if (count($result) !== 1 || $result[0]['title'] !== 'VISIBLE') throw new RuntimeException('Only enabled banners using published local media may render.');
echo "Home banners MariaDB integration passed\n";
