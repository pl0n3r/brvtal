<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/hero_slider_integrity.php';

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "Hero Slider integrity contract failed: {$message}\n");
        exit(1);
    }
};

$assets = [
    '/uploads/hero/published.jpg' => ['type'=>'image','status'=>'published','exists'=>true],
    '/uploads/hero/draft.jpg' => ['type'=>'image','status'=>'draft','exists'=>true],
    '/uploads/hero/missing-file.jpg' => ['type'=>'image','status'=>'published','exists'=>false],
    '/uploads/hero/published.mp4' => ['type'=>'video','status'=>'published','exists'=>true],
];
$resolver = static fn(string $path): ?array => $assets[$path] ?? null;
$config = static fn(array $slide): array => ['enabled'=>true,'slides'=>[$slide]];

$error = brvtal_hero_slider_config_error($config([
    'enabled'=>true,'mediaType'=>'image','desktopSrc'=>'',
]), $resolver);
$assert($error === ['error'=>'HERO_SLIDER_MEDIA_REQUIRED','field'=>'slides.0.desktopSrc'], 'enabled slide must require desktop media');

$error = brvtal_hero_slider_config_error($config([
    'enabled'=>true,'mediaType'=>'image','desktopSrc'=>'/uploads/hero/unknown.jpg',
]), $resolver);
$assert($error === ['error'=>'HERO_SLIDER_MEDIA_NOT_REGISTERED','field'=>'slides.0.desktopSrc'], 'unknown local media must fail closed');

$error = brvtal_hero_slider_config_error($config([
    'enabled'=>true,'mediaType'=>'image','desktopSrc'=>'/uploads/hero/draft.jpg',
]), $resolver);
$assert($error === ['error'=>'HERO_SLIDER_MEDIA_NOT_PUBLISHED','field'=>'slides.0.desktopSrc'], 'draft media must not back enabled slides');

$error = brvtal_hero_slider_config_error($config([
    'enabled'=>true,'mediaType'=>'image','desktopSrc'=>'/uploads/hero/missing-file.jpg',
]), $resolver);
$assert($error === ['error'=>'HERO_SLIDER_MEDIA_FILE_MISSING','field'=>'slides.0.desktopSrc'], 'missing local file must fail');

$error = brvtal_hero_slider_config_error($config([
    'enabled'=>true,'mediaType'=>'video','desktopSrc'=>'/uploads/hero/published.jpg',
]), $resolver);
$assert($error === ['error'=>'HERO_SLIDER_MEDIA_TYPE_MISMATCH','field'=>'slides.0.desktopSrc'], 'slide media type must match Media Library type');

$valid = brvtal_hero_slider_config_error($config([
    'enabled'=>true,
    'mediaType'=>'image',
    'desktopSrc'=>'/uploads/hero/published.jpg',
    'mobileSrc'=>'https://cdn.example.test/mobile.jpg',
    'layers'=>[
        ['type'=>'image','src'=>'/uploads/hero/published.jpg','mobileSrc'=>'https://cdn.example.test/layer.jpg'],
        ['type'=>'text','text'=>'RAVE TILL GRAVE'],
    ],
]), $resolver);
$assert($valid === null, 'published local media plus explicit HTTPS overrides must pass');

$external = brvtal_hero_slider_config_error($config([
    'enabled'=>true,'mediaType'=>'video','desktopSrc'=>'https://cdn.example.test/hero.mp4','poster'=>'https://cdn.example.test/poster.jpg',
]), $resolver);
$assert($external === null, 'HTTPS external media must be explicit and accepted');

$error = brvtal_hero_slider_config_error($config([
    'enabled'=>true,'mediaType'=>'image','desktopSrc'=>'http://cdn.example.test/hero.jpg',
]), $resolver);
$assert($error === ['error'=>'HERO_SLIDER_MEDIA_REFERENCE_INVALID','field'=>'slides.0.desktopSrc'], 'HTTP external media must be rejected');

$error = brvtal_hero_slider_config_error($config([
    'enabled'=>true,'mediaType'=>'image','desktopSrc'=>'/uploads/../secret.jpg',
]), $resolver);
$assert($error === ['error'=>'HERO_SLIDER_MEDIA_REFERENCE_INVALID','field'=>'slides.0.desktopSrc'], 'path traversal must be rejected');

$error = brvtal_hero_slider_config_error($config([
    'enabled'=>true,
    'mediaType'=>'video',
    'desktopSrc'=>'/uploads/hero/published.mp4',
    'poster'=>'/uploads/hero/published.mp4',
]), $resolver);
$assert($error === ['error'=>'HERO_SLIDER_MEDIA_TYPE_MISMATCH','field'=>'slides.0.poster'], 'video poster must be an image');

$error = brvtal_hero_slider_config_error($config([
    'enabled'=>true,
    'mediaType'=>'image',
    'desktopSrc'=>'/uploads/hero/published.jpg',
    'layers'=>[['type'=>'logo','src'=>'/uploads/hero/unknown.jpg']],
]), $resolver);
$assert($error === ['error'=>'HERO_SLIDER_MEDIA_NOT_REGISTERED','field'=>'slides.0.layers.0.src'], 'provided visual layer media must be valid');

$disabled = brvtal_hero_slider_config_error(['enabled'=>false,'slides'=>[[
    'enabled'=>false,'mediaType'=>'image','desktopSrc'=>'/uploads/hero/missing.jpg',
]]], $resolver);
$assert($disabled === null, 'disabled slides may retain incomplete draft media');

fwrite(STDOUT, "Hero Slider integrity contract passed.\n");
