<?php
declare(strict_types=1);

$root = dirname(__DIR__);
$config = (string) file_get_contents($root . '/.sonarcloud.properties');

$fail = static function (string $message): never {
    fwrite(STDERR, "SonarQube scope contract failed: {$message}\n");
    exit(1);
};

$readList = static function (string $name) use ($config, $fail): array {
    if (!preg_match('/^' . preg_quote($name, '/') . '=(.*)$/m', $config, $match)) {
        $fail("missing {$name}");
    }

    $items = array_values(array_filter(array_map(
        static fn (string $item): string => trim(str_replace('\\', '/', $item), " /\t\r\n"),
        explode(',', trim($match[1]))
    ), static fn (string $item): bool => $item !== ''));

    if ($items === []) {
        $fail("{$name} must not be empty");
    }

    return $items;
};

$sources = $readList('sonar.sources');
$tests = $readList('sonar.tests');

if (in_array('.', $sources, true)) {
    $fail('sonar.sources must not use the project root when sonar.tests is configured');
}

foreach ($sources as $source) {
    foreach ($tests as $test) {
        $overlaps = $source === $test
            || str_starts_with($test, $source . '/')
            || str_starts_with($source, $test . '/');

        if ($overlaps) {
            $fail("source '{$source}' overlaps test root '{$test}'");
        }
    }
}

if (!in_array('tests', $tests, true)) {
    $fail('tests directory must remain classified as test code');
}

echo "BRVTAL SonarQube source/test scope contract passed.\n";
