<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/public_seo.php';
require_once __DIR__ . '/../config/public_page.php';
function event_map_expect(bool $condition, string $message): void {
    if (!$condition) {fwrite(STDERR, "EVENT MAP CONTRACT FAILED: $message\\n"); exit(1);}
}
$schema = file_get_contents(__DIR__ . '/../database/schema.sql');
$migration = file_get_contents(__DIR__ . '/../database/migration_events_venue_address_01.sql');
$proof = file_get_contents(__DIR__ . '/../config/migration_reconcile.php');
$api = file_get_contents(__DIR__ . '/../api/index.php');
$htaccess = file_get_contents(__DIR__ . '/../.htaccess');
event_map_expect(str_contains($schema, 'venue_address VARCHAR(300) NULL'), 'fresh schema includes nullable address');
event_map_expect(str_contains($migration, 'ADD COLUMN IF NOT EXISTS venue_address VARCHAR(300) NULL'), 'migration is additive and idempotent');
event_map_expect(str_contains($proof, "'migration_events_venue_address_01.sql'"), 'reconciler has a structural proof');
event_map_expect(str_contains($api, "'venue_address'=>300") && str_contains($api, "'venue','venue_address','city'"), 'admin API limits and allowlists venue_address');
event_map_expect(str_contains($htaccess, "frame-src 'self' https://www.google.com"), 'CSP limits Google Maps framing');
$page = ['entity'=>['id'=>7,'route_type'=>'events','title'=>'Prueba','description'=>'Detalles','venue'=>'Sala','city'=>'Pereira','venue_address'=>'Calle 8 & 9, Pereira'], 'facts'=>[], 'links'=>[], 'related'=>[], 'record'=>['state'=>'active','status'=>'UPCOMING'], 'degraded'=>false];
$seo = ['title'=>'Prueba','description'=>'Detalles','canonical'=>'https://www.brvtal.com.co/events/prueba','image'=>'','schema'=>['@context'=>'https://schema.org','@type'=>'MusicEvent']];
$html = brvtal_public_entity_page($page, $seo);
event_map_expect(str_contains($html,'Cómo llegar'), 'map section renders');
event_map_expect(str_contains($html,'Calle 8 &amp; 9, Pereira'), 'address escaped');
event_map_expect(str_contains($html, 'q=Calle%208%20%26%209%2C%20Pereira&amp;output=embed'), 'embed URL safely encoded');
event_map_expect(!str_contains($html, '<iframe '), 'no Google iframe is loaded before interaction');
$page['entity']['venue_address']='';
event_map_expect(!str_contains(brvtal_public_entity_page($page,$seo),'class="event-map"'), 'no section for empty address');
echo "Event map contract OK\\n";
