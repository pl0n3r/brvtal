<?php
declare(strict_types=1);

require_once __DIR__ . '/admin_development_signals.php';

const BRVTAL_CODERABBIT_REPO = 'pl0n3r/brvtal';
const BRVTAL_CODERABBIT_STALE_SECONDS = 86400;
const BRVTAL_CODERABBIT_PAGE_SIZE = 100;
const BRVTAL_CODERABBIT_MAX_PAGES = 5;

function brvtalCodeRabbitBotLogin(mixed $user): bool
{
    return is_array($user)
        && ($user['login'] ?? null) === 'coderabbitai[bot]'
        && ($user['type'] ?? null) === 'Bot';
}

/** @return array{at:string,ts:int,body:string,url:string,commit_id:?string}|null */
function brvtalCodeRabbitEvent(array $item): ?array
{
    if (!brvtalCodeRabbitBotLogin($item['user'] ?? null)) {
        return null;
    }

    $body = $item['body'] ?? '';
    $at = $item['submitted_at'] ?? $item['updated_at'] ?? $item['created_at'] ?? null;
    $url = $item['html_url'] ?? null;
    $commitId = $item['commit_id'] ?? null;
    if (
        !is_string($body)
        || !is_string($at)
        || trim($at) === ''
        || !is_string($url)
        || ($commitId !== null && !is_string($commitId))
    ) {
        return null;
    }
    $ts = strtotime($at);
    if ($ts === false) {
        return null;
    }

    return [
        'at'=>$at,
        'ts'=>$ts,
        'body'=>$body,
        'url'=>$url,
        'commit_id'=>$commitId,
    ];
}

/** @return array{state:string,at:string,ts:int,url:string}|null */
function brvtalCodeRabbitClassify(array $events, ?string $headSha = null): ?array
{
    $normalized = [];
    foreach ($events as $item) {
        if (!is_array($item)) {
            continue;
        }
        $event = brvtalCodeRabbitEvent($item);
        if ($event !== null) {
            $normalized[] = $event;
        }
    }
    if (!$normalized) {
        return null;
    }

    usort(
        $normalized,
        static fn(array $left, array $right): int => $right['ts'] <=> $left['ts']
    );
    foreach ($normalized as $event) {
        $body = strtolower($event['body']);
        $state = null;
        $requiresCurrentCommit = false;
        if (str_contains($body, 'review rate limited')) {
            $state = 'rate_limited';
        } elseif (
            str_contains($body, 'actionable comments posted:')
            && !preg_match('/actionable comments posted:\s*0\b/i', $event['body'])
        ) {
            $state = 'findings';
            $requiresCurrentCommit = true;
        } elseif (
            str_contains($body, 'review finished')
            || str_contains($body, 'no actionable comments')
            || str_contains($body, '0 actionable comments')
            || preg_match('/actionable comments posted:\\s*0\\b/i', $event['body'])
        ) {
            $state = 'passed';
            $requiresCurrentCommit = true;
        } elseif (
            str_contains($body, 'currently processing new changes')
            || str_contains($body, 'review in progress')
            || str_contains($body, 'trigger review')
        ) {
            $state = 'pending';
        }

        if ($state === null) {
            continue;
        }
        if (
            $requiresCurrentCommit
            && $headSha !== null
            && $event['commit_id'] !== $headSha
        ) {
            return [
                'state'=>'pending',
                'at'=>$event['at'],
                'ts'=>$event['ts'],
                'url'=>$event['url'],
            ];
        }
        return [
            'state'=>$state,
            'at'=>$event['at'],
            'ts'=>$event['ts'],
            'url'=>$event['url'],
        ];
    }

    $latest = $normalized[0];
    return ['state'=>'pending', 'at'=>$latest['at'], 'ts'=>$latest['ts'], 'url'=>$latest['url']];
}

