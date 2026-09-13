# Learn consolidation — LE-1

Development and targeted internal checks completed on 2026-09-13. This closes LE-1's shared scaffold, lesson navigation and five missing-context repairs. It does not claim every lesson's wording, every page's independent markup, or every language has passed final review.

| Completed change | Runtime evidence |
|---|---|
| Move the scaffold's instructional content into Learn | `resources/js/Components/Surface/PageScaffold.vue` keeps the title, short introduction and working page content; its `about` slot now uses `LearnContent.vue`. Vue's deferred Teleport mounts that page-owned content inside the Learn drawer, including when the drawer is closed. Page updates, replacement and unmount remove the corresponding content. |
| One home for references | `resources/js/Components/ShellV2/LearnFlyout.vue` renders named related actions and a separate Reference codes disclosure. The old main-page About disclosure is removed; raw workflow codes no longer decorate the ordinary flow labels. |
| Connect existing lessons and videos | The disabled future-lessons chip is replaced by active `/learn` and `/videos` links in Learn. No new lesson engine or video player was invented. |
| Both application shells support Learn | `resources/js/Layouts/AppShellV2.vue` and `AppShell.vue` provide unique targets. Legacy operator/dev pages retain their existing menu and receive a Learn-only command bar; footer/dev controls reserve space above it. A standalone scaffold still has a native Learn disclosure if rendered without either shell. |
| Five specific guides | `config/cga/surfaces.php` now registers `economy/work`, `economy/help`, `economy/help-detail`, `rooms/directory` and `learn/video-library`. Their controllers supply metadata on normal and early-return paths; page props accept it. Registry navigation IDs match the existing menu. |
| Guidance describes actual available actions | `docs/plans/education/K2_CONTENT_DEMO_CONSOLIDATION.md` covers application/offer/countersignature, private help drafts and responses, helper selection/withdrawal/completion, rooms by place and institutional access, and audio/subtitle playback/retry. The generator produces the English payload and registry; no translation files are silently regenerated. |
| Video page focuses on choosing and watching | `resources/js/Pages/Learn/VideoLibrary.vue` retains the real player and catalog. Player guidance and the translation-workspace link move into Learn. Claims that every guide already has a film and tracks can never fail are removed. |

## Passed internal checks

| Check | Result and scope |
|---|---|
| `node --experimental-vm-modules --test tests/js/learnFlyout.test.mjs` | **7 passed.** Real compiled Scaffold, LearnContent, LearnFlyout, ReferenceText and CmdBar components execute in a synthetic renderer. Tests cover deferred target mounting; main-content separation; live lesson/video links; all five authored guides; reactive slot updates; repeated navigation with/without guidance; no stale or duplicate content; Escape focus restoration; one-open/outside-click/navigation closure; listener and Teleport cleanup on unmount; legacy Learn-only mode; standalone fallback. |
| `php vendor/bin/phpunit tests/Unit/LearnProfileReadFixtureTest.php` | **4 passed, 49 assertions.** Explicit private SQLite memory database. Real LearnController reads live tracks/modules for guests, omits drafts and answer keys, and scopes completion to the signed-in learner. Real PersonProfileController and JourneyService read earned awards for the selected person, newest first; private profiles suppress them for guests while self access remains. Legal name/email and another person's awards are absent. Office resolution is mocked and candidacy tables empty: this is not an office/candidacy lifecycle test. |
| `php vendor/bin/phpunit tests/Unit/WorkWorkflowTest.php tests/Unit/AssistanceWorkflowTest.php` | **26 passed, 240 assertions.** Existing explicit SQLite-memory simulated-participant fixtures still pass after controller metadata additions. Consent, actor scoping, private responses, history paging and withdrawal/recovery are covered by those fixtures. |
| SFC compilation | All **11 changed Vue components/pages/layouts** compile with `@vue/compiler-sfc` in memory. No production asset build. |
| `node scripts/education/build_education_payload.mjs` | Passed. **111 registered surface IDs all covered; 122 authored entries, 947 English strings.** Eleven authored-ahead entries remain outside the surface registry, explicitly reported by the generator. This is metadata coverage, not a claim that every route has a fully reviewed lesson. |
| Whitespace/diff check | `git diff --check` passed; only pre-existing shared-checkout CRLF normalization warnings appeared. |

No live PostgreSQL query, civic action, simulation restart, media upload, remote deployment or production build was used for this work.

## Remaining scope

- **LE-2:** Educational material management and persistent publication remain a separate confirmed build item.
- **LE-3:** Connecting a specific lesson/surface to a playable multilingual film remains a separate confirmed build item. This pass links the existing library and preserves its selectors.
- **AC-1:** Broader civic/economic achievement triggers remain a separate confirmed build item. This pass verifies the existing profile ledger read and privacy behavior; it does not create new awards.
- Full route-specific content/language/accessibility review remains an internal check in the [review register](../2026-09-12/DEMO_REVIEW_REGISTER.md). A concrete failure found there becomes a build repair. Subjective clarity and assistive-technology comfort with real users remain human review; they do not hold LE-1 open.
