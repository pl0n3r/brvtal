<?php
declare(strict_types=1);

const BRVTAL_SONAR_PROJECT = 'pl0n3r_brvtal';
const BRVTAL_SONAR_BASE = 'https://sonarcloud.io';
const BRVTAL_SONAR_DASHBOARD = 'https://sonarcloud.io/project/overview?id=pl0n3r_brvtal';
const BRVTAL_SONAR_MAX_BYTES = 262144;
const BRVTAL_SONAR_STALE_SECONDS = 86400;

/** @return array{status:int,body:string} */
function brvtalSonarRequest(string $url): array
{
    if (!str_starts_with($url, BRVTAL_SONAR_BASE . '/api/')) {
        throw new RuntimeException('SONAR_URL_NOT_ALLOWED');
    }
    if (!function_exists('curl_init')) {
        throw new RuntimeException('SONAR_TRANSPORT_UNAVAILABLE');
    }

    $headers = ['Accept: application/json', 'User-Agent: BRVTAL-DISCADMIN'];
    $token = trim((string)(getenv('BRVTAL_SONAR_TOKEN') ?: ''));
    if ($token !== '') {
        $headers[] = 'Authorization: Bearer ' . $token;
    }

    $body = '';
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_HTTPHEADER=>$headers,
        CURLOPT_RETURNTRANSFER=>false,
        CURLOPT_CONNECTTIMEOUT=>2,
        CURLOPT_TIMEOUT=>4,
        CURLOPT_FOLLOWLOCATION=>false,
        CURLOPT_WRITEFUNCTION=>static function ($curl, string $chunk) use (&$body): int {
            if (strlen($body) + strlen($chunk) > BRVTAL_SONAR_MAX_BYTES) {
                return 0;
            }
            $body .= $chunk;
            return strlen($chunk);
        },
    ]);

    $ok = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    if ($ok === false || $status < 200 || $status >= 300) {
        throw new RuntimeException('SONAR_READ_FAILED');
    }

    return ['status'=>$status, 'body'=>$body];
}

/** @return array<string,mixed> */
function brvtalSonarDecode(array $response): array
{
    $decoded = json_decode((string)($response['body'] ?? ''), true);
    if (!is_array($decoded)) {
        throw new RuntimeException('SONAR_INVALID_RESPONSE');
    }
    return $decoded;
}

function brvtalSonarPagingTotal(array $payload): int
{
    $total = $payload['paging']['total'] ?? null;
    if (!is_int($total) || $total < 0) {
        throw new RuntimeException('SONAR_INVALID_RESPONSE');
    }
    return $total;
}

/** @return array{quality_gate:string,raw_status:string} */
function brvtalSonarQualityGate(array $payload): array
{
    $status = $payload['projectStatus']['status'] ?? null;
    if (!is_string($status) || !in_array($status, ['OK', 'ERROR', 'WARN', 'NONE'], true)) {
        throw new RuntimeException('SONAR_INVALID_RESPONSE');
    }

    return [
        'quality_gate'=>match ($status) {
            'OK'=>'passed',
            'ERROR'=>'failed',
            'WARN'=>'warning',
            default=>'unknown',
        },
        'raw_status'=>$status,
    ];
}

/** @return array{date:string,source_ts:int} */
function brvtalSonarLatestAnalysis(array $payload): array
{
    $date = $payload['analyses'][0]['date'] ?? null;
    if (!is_string($date) || trim($date) === '') {
        throw new RuntimeException('SONAR_INVALID_RESPONSE');
    }
    $sourceTs = strtotime($date);
    if ($sourceTs === false) {
        throw new RuntimeException('SONAR_INVALID_RESPONSE');
    }
    return ['date'=>$date, 'source_ts'=>$sourceTs];
}

/** @return array<string,mixed> */
function brvtalAdminSonarSignals(?callable $requester = null): array
{
    $request = $requester ?? 'brvtalSonarRequest';
    $base = BRVTAL_SONAR_BASE . '/api';
    $project = rawurlencode(BRVTAL_SONAR_PROJECT);

    try {
        $gate = brvtalSonarQualityGate(brvtalSonarDecode(
            $request($base . '/qualitygates/project_status?projectKey=' . $project)
        ));
        $issues = brvtalSonarPagingTotal(brvtalSonarDecode(
            $request($base . '/issues/search?componentKeys=' . $project . '&resolved=false&sinceLeakPeriod=true&ps=1')
        ));
        $hotspots = brvtalSonarPagingTotal(brvtalSonarDecode(
            $request($base . '/hotspots/search?projectKey=' . $project . '&status=TO_REVIEW&ps=1')
        ));
        $analysis = brvtalSonarLatestAnalysis(brvtalSonarDecode(
            $request($base . '/project_analyses/search?project=' . $project . '&ps=1')
        ));
        $freshness = (time() - $analysis['source_ts']) > BRVTAL_SONAR_STALE_SECONDS
            ? 'stale'
            : 'fresh';

        return [
            'status'=>'available',
            'freshness'=>$freshness,
            'quality_gate'=>$gate['quality_gate'],
            'raw_status'=>$gate['raw_status'],
            'new_issues'=>$issues,
            'security_hotspots'=>$hotspots,
            'source_at'=>$analysis['date'],
            'dashboard_url'=>BRVTAL_SONAR_DASHBOARD,
            'read_only'=>true,
        ];
    } catch (Throwable) {
        return [
            'status'=>'unavailable',
            'freshness'=>'unavailable',
            'quality_gate'=>null,
            'raw_status'=>null,
            'new_issues'=>null,
            'security_hotspots'=>null,
            'source_at'=>null,
            'dashboard_url'=>BRVTAL_SONAR_DASHBOARD,
            'read_only'=>true,
        ];
    }
}
