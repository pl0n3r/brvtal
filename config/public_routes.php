<?php
declare(strict_types=1);

require_once __DIR__ . '/public_visibility.php';

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

    return [
        'events' => [
            'table' => 'events',
            'title_field' => 'title',
            'description_field' => 'description',
            'image_field' => 'cover_image',
            'where' => $eventWhere,
            'schema_type' => 'MusicEvent',
            'parameters' => $eventStatuses,
            'event_visibility' => true,
        ],
        'artists' => [
            'table' => 'artists',
            'title_field' => 'name',
            'description_field' => 'bio',
            'image_field' => 'photo',
            'where' => "status='published'",
            'schema_type' => 'MusicGroup',
            'parameters' => [],
            'event_visibility' => false,
        ],
        'sets' => [
            'table' => 'sets_media',
            'title_field' => 'title',
            'description_field' => 'description',
            'image_field' => 'cover_image',
            'where' => "status='published'",
            'schema_type' => 'MusicRecording',
            'parameters' => [],
            'event_visibility' => false,
        ],
        'releases' => [
            'table' => 'releases',
            'title_field' => 'title',
            'description_field' => 'description',
            'image_field' => 'artwork',
            'where' => "status='published'",
            'schema_type' => 'MusicAlbum',
            'parameters' => [],
            'event_visibility' => false,
        ],
        'blog' => [
            'table' => 'blog_posts',
            'title_field' => 'title',
            'description_field' => 'excerpt',
            'image_field' => 'cover_image',
            'where' => "status='published'",
            'schema_type' => 'BlogPosting',
            'parameters' => [],
            'event_visibility' => false,
        ],
        'pages' => [
            'table' => 'pages',
            'title_field' => 'title',
            'description_field' => 'content_json',
            'image_field' => '',
            'where' => "status='published' AND locale='en'",
            'schema_type' => 'WebPage',
            'parameters' => [],
            'event_visibility' => false,
        ],
    ];
}

/** @return list<string> */
function brvtal_public_static_routes(): array
{
    return ['/', '/contact'];
}
