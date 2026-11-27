<?php
declare(strict_types=1);

/** Run opt-in database tests without replaying the canonical integration suite. */
function markedDatabaseFiles(string $directory, string $entrypoints): array
{
    $files = glob($directory . '/*.php') ?: [];
    sort($files, SORT_STRING);
    $selected = [];
    $reachable = $entrypoints;
    // Follow literal fixture names in the existing suite, including require/proc_open.
    $candidates = glob($directory . '/*') ?: [];
    do {
        $changed = false;
        foreach ($candidates as $path) {
            if (!is_file($path) || is_link($path)) {
                continue;
            }
            $name = basename($path);
            if (str_contains($reachable, "\n# reached " . $name . "\n")
                || preg_match('/(?<![A-Za-z0-9_.-])' . preg_quote($name, '/') . '(?![A-Za-z0-9_.-])/', $reachable) !== 1) {
                continue;
            }
            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new RuntimeException('Unreadable integration entrypoint: ' . $name);
            }
            $reachable .= "\n# reached " . $name . "\n" . $contents;
            $changed = true;
        }
    } while ($changed);
    foreach ($files as $path) {
        $name = basename($path);
        if (is_link($path) || !is_file($path) || preg_match('/^[a-zA-Z0-9_-]+\.php$/D', $name) !== 1) {
            throw new RuntimeException('Unexpected integration test path');
        }
        $header = file_get_contents($path, false, null, 0, 4096);
        if ($header === false) {
            throw new RuntimeException('Unreadable integration test: ' . $name);
        }
        if (preg_match('~^\h*(?://|#|/\*\*?|\*)\h*@brvtal-ci database\h*(?:\*/)?\h*$~m', $header) !== 1) {
            continue;
        }
        if (preg_match('/(?<![A-Za-z0-9_.-])' . preg_quote($name, '/') . '(?![A-Za-z0-9_.-])/', $reachable) === 1) {
            throw new RuntimeException('Duplicate database integration test: ' . $name);
        }
        $selected[] = $path;
    }
    if ($selected === []) {
        throw new RuntimeException('No marked database integration tests');
    }
    return $selected;
}

/** Expand npm aliases reachable from the existing test:integration command. */
function markedDatabaseEntrypoints(string $root): string
{
    $workflow = file_get_contents($root . '/.github/workflows/update-release-metadata.yml');
    $package = file_get_contents($root . '/package.json');
    if ($workflow === false || $package === false
        || preg_match('/^  database:\h*\R(.*?)(?=^  [A-Za-z0-9_-]+:\h*$|\z)/ms', $workflow, $matches) !== 1) {
        throw new RuntimeException('Cannot read database integration entrypoints');
    }
    $scripts = json_decode($package, true, 512, JSON_THROW_ON_ERROR)['scripts'] ?? [];
    $commands = $matches[1];
    if (!is_string($scripts['test:integration'] ?? null)) {
        throw new RuntimeException('Missing canonical integration command');
    }
    $seen = [];
    do {
        $changed = false;
        preg_match_all('/\bnpm\s+run\s+([A-Za-z0-9:_-]+)/', $commands, $aliases);
        foreach ($aliases[1] as $alias) {
            if (isset($seen[$alias])) {
                continue;
            }
            if (!is_string($scripts[$alias] ?? null)) {
                throw new RuntimeException('Unknown npm entrypoint: ' . $alias);
            }
            $seen[$alias] = true;
            $commands .= "\n" . $scripts[$alias];
            $changed = true;
        }
    } while ($changed);
    return $commands;
}

