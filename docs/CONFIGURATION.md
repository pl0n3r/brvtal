# BRVTAL configuration control plane

This document defines where configurable behavior belongs in DISCADMIN. The goal is one logical owner per option while preserving the existing `settings` table and Theme JSON architecture.

## Navigation

The permanent sidebar stays intentionally small:

- **SITE → Theme Studio** — visual identity and active theme lifecycle.
- **SITE → Settings** — global behavior, public destinations, canonical SEO defaults and integrations.

Do not add a permanent sidebar destination for every configuration category. Both workspaces use internal sub-navigation.

## Theme Studio ownership

Theme Studio owns visual, theme-specific controls that have a real public runtime consumer:

- Brand identity: public site name/tagline presentation, main logo, mobile logo, favicon, preloader logo and dedicated BRVTAL wordmark.
- Palette: background, surface, text, muted, primary, accent and border.
- Typography: display/body/mono stacks, hero scale, body size and display tracking.
- Navigation: fixed/transparent/blur behavior, brand position, scene indicator, sound control and menu presentation.
- Experience: grain, scanlines, glitch, custom cursor, magnetic interactions and motion profile.
- Theme lifecycle: draft, duplicate, revert, preset and activate.

The configurable wordmark is stored as `theme.<slug>.branding.wordmark`. The public runtime treats it as an external/public image asset, including SVG paths, and never injects raw SVG markup. Header and loader keep the textual `BRVTAL` fallback if the asset is empty or fails to load.

### Preserved but not presented as live controls

Older theme JSON may contain fields such as:

- `navigation.menuAnimation`
- `effects.distortion`
- `effects.pageTransitions`
- `effects.parallax`
- `effects.horizontalScroll`
- `effects.webgl`
- `sound.defaultState`
- `sound.uiSounds`
- `sound.masterVolume`
- `preloader.enabled / animation / duration / text`
- `responsive.desktopScale / tabletScale / mobileScale`
- `customCode.*`
- legacy `theme.seo.*`

These values remain round-trippable so old themes are not damaged, but they are not shown as working product controls while the public runtime has no authoritative consumer for them. Adding a polished control requires first wiring and testing the runtime behavior.

## Settings ownership

Settings owns global/non-visual behavior through typed editors while persisting to the same `settings` rows.

### General — `site`

- Public site name.
- Global tagline.
- Existing locale metadata is shown only as context until #212 implements the public ES/EN contract; it is not presented as a live language control today.

### Social & Contact — `social`

- Instagram.
- SoundCloud.
- YouTube.
- Spotify.
- Optional website/external hub.

Only safe public HTTP/HTTPS destinations are accepted by the typed UI.

### SEO — `seo`

Canonical Home defaults are server-owned:

- `site_title`
- `description`
- `share_image`

Entity-specific SEO fields remain authoritative for Events, Artists, Sets, Releases, Blog and Pages. Legacy `theme.seo` remains stored for compatibility but is no longer the primary editing surface and must not override server-rendered canonical metadata.

### Analytics & Privacy — `analytics`

- `ga4_id` is the supported global analytics identifier.
- Google Analytics stays consent-gated by the existing public analytics runtime.
- The standalone `analytics` setting is server-side configuration and is not exposed through `api/public.php`.
- Existing `theme.analytics.google` remains a temporary fallback so current installations migrate non-destructively.

### Advanced

Raw setting editing is an escape hatch for recovery, unknown keys and compatibility work. It is not the normal editor for known categories.

`appearance` is legacy compatibility data. Theme Studio is the visual authority. Theme records remain editable through Theme Studio; raw editing is available only under Advanced when necessary.

## Persistence rules

- Keep the existing `settings` table and `/settings` endpoint.
- Typed editors parse the existing JSON object, update only the fields they own and preserve unknown sibling keys.
- Do not create duplicate settings tables or a second theme subsystem.
- Do not expose protected/private configuration through the public settings API.
- New configuration must fail safely: missing/invalid settings fall back to the current static public behavior.

## Media / SVG policy

The wordmark may point to an SVG or other image asset already available through a safe public path/URL. Raw inline SVG/HTML is never stored or injected by Theme Studio. The generic Media upload allowlist is not weakened merely to support wordmarks; accepting uploaded SVG would require a dedicated sanitizer and security review.

## Future configuration

When #212 is implemented, language controls should be added inside Settings rather than as another top-level sidebar destination. The current Spanish-default / automatic-English specification remains documented in #212 and is not implemented by this configuration refactor.
