# BRVTAL Hero Slider Manager

## Product intent

The first Home section may operate as a campaign banner / video slider managed from the canonical DISCADMIN shell. The interaction model is inspired by LayerSlider: visual, predictable and easy to operate, but intentionally constrained so BRVTAL does not become a generic page builder.

The Hero Slider is an internal DISCADMIN module. It preserves **ONE SHELL / ONE SIDEBAR / ONE SESSION / ONE CENTRAL WORKSPACE** and does not create a second admin application.

## Persistence

The manager stores one JSON document in the existing `settings` table under `home.hero.slider`. This keeps the feature deploy-safe on shared hosting and avoids a schema migration while the editor/runtime mature.

Top-level fields:

- `enabled`: whether the managed slider may replace the static Home hero;
- `autoplay`: automatic advance for multiple slides;
- `interval`: 2500–30000 ms;
- `slides`: ordered list, maximum 20.

Each slide supports:

- enabled / hidden state;
- internal name;
- image or muted inline video;
- desktop media source;
- optional mobile media override;
- optional video poster;
- legacy kicker, title, body and CTA for backward compatibility;
- left / center / right legacy alignment;
- dark overlay strength;
- `fade`, `slide` or `zoom` transition;
- up to 12 constrained visual layers.

## v2 visual layers

Layer types are deliberately allowlisted:

- `text`;
- `image`;
- `logo`;
- `cta`.

Each layer may define:

- internal name;
- text and/or media source depending on type;
- optional CTA URL;
- desktop X/Y position as percentages of the hero stage;
- desktop width as a percentage;
- optional mobile X/Y/width overrides;
- optional mobile-specific image/logo source;
- hide-on-mobile state;
- left / center / right alignment;
- entrance animation: none, fade, slide-up, slide-left or zoom;
- delay 0–10000 ms;
- duration 100–5000 ms.

The editor supports direct pointer/touch positioning in the preview. Dragging in Desktop updates desktop coordinates; dragging in Mobile creates/updates mobile coordinate overrides. Numeric fields remain available for precise corrections.

A slide can also be duplicated. Duplicated slide and layer IDs are regenerated so later edits do not alias the source record.

## Mobile-first requirements

Mobile is a first-class editing and delivery target.

- Desktop content is the default source of truth.
- A mobile-specific slide asset is optional; empty mobile fields inherit the desktop asset.
- Per-layer mobile position/width overrides are optional and inherit desktop values when empty.
- Individual layers can be hidden on mobile.
- Image/logo layers may use an optional mobile-specific asset.
- The editor remains usable on touch devices without hover-only actions.
- Primary controls use touch targets of at least 44 px.
- The editor preview switches between Desktop and Mobile.
- Public video uses muted `playsinline` playback; autoplay never requires sound permission.
- `prefers-reduced-motion` disables automatic slide advancement and layer entrance animation.

## Safe fallback

The current art-directed BRVTAL hero is the permanent fallback.

The public runtime leaves the existing hero untouched when:

- no Hero Slider configuration exists;
- the slider is disabled;
- there are no enabled slides with valid media;
- the public endpoint fails;
- the client-side runtime fails to initialize.

The static hero therefore remains deploy-safe and does not depend on database content being present.

## Backward compatibility

Slides created in v1 continue working. When a slide has no visual layers, public delivery renders its legacy kicker/title/body/CTA exactly through the existing content path. Once at least one v2 layer exists, the layer composition becomes the foreground presentation for that slide.

## Public delivery

`GET /api/hero-slider.php` is read-only and sanitized. It returns only valid enabled slides and allowlisted presentation fields. It caps public output at 20 slides and 12 layers per slide.

Public Home loads the slider runtime after the existing application scripts. If valid published data exists, the runtime overlays the first `.hero` scene and hides its static children; otherwise no DOM mutation occurs.

The public runtime supports:

- image and video slides;
- visual text/image/logo/CTA layers;
- desktop/mobile layer overrides;
- manual previous/next/dot navigation;
- autoplay when enabled and motion is not reduced;
- pause while hovered/focused;
- accessible labels and focus-visible controls;
- 44 px touch controls;
- mobile source selection;
- reduced-motion-safe layer behavior.

## Performance rules

- v2 remains dependency-free and uses no Slider Revolution/LayerSlider runtime.
- No new production Node service is introduced.
- Slider and layer media remain normal browser assets; mobile-specific media is optional rather than mandatory duplication.
- The original static hero remains the failure fallback.
- Richer layer controls must not justify loading a generic page-builder framework publicly.

## Deferred beyond v2

Potential later capabilities, only if real editorial use proves they are worth the complexity:

- scheduled start/end publication;
- drag-and-drop slide/layer ordering;
- richer timeline visualization;
- more transition presets;
- server-resolved first-slide delivery for stronger LCP prioritization;
- reusable campaign templates.

Any future work must preserve public performance, fallback behavior and mobile editing usability.
