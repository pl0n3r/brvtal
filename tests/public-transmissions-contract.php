<?php
declare(strict_types=1);
function transmissions_expect(bool $condition, string $message): void { if (!$condition) { fwrite(STDERR, "PUBLIC TRANSMISSIONS CONTRACT FAILED: {$message}\n"); exit(1); } }
$script=(string)file_get_contents(__DIR__.'/../js/public-transmissions.js');
$runtime=(string)file_get_contents(__DIR__.'/../js/public-runtime-loader.js');
$entry=(string)file_get_contents(__DIR__.'/../index.php');
$home=(string)file_get_contents(__DIR__.'/../index.html');
transmissions_expect(str_contains($script,'window.BRVTALPublicDataPromise'),'must reuse shared public payload');
transmissions_expect(!str_contains($script,"fetch('/api/public.php"),'must not issue a second CMS request');
transmissions_expect(str_contains($script,"routeUrl('blog', post.slug)"),'posts must use canonical Blog routes');
transmissions_expect(str_contains($script,"['events','artists','sets','releases']"),'only explicit structured relation layers may render');
transmissions_expect(str_contains($runtime,"'js/public-transmissions.js'"),'runtime must load Transmissions');
transmissions_expect(str_contains($entry,'css/public-transmissions.css'),'Home must deliver Transmissions styles');
transmissions_expect(str_contains($home,'data-dynamic="transmissions"'),'Home must contain a Transmissions mount');
echo "BRVTAL public Transmissions contract tests passed.\n";
