# BRVTAL Hero Slider Manager

## Product intent

The first Home section may operate as a campaign banner / video slider managed from the canonical DISCADMIN shell. The interaction model is inspired by LayerSlider: visual, predictable and easy to operate, but intentionally constrained so BRVTAL does not become a generic page builder.

The Hero Slider is an internal DISCADMIN module. It must preserve **ONE SHELL / ONE SIDEBAR / ONE SESSION / ONE CENTRAL WORKSPACE** and must not create a second admin application.

## v1 data model

The first version stores one JSON document in the existing `settings` table under `home.hero.slider`. This avoids a production schema migration while the editor and public runtime stabilize.

Top-level fields:

- `enabled`: whether the managed slider is allowed to replace the static Home hero;
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
- kicker, title and body;
- optional CTA label + URL;
- left / center / right content alignment;
- dark overlay strength.

The public endpoint exposes only this allowlisted presentation data. It never exposes raw settings or private configuration.

## Mobile-first requirements

Mobile is a first-class editing and delivery target.

- Desktop content is the default source of truth.
- A mobile-specific asset is optional; empty mobile fields inherit the desktop asset.
- The editor must remain usable on touch devices without hover-only actions.
- Primary controls use touch targets of at least 44 px.
- The editor preview can switch between Desktop and Mobile.
- Public video uses muted `playsinline` playback; autoplay must never require sound permission.
- `prefers-reduced-motion` disables automatic slide advancement.
- Mobile media should prefer purpose-made vertical/portrait assets where editorially useful, without forcing duplicate campaigns.

## Safe fallback

The current art-directed BRVTAL hero is the permanent fallback.

The public runtime must leave the existing hero untouched when:

- no Hero Slider configuration exists;
- the slider is disabled;
- there are no enabled slides with valid media;
- the public endpoint fails;
- the client-side runtime fails to initialize.

The static hero therefore remains deploy-safe and does not depend on database content being present.

## v1 editor interaction

DISCADMIN exposes `HERO SLIDER` near the content navigation. Administrators can:

- add slides;
- select/edit slides;
- reorder with explicit up/down controls;
- enable/hide slides;
- select image/video assets from Media Library data;
- set an optional mobile override;
- preview desktop/mobile output;
- publish/unpublish the slider;
- save through the authenticated settings API with CSRF inherited from the canonical admin request layer.

## Public delivery

`GET /api/hero-slider.php` is read-only and sanitized. It returns only valid enabled slides. Public Home loads the slider runtime after the existing application scripts. If valid published data exists, the runtime overlays the first `.hero` scene and hides its static children; otherwise no DOM mutation occurs.

The public runtime supports:

- image and video slides;
- manual previous/next/dot navigation;
- autoplay when enabled and motion is not reduced;
- pause while hovered/focused;
- accessible labels and focus-visible controls;
- 44 px touch controls;
- mobile source selection.

## Deliberately deferred

The v1 manager is the stable foundation, not a complete clone of LayerSlider. Later iterations may add constrained visual layers and a timeline after the core persistence/runtime path is proven.

Potential v2 capabilities:

- text/image/logo layers;
- visual drag positioning;
- per-layer entrance/exit animation;
- delay/duration timeline;
- per-layer mobile overrides;
- scheduled start/end publication;
- duplicate slide;
- drag-and-drop ordering;
- richer transition presets.

Any v2 work must preserve public performance, fallback behavior and mobile editing usability.
