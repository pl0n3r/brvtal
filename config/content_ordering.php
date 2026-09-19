<?php
declare(strict_types=1);

/**
 * @return array<string,array{table:string,label:string,activity:string}>
 */
function brvtal_content_order_resources(): array
{
    return [
        'artists' => ['table'=>'artists','label'=>'name','activity'=>'artists'],
        'sets' => ['table'=>'sets_media','label'=>'title','activity'=>'sets'],
        'releases' => ['table'=>'releases','label'=>'title','activity'=>'releases'],
        'blog' => ['table'=>'blog_posts','label'=>'title','activity'=>'blog'],
    ];
}

/** @return array{table:string,label:string,activity:string}|null */
function brvtal_content_order_resource(string $resource): ?array
{
    $key = strtolower(trim($resource));
    return brvtal_content_order_resources()[$key] ?? null;
}

/** @return array<int,int> */
function brvtal_content_order_ids(mixed $value): array
{
    if (!is_array($value) || $value === [] || count($value) > 500) {
        throw new InvalidArgumentException('INVALID_ORDER');
    }

    $ids = [];
    $seen = [];
    foreach ($value as $raw) {
        if (is_int($raw)) {
            $id = $raw;
        } elseif (is_string($raw) && ctype_digit($raw)) {
            $id = (int)$raw;
        } else {
            throw new InvalidArgumentException('INVALID_ORDER');
        }
        if ($id < 1 || isset($seen[$id])) {
            throw new InvalidArgumentException('INVALID_ORDER');
        }
        $seen[$id] = true;
        $ids[] = $id;
    }
    return $ids;
}

/**
 * @param array<int,int> $submitted
 * @param array<int,int> $current
 */
function brvtal_content_order_matches(array $submitted, array $current): bool
{
    if (count($submitted) !== count($current)) return false;
    sort($submitted, SORT_NUMERIC);
    sort($current, SORT_NUMERIC);
    return $submitted === $current;
}
