<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/page_content.php';

function brvtal_page_publication_error(array $state, ?PDO $pdo = null): ?string
{
    if (($state['status'] ?? 'draft') !== 'published') {
        return null;
    }

    $content = json_decode((string)($state['content_json'] ?? ''), true);
    $blocks = $content['blocks'] ?? $content;
    $form = is_array($blocks) && in_array('form_ref', array_column($blocks, 'type'), true);
    if (($state['locale'] ?? 'en') !== ($form ? 'es' : 'en')) {
        return $form ? 'PAGE_FORM_REF_LOCALE_MUST_BE_ES' : 'PAGE_PUBLIC_LOCALE_MUST_BE_EN';
    }

    return brvtal_page_content_structure_error($state['content_json'] ?? '', $pdo);
}
