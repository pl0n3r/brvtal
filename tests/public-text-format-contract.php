<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/public_text_format.php';

function public_text_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "Public text format: $message\n");
        exit(1);
    }
}

$html = brvtalPublicTextFormat("**BRVTAL** y *baile* con _energía_\nSiguiente línea\n\n# GENESIS — EL REGRESO\n## Segunda sección\n### Detalles\n- Uno\n- Dos\n\n[Entradas](https://tickets.example.org/?a=1&b=2)");
public_text_expect(str_contains($html, '<strong>BRVTAL</strong>'), 'bold');
public_text_expect(str_contains($html, '<em>baile</em>'), 'asterisk italic');
public_text_expect(str_contains($html, '<em>energía</em>'), 'underscore italic');
public_text_expect(str_contains($html, '<br>Siguiente línea'), 'single newline');
public_text_expect(str_contains($html, '<h2>GENESIS — EL REGRESO</h2>'), 'h2');
public_text_expect(str_contains($html, '<h3>Detalles</h3>'), 'h3');
public_text_expect(str_contains($html, '<ul><li>Uno</li><li>Dos</li></ul>'), 'bullets');
public_text_expect(str_contains($html, '<a href="https://tickets.example.org/?a=1&amp;b=2" rel="noopener noreferrer">Entradas</a>'), 'https link');
$unsafe = brvtalPublicTextFormat('<script>alert(1)</script>' . "\n" . '<img src=x onerror=alert(1)>' . "\n" . '[Bad](javascript:alert(1))');
public_text_expect(str_contains($unsafe, '&lt;script&gt;alert(1)&lt;/script&gt;'), 'raw script escaped');
public_text_expect(str_contains($unsafe, '&lt;img src=x onerror=alert(1)&gt;'), 'raw img escaped');
public_text_expect(!str_contains($unsafe, '<img') && !str_contains($unsafe, '<script') && !str_contains($unsafe, 'href="javascript:'), 'no unsafe HTML');
public_text_expect(str_contains($unsafe, 'javascript:'), 'invalid link is literal');
public_text_expect(brvtalPublicTextFormat('   ') === '', 'empty input');
public_text_expect(brvtalPublicTextPlain("# GENESIS\n**DNL5** y [Boletas](https://example.org)") === 'GENESIS DNL5 y Boletas', 'plain SEO');
$light = brvtalPublicEventPalette('#96d35f');
$dark = brvtalPublicEventPalette('#101020');
$empty = brvtalPublicEventPalette('');
public_text_expect($light['signal'] === '#96d35f' && $light['ink'] === '#030303', 'light accent and ink');
public_text_expect($dark['signal'] === '#101020' && $dark['ink'] !== '#030303', 'dark accent and ink');
public_text_expect($empty['signal'] === '#e12e34', 'Cromo red fallback');
echo "Public text format and event palette: OK\n";
