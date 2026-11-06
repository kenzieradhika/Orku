# Demo design and browser evidence

Direction: user's minimal developer-oriented, neutral/light, locally available
fonts, accessible, responsive brief. ENERGY 1 / RHYTHM 2 / MOTION 1.

- Warm neutral paper reduces glare; dark ink and forest-green links/actions give
  stable readable contrast without turning the demo into a promotional landing.
- System sans uses installed local fonts, keeping network/font dependencies zero.
- Left-aligned heading and code example explain actual renderer behavior, not
  fake product imagery. Code is a real API example, not a decorative terminal.
- Mobile navigation uses two columns of visible links; desktop uses one row.
- Split explanation/example becomes stacked on small screens; specimen labels
  sit beside components only once content fits. Forms separate task from notes.
- Cards appear only as component specimens. Lists describe capabilities directly.
- Wordmark is product-name text. Image explicitly says placeholder; no invented
  logo, team, testimonial, usage statistic, illustration or third-party asset.
- Spacing separates page introduction, technical examples and form task; small
  corner radii only distinguish controls. No shadows, gradients or animations.
- Light-only theme follows user's explicit light/neutral brief. No theme toggle.

## Browser run

2026-10-25: Playwright with installed Chromium headless shell build 1243 on Linux.
Built-in browser MCP unavailable (Chrome absent); direct installed Playwright used
without adding any project/runtime dependency. Newer cached Chrome build crashed;
headless shell succeeded. Screenshots examined at 375 and 1440; 768 also captured.

All `/`, `/about`, `/components`, `/contact`, `/missing` checked at widths 375,
768, 1440 and height 1000. Expected 200/404, no horizontal document overflow,
all displayed images decoded. Screenshots originally in
`/tmp/opencode/orku-home-{375,768,1440}.png`.

Recorded interactions:

| Control | Observed action |
|---|---|
| About nav | `/about`, real content |
| Components nav | `/components`, real specimens |
| Open feedback form | `/contact`, form fields |
| Validate demo feedback, empty | HTTP 422, visible summary and field errors |
| Validate demo feedback, valid | Redirect to `/contact/success` |
| Return to feedback form | `/contact` |
| Home nav | `/` |
| See working components | `/components` |
| Use the working form | `/contact` |
| Tab from home | Skip link receives visible focus |

Zero page errors; no unexpected console errors. Expected 404/422 request messages
were excluded from console-error classification. No JS required by application.
SSR request navigation has browser-native loading, not a fake loading spinner.
Empty contact, field-error, success, missing-page and generic error states exist.
No asynchronous data UI or loading placeholder is shipped.

## Delivery gate

- R-02 PASS: authored UI uses no em dash.
- R-03 PASS: 375/768/1440 checks show no horizontal overflow; navigation and
  controls use at least 44px minimum height; mobile columns reflow.
- R-17/18/36/38 PASS: no fabricated stats/testimonials/security certification;
  placeholder image labeled; scope and non-delivery explicit.
- R-23 PASS: text wordmark and visibly labeled placeholder, no assumed final logo.
- R-24/26 PASS: all navigation/control destinations exist; interactions above.
- R-25 PASS: ink, muted, link, white button and error text contrast against their
  backgrounds checked numerically; results below.
- R-27 PASS: empty/error/success SSR form states, browser-native request loading.
- R-28 PASS: no speculative FAQ.
- R-32 PASS: native controls/links, skip link and visible focus style; keyboard
  Tab verified. No dialogs/custom keyboard controls.
- R-33/34 PASS: styles edited directly; no UI rewrite scripts or broken extra theme.
- R-35 PASS: app run, pages/screenshots inspected, actions above, zero unexpected
  console/page errors.
- R-37 PASS: user's explicit visual brief supplies direction and dials above.
- Purpose gate PASS: colors/type/layout/spacing/cards/image reasons recorded;
  no decorative icons, badges, glow, shadows, animation or glass effects.
- Liveliness PASS: real code specimen and component/form composition supply
  content-led rhythm; clear heading focus, green action accent, consistent type.
- Craftsmanship/quality PASS: functional forms, explicit boundaries, responsive
  states, no fake proof; palette/radius/type consistent, no copied product identity.

Not a comprehensive accessibility audit: no screen-reader/device-farm testing.

WCAG relative-luminance calculation: ink/paper 14.52:1, muted/paper 6.76:1,
accent/paper 8.00:1, white/accent 8.60:1, error/paper 8.10:1,
muted/specimen-background 6.13:1. All exceed normal-text 4.5:1.
