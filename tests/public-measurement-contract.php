<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/public_analytics.php';

function measurement_expect(bool $condition, string $message): void
{
    if (!$condition) {
        fwrite(STDERR, "PUBLIC MEASUREMENT FAILED: {$message}\n");
        exit(1);
    }
}

$markup = brvtal_public_analytics_markup('GTM-W23PHGJG', 'abcdef1');
measurement_expect(str_contains($markup, '/js/public-measurement.js?v=abcdef1'), 'measurement runtime ships with GTM bootstrap');

$runtime = file_get_contents(__DIR__ . '/../js/public-measurement.js');
measurement_expect(is_string($runtime), 'read measurement runtime');
measurement_expect(str_contains($runtime, "brvtal.analytics.choice.v1"), 'measurement reuses canonical analytics consent');
measurement_expect(str_contains($runtime, "brvtal_page_view"), 'page view event exists');
measurement_expect(str_contains($runtime, "brvtal_section_view"), 'section visibility event exists');
measurement_expect(str_contains($runtime, "brvtal_scroll_depth"), 'scroll milestone event exists');
measurement_expect(str_contains($runtime, "brvtal_navigation_click"), 'navigation event exists');
measurement_expect(str_contains($runtime, "brvtal_outbound_click"), 'outbound event exists');
measurement_expect(str_contains($runtime, "IntersectionObserver"), 'section visibility uses IntersectionObserver');
measurement_expect(str_contains($runtime, "SCROLL_MILESTONES = [25, 50, 75, 90]"), 'scroll measurement is milestone-based');
measurement_expect(str_contains($runtime, "data-measure-event"), 'declarative content instrumentation hook exists');
measurement_expect(str_contains($runtime, "window.BRVTALMeasure"), 'shared public measurement API is exposed');
measurement_expect(!str_contains($runtime, 'mousemove'), 'mousemove is not measured');
measurement_expect(!str_contains($runtime, 'pointermove'), 'pointer movement is not measured');
measurement_expect(!str_contains($runtime, '.value'), 'form field values are not harvested');
measurement_expect(!str_contains($runtime, 'innerText'), 'arbitrary DOM text is not harvested');
measurement_expect(!str_contains($runtime, 'textContent'), 'arbitrary DOM text is not harvested');

echo "BRVTAL public measurement contract tests passed.\n";
