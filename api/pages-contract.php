<?php
declare(strict_types=1);
// Rector probe #686; removed before merge.

require_once __DIR__ . '/../config/page_content.php';

function brvtal_page_publication_error(array $state): ?string
{
    if (($state['status'] ?? 'draft') !== 'published') {
        return null;
    }

    if (($state['locale'] ?? 'en') !== 'en') {
        return 'PAGE_PUBLIC_LOCALE_MUST_BE_EN';
    }

    return brvtal_page_content_structure_error($state['content_json'] ?? '');
}