/** Children run without a shell; their output is private, and only the receipt is inspected. */
function markedDatabaseRun(array $files): int
{
    foreach ($files as $path) {
        $name = basename($path);
        $started = hrtime(true);
        $output = tmpfile();
        if ($output === false) {
            throw new RuntimeException('Cannot create private receipt file');
        }
        try {
            $process = proc_open([PHP_BINARY, $path], [0 => ['file', '/dev/null', 'r'], 1 => $output,
                2 => ['file', '/dev/null', 'w']], $pipes);
            if (!is_resource($process)) {
                throw new RuntimeException('Cannot start test: ' . $name);
            }
            $status = proc_close($process);
            rewind($output);
            $receipt = false;
            while (($line = fgets($output)) !== false) {
                if (rtrim($line, "\r\n") === 'BRVTAL_MARKED_DATABASE_PASS: ' . $name) {
                    $receipt = true;
                }
            }
            $elapsed = (hrtime(true) - $started) / 1e9;
            printf("%s %.3fs %s\n", $name, $elapsed, $status === 0 && $receipt ? 'PASS' : 'FAIL');
            if ($status !== 0) {
                return $status > 0 && $status <= 255 ? $status : 1;
            }
            if (!$receipt) {
                return 1;
            }
        } finally {
            fclose($output);
        }
    }
    return 0;
}

function markedDatabaseSelfTest(): void
{
    $directory = sys_get_temp_dir() . '/brvtal-marked-' . bin2hex(random_bytes(12));
    if (!mkdir($directory, 0700)) {
        throw new RuntimeException('Cannot create self-test directory');
    }
    $write = static function (string $name, string $code) use ($directory): void {
        if (file_put_contents($directory . '/' . $name, "<?php\n// @brvtal-ci database\n" . $code) === false) {
            throw new RuntimeException('Cannot create self-test fixture');
        }
    };
    $reject = static function (string $entrypoints, string $expected) use ($directory): void {
        try {
            markedDatabaseFiles($directory, $entrypoints);
        } catch (RuntimeException $error) {
            if (str_contains($error->getMessage(), $expected)) {
                return;
            }
            throw $error;
        }
        throw new RuntimeException('Expected discovery rejection: ' . $expected);
    };
    try {
        $reject('', 'No marked');
        $write('z.php', 'echo "BRVTAL_MARKED_DATABASE_PASS: z.php\n";');
        $write('a.php', 'echo "BRVTAL_MARKED_DATABASE_PASS: a.php\n";');
        $files = markedDatabaseFiles($directory, '');
        if (array_map(basename(...), $files) !== ['a.php', 'z.php'] || markedDatabaseRun($files) !== 0) {
            throw new RuntimeException('Marked alphabetical execution failed');
        }
        $reject('php tests/integration/a.php', 'Duplicate');
        file_put_contents($directory . '/legacy.php', '<?php $fixture = "z.php";');
        $reject('php tests/integration/legacy.php', 'Duplicate');
        $write('a.php', 'exit(7);');
        if (markedDatabaseRun([$directory . '/a.php']) !== 7) {
            throw new RuntimeException('Child failure status was lost');
        }
        $write('a.php', 'exit(0);');
        if (markedDatabaseRun([$directory . '/a.php']) !== 1) {
            throw new RuntimeException('Missing receipt did not fail closed');
        }
        echo "Marked discovery self-test passed (empty, duplicates, order, failure, missing receipt).\n";
    } finally {
        foreach (glob($directory . '/*') ?: [] as $path) {
            unlink($path);
        }
        rmdir($directory);
    }
}

try {
    markedDatabaseSelfTest();
    if (($argv[1] ?? '') === '--self-test') {
        exit(0);
    }
    if (getenv('BRVTAL_INTEGRATION_TESTS') !== '1'
        || preg_match('/^brvtal_test[a-zA-Z0-9_]*$/D', (string) getenv('BRVTAL_TEST_DB_NAME')) !== 1
        || getenv('BRVTAL_TEST_DB_HOST') !== '127.0.0.1') {
        throw new RuntimeException('Disposable local MariaDB test environment required');
    }
    $root = dirname(__DIR__);
    exit(markedDatabaseRun(markedDatabaseFiles($root . '/tests/integration', markedDatabaseEntrypoints($root))));
} catch (Throwable $error) {
    // Diagnostics here are controlled by the runner; never print child output or connection data.
    fwrite(STDERR, $error instanceof RuntimeException ? $error->getMessage() . "\n" : "Marked database runner failed.\n");
    exit(1);
}
