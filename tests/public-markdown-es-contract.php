<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/public_text_format.php';

function public_markdown_es_expect(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "public-markdown-es: {$message}" . PHP_EOL);
        exit(1);
    }
}
$html = brvtalPublicTextFormat('** ZEBTS**' . PHP_EOL . "* CRIXXES'T B2B DNL5*");
public_markdown_es_expect(str_contains($html, '<strong>ZEBTS</strong>'), 'negrita sin asteriscos');
public_markdown_es_expect(str_contains($html, '<em>CRIXXES&#039;T B2B DNL5</em>'), 'cursiva sin asteriscos');
public_markdown_es_expect(!str_contains($html, '** ZEBTS**') && !str_contains($html, '* CRIXXES'), 'Markdown renderizado');
public_markdown_es_expect(brvtalPublicTextPlain('**A** and **B**') === 'A and B', 'los espacios entre énfasis consecutivos se conservan');
public_markdown_es_expect(str_contains(brvtalPublicTextFormat('** A** y ** B**'), '<strong>A</strong> y <strong>B</strong>'), 'énfasis acolchado separado');
public_markdown_es_expect(brvtalPublicTextPlain('# SAVØR 001' . PHP_EOL . "** ZEBTS** y * CRIXXES'T B2B DNL5*") === "SAVØR 001 ZEBTS y CRIXXES'T B2B DNL5", 'resúmenes sin # ni *');
public_markdown_es_expect(str_contains(brvtalPublicTextFormat('** <script>alert(1)</script>**'), '&lt;script&gt;'), 'HTML externo escapado');
$home = (string) file_get_contents(__DIR__ . '/../js/public-home-visual.js');
$cromo = (string) file_get_contents(__DIR__ . '/../js/public-cromo-home.js');
$related = (string) file_get_contents(__DIR__ . '/../js/related-content.js');
public_markdown_es_expect(str_contains($home, 'VER NOCHES ↓') && str_contains($home, 'Explorar noches de BRVTAL'), 'CTA y aria en español');
public_markdown_es_expect(str_contains($cromo, 'const plain =') && str_contains($cromo, 'plain(site?.description, 240)') && str_contains($cromo, 'plain(event.title)'), 'tarjetas Cromo en texto plano');
public_markdown_es_expect(str_contains($related, 'const plain =') && str_contains($related, 'plain(title') && str_contains($related, 'UI.publicLinks'), 'relaciones en español');
foreach (['VIEW NIGHTS', 'RELATED CONTENT / PUBLIC GRAPH', 'PUBLIC LINKS', 'Secret location'] as $english) {
    public_markdown_es_expect(!str_contains($home . $cromo . $related, $english), "texto inglés: {$english}");
}
public_markdown_es_expect(str_contains($related, 'CONTENIDO RELACIONADO / RED PÚBLICA') && str_contains($related, 'Tipo de contenido'), 'copy Cromo');
echo "Contrato Markdown y español público: OK" . PHP_EOL;
