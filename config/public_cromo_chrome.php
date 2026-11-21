<?php
declare(strict_types=1);

/**
 * Cromo chrome shared by the interior public pages (entity pages, privacy, 404, unavailable):
 * the same top bar as the home (brand, section menu, sound switch) so no page feels apart.
 * $backHref / $backLabel must already be escaped by the caller.
 */
function brvtalPublicCromoSoundButton(): string
{
    return '<button class="cp-snd" type="button" aria-pressed="false" data-cromo-sound'
        . ' data-cromo-sound-off="Activar sonido" data-cromo-sound-on="Apagar el pulso">'
        . '<i aria-hidden="true"></i><span data-cromo-sound-label>Activar sonido</span></button>';
}

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
        . brvtalPublicCromoSoundButton()
        . '<a class="cp-back" href="/mi-cuenta/acceso" data-buyer-account-link>Mi cuenta</a>'
        . '<a class="cp-back" href="' . $backHref . '">' . $backLabel . '</a>'
        . '</div></header>'
        // Public HTML may be cached. Resolve buyer state in the browser,
        // using the buyer-only session API (never cookie presence or admin session).
        . '<script>(async()=>{try{const response=await fetch("/api/buyer/auth.php",'
        . '{credentials:"same-origin",cache:"no-store"});'
        . 'if(!response.ok)return;const session=await response.json();'
        . 'if(session?.ok!==true||session.authenticated!==true)return;'
        . 'document.querySelectorAll("a[data-buyer-account-link]").forEach(a=>a.href="/mi-cuenta");'
        . '}catch(_){}})();</script>';
}
