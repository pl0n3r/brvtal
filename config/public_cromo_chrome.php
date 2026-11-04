<?php
declare(strict_types=1);

/**
 * Cromo chrome shared by the interior public pages (entity pages, privacy, 404, unavailable):
 * the same top bar as the home (brand, section menu, sound switch) so no page feels apart.
 * $backHref / $backLabel must already be escaped by the caller.
 */
function brvtalPublicCromoTopbar(string $backHref = '/', string $backLabel = '← VOLVER AL INICIO'): string
{
    $sections = [
        'noches' => 'Noches', 'artistas' => 'Artistas', 'sonido' => 'Sonido',
        'memorias' => 'Memorias', 'journal' => 'Journal', 'contacto' => 'Contacto',
    ];
    $menu = '';
    foreach ($sections as $id => $label) {
        $menu .= '<a href="/#' . $id . '">' . $label . '</a>';
    }
    return '<header class="entity-nav cp-top">'
        . '<a href="/" class="entity-brand">BRVTAL<small>RAVE TILL GRAVE</small></a>'
        . '<nav class="cp-nav" aria-label="Secciones">' . $menu . '</nav>'
        . '<div class="cp-tools">'
        . '<button class="cp-snd" type="button" aria-pressed="false" data-cromo-sound data-cromo-sound-off="Activar sonido" data-cromo-sound-on="Apagar el pulso"><i></i><span data-cromo-sound-label>Activar sonido</span></button>'
        . '<a class="cp-back" href="' . $backHref . '">' . $backLabel . '</a>'
        . '</div></header>';
}
