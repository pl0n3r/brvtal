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

        if (
            !isset($issues['total_count'], $prs['total_count'])
            || !isset($ci['workflow_runs'])
            || !is_array($ci['workflow_runs'])
        ) {
            throw new RuntimeException('GITHUB_INVALID_RESPONSE');
        }

        $latestPr = (
            is_array($prs['items'] ?? null)
            && isset($prs['items'][0])
            && is_array($prs['items'][0])
        ) ? $prs['items'][0] : null;
        $latestRun = (
            isset($ci['workflow_runs'][0])
            && is_array($ci['workflow_runs'][0])
        ) ? $ci['workflow_runs'][0] : null;
        $sourceAt = is_string($latestRun['updated_at'] ?? null)
            ? $latestRun['updated_at']
            : gmdate('c');
        $sourceTs = strtotime($sourceAt);
        $freshness = (
            $sourceTs !== false
            && (time() - $sourceTs) > BRVTAL_DEVELOPMENT_STALE_SECONDS
        ) ? 'stale' : 'fresh';

        return [
            'status'=>'available',
            'freshness'=>$freshness,
            'open_issues'=>(int)$issues['total_count'],
            'open_prs'=>(int)$prs['total_count'],
            'latest_pr'=>$latestPr ? [
                'number'=>(int)($latestPr['number'] ?? 0),
                'title'=>(string)($latestPr['title'] ?? ''),
                'url'=>(string)($latestPr['html_url'] ?? ''),
            ] : null,
            'latest_ci'=>$latestRun ? [
                'status'=>(string)($latestRun['status'] ?? ''),
                'conclusion'=>$latestRun['conclusion'] !== null
                    ? (string)$latestRun['conclusion']
                    : null,
                'url'=>(string)($latestRun['html_url'] ?? ''),
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
            'source_at'=>gmdate('c'),
            'read_only'=>true,
        ];
    }
}
