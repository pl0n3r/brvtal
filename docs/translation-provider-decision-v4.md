# BRVTAL Translation Provider Decision Packet V4

Observed: `2026-10-03`  
Status: **evaluation-only / no provider selected**  
Selected provider: **NONE**  
Production authority: **NONE**

This packet exists only to prepare a later owner decision. It does not select, buy,
contract, configure or call a translation provider. No credentials, provider SDK,
DPA/terms acceptance, billing change, production call, runtime authority, spend or
go-live action is authorized here.

## Evidence method

The completed Phase 4 evaluation contract in
`docs/translation-provider-evaluation-v4.json` requires dated provenance for cost,
privacy, quality, latency and limits. This packet uses only public provider
documentation observed on 2026-10-03.

BRVTAL did **not** call provider APIs because #861 authorizes evaluation/evidence
only. Therefore the BRVTAL synthetic benchmark has not produced provider-specific
quality or latency measurements. Those fields remain `UNKNOWN` rather than being
inferred from marketing claims or unrelated benchmarks.

## Candidate: Google Cloud Translation

Observed: `2026-10-03`

- Public pricing evidence: <https://cloud.google.com/products/translate/pricing>
  - NMT text translation lists a monthly credit covering the first 500,000
    characters and a public rate of USD 20 per 1,000,000 characters in the
    next published tier.
  - Exact commercial pricing must be rechecked at the later owner decision.
- Privacy/data-handling evidence:
  <https://docs.cloud.google.com/translate/data-usage>
  - Google states submitted text is used only to provide Cloud Translation,
    is not made public or shared with third parties, and is held briefly in
    memory to perform translation.
- Limits evidence: <https://docs.cloud.google.com/translate/quotas>
  - General-model content quota defaults to 6,000,000 characters per project
    per minute; v3 requests default to 6,000 per project per minute.
  - Recommended text request length is 5,000 code points; the documented
    Advanced maximum is 30,000 code points.
- Terms/contract status: `NOT_ACCEPTED`. Any DPA/commercial terms review is a
  separate later gate.
- BRVTAL benchmark quality: `UNKNOWN` — no provider call was authorized.
- BRVTAL benchmark latency: `UNKNOWN` — no provider call was authorized.

## Candidate: DeepL API

Observed: `2026-10-03`

- Public plan/billing evidence:
  <https://support.deepl.com/hc/en-us/articles/360021200939-DeepL-API-plans>
  and
  <https://support.deepl.com/hc/en-us/articles/360020685720-Usage-count-and-billing-in-DeepL-API>
  - The Developer API currently documents up to 1,000,000 characters total.
  - The Growth monthly plan includes 1,000,000 characters and documents a
    50,000,000-character monthly usage limit; usage beyond included amounts is
    usage-based. Exact current rates remain subject to the live pricing page.
- Privacy/data-handling evidence:
  <https://www.deepl.com/en/products/api>
  and the plan page above.
  - DeepL states API customer data is not used to train its models; the Growth
    plan states texts are deleted immediately after translation/improvement.
- Limits evidence: the Growth plan documents the 50,000,000-character monthly
  usage limit. Request-level throughput for the intended BRVTAL workload was
  not independently exercised and remains `UNKNOWN`.
- Terms/contract status: `NOT_ACCEPTED`. Any commercial/DPA terms review is a
  separate later gate.
- BRVTAL benchmark quality: `UNKNOWN` — no provider call was authorized.
- BRVTAL benchmark latency: `UNKNOWN` — no provider call was authorized.

## Candidate: Azure AI Translator

Observed: `2026-10-03`

- Public pricing evidence:
  <https://azure.microsoft.com/en-us/pricing/details/translator/>
  - The public page documents an F0 allowance of 2,000,000 standard-translation
    characters per month.
  - Paid pricing is region/offer dependent on the live Azure pricing surface;
    this packet intentionally does not freeze a stale commercial quote.
- Privacy/data-handling evidence:
  <https://learn.microsoft.com/en-us/azure/ai-foundry/responsible-ai/translator/data-privacy-security>
  - Microsoft states text translation does not persist customer data.
- Limits/latency evidence:
  <https://learn.microsoft.com/en-us/azure/ai-services/translator/service-limits>
  - Translate requests are limited to 50,000 characters.
  - F0 documents 2,000,000 characters/hour and S1 40,000,000 characters/hour.
  - Microsoft documents typical service response time of roughly 150–300 ms
    for text under 100 characters and a 15-second maximum for standard models.
    These are service-level statements, **not** BRVTAL benchmark measurements.
- Terms/contract status: `NOT_ACCEPTED`. Any Microsoft commercial/data terms
  review is a separate later gate.
- BRVTAL benchmark quality: `UNKNOWN` — no provider call was authorized.
- BRVTAL benchmark latency: `UNKNOWN` — no provider call was authorized.

## Cross-candidate evidence state

| Field | Google | DeepL | Azure |
| --- | --- | --- | --- |
| Dated public pricing evidence | present | present | present |
| Dated privacy/data evidence | present | present | present |
| Dated limits evidence | present | present | present |
| Contract/DPA accepted | no | no | no |
| BRVTAL benchmark quality | UNKNOWN | UNKNOWN | UNKNOWN |
| BRVTAL benchmark latency | UNKNOWN | UNKNOWN | UNKNOWN |
| Credentials provisioned | no | no | no |
| Production calls performed | no | no | no |
| Spend authorized | no | no | no |

The evidence is intentionally insufficient to name a winner. Provider-specific
quality/latency remains a missing prerequisite for a defensible selection.

## Neutral owner options

### Option A — select a provider later

Return to the owner with a fresh packet after provider-specific benchmark evidence
exists. A later explicit owner decision must name the provider. This option **does
not select a provider now**.

### Option B — continue provider-neutral fallback

Keep the current provider-neutral/cache behavior and make no external provider
activation. No credentials, spend or production calls are introduced.

### Option C — defer

Take no provider action and revisit when requirements, pricing or authorization
change.

Current packet decision: **NONE**.

## Mandatory activation gate

Any future provider activation requires a **separate explicit owner decision** that:

1. names the exact provider to activate;
2. explicitly authorizes any required provider spend or paid plan;
3. explicitly authorizes credential creation/provisioning when credentials are
   required;
4. reviews current privacy/data-handling and contractual/DPA terms;
5. identifies a reversible rollout/rollback path and production validation plan.

A generic `sigue`, this packet, #861 option A, or completion of #862/#863 does
**not** satisfy that activation gate.

## Safety / reversibility

- provider selected: **no**
- provider purchase/contract: **no**
- credentials/secrets created: **no**
- provider API/network calls: **no**
- production mutation: **no**
- private DISCADMIN data used: **no**
- spend authorized: **no**
- go-live authority added: **no**

This file and its static tests are fully reversible by revert.
