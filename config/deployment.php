<?php
declare(strict_types=1);

require_once __DIR__ . '/version.php';

function brvtal_deployment_sha(): string
{
    static $resolved = null;
    if (is_string($resolved)) {
        return $resolved;
    }

    $environment = trim((string)getenv('BRVTAL_DEPLOY_COMMIT'));
    if (preg_match('/^[a-f0-9]{40}$/i', $environment)) {
        return $resolved = strtolower($environment);
    }

    $gitDirectory = dirname(__DIR__) . '/.git';
    $head = @file_get_contents($gitDirectory . '/HEAD');
    if (is_string($head)) {
        $head = trim($head);
        if (preg_match('/^ref:\s+(.+)$/', $head, $match)) {
            $reference = $gitDirectory . '/' . ltrim($match[1], '/');
            $head = trim((string)@file_get_contents($reference));
            if (!preg_match('/^[a-f0-9]{40}$/i', $head)) {
                $packed = @file($gitDirectory . '/packed-refs', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
                foreach ($packed as $line) {
                    if ($line === '' || $line[0] === '#' || $line[0] === '^') {
                        continue;
                    }
                    [$sha, $ref] = array_pad(preg_split('/\s+/', trim($line), 2) ?: [], 2, '');
                    if ($ref === $match[1] && preg_match('/^[a-f0-9]{40}$/i', $sha)) {
                        $head = $sha;
                        break;
                    }
                }
            }
        }
        if (preg_match('/^[a-f0-9]{40}$/i', $head)) {
            return $resolved = strtolower($head);
        }
    }

    return $resolved = strtolower(BRVTAL_APP_BUILD);
}

function brvtal_deployment_short_sha(): string
{
    return substr(brvtal_deployment_sha(), 0, 7);
}

function brvtal_deployment_source(): string
{
    $environment = trim((string)getenv('BRVTAL_DEPLOY_COMMIT'));
    if (preg_match('/^[a-f0-9]{40}$/i', $environment)) {
        return 'environment';
    }

    $gitDirectory = dirname(__DIR__) . '/.git';
    $head = trim((string)@file_get_contents($gitDirectory . '/HEAD'));
    if (preg_match('/^[a-f0-9]{40}$/i', $head)) {
        return 'git_checkout';
    }

    if (preg_match('/^ref:\s+(.+)$/', $head, $match)) {
        $reference = trim((string)@file_get_contents($gitDirectory . '/' . ltrim($match[1], '/')));
        if (preg_match('/^[a-f0-9]{40}$/i', $reference)) {
            return 'git_checkout';
        }

        $packed = @file($gitDirectory . '/packed-refs', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
        foreach ($packed as $line) {
            if ($line === '' || $line[0] === '#' || $line[0] === '^') {
                continue;
            }
            [$sha, $ref] = array_pad(preg_split('/\s+/', trim($line), 2) ?: [], 2, '');
            if ($ref === $match[1] && preg_match('/^[a-f0-9]{40}$/i', $sha)) {
                return 'git_checkout';
            }
        }
    }

    return 'release_fallback';
}

function brvtalDeploymentIsExact(): bool
{
    return brvtal_deployment_source() !== 'release_fallback';
}

function brvtalReleaseIdentity(): string
{
    return 'v' . BRVTAL_APP_VERSION;
}

function brvtalDeploymentCacheKey(): string
{
    if (brvtalDeploymentIsExact()) {
        return brvtal_deployment_short_sha();
    }

    $version = preg_replace('/[^0-9A-Za-z._-]+/', '-', BRVTAL_APP_VERSION) ?? BRVTAL_APP_VERSION;
    return 'release-' . trim($version, '-');
}
