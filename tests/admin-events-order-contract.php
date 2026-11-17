<?php
declare(strict_types=1);

/**
 * DISCADMIN Events date-order contract.
 *
 * Checks the exact source query and executes that SQL over synthetic events
 * with an application-local clock bound twice as parameters. SQLite's fake
 * server NOW() deliberately uses another timezone to detect regressions.
 * No production connections, real records or external I/O are involved.
 */
$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "Admin Events date-order contract failed: {$message}\n");
        exit(1);
    }
};

$source = (string)file_get_contents(__DIR__ . '/../api/index.php');
$parsed = [];
$assert(
    preg_match('/\$orderBy\s*=\s*match\s*\(\$resource\)\s*\{(?<cases>.*?)\n\s*\};/s', $source, $parsed) === 1,
    'the admin endpoint must use a resource-scoped ORDER BY'
);
$cases = $parsed['cases'];
$eventsSql = 'event_date IS NULL ASC, event_date < ? ASC, CASE WHEN event_date >= ? THEN event_date END ASC, event_date DESC, id DESC';
$assert(str_contains($cases, "'events' => '{$eventsSql}'"), 'Events require a parameterized clock, ascending upcoming dates, descending past dates and stable ID ties');
$assert(str_contains($cases, "'artists', 'sets' => 'sort_order ASC,id ASC'"), 'Artists and Sets must retain manual order');
$assert(str_contains($cases, "default => 'id DESC'"), 'other resources must keep their existing ID order');
$assert(
    str_contains($source, "new DateTimeImmutable('now')->format('Y-m-d H:i:s')")
        && str_contains($source, '$eventNow = $resource === \'events\'')
        && str_contains($source, '$orderParams = $eventNow === null ? [] : [$eventNow, $eventNow];'),
    'use one PHP application-local clock bound to both sort expressions, without DB NOW()'
);
$assert(
    str_contains($source, 'ORDER BY {$orderBy} LIMIT {$limit} OFFSET {$offset}')
        && str_contains($source, '$statement->execute([...$queryParams, ...$orderParams]);'),
    'pagination must bind search filters first and the identical clock twice after the WHERE filters'
);
$assert(
    str_contains($source, "} elseif (\$resource === 'events') {")
        && str_contains($source, '$statement->execute($orderParams);')
        && str_contains($source, '$rows = $pdo->query("SELECT * FROM {$table} ORDER BY {$orderBy}")->fetchAll();'),
    'unpaginated Events must bind the clock, leaving other resources on their legacy query path'
);

if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
    fwrite(STDOUT, "Admin Events source SQL contract OK (SQLite not available).\n");
    exit(0);
}

$checkClock = static function (DateTimeImmutable $now, string $scenario) use ($assert, $eventsSql): void {
    $clock = $now->format('Y-m-d H:i:s');
    $utcClock = $now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    $assert($utcClock !== $clock, 'the simulated DB time must differ from application local time');

    $pdo = new Pdo\Sqlite('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    // This must have no effect: the production SQL uses bound app clock, not NOW().
    $pdo->createFunction('NOW', static fn (): string => $utcClock);
    $pdo->exec('CREATE TABLE events (id INTEGER PRIMARY KEY, event_date TEXT NULL)');

    $fixtures = [
        [1, '-7 days'], [2, '+2 hours'], [3, '-1 hour'],
        [4, '+1 hour'], [5, '+1 hour'], [6, null],
        [7, '-3 hours'], [8, '-3 hours'], [9, '+3 days'],
        [10, '+1 second'], [11, null], [12, '-1 second'], [13, '+0 seconds'],
    ];
    $insert = $pdo->prepare('INSERT INTO events(id,event_date) VALUES (?,?)');
    foreach ($fixtures as [$id, $delta]) {
        $date = $delta === null ? null : $now->modify($delta)->format('Y-m-d H:i:s');
        $insert->execute([$id, $date]);
    }
    $expected = [13, 10, 5, 4, 2, 9, 12, 3, 8, 7, 1, 11, 6];
    $fetchIds = static function (string $sql, array $params) use ($pdo): array {
        $statement = $pdo->prepare($sql);
        $statement->execute($params);
        return array_map(intval(...), $statement->fetchAll(PDO::FETCH_COLUMN));
    };
    $assert(
        $fetchIds("SELECT id FROM events ORDER BY {$eventsSql}", [$clock, $clock]) === $expected,
        "{$scenario}: full list must honor same-date times, equality, ties, past and nulls"
    );
    $pageSize = 3;
    $pages = [];
    for ($offset = 0; $offset < count($fixtures); $offset += $pageSize) {
        $page = $fetchIds(
            "SELECT id FROM events ORDER BY {$eventsSql} LIMIT {$pageSize} OFFSET {$offset}",
            [$clock, $clock]
        );
        $pages[] = $page;
        $assert($page === array_slice($expected, $offset, $pageSize), "{$scenario}: page offset {$offset}");
    }
    $assert($pages[0] === [13, 10, 5] && $pages[1] === [4, 2, 9], "{$scenario}: stable first/second page boundary and tied timestamps");
    $assert(count(array_unique(array_merge(...$pages))) === count($fixtures), "{$scenario}: no dropped or repeated events");

    $filtered = $fetchIds(
        "SELECT id FROM events WHERE id > ? ORDER BY {$eventsSql} LIMIT 3 OFFSET 0",
        [2, $clock, $clock]
    );
    $assert($filtered === [13, 10, 5], "{$scenario}: search WHERE parameters must precede the clock parameters");
};

$appZone = new DateTimeZone('America/Bogota');
$today = new DateTimeImmutable('now', $appZone);
$checkClock($today->setTime(12, 0, 0), 'same-day morning/afternoon');
$checkClock($today->setTime(23, 59, 30), 'local midnight transition');

fwrite(STDOUT, "Admin Events date-order, time-zone and pagination contract OK.\n");
