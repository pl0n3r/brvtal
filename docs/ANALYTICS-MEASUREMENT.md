# BRVTAL public measurement contract

BRVTAL uses one public measurement path:

**Public UI → `dataLayer` → Google Tag Manager → GA4 / optional destinations**

Google Tag Manager is the only public tag-delivery layer. It loads automatically on public pages when a valid `GTM-...` container is configured. BRVTAL does not load GA4 directly.

## Bootstrap policy

- GTM is requested automatically on the first public page load; no Analytics acceptance dialog is required.
- `analytics_storage` starts as `granted` so analytics measurement can begin immediately.
- `ad_storage`, `ad_user_data` and `ad_personalization` remain `denied` in the BRVTAL bootstrap.
- BRVTAL itself does not inject arbitrary raw analytics snippets from DISCADMIN; optional tags are configured inside GTM.
- Legacy `brvtal.analytics.choice.v1` values are ignored by the current runtime.

Any tag added inside GTM must still be configured appropriately for the data it collects and the jurisdictions in which it is used.

## Foundation events

| dataLayer event | Meaning | Core parameters |
|---|---|---|
| `brvtal_page_view` | Public page view | `page_type`, optional `content_type`, `content_slug` |
| `brvtal_section_view` | A Home section reaches the controlled viewport band | `section` |
| `brvtal_scroll_depth` | Visitor crosses a meaningful depth milestone | `depth` = 25 / 50 / 75 / 90 |
| `brvtal_menu_toggle` | Main menu opens/closes | `action`, `control` |
| `brvtal_navigation_click` | Header/menu navigation | `destination`, `source` |
| `brvtal_outbound_click` | HTTP(S) navigation leaves BRVTAL | `destination` without query string |

The runtime also supports declarative content events through `data-measure-event="brvtal_..."` plus approved `data-measure-*` parameters. Content-specific Events, Artists, Sets, Memories and Contact instrumentation should use this shared contract instead of adding independent analytics listeners.

## Shared parameters

The runtime accepts only an explicit allowlist. Current fields are:

- `page_type`
- `content_type`
- `content_id`
- `content_slug`
- `content_title`
- `section`
- `action`
- `destination`
- `position`
- `media_type`
- `control`
- `source`
- `status`
- `filter_type`
- `relation_type`
- `platform`
- `result_state`
- `depth`

Arbitrary DOM text, form values, email addresses, names, IPs, query strings, admin/session data and unknown dataset fields are not harvested by the BRVTAL measurement runtime.

## Page context

The shared runtime derives stable context from the public route:

- `/` → `page_type=home`
- `/contact` → `page_type=contact`
- `/events/{slug}` → `page_type=event`, `content_type=event`, `content_slug={slug}`
- `/artists/{slug}` → Artist equivalents
- `/sets/{slug}` → Set equivalents
- `/releases/{slug}` → Release equivalents
- `/blog/{slug}` and `/pages/{slug}` → corresponding public content context

No title or database ID is inferred from arbitrary rendered text. Content modules may pass those fields only when they already have structured public data.

## GTM → GA4 mapping

Create GTM **Custom Event** triggers for the `brvtal_*` events and Data Layer Variables for the parameters above. Configure GA4 event tags from those triggers.

Recommended initial mapping:

| BRVTAL event | GA4 destination |
|---|---|
| `brvtal_page_view` | `page_view` |
| `brvtal_section_view` | custom `section_view` |
| `brvtal_scroll_depth` | custom `scroll_depth` |
| `brvtal_menu_toggle` | custom `menu_toggle` |
| `brvtal_navigation_click` | custom `navigation_click` |
| `brvtal_outbound_click` | custom `outbound_click` |

### Avoid duplicates

If `brvtal_page_view` is mapped to GA4 `page_view`, configure the Google tag in GTM so it does **not** also send an automatic page view. Likewise, do not simultaneously rely on GA4 Enhanced Measurement scroll/outbound events and map the equivalent BRVTAL events unless duplicate reporting is intentional.

## Runtime API

Public modules may emit structured events through:

```js
window.BRVTALMeasure?.push('brvtal_example_action', {
  content_type: 'event',
  content_id: 123,
  content_slug: 'example-event',
  action: 'open',
  section: 'events'
});
```

The API enforces event-name format and the parameter allowlist.

## Next measurement slices

The foundation intentionally does not guess content semantics. Follow-up work under #427 should add structured instrumentation for:

1. Event impressions/details/ticket CTAs and archive discovery;
2. Roster/Artist profiles and related-content navigation;
3. Sets/listen/player behavior;
4. curated Memories viewer/media behavior once the Memories model exists;
5. Contact start/validation/success/failure without field values;
6. server-side GA4 Data API reporting inside the existing DISCADMIN shell.
