# JG funnel investigation: offer 914 versus 915

Investigated September 16, 2026 Singapore time (September 15 UTC). Checkout: `a1f97d7`; deployed assets: `v=46`. No production behavior changed and no lead submitted.

## Conclusion

**The first-step failure did not reproduce. The exact cause of the observed traffic drop-off remains unproven.** Both specified URLs accept all five debt options and advance. Both reached step 7 (DOB), including successful address resolution. Changing only `oid` does not select a different form or validation path in the inspected application.

The shared Umami dashboard independently confirms a large pre-submission engagement gap. It persists within US Chrome traffic, so neither non-US traffic nor a Meta-in-app-only explanation accounts for the whole gap. Traffic quality, ad/landing expectation mismatch, preloaded/nonhuman visits, and session-specific browser failures remain hypotheses, not established causes. There is no evidence supporting an `oid=914` UI patch or blaming LeadProsper for the initial drop-off.

## 1–2. Reproduction results

URLs tested:

- `https://jgdebtrelief.com/?oid=914&affid=995`
- `https://jgdebtrelief.com/?oid=915&affid=995`

| Check | 914 | 915 |
|---|---|---|
| Page loads; five options render | Pass | Pass |
| Click each of five option labels; checked value changes | Pass | Pass |
| Continue after selection | Step 2 | Step 2 |
| Narrow mobile: each option individually followed by Continue, then Back | 5/5 pass | 5/5 pass |
| iPhone-sized layout: all options selectable; progression works | Pass | Pass |
| Payments → employment → income → name → address | Pass | Pass |
| Select resolved public sample address, advance to DOB | Pass | Pass |
| Captured console logs, warnings and errors | Empty | Empty |

The available browser was the Codex in-app browser. Chrome was explicitly requested from the tool but unavailable. Desktop content width was 801 CSS pixels, height 856. Mobile overrides were 360×800 and 390×844; measured document widths were 345 and 375 respectively because of the scrollbar. The override initially affected only the selected tab; 914 was subsequently retested in that tab with dimensions verified. This was responsive-layout testing, **not actual iOS Safari, touch-device or Facebook/Instagram webview testing**.

Evidence: [914 selected mobile state](914-mobile-selected.png), [914 DOB state](914-dob-state.txt), [915 DOB state](915-dob-state.txt), and the interaction/tool transcript. The sample inputs were a synthetic diagnostic name and Google's public campus address. Testing stopped at the DOB screen, whose Continue action carries credit-report consent. No DOB, email, phone, credit consent, final submission or buyer delivery was tested. Reaching DOB establishes progression, not successful end-to-end submission.

## 3–4. Behavioral differences and responsible code

**No first-step behavioral difference was found.** Public HTTP GETs returned 200 for both URLs. Their HTML was byte-identical after normalizing only the landing-event `oid`, generated submission nonce, and render timestamp. Deployed `funnel.js`, `tracking/attribution.js`, `tracking/everflow.js`, and `style.css` each returned 200 and matched this checkout after CRLF normalization.

| Area | What changing 914 to 915 does |
|---|---|
| Form configuration, options, allowed ranges | Nothing. Shared options at `config.php:776`, rendered by `index.php:337`. Even the lowest bucket can advance. |
| Validation and Continue | Nothing. `funnel.js:452` checks only for a selected required radio; `:1014` validates, records completion and advances. |
| Styling, overlays, handlers, initialization | Nothing. Same HTML/CSS/JS. Native radio inputs and plain JavaScript; no React/Vue hydration. |
| Everflow offer | Nothing with this fixed affiliate. `everflow.js:39–48` derives offer from `affid`; deployed config lists 995 as first party. **Both requested URLs resolve to offer 914**, not an Everflow 914/915 split. |
| Attribution | URL and landing-event `oid` differ (`index.php:60`, `:208`). Both query values are copied through the same code (`funnel.js:1189`), persisted (`attribution.js:60`), stored (`submit.php:472`) and forwarded (`leadprosper.php:110`, `:420`). |
| API/campaign selection | Same frontend `POST submit.php` (`funnel.js:1116`). Same configured LP endpoint/campaign (`config.php:258`, `leadprosper.php:65`, `:209`); `oid` is a passthrough field. External LP campaign rules were not accessible. |
| Qualification and redirect | Based on verified debt, student debt, bot status and accepted buyer (`routing.php:58`), not `oid`. Attribution is forwarded by `routing.php:173`. |
| QA test mode | Affiliate/token based, not offer based (`submit.php:245`, `config.php:322`). The default QA affiliate is 300, not 995. |

