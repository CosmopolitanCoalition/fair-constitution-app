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
