<?php
declare(strict_types=1);

function admin_quick_win_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "ADMIN RELIABILITY QUICK WINS CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$index = (string)file_get_contents(__DIR__ . '/../discadmin/index.php');
$auth = (string)file_get_contents(__DIR__ . '/../discadmin/admin-auth-boundary.js');
$hero = (string)file_get_contents(__DIR__ . '/../discadmin/hero-slider-accessibility.js');
$storage = (string)file_get_contents(__DIR__ . '/../discadmin/system-status-storage.js');
$backups = (string)file_get_contents(__DIR__ . '/../discadmin/backups.js');

admin_quick_win_assert(
    str_contains($index, '/discadmin/admin-auth-boundary.js')
        && str_contains($index, '/discadmin/hero-slider-accessibility.js'),
    'DISCADMIN must load both shared reliability layers'
);

admin_quick_win_assert(
    str_contains($auth, 'response.status === 401')
        && str_contains($auth, "url.pathname.startsWith('/api/')")
        && str_contains($auth, "url.pathname.startsWith('/discadmin/')")
        && str_contains($auth, 'currentState.authed = false'),
    'same-origin admin 401 responses must invalidate the shell session'
);

admin_quick_win_assert(
    str_contains($hero, "'aria-keyshortcuts', 'Alt+ArrowUp Alt+ArrowDown'")
        && str_contains($hero, "event.key !== 'ArrowUp'")
        && str_contains($hero, "event.key !== 'ArrowDown'")
        && str_contains($hero, "event.key === 'ArrowUp' ? 'up' : 'down'")
        && str_contains($hero, 'control.click()'),
    'Hero Slider reorder must expose an equivalent keyboard interaction'
);

admin_quick_win_assert(
    str_contains($storage, "panel.dataset.storageScope = 'unavailable'")
        && str_contains($storage, 'MANAGED STORAGE UNAVAILABLE')
        && str_contains($storage, 'lastFetchAt = Date.now();'),
    'managed storage failures must render an explicit throttled unavailable state'
);

admin_quick_win_assert(
    !str_contains($backups, 'items.slice(0, 8)')
        && str_contains($backups, 'return items.map(item =>'),
    'Backups UI must render the full retained collection'
);

echo "BRVTAL admin reliability quick-win contract tests passed.\n";
