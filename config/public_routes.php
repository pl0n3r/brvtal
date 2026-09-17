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
        'pages' => brvtal_public_route_definition(
            'pages',
            'title',
            'content_json',
            '',
            'WebPage',
            "status='published' AND locale='en'"
        ),
    ];
}

/** @return list<string> */
function brvtal_public_static_routes(): array
{
    return ['/', '/contact'];
}
