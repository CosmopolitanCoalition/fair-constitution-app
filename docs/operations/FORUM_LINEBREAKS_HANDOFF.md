# Forum line breaks — 2026-09-21

Public Square and Halls rendered stored post bodies in ordinary paragraphs,
so browser whitespace handling collapsed newlines and blank lines into spaces.
Their composer, controller and SocialSpaceService preserve interior newlines;
there is no body mutator that removes them. Both readers now use `white-space:
pre-wrap` and `overflow-wrap: anywhere`, matching the existing bill-comment
reader. Vue text interpolation still escapes HTML. Existing stored posts benefit
without a data rewrite.

Validation uses the real Inertia pages and shared shell in Chromium and Firefox,
with bounded intercepted fixture posts: LF and CRLF, blank paragraphs, literal
HTML, long links at phone width, and exact multiline composer submission. No
installed database or public posting endpoint receives fixture writes.

Deploy the frontend assets under the existing exclusive deployment lock, using
an isolated build and publishing hashed assets before the manifest. Preserve
configuration and existing hashed assets. No migration, service restart,
configuration clear, simulation action or post rewrite is required. Check both
public page bundles with fixture content intercepted only in the browser; do not
publish verification posts to the live forum.

## Deployed

`1074eb20fc2e88cae1ac968de4f3c2b1a706891d` deployed at 17:32 UTC on
September 21. Chromium and Firefox passed the local checks above. The isolated
production build and exact public asset hashes passed; both deployed page
bundles also passed the multiline rendering checks in Chromium using
browser-only fixture data. No live posts were created or changed.

Services retained their start times and configuration retained its checksums.
Evidence is under `/home/cosmo/wos-step5-operations/evidence/FORUM-LINEBREAKS-20260921/`;
the remote checkpoint records `forumLinebreakDeployment`.
