<?php
declare(strict_types=1);

const BRVTAL_DEVELOPMENT_REPO = 'pl0n3r/brvtal';
const BRVTAL_DEVELOPMENT_MAX_BYTES = 262144;
const BRVTAL_DEVELOPMENT_STALE_SECONDS = 86400;

/** @return array{status:int,body:string} */
function brvtalDevelopmentGithubRequest(string $url): array
{
    if (!function_exists('curl_init')) {
        throw new RuntimeException('GITHUB_TRANSPORT_UNAVAILABLE');
    }

    $headers = [
        'Accept: application/vnd.github+json',
        'User-Agent: BRVTAL-DISCADMIN',
    ];
    $token = trim((string)(getenv('BRVTAL_GITHUB_TOKEN') ?: ''));
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $body = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_RETURNTRANSFER=>false,
        CURLOPT_CONNECTTIMEOUT=>3,
        CURLOPT_TIMEOUT=>5,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_WRITEFUNCTION=>static function ($curl, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > BRVTAL_DEVELOPMENT_MAX_BYTES) {
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);

    $ok = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($ok === false || $status < 200 || $status >= 300) {
        throw new RuntimeException('GITHUB_READ_FAILED');
    }

    return ['status'=>$status,'body'=>$body];
}

/** @return array<string,mixed> */
function brvtalDevelopmentDecode(array $response): array
{
    $decoded = json_decode((string)($response['body'] ?? ''), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('GITHUB_INVALID_RESPONSE');
    }

    return $decoded;
}

function brvtalDevelopmentNonEmptyString(mixed $value): bool
{
    return is_string($value) && trim($value) !== '';
}

/** @return array{number:int,title:string,url:string}|null */
function brvtalDevelopmentLatestPr(array $payload): ?array
{
    if (!isset($payload['items']) || !is_array($payload['items'])) {
        throw new RuntimeException('GITHUB_INVALID_RESPONSE');
    }
    if (!isset($payload['items'][0])) {
        return null;
    }

    $item = $payload['items'][0];
    if (
        !is_array($item)
        || !is_int($item['number'] ?? null)
        || $item['number'] <= 0
        || !brvtalDevelopmentNonEmptyString($item['title'] ?? null)
        || !brvtalDevelopmentNonEmptyString($item['html_url'] ?? null)
    ) {
        throw new RuntimeException('GITHUB_INVALID_RESPONSE');
    }

    return [
        'number'=>$item['number'],
        'title'=>$item['title'],
        'url'=>$item['html_url'],
    ];
}

/** @return array{status:string,conclusion:?string,url:string,updated_at:string,source_ts:int}|null */
function brvtalDevelopmentLatestCi(array $payload): ?array
{
    if (!isset($payload['workflow_runs']) || !is_array($payload['workflow_runs'])) {
        throw new RuntimeException('GITHUB_INVALID_RESPONSE');
    }
    if (!isset($payload['workflow_runs'][0])) {
        return null;
    }

    $run = $payload['workflow_runs'][0];
    $conclusion = is_array($run) && array_key_exists('conclusion', $run)
        ? $run['conclusion']
        : null;
    if (
        !is_array($run)
        || !brvtalDevelopmentNonEmptyString($run['status'] ?? null)
        || ($conclusion !== null && !is_string($conclusion))
        || !brvtalDevelopmentNonEmptyString($run['html_url'] ?? null)
        || !brvtalDevelopmentNonEmptyString($run['updated_at'] ?? null)
    ) {
        throw new RuntimeException('GITHUB_INVALID_RESPONSE');
    }

    $sourceTs = strtotime($run['updated_at']);
    if ($sourceTs === false) {
        throw new RuntimeException('GITHUB_INVALID_RESPONSE');
    }

    return [
        'status'=>$run['status'],
        'conclusion'=>$conclusion,
        'url'=>$run['html_url'],
        'updated_at'=>$run['updated_at'],
        'source_ts'=>$sourceTs,
    ];
}

/** @return array<string,mixed> */
function brvtalAdminDevelopmentSignals(?callable $requester = null): array
{
    $request = $requester ?? 'brvtalDevelopmentGithubRequest';
    $repo = BRVTAL_DEVELOPMENT_REPO;
    $base = 'https://api.github.com';

    try {
        $issuesQuery = rawurlencode(
            'repo:' . BRVTAL_DEVELOPMENT_REPO . ' is:issue is:open'
        );
        $prsQuery = rawurlencode(
            'repo:' . BRVTAL_DEVELOPMENT_REPO . ' is:pr is:open'
        );
        $issues = brvtalDevelopmentDecode(
            $request($base . '/search/issues?q=' . $issuesQuery . '&per_page=1')
        );
        $prs = brvtalDevelopmentDecode(
            $request(
                $base
                . '/search/issues?q='
                . $prsQuery
                . '&sort=updated&order=desc&per_page=1'
            )
        );
        $ci = brvtalDevelopmentDecode(
            $request(
                $base
                . '/repos/'
                . $repo
                . '/actions/workflows/update-release-metadata.yml/runs'
                . '?branch=main&per_page=1'
            )
        );

        $issueCount = $issues['total_count'] ?? null;
        $prCount = $prs['total_count'] ?? null;
        if (
            !is_int($issueCount)
            || $issueCount < 0
            || !is_int($prCount)
            || $prCount < 0
        ) {
            throw new RuntimeException('GITHUB_INVALID_RESPONSE');
        }

        $latestPr = brvtalDevelopmentLatestPr($prs);
        $latestRun = brvtalDevelopmentLatestCi($ci);
        $sourceAt = $latestRun['updated_at'] ?? null;
        $freshness = 'unavailable';
        if ($latestRun !== null) {
            $freshness = (time() - $latestRun['source_ts']) > BRVTAL_DEVELOPMENT_STALE_SECONDS
                ? 'stale'
                : 'fresh';
        }

        return [
            'status'=>'available',
            'freshness'=>$freshness,
            'open_issues'=>$issueCount,
            'open_prs'=>$prCount,
            'latest_pr'=>$latestPr,
            'latest_ci'=>$latestRun ? [
                'status'=>$latestRun['status'],
                'conclusion'=>$latestRun['conclusion'],
                'url'=>$latestRun['url'],
            ] : null,
            'source_at'=>$sourceAt,
            'read_only'=>true,
        ];
    } catch (Throwable) {
        return [
            'status'=>'unavailable',
            'freshness'=>'unavailable',
            'open_issues'=>null,
            'open_prs'=>null,
            'latest_pr'=>null,
            'latest_ci'=>null,
            'source_at'=>null,
            'read_only'=>true,
        ];
    }
}
