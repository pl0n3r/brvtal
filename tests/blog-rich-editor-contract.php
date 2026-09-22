<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/blog_html.php';

function rich_blog_assert(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "BLOG RICH EDITOR CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

rich_blog_assert(class_exists('DOMDocument'), 'DOM extension is required for safe rich Blog HTML');

$unsafe = <<<'HTML'
<div class="editor-spam">
  <script>alert(1)</script>
  <p onclick="alert(2)" style="color:red;text-align:center">
    Safe <strong>copy</strong>
    <a href="javascript:alert(3)" onmouseover="alert(4)">bad link</a>
  </p>
  <iframe src="https://example.com"></iframe>
</div>
HTML;

$result = brvtal_blog_sanitize_html($unsafe);
$clean = $result['html'];
$warnings = $result['warnings'];

rich_blog_assert(!str_contains(strtolower($clean), '<script'), 'nested scripts must be removed even inside an unwrapped container');
rich_blog_assert(!str_contains(strtolower($clean), '<iframe'), 'unsafe embeds must be removed');
rich_blog_assert(!str_contains(strtolower($clean), 'onclick='), 'event handlers must be removed');
rich_blog_assert(!str_contains(strtolower($clean), 'onmouseover='), 'nested event handlers must be removed');
rich_blog_assert(!str_contains(strtolower($clean), 'javascript:'), 'javascript URLs must be removed');
rich_blog_assert(!str_contains(strtolower($clean), '<div'), 'unsupported harmless wrappers must be unwrapped');
rich_blog_assert(str_contains($clean, '<p style="text-align:center">'), 'supported text alignment must survive sanitation');
rich_blog_assert(str_contains($clean, '<strong>copy</strong>'), 'semantic inline formatting must survive sanitation');
rich_blog_assert($warnings !== [], 'destructive sanitation must return visible warnings');

$link = brvtal_blog_sanitize_html(
    '<p><a href="https://example.com/story" target="_blank" title="Story">Read</a></p>'
);
rich_blog_assert(
    str_contains($link['html'], 'rel="noopener noreferrer"'),
    'external blank-target links must receive safe rel attributes'
);

$image = brvtal_blog_sanitize_html(
    '<p><img src="/uploads/editorial/photo.webp" alt="Crowd" onerror="alert(1)"></p>'
);
rich_blog_assert(str_contains($image['html'], 'src="/uploads/editorial/photo.webp"'), 'canonical upload image paths must survive sanitation');
rich_blog_assert(str_contains($image['html'], 'alt="Crowd"'), 'image alt text must survive sanitation');
rich_blog_assert(!str_contains(strtolower($image['html']), 'onerror='), 'image event handlers must be removed');

$unsafeImage = brvtal_blog_sanitize_html('<img src="data:text/html;base64,PHNjcmlwdD4=" alt="bad">');
rich_blog_assert(!str_contains(strtolower($unsafeImage['html']), 'data:'), 'data URLs must not survive image sanitation');

$semantic = '<h2>Signal</h2><p>One <em>night</em>.</p><ul><li>A</li><li>B</li></ul><blockquote>Remain.</blockquote>';
$roundTrip = brvtal_blog_sanitize_html($semantic);
$secondPass = brvtal_blog_sanitize_html($roundTrip['html']);
rich_blog_assert($roundTrip['html'] === $secondPass['html'], 'clean rich Blog HTML must be idempotent');
rich_blog_assert($secondPass['warnings'] === [], 'clean round-trip must not create cleanup warnings');

$controller = (string)file_get_contents(__DIR__ . '/../discadmin/blog.js');
foreach ([
    'data-blog-body-mode="visual"',
    'data-blog-body-mode="source"',
    'data-blog-body-preview',
    'data-blog-body-media',
    'insertUnorderedList',
    'insertOrderedList',
    'data-blog-body-link',
    'justifyCenter',
    'undo',
    'redo',
    'clipboardData?.getData(\'text/plain\')',
    'BRVTALMediaLibrary?.openPicker',
    'setBodyClientCleanupWarning',
] as $marker) {
    rich_blog_assert(str_contains($controller, $marker), "Blog editor must contain {$marker}");
}

$publicPage = (string)file_get_contents(__DIR__ . '/../config/public_page.php');
rich_blog_assert(
    str_contains($publicPage, "brvtal_blog_sanitize_html"),
    'public Blog rendering must cross the canonical sanitizer boundary'
);
rich_blog_assert(
    str_contains($publicPage, 'entity-rich-body'),
    'public Blog rendering must expose the semantic rich-body surface'
);

echo "BRVTAL rich Blog editor contract passed.\n";
