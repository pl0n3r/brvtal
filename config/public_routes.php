<?php
declare(strict_types=1);

require_once __DIR__ . '/public_visibility.php';

/**
 * Build one canonical public content-family definition.
 *
 * @param list<string> $parameters
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
    string $where,
    string $schemaType,
    array $parameters = [],
    bool $eventVisibility = false
): array {
    return [
        'table' => $table,
        'title_field' => $titleField,
        'description_field' => $descriptionField,
        'image_field' => $imageField,
        'where' => $where,
        'schema_type' => $schemaType,
        'parameters' => $parameters,
        'event_visibility' => $eventVisibility,
    ];
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
function brvtal_public_content_definitions(): array
{
    $eventStatuses = brvtal_public_visible_event_statuses();
    $eventWhere = 'status IN (' . brvtal_public_sql_placeholders($eventStatuses) . ')';
    $published = "status='published'";

    return [
        'events' => brvtal_public_route_definition(
            'events', 'title', 'description', 'cover_image', $eventWhere, 'MusicEvent', $eventStatuses, true
        ),
        'artists' => brvtal_public_route_definition(
            'artists', 'name', 'bio', 'photo', $published, 'MusicGroup'
        ),
        'sets' => brvtal_public_route_definition(
            'sets_media', 'title', 'description', 'cover_image', $published, 'MusicRecording'
        ),
        'releases' => brvtal_public_route_definition(
            'releases', 'title', 'description', 'artwork', $published, 'MusicAlbum'
        ),
        'blog' => brvtal_public_route_definition(
            'blog_posts', 'title', 'excerpt', 'cover_image', $published, 'BlogPosting'
        ),
        'pages' => brvtal_public_route_definition(
            'pages', 'title', 'content_json', '', "{$published} AND locale='en'", 'WebPage'
        ),
    ];
}

/** @return list<string> */
function brvtal_public_static_routes(): array
{
    return ['/', '/contact'];
}
