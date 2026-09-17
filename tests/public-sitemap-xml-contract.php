<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/public_sitemap.php';

/** Fail when generated Sitemap XML stops matching Google's documented structure. */
function public_sitemap_xml_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "PUBLIC SITEMAP XML CONTRACT FAILED: {$message}\n");
        exit(1);
    }
}

$base = 'https://www.brvtal.com.co';
$xml = brvtal_public_sitemap_xml([
    [$base . '/', null],
    [$base . '/events/genesis?source=search&medium=organic', '2026-09-17 21:45:00'],
    [$base . '/artists/pl0n3r', 'not-a-date'],
    ['https://example.com/off-site', '2026-09-17'],
    ['/relative-url', '2026-09-17'],
], $base);

public_sitemap_xml_expect(class_exists(DOMDocument::class), 'DOM XML support must be available in CI.');
libxml_use_internal_errors(true);
$document = new DOMDocument();
$loaded = $document->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
$errors = libxml_get_errors();
libxml_clear_errors();
public_sitemap_xml_expect($loaded === true && $errors === [], 'generated sitemap XML must be well formed.');
public_sitemap_xml_expect($document->encoding === 'UTF-8', 'sitemap XML declaration must use UTF-8.');

$root = $document->documentElement;
public_sitemap_xml_expect($root !== null && $root->localName === 'urlset', 'root element must be urlset.');
public_sitemap_xml_expect(
    $root?->namespaceURI === BRVTAL_SITEMAP_NAMESPACE,
    'urlset must use the standard Sitemap protocol namespace.'
);

$xpath = new DOMXPath($document);
$xpath->registerNamespace('sm', BRVTAL_SITEMAP_NAMESPACE);
$urlNodes = $xpath->query('/sm:urlset/sm:url');
public_sitemap_xml_expect($urlNodes !== false && $urlNodes->length === 3, 'only valid canonical-site URLs may be emitted.');

$locNodes = $xpath->query('/sm:urlset/sm:url/sm:loc');
public_sitemap_xml_expect($locNodes !== false && $locNodes->length === 3, 'every url entry must contain exactly one loc element.');
if ($locNodes !== false) {
    foreach ($locNodes as $locNode) {
        $location = trim($locNode->textContent);
        public_sitemap_xml_expect(
            str_starts_with($location, $base . '/'),
            'every loc must be an absolute URL on the canonical BRVTAL host.'
        );
        public_sitemap_xml_expect(strlen($location) < 2048, 'every loc must remain below the Sitemap protocol URL limit.');
    }
}

$lastmodNodes = $xpath->query('/sm:urlset/sm:url/sm:lastmod');
public_sitemap_xml_expect($lastmodNodes !== false && $lastmodNodes->length === 1, 'invalid lastmod values must not be emitted.');
public_sitemap_xml_expect(
    $lastmodNodes !== false && $lastmodNodes->item(0)?->textContent === '2026-09-17',
    'lastmod must use the W3C YYYY-MM-DD date form.'
);
public_sitemap_xml_expect(
    str_contains($xml, 'source=search&amp;medium=organic'),
    'XML-sensitive URL characters must be entity escaped.'
);
public_sitemap_xml_expect(
    !str_contains($xml, 'example.com/off-site') && !str_contains($xml, '<loc>/relative-url</loc>'),
    'off-host and relative URLs must never reach the public sitemap.'
);

echo "Public sitemap XML contract passed.\n";
