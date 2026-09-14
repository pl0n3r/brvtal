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

## Production follow-up — deploy `e238f15` / 2026-09-13 22:35 GMT-5

The user repeated PageSpeed against production after the adaptive runtime and intrinsic-image sizing changes were deployed. The report itself shows first-party assets carrying `?v=e238f15`, matching the deployed `main` short SHA.

### Mobile lab scores

- Performance: 85
- Accessibility: 97
- Best Practices: 96
- SEO: 100
- Field / real-user data: unavailable (`No Data`)

### Mobile lab metrics

- First Contentful Paint: 2.6 s
- Largest Contentful Paint: 3.3 s
- Total Blocking Time: 0 ms
- Cumulative Layout Shift: 0
- Speed Index: 4.7 s

The LCP change from 3.4 s to 3.3 s is directionally positive but too small to attribute confidently from a single Lighthouse run. FCP is unchanged, TBT/CLS remain strong, and Speed Index varied from 4.5 s to 4.7 s.

### Confirmed production effects

- The previous `Image elements do not have explicit width and height` issue is no longer the active diagnostic shown for the inspected artist preview. PageSpeed displays explicit intrinsic dimensions (`width="886"`, `height="886"`) on that image.
- The deployed short SHA is visible on CSS/JS asset URLs, confirming the tested production page is serving the relevant `main` generation.
- Mobile coarse-pointer execution continues with 0 ms TBT, consistent with keeping the desktop motion stack out of the mobile runtime.

### Remaining measured opportunities

Mobile:

- Render-blocking CSS: about 13.0 KiB across the public stylesheets, with PageSpeed reporting a combined blocking duration up to about 3.81 s in the inspected run.
- Network dependency tree: maximum critical-path latency about 826 ms.
- Efficient cache lifetimes: estimated savings about 26 KiB; first-party static assets currently show roughly 7-day TTL.
- Improve image delivery: estimated savings about 27 KiB. The inspected artist-preview image is larger than its rendered viewport and should eventually use a responsive/derived variant.

Desktop screenshots from the same report additionally show:

- Efficient cache-lifetime savings about 39 KiB.
- Image-delivery savings about 125 KiB, including the large event flyer and BRVTAL logo.
- Forced reflow attributed to `related-content.js` (about 28 ms in the inspected call) plus GSAP / ScrollTrigger work on desktop.
- Desktop network critical path around 345 ms in one inspected view.

## Optimization work derived from this evidence

Implemented before the production follow-up:

- Adaptive public runtime: mobile/coarse-pointer and reduced-motion visitors no longer download the desktop GSAP / ScrollTrigger / Lenis stack.
- Initial Home image sizing: the PHP delivery layer resolves the real dimensions of local `assets/` and `uploads/` images and adds missing intrinsic `width` / `height` attributes without overriding explicit author sizing.

Current critical-path iteration:

- keep only primary/hero styles synchronous;
- defer Archive, Public Media, input-accessibility and mobile-events CSS so they do not block first render;
- apply deploy-SHA versioning to initial static image URLs as well as CSS/JS;
- give versioned CSS and static images/fonts long immutable browser caching;
- intentionally keep JavaScript out of the 1-year immutable cache until every lazy import is deploy-versioned.

## Interpretation / next order of work

This remains Lighthouse/PageSpeed lab evidence, not CrUX/field Core Web Vitals. TBT and CLS are already strong, so the order of work is:

1. shorten the render-blocking / critical CSS path;
2. improve safe static cache lifetimes;
3. repeat the same mobile PageSpeed test;
4. optimize responsive image delivery using existing Media Engine variants where possible;
5. then address measured forced reflow (`related-content.js` first, GSAP/ScrollTrigger desktop second);
6. fix the remaining contrast/accessibility findings without compromising the BRVTAL visual system.

Do not claim a performance win from code changes until the same production PageSpeed test is repeated after deployment.
