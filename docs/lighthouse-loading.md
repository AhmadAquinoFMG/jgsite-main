# Landing-page loading fixes

The September 10 mobile PageSpeed report flagged render-blocking Google Fonts
and CSS, missing priority on the LCP logo, image dimensions, and Continue-button
contrast.

## Changes

- Serve the existing Poppins weights and Unicode subsets locally, with their
  license. Preload only Latin 400 and 700; retain `font-display: swap`.
- Inline the existing stylesheet and font declarations on the landing page to
  remove stylesheet round trips. `assets/css/style.css` remains the source of
  truth; no build step or asynchronous stylesheet swap is required.
- Preload/prioritize the header logo and declare the badges/footer logo sizes.
- Use the existing darker brand greens for primary-button contrast.
- Cache only the versioned WOFF2 files for one year via their scoped .htaccess.

Form markup and all script tags were compared with HEAD and are unchanged.
No tracking delays, pixel removals, routing changes, or submission changes were
made. Third-party caching, unused code, and execution warnings may remain.

## Validation

- PHP syntax checks and `git diff --check` passed.
- Mobile header, funnel, active step, button, and badge dimensions matched the
  pre-change page. Required-choice validation, Continue, and Back were checked.
- Local Lighthouse 13.4.0, mobile preset, with PHP HTML compression enabled:
  performance 82, accessibility 100, SEO 100; FCP 1.1 s, LCP 3.2 s,
  TBT 440 ms, CLS 0.001.
- Render-blocking, font-display, image-dimension, and color-contrast audits pass.
  All four requested Latin fonts returned HTTP 200.
- Local Best Practices was 58 due to HTTP/third-party cookie/inspector findings.
  The localhost PHP server does not apply Apache .htaccess directives. These
  results are not directly comparable with the production PageSpeed run.

Deploy the changed PHP/CSS files and the complete `assets/fonts/poppins-v24`
directory, including its .htaccess. Then rerun mobile PageSpeed against HTTPS
production to establish the live score and verify font cache headers. Do not
cache the session-dependent PHP page to improve the score.