/** @return list<array<string,mixed>> */
function brvtalCodeRabbitPagedList(callable $request, string $url): array
{
    $items = [];
    for ($page = 1; $page <= BRVTAL_CODERABBIT_MAX_PAGES; $page++) {
        $separator = str_contains($url, '?') ? '&' : '?';
        $payload = brvtalDevelopmentDecode(
            $request($url . $separator . 'page=' . $page)
        );
        if (!array_is_list($payload)) {
            throw new RuntimeException('GITHUB_INVALID_RESPONSE');
        }
        foreach ($payload as $item) {
            if (!is_array($item)) {
                throw new RuntimeException('GITHUB_INVALID_RESPONSE');
            }
            $items[] = $item;
        }
        if (count($payload) < BRVTAL_CODERABBIT_PAGE_SIZE) {
            return $items;
        }
    }
    throw new RuntimeException('GITHUB_RESPONSE_TOO_LARGE');
}

/** @return array<string,mixed> */
function brvtalAdminCodeRabbitSignals(?callable $requester = null): array
{
    $request = $requester ?? 'brvtalDevelopmentGithubRequest';
    $base = 'https://api.github.com';
    $repo = BRVTAL_CODERABBIT_REPO;

    try {
        $query = rawurlencode('repo:' . $repo . ' is:pr is:open');
        $search = brvtalDevelopmentDecode(
            $request($base . '/search/issues?q=' . $query . '&sort=updated&order=desc&per_page=1')
        );
        if (
            ($search['incomplete_results'] ?? null) !== false
            || !isset($search['items'])
            || !is_array($search['items'])
        ) {
            throw new RuntimeException('GITHUB_INVALID_RESPONSE');
        }

        $pr = $search['items'][0] ?? null;
        if ($pr === null) {
            return [
                'status'=>'available',
                'freshness'=>'fresh',
                'review_state'=>'unavailable',
                'pr_number'=>null,
                'source_at'=>null,
                'url'=>null,
                'read_only'=>true,
            ];
        }
        $number = $pr['number'] ?? null;
        if (!is_int($number) || $number <= 0) {
            throw new RuntimeException('GITHUB_INVALID_RESPONSE');
        }

        $pull = brvtalDevelopmentDecode(
            $request($base . '/repos/' . $repo . '/pulls/' . $number)
        );
        $headSha = $pull['head']['sha'] ?? null;
        if (!is_string($headSha) || !preg_match('/^[0-9a-f]{40}$/', $headSha)) {
            throw new RuntimeException('GITHUB_INVALID_RESPONSE');
        }

        $reviews = brvtalCodeRabbitPagedList(
            $request,
            $base . '/repos/' . $repo . '/pulls/' . $number . '/reviews?per_page=100'
        );
        $comments = brvtalCodeRabbitPagedList(
            $request,
            $base . '/repos/' . $repo . '/issues/' . $number . '/comments?per_page=100'
        );
        $event = brvtalCodeRabbitClassify([...$reviews, ...$comments], $headSha);
        if ($event === null) {
            return [
                'status'=>'available',
                'freshness'=>'fresh',
                'review_state'=>'pending',
                'pr_number'=>$number,
                'source_at'=>null,
                'url'=>(string)($pr['html_url'] ?? ''),
                'read_only'=>true,
            ];
        }

        $freshness = (time() - $event['ts']) > BRVTAL_CODERABBIT_STALE_SECONDS
            ? 'stale'
            : 'fresh';

        return [
            'status'=>'available',
            'freshness'=>$freshness,
            'review_state'=>$event['state'],
            'pr_number'=>$number,
            'source_at'=>$event['at'],
            'url'=>$event['url'],
            'read_only'=>true,
        ];
    } catch (Throwable) {
        return [
            'status'=>'unavailable',
            'freshness'=>'unavailable',
            'review_state'=>'unavailable',
            'pr_number'=>null,
            'source_at'=>null,
            'url'=>null,
            'read_only'=>true,
        ];
    }
}
