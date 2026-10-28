<?php
declare(strict_types=1);

require_once __DIR__ . '/public_visibility.php';

/**
 * Build one standard public content-family definition.
 *
 * @return array{
 *   table:string,
 *   title_field:string,
 *   description_field:string,
 *   image_field:string,
 *   where:string,
 *   schema_type:string,
 *   parameters:list<string>,
 *   event_visibility:bool
 * }
 */
function brvtal_public_route_definition(
    string $table,
    string $titleField,
    string $descriptionField,
    string $imageField,
    string $schemaType,
    string $where = "status='published'"
): array {
    return [
        'table' => $table,
        'title_field' => $titleField,
        'description_field' => $descriptionField,
        'image_field' => $imageField,
        'where' => $where,
        'schema_type' => $schemaType,
        'parameters' => [],
        'event_visibility' => false,
    ];
}

/**
 * Default route policy is unchanged. With the operator's one-slug opt-in,
 * only the selected page may resolve to its Spanish canonical source.
 * SQL values remain bound, never concatenated from environment input.
 *
 * @param null|callable(string):(?string) $environment
 */
function brvtal_public_trial_page_definition(?callable $environment = null): array
{
    $selected = brvtal_public_trial_spanish_page_slug($environment);
    if ($selected === null) {
        return brvtal_public_route_definition(
            'pages', 'title', 'content_json', '', 'WebPage',
            "status='published' AND locale='en'"
        );
    }

    $definition = brvtal_public_route_definition(
        'pages', 'title', 'content_json', '', 'WebPage',
        "status='published' AND ((locale='en' AND slug<>?) OR (locale='es' AND slug=?))"
    );
    $definition['parameters'] = [$selected, $selected];
    return $definition;
}

/**
 * Canonical registry for indexable public content families.
 *
 * @return array<string,array{
 *   table:string,
 *   title_field:string,
 *   description_field:string,
 *   image_field:string,
 *   where:string,
 *   schema_type:string,
 *   parameters:list<string>,
 *   event_visibility:bool
 * }>
 */
function brvtal_public_content_definitions(?callable $environment = null): array
{
    $eventStatuses = brvtal_public_visible_event_statuses();
    $eventWhere = 'status IN (' . brvtal_public_sql_placeholders($eventStatuses) . ')';
    $event = brvtal_public_route_definition(
        'events',
        'title',
        'description',
        'cover_image',
        'MusicEvent',
        $eventWhere
    );
    $event['parameters'] = $eventStatuses;
    $event['event_visibility'] = true;

    return [
        'events' => $event,
        'artists' => brvtal_public_route_definition(
            'artists',
            'name',
            'bio',
            'photo',
            'MusicGroup'
        ),
        'sets' => brvtal_public_route_definition(
            'sets_media',
            'title',
            'description',
            'cover_image',
            'MusicRecording'
        ),
        'releases' => brvtal_public_route_definition(
            'releases',
            'title',
            'description',
            'artwork',
            'MusicAlbum'
        ),
        'blog' => brvtal_public_route_definition(
            'blog_posts',
            'title',
            'excerpt',
            'cover_image',
            'BlogPosting'
        ),
        'pages' => brvtal_public_trial_page_definition($environment),
    ];
}

/** @return list<string> */
function brvtal_public_static_routes(): array
{
    return ['/', '/contact'];
}
