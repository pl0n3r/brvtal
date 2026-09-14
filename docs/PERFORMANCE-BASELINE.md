# Public performance baseline

User-captured PageSpeed Insights mobile lab baseline against the production site, captured 2026-09-13 at 21:57 GMT-5 before the adaptive public motion-runtime change was deployed.

## Baseline metrics

- First Contentful Paint: 2.6 s
- Largest Contentful Paint: 3.4 s
- Total Blocking Time: 0 ms
- Cumulative Layout Shift: 0
- Speed Index: 4.5 s

## PageSpeed findings shown in the baseline

- Use efficient cache lifetimes — estimated savings about 23 KiB
- Forced reflow
- Network dependency tree
- Improve image delivery — estimated savings about 7 KiB
- Render-blocking requests
- Image elements without explicit width and height
- Background/foreground contrast below the required ratio
- ARIA-role compatibility and identical-link-purpose items should be manually reviewed

## Interpretation

This is Lighthouse/PageSpeed lab evidence, not field/CrUX Core Web Vitals. TBT and CLS are already strong in this run, so the next performance work should prioritize FCP/LCP, render-blocking resources, intrinsic image sizing, cache policy, image delivery, and the reported contrast issue.

Repeat the same mobile PageSpeed test after the adaptive public runtime is deployed before attributing any improvement to that change.
