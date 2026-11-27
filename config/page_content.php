<?php
declare(strict_types=1);

require_once __DIR__ . '/form_schema.php';
require_once __DIR__ . '/public_text_format.php';

function brvtal_page_content_json_error(mixed $value): ?string
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }

    $decoded = json_decode($raw, true);
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
        return 'INVALID_PAGE_CONTENT_JSON';
    }

    return null;
}

function brvtal_page_content_block_text(mixed $block): ?string
{
    if (!is_array($block) || !is_string($block['type'] ?? null)) {
        return null;
    }

    $type = strtolower(trim((string)($block['type'] ?? '')));
    if (!in_array($type, ['text', 'paragraph', 'heading'], true)) {
        return null;
    }

    foreach (['content', 'text', 'body'] as $field) {
        if (array_key_exists($field, $block) && is_string($block[$field])) {
            return trim($block[$field]);
        }
    }

    return null;
}

function brvtal_page_form_id(mixed $block): ?string
{
    return is_array($block) && count($block) === 2 && ($block['type'] ?? null) === 'form_ref'
        && is_string($block['public_id'] ?? null) && preg_match('/\A[a-f0-9]{32}\z/D', $block['public_id'])
        ? $block['public_id'] : null;
}
/** Only immutable public copy is returned; no draft, policy keys, IDs or delivery configuration. */
function brvtal_page_form_resolve(PDO $pdo, string $id): ?array
{
    if (!preg_match('/\A[a-f0-9]{32}\z/D', $id)) return null;
    try {
        $query = $pdo->prepare("SELECT r.form_key,r.published_version_number,v.definition_json,
            v.definition_sha256,p.notice_version,p.privacy_policy_json,p.delivery_policy_json
            FROM form_definition_registry r
            JOIN form_definition_versions v ON v.form_key=r.form_key AND v.version_number=r.published_version_number
            JOIN form_publication_policies p ON p.form_key=v.form_key AND p.version_number=v.version_number
                AND p.definition_sha256=v.definition_sha256
            WHERE r.public_id=UNHEX(?) AND r.state='active'" . ($pdo->inTransaction() ? ' FOR UPDATE' : ''));
        $query->execute([$id]);
        $row = $query->fetch(PDO::FETCH_ASSOC);
        if (!$row || !hash_equals($row['definition_sha256'], hash('sha256', $row['definition_json'], true))) {
            return null;
        }
        $definition = json_decode($row['definition_json'], true, 16, JSON_THROW_ON_ERROR);
        $fields = brvtalFormSchemaValidate($definition);
        $policy = json_decode($row['privacy_policy_json'], true, 16, JSON_THROW_ON_ERROR);
        $delivery = json_decode($row['delivery_policy_json'], true, 16, JSON_THROW_ON_ERROR);
        if ($definition['form_key'] !== $row['form_key']
            || $definition['version_number'] !== (int)$row['published_version_number']
            || !is_array($policy) || count($policy) !== 5
            || !brvtalFormSchemaCopy($policy['form_title'] ?? null, 120)
            || !brvtalFormSchemaCopy($policy['notice_text'] ?? null, 4000)
            || ($policy['notice_version'] ?? null) !== $row['notice_version']
            || !is_string($policy['purpose_key'] ?? null) || !preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/D', $policy['purpose_key'])
            || !is_string($policy['retention_policy_key'] ?? null) || !preg_match('/\A[a-z][a-z0-9_.-]{0,63}\z/D', $policy['retention_policy_key'])
            || !in_array($delivery, [['actions'=>[]], ['actions'=>['form_entry_received']]], true)) {
            return null;
        }
        $consent = array_filter($fields, static fn(array $field): bool => $field['type'] === 'consent'
            && $field['purpose'] === 'privacy' && $field['notice_version'] === $row['notice_version']);
        if (count($consent) !== 1) return null;
        return ['title'=>$policy['form_title'], 'fields'=>array_values(array_map(
            static fn(array $field): array => ['label'=>$field['label'],'help'=>$field['help'] ?? '',
                'options'=>array_column($field['options'] ?? [], 'label')], $fields
        ))];
    } catch (Throwable) {
        return null;
    }
}
function brvtal_page_form_html(?array $form): string
{
    if ($form === null) {
        return '<section data-form-ref="unavailable"><p>Formulario no disponible.</p></section>';
    }
    $escape = static fn(string $value): string => htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $html = '<section data-form-ref="preview"><h2>' . $escape($form['title']) . '</h2>'
        . '<p>Recepción no disponible.</p><ul>';
    foreach ($form['fields'] as $field) {
        $copy = $field['label'] . (!empty($field['help']) ? ' — ' . $field['help'] : '');
        $options = $field['options'] ?? [];
        $html .= '<li>' . $escape($copy . ($options ? ' (' . implode(' · ', $options) . ')' : '')) . '</li>';
    }
    return $html . '</ul></section>';
}
/** Render in source order. Forms are descriptive, never interactive, regardless of configuration. */
function brvtal_page_content_html(mixed $value, callable $resolve): string
{
    $decoded = json_decode((string)$value, true);
    if (!is_array($decoded)) {
        return '';
    }
    if (!array_is_list($decoded)) {
        foreach (['body', 'content', 'text'] as $field) {
            if (is_string($decoded[$field] ?? null)) {
                return brvtalPublicTextFormat($decoded[$field]);
            }
        }
        $decoded = $decoded['blocks'] ?? [];
    }
    if (!is_array($decoded)) {
        return '';
    }
    $html = '';
    foreach ($decoded as $block) {
        $text = brvtal_page_content_block_text($block);
        if ($text !== null) {
            $html .= ($block['type'] ?? '') === 'heading' && $text !== ''
                ? '<h2>' . brvtalPublicTextInline($text) . '</h2>' : brvtalPublicTextFormat($text);
        } elseif (is_array($block) && ($block['type'] ?? null) === 'form_ref') {
            $id = brvtal_page_form_id($block);
            $html .= brvtal_page_form_html($id === null ? null : $resolve($id));
        }
    }
    return $html;
}
function brvtal_page_content_structure_error(mixed $value, ?PDO $pdo = null): ?string
{
    $raw = trim((string)$value);
    if ($raw === '') {
        return null;
    }

    $jsonError = brvtal_page_content_json_error($raw);
    if ($jsonError !== null) {
        return $jsonError;
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || $decoded === []) {
        return null;
    }

    if (!array_is_list($decoded)) {
        foreach (['body', 'content', 'text'] as $field) {
            if (array_key_exists($field, $decoded) && is_string($decoded[$field])) {
                return array_key_exists('blocks', $decoded) ? 'PAGE_CONTENT_STRUCTURE_UNSUPPORTED' : null;
            }
        }

        if (!array_key_exists('blocks', $decoded) || !is_array($decoded['blocks'])) {
            return 'PAGE_CONTENT_STRUCTURE_UNSUPPORTED';
        }
        $blocks = $decoded['blocks'];
    } else {
        $blocks = $decoded;
    }

    foreach ($blocks as $block) {
        if (brvtal_page_content_block_text($block) !== null) {
            continue;
        }
        $id = brvtal_page_form_id($block);
        if ($id === null) {
            return 'PAGE_CONTENT_STRUCTURE_UNSUPPORTED';
        }
        if ($pdo === null || brvtal_page_form_resolve($pdo, $id) === null) {
            return 'PAGE_FORM_REF_UNAVAILABLE';
        }
    }

    return null;
}

function brvtal_page_content_plain_text(mixed $value): string
{
    $raw = trim((string)$value);
    if ($raw === '' || brvtal_page_content_json_error($raw) !== null) {
        return '';
    }

    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || $decoded === []) {
        return '';
    }

    if (!array_is_list($decoded)) {
        foreach (['body', 'content', 'text'] as $field) {
            if (array_key_exists($field, $decoded) && is_string($decoded[$field])) {
                return trim($decoded[$field]);
            }
        }
        $blocks = isset($decoded['blocks']) && is_array($decoded['blocks']) ? $decoded['blocks'] : [];
    } else {
        $blocks = $decoded;
    }

    $parts = [];
    foreach ($blocks as $block) {
        $text = brvtal_page_content_block_text($block);
        if ($text !== null && $text !== '') {
            $parts[] = $text;
        }
    }

    return implode("\n\n", $parts);
}