The full application search found `oid` in attribution, persistence, storage, forwarding, reporting labels/schema and comments, not a form conditional. A separate `address_classic=1` query switch exists at `index.php:422`; neither supplied URL enables it. No asynchronous offer/form configuration fetch gates step 1: PHP renders the options directly.

For 914, option-center `elementFromPoint` checks hit descendants of their own labels, with no covering element. All radios were enabled; label/input pointer events were `auto`; label z-index was `auto`. Checked state and green selection styling changed. `style.css:141` intentionally hides the native input at zero size; the wrapping label is the click target. Styling uses `:has(input:checked)` at `:161`. No responsive overlay intercepted these tested clicks. Separate pointerdown/click event traces were not captured; successful label selection and Continue establish working interactions, not a direct event-listener trace.

## 5–6. Console and network

No console entries were returned for either tested production tab, including after reaching DOB. This does not rule out errors in other users' sessions or errors swallowed by third-party libraries.

| Request | Method/status | Payload / response / difference |
|---|---|---|
| `https://jgdebtrelief.com/?oid=914&affid=995` | GET 200 | No body; query as shown. HTML comparison above. |
| `https://jgdebtrelief.com/?oid=915&affid=995` | GET 200 | No body; query as shown. Only normalized dynamic/attribution fields differ. |
| `/assets/js/funnel.js?v=46` | GET 200 | No body; deployed source matches local. |
| `/assets/js/tracking/attribution.js?v=46` | GET 200 | Same. |
| `/assets/js/tracking/everflow.js?v=46` | GET 200 | Same. |
| `/assets/css/style.css?v=46` | GET 200 | Same. |
| `https://cloud.umami.is/script.js` | GET 200 | Public tracker retrieved for source inspection; saved alongside this report. |
| `https://gateway.umami.is/api/send` | POST by tracker source; browser status/body **not captured** | Source sends `{type:"event", payload:{…name,data,url…}}`; no offer-specific gate found. |
| Everflow SDK / click | SDK present in live DOM; click response **not captured** | `https://www.f0cg2trk.com/scripts/sdk/everflow.js`; source uses offer 914 and affiliate 995 in both cases. |
| `submit.php` / LeadProsper direct post | **Not issued by this investigation** | No live request/response or delivery claim. |

HTTP statuses above come from separate public HTTP probes, not a browser HAR. The available browser API exposes console and DOM inspection but no network capture/interception. Resource Timing was unavailable in its read-only evaluation scope. Therefore full failed-request, CORS/CSP, payload and response comparisons are incomplete. DOM inspection also did not expose reliable hidden-input values (even server-rendered hidden values appeared empty); no attribution defect is inferred from that tool limitation.

## 7. Analytics and where the loss occurs

Instrumentation:

- Step view: `funnel.js:85–91`, called by `render()` at `:144`.
- Choice: declarative click attributes on the radio, `index.php:339–340`.
- Continue click: `index.php:550`; validated completion: `funnel.js:98–101`, `:1044`; next step: `:166`.
- Submit click: `index.php:553`; attempt before HTTP completion: `funnel.js:1106`.
- Thank-you view: `thank-you.php:197`. It is a page event, not proof LP accepted/delivered a lead.
- Programmatic event queue: `includes/track.php:47`; deferred tracker: `includes/analytics.php:31`.

The fetched Umami tracker delegates capture-phase clicks to the nearest `data-umami-event` element. The label's native forwarded radio click supplies that target. No `oid`-specific condition exists. **Choice clicks before the deferred tracker attaches are not queued**, unlike programmatic step views. That is a shared potential undercount mechanism, not an established explanation for the cohort gap. Keyboard changes may also escape click-only analytics. Existing step-completion and step-2-view events help distinguish that from actual advancement.

A current `event_view_debt_amount` indicates funnel execution reached the final `render()` after the Continue/change handlers were installed (`funnel.js:1014`, `:1051`, final `render()`). A universal parse/hydration failure is consequently inconsistent with these events from the current build. Historical/cached builds remain unverified.

### Independent Umami observations

