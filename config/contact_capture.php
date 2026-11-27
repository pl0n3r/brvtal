<?php
declare(strict_types=1);

/**
 * Contact capture policy approved by the owner on 2026-11-27 (#2321, decision on #1671).
 * Used only when config/config.php does not define contact.capture, so the server
 * config can still switch capture off ('enabled' => false) without a deploy.
 */
return [
    'enabled' => true,
    'privacy_approved' => true,
    'notice_version' => 'owner-2026-11-27',
    'retention_policy_key' => 'contact_capture_24m',
    'retention_days' => 730,
];
