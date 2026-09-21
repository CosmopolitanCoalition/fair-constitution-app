# Report input focus and GitHub handoff — 2026-09-21

## Change

The reported phone keyboard problem came from the form disabling its category,
summary and description whenever the viewer was signed out. These fields are
now editable for guests. Private local filing remains authenticated and keeps
the existing category routing, attribution and ticket visibility.

**Sign in to file privately** uses the existing same-origin continuation route.
It preserves the written draft in this browser tab for up to 30 minutes and
restores it once on return. The report text never enters the sign-in URL. Storage
failure explains how to retain the text instead of silently navigating away and
losing it. Draft storage happens only on the explicit sign-in/GitHub action,
not on every keystroke; restoration and successful local filing clear it.

Bug, accessibility, translation and idea categories offer **Open issue on
GitHub**. The destination is the public, issues-enabled
`CosmopolitanCoalition/fair-constitution-app` repository. This opens a prefilled
issue composer: the reporter reviews and submits there using a GitHub account.
It does not claim that opening the composer has filed an issue. No server token,
API publishing or automatic forwarding is involved. Abuse and content reports
keep the private instance route and never offer GitHub publishing.

The reporter sees the public-disclosure notice before leaving. The link includes
only the written summary/details, selected category, source page and instance
origin. Query strings/fragments and account identity are excluded. Long encoded
reports that would exceed a safe URL size are shown in full for copying into
GitHub, without silent truncation. Local private filing remains available for
software reports as well.

Forks can set `GITHUB_ISSUE_REPOSITORY=owner/repository`, or an empty value to
disable the link. Invalid repository values are rejected. GitHub documents the
supported title/body URL parameters in
[Creating an issue](https://docs.github.com/en/issues/tracking-your-work-with-issues/using-issues/creating-an-issue).

## Validation and deployment

- Three PHP tests / 70 assertions passed against guarded disposable PostgreSQL:
  guest access, local authentication, private routing/ownership, continuation,
  valid repository configuration and no outgoing API requests.
- Four JavaScript tests passed: encoded issue text, restricted destinations and
  categories, complete oversized/multibyte report handling, draft expiry and
  one-time restoration.
- Chromium and Firefox journeys passed: `tests/browser/supportReport.mjs` exercises phone-width touch
  and keyboard focus, Tab navigation, sign-in with draft restoration, local
  filing and intercepted GitHub navigation. It uses only the disposable browser
  database and never publishes a real GitHub issue.

Deploy under the existing exclusive deployment lock. Build assets in isolation,
fast-forward the tested release, publish immutable assets/locales with the
manifest last, and clear the application configuration cache. No migration,
new dependency, service restart, simulation replay or payment action is needed.
Preserve `.env` and the four existing local configuration files. Check the public
guest form can be focused and typed into; intercept the GitHub navigation during
acceptance rather than publishing a test issue. Keep private live data out of
browser fixtures.

## Deployment completed

`3a925064fb0602a46a494fe1db3fa2b43c547ca4` deployed at 12:04 UTC on
September 21. The isolated production build and exact live asset hashes passed.
A fresh phone-width Chromium session on the public demo confirmed guest touch
focus, Tab navigation and typing, correct GitHub prefill, no GitHub action for
abuse reports and zero page errors. GitHub navigation was intercepted; no live
report or GitHub issue was created. The disposable database was removed.

All checked services retained their start times. `.env` and the four existing
local configuration files retained their hashes. No migrations, dependency
changes, service restarts or world-data changes were performed.

Evidence: `/home/cosmo/wos-step5-operations/evidence/SUPPORT-REPORT-20260921/`.
The remote checkpoint records `supportReportDeployment`.