Source: [shared dashboard](https://cloud.umami.is/share/4XbqcyO28U5M6NK1). Date control: **Last 24 hours**, observed during this investigation. The original 421/1,632-session date range was not supplied; these are a separate check. Query filters cover each offer across affiliates, not only affiliate 995. Counts below are event totals, **not unique-session conversion rates**. The live window can include our QA pageviews/clicks and shift during inspection.

| Event | All 914 | All 915 | US 914 | US + Chrome 914 | US + Chrome 915 |
|---|---:|---:|---:|---:|---:|
| Debt-step view | 442 | 761 | 339 | 172 | 327 |
| Debt-choice click | 27 | 415 | 6 | 2 | 170 |
| Step-2 view | 9 | 358 | 4 | 1 | 141 |

The US Chrome comparison is particularly useful: the gap persists while holding these two broad dimensions constant. See [914 evidence](914-us-chrome-events.txt), [915 evidence](915-us-chrome-events.txt), [914 US evidence](914-us-events.txt).

Overview additionally showed 914: 329 visitors, 246 US (74.8%), 36 Sweden, 32 Ireland and 10 Afghanistan; 915: 679 visitors, 678 US (99.9%). 914 was 55% Chrome, 21% iOS and 8% Facebook; 915 was 42% Chrome, 1% iOS and 35% Facebook. Thus the cohorts are not a controlled offer-ID experiment. Non-US traffic is material but cannot explain the severe US-only gap. Browser classification is not proof of a human visit or an exact engine/version.

Both the reported gap and this independent event comparison occur **before LeadProsper is called**. Actual outbound LP requests occur server-side after lead storage (`submit.php:799–828`). LP remains untested, but cannot directly block a debt radio before submission in this architecture.

## 8. Root-cause assessment

**Exact root cause: not established.** Strongest current working hypothesis: a difference in the arriving traffic or its session context, rather than `oid` selecting broken application behavior. Ad intent mismatch, low-intent/accidental clicks, previews/preloads or automation, and device/version-specific problems must be distinguished with evidence. None is proven here; geography alone and an exclusively Meta-webview issue are insufficient explanations.

Missing decisive evidence: the original fixed reporting window, affiliate-995-only session funnel, actual ad preview/redirect chain and placement/optimization breakdown, and a failing user's browser trace. Current analytics count absence of interaction; they cannot tell whether the person declined to click or a click was intercepted.

## 9. Minimal safe next action / fix

**No production behavioral fix is justified yet. Do not switch 914 to 915 as a presumed repair.** With affiliate 995, that switch would still use Everflow offer 914 and would change passthrough attribution.

First replay the actual ad link on a real affected device/webview while capturing network, console and input events. Segment a fixed pre-QA window by affiliate, placement, device/version and country. If code remains unreproducible, audit campaign objective/optimization, creatives and placements against genuine downstream leads.

Proposed development-only instrumentation (not deployed): before funnel initialization, attach error/unhandledrejection and capture-phase pointerdown/click listeners; log debt-option rendered, checked change, Continue, validation result and next-step rendered. Include only elapsed time, build, `oid`, `affid`, viewport, visibility, event type, target category and boolean checked/disabled/config-present status. Avoid names, address, DOB, contact details, full URLs/cookies or lead identifiers. Pair this trace with a HAR on a consented diagnostic session. A future tracking-only improvement could move choice reporting to a queued `change` event, replacing the declarative click event to avoid double counting; it must not be represented as the conversion fix.

## 10. Regression checks to add after diagnosis

1. Both offer query values × all five debt options: label text/card/radio decoration click, checked styling, Continue → step 2; empty selection stays on step 1 with an error.
2. Desktop, narrow viewport and real Safari/Meta webviews, including touch and keyboard input.
3. Block/delay Umami, Everflow, TrustedForm and storage access: option selection and advancement must remain usable; verify analytics semantics separately.
4. Same affiliate/different `oid`: identical form configuration and validation; correct passthrough `oid`; affiliate-derived Everflow offer remains 914 for 995.
5. Fully isolated staging submission with mocked credit/buyer integrations: correct request fields, 422 recovery, network failure, accepted response and redirect. Existing QA LP mode alone is insufficient isolation because other integrations can still run (README.md:272).

Only investigation artifacts were added. The pre-existing untracked `bin/equifax-student-probe.php` was left untouched.
