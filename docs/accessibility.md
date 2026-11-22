# Admin accessibility verification

[Project overview](../README.md)

## Target and scope

The admin area targets WCAG 2.2 Level AA. Automated checks use
`@axe-core/playwright` with WCAG 2.0/2.1 A/AA, WCAG 2.2 AA, and best-practice tags.
Detected violations fail browser verification and therefore release acceptance.
Rules and page regions are not disabled to obtain a passing result.

A passing axe run covers only automatically detectable issues in the tested
states. It does not establish complete WCAG conformance, legal compliance, or
compatibility with every browser and assistive technology. Screen-reader and
native browser zoom checks below remain manual release work.

Public Latvian pages retain their existing markup and design. Printed permits
retain their existing appearance; admin success messages are hidden in print.
Their established screenshot, QR, and print regression checks remain in the suite.
This change does not certify those legacy layouts against the admin target.

## Running and reviewing checks

All tools run through Docker against an extracted production archive, isolated
Apache/MySQL services, and synthetic fixtures:

```sh
make browser-check
make release
make test-browser
```

For focused iteration after building an archive:

```sh
make test-browser ARGS=accessibility
```

The full run remains the release check. Existing visual baselines are compared
without automatic updates. Browser tools and axe are development dependencies
and are excluded from the production archive; runtime pages load no axe code.

Each scan writes a complete `*.axe.json` result under
`.phpunit.cache/browser/test-results/` and attaches it to the HTML test report.
Review both `violations` and `incomplete` findings; the latter need human judgment.
Reports retain passes, rule metadata, and affected markup for diagnosis. Calendar
screenshots support desktop/mobile review. `make browser-report` serves the HTML
report locally; CI includes this evidence in its existing 14-day browser artifact.
Reports contain only the disposable synthetic environment.

Scans wait for fonts and current CSS/jQuery transitions to settle. This measures
the completed control state. Unexpected JavaScript, CSP, external-request, and
console errors still fail. Negative form tests explicitly declare the expected
401/422 document responses, check their exact navigation sequence, and permit
only the corresponding browser resource-error messages.

## Covered behavior

| Area | Automated coverage |
| --- | --- |
| Login and navigation | Login scans in EN/LV/RU; keyboard login; logical header tab order; visible skip link and main-content focus |
| Language selector | Open-state scans in all languages; keyboard selection, current language, Escape dismissal and focus restoration |
| Admin pages | Permit list, blacklist, check history, and generation form in all three languages, including labelled table regions |
| Validation | Focused server-error summaries and form descriptions for login, generation, and blacklist; invalid date identified on its field |
| Action feedback | Translated success messages, focus after changes, refresh dismissal, styled removal dialogs, cancellation without submission, and disabled removal without JavaScript |
| Calendar | Open and selected-day scans, translated month/year labels; typed date entry, day/month shortcuts, selection, Escape and onward Tab |
| Reflow | 320 CSS-pixel width in all admin languages, local table scrolling by keyboard, open language selector and calendar |
| Text resizing | 200% root text enlargement on the four authenticated pages with Russian wording |

Layout checks cover narrow reflow and text resizing. Native browser zoom remains
a separate manual check. Data tables can scroll within their labelled regions;
the surrounding document must reflow without horizontal scrolling. Focus tests
check an outline and a visible, unobscured part of the focused component. These
checks do not require an entire large table to fit in a viewport.

## Admin behavior and keyboard use

The first tab stop on authenticated pages is **Skip to main content**. Activating
it focuses the main landmark. Normal login retains username autofocus. Returned
server errors receive focus on an alert summary; the form describes that error,
and the date field references its format hint and date-specific error.

Table regions have names derived from their page headings. Tab to the region,
then use arrow keys to scroll overflowing columns. Header links, buttons, inputs,
selects, and the language selector have visible keyboard focus. Secondary text
and outlined buttons use darker admin-only colors for contrast on the light page
background; calendar highlights and the Today button have sufficient text contrast.
On narrow screens the open language list takes space in the header's normal flow,
keeping nearby navigation links fully exposed and usable.

Blacklist removal uses a styled native HTML dialog with a labelled title and
description. Cancel receives initial focus; Escape and Cancel close it without
submitting, restoring focus to the removal button. The browser makes background
content inert while the dialog is open. Desktop and 320 CSS-pixel dialog states
are scanned in all three languages. Success messages receive focus and use a
status region; messages on generated or reprinted permits are hidden in print.

The datepicker keeps its existing nonmodal jQuery UI behavior and date format.
Focus stays in the date input for its documented shortcuts:

| Key | Action |
| --- | --- |
| Control+Home | Open the closed calendar; when open, move to the current month |
| Control/Command+arrows | Move the calendar day/week |
| Page Up / Page Down | Change month |
| Control+Page Up / Page Down | Change year |
| Enter | Select the highlighted date |
| Escape | Close without changing the selected value |
| Tab | Leave the date field and close the popup |

Dates can always be entered directly as `DD.MM.YYYY`. The calendar stays within
the main landmark, uses translated labels, and preserves the existing screen
appearance apart from the documented contrast/focus improvements.

## Manual release checks

Review the generated menu/calendar screenshots and keyboard behavior when changing
these controls. Before claiming conformance for a deployment, also verify:

- Real screen readers, such as NVDA with Firefox/Chromium and VoiceOver with Safari:
  landmarks, table headers/scrolling, language pronunciation/current state, form
  hints, returned-error announcements, and date/day/month/year announcements.
- Native browser zoom at 200% and 400%, browser text enlargement, touch targets,
  focus after scrolling, and language/menu/calendar usability on actual devices.
- OS high-contrast/forced-color modes and user styles. Computed light-theme
  contrast checks do not verify those environments.
- Datepicker shortcut conflicts with browser/assistive-technology commands,
  discoverability, direct date entry, and operation without a mouse.
- Any private template, wording, font, or CSS overrides used by the deployment.
  Shared synthetic fixtures cannot certify private customizations.

Record the browser/OS/assistive technology and remaining findings for each release.
Automated and visual review supplements this work; no real screen-reader session
or provider-specific manual conformance audit is asserted by the repository tests.

References: [Playwright accessibility testing](https://playwright.dev/docs/accessibility-testing),
[jQuery UI keyboard behavior](https://api.jqueryui.com/datepicker/#keyboard-interaction),
[WCAG reflow](https://www.w3.org/WAI/WCAG22/Understanding/reflow.html), and
[text resizing](https://www.w3.org/WAI/WCAG22/Understanding/resize-text.html).
