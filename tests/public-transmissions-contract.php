<?php
declare(strict_types=1);
function transmissions_expect(bool $condition,string $message):void{if(!$condition)throw new RuntimeException($message);}
$index=(string)file_get_contents(__DIR__.'/../index.html');$entry=(string)file_get_contents(__DIR__.'/../index.php');$runtime=(string)file_get_contents(__DIR__.'/../js/public-runtime-loader.js');$script=(string)file_get_contents(__DIR__.'/../js/public-transmissions.js');$public=(string)file_get_contents(__DIR__.'/../api/public.php');$routes=(string)file_get_contents(__DIR__.'/../config/public_routes.php');
transmissions_expect(str_contains($index,'id="transmissions"'),'Home must expose TRANSMISSIONS');
transmissions_expect(str_contains($index,'href="#transmissions"')&&str_contains($index,'>TRANSMISSIONS</a>'),'Navigation must expose TRANSMISSIONS');
transmissions_expect(str_contains($runtime,"'js/public-transmissions.js'"),'Runtime must load TRANSMISSIONS');
transmissions_expect(str_contains($script,'window.BRVTALPublicDataPromise'),'TRANSMISSIONS must reuse shared public request');
transmissions_expect(!str_contains($script,"fetch('/api/public.php")&&!str_contains($script,'fetch("/api/public.php'),'TRANSMISSIONS must not issue a second public request');
transmissions_expect(str_contains($script,"routeUrl('blog',post?.slug)"),'Cards must use canonical Blog routes');
transmissions_expect(str_contains($script,'relation?.related_type'),'Relation context must be explicit');
transmissions_expect(str_contains($script,'catalog[type].get(id)'),'Relations must resolve against public pools');
transmissions_expect(str_contains($public,"'blog' => $blog"),'Public API must expose sanitized Blog');
transmissions_expect(str_contains($routes,"'blog' => brvtal_public_route_definition"),'Canonical Blog route must remain registered');
transmissions_expect(substr_count($entry,'css/public-transmissions.css')===2,'Home must inject and defer TRANSMISSIONS styles');
echo "BRVTAL public TRANSMISSIONS contract tests passed.\n";
