<?php
declare(strict_types=1);

/** #1964: the Cupones catalog (events + ticket names) sits behind the same permission as the rules. */
function promosContract(bool $ok, string $what): void
{
    if (!$ok) throw new RuntimeException('PROMOS_CONTRACT: ' . $what);
}
$api = (string)file_get_contents(__DIR__ . '/../api/ticketing/admin-promos.php');
$auth = strpos($api, "brvtal_admin_require();");
$permission = strpos($api, "brvtalTicketingCan((string)(\$q->fetchColumn()?:''),'settings')");
$catalog = strpos($api, "(\$_GET['catalog']??'')==='1'");
promosContract($auth !== false && $permission !== false && $catalog !== false, 'auth, permission and catalog present');
promosContract($auth < $permission && $permission < $catalog, 'catalog is served only after authentication and permission');
promosContract(str_contains($api, "brvtal_public_event_statuses()['active']") && !str_contains($api, "status='published'"),
    'only canonical selling events are listed (#2022)');
promosContract(!preg_match('/code_digest|buyer|email/i', substr($api, $catalog, 1400)), 'catalog exposes no coupon digests or buyer data');
$ui = (string)file_get_contents(__DIR__ . '/../discadmin/ticketing-promos.js');
promosContract(!str_contains($ui, 'Evento ID') && !str_contains($ui, 'Inicio UTC') && !str_contains($ui, 'fixed, percent'),
    'no typed IDs, UTC labels or raw enums in the Cupones UI');
echo "BRVTAL #1964 Cupones catalog contract passed.\n";
