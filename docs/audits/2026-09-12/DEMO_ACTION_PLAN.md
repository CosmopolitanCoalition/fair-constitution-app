# Demo build punch list

Updated 13 September 2026. **This list contains confirmed missing features and repairs only.** Each row includes the internal test required to finish that build. Checking whether something works is tracked separately; it is not itself a build item.

[Completed work and passed checks](DEMO_COMPLETED_WORK.md) · [Pending internal checks](DEMO_REVIEW_REGISTER.md) · [Human-dependent checks](DEMO_DEFERRED_CHECKS.md) · [Deployment handoff](NEXT_SESSION_HANDOFF.md)

Finish the current build before starting the next. Consolidation comes first, then the remaining institutional actions and supporting features. Rehearse the completed flows with simulated participants before the final language/accessibility review. Setup must then produce both a walkable demo and a player-ready beta. Performance repairs accompany the affected feature.

## Progress since the instruction to work through both lists

Fixed baseline: commit `8bf59184`, immediately before this development batch. Closed means development and required internal tests passed; partial review passes do not count as a closed review.

| List | At baseline | New confirmed items | Closed | Remaining |
|---|---:|---:|---:|---:|
| Build punch list | 29 | 14 | 36 | 7 |
| Internal review register | 29 | 0 | 16 | 13 |

Four new items were confirmed by the 14 September reviews: LG-1, LG-2 and LG-3 (conference-language catalogs and the player) and DP-1 (the Windows public-deploy gate, pending a ruling). The accessibility passes added A11Y-1. On 14 September DP-1 closed (Windows public-deploy gate, merge b691d84b) and LG-0 (the translation export and import tooling) was confirmed and closed in one step; LG-1 and LG-2 now wait only on the operator's GO for the machine first pass. The browser accessibility pass added A11Y-2, A11Y-3 and A11Y-4 (contrast, prose links, table focus and page title). LE-5 (Learn bar on the front-door pages, merge 688627ad) was confirmed and closed in one step. All thirty-three earlier builds are closed: are B3, P2, B4, EO-6, LE-1, EO-7, B5, EO-1, E5, IO-6, EO-8, EO-2, EO-3, B6, EO-4, EO-5, IO-1, IO-2, IO-3, IO-4, IO-7, IO-5, S2, LE-2, LE-3, AC-1, G1, G2, G3, M3, M4, M5 and M6. The two closed reviews are S3 ownership concurrency and L1 lesson completion/awards/stipends. Added builds are B5, EO-8 and B6 (already closed), and IO-7 (open), each tied to a demonstrated defect or missing action. EO-4 nomination, committee designation, confirmation and seating are now integrated and internally tested. The separately requested map-sidebar cleanup is completed extra work, outside this fixed baseline. These are item counts, not a percentage of effort or a cost forecast. Earlier completed work remains in the archive and is excluded from this fixed-baseline comparison.

## 1. Complete the institutional action paths

| ID | Confirmed build / repair | Done when development and internal tests establish |
|---|---|---|

Evidence: [board audit](ORGANIZATION_BOARD_AUDIT.md), [election and institutional checks](../2026-09-13/ELECTION_OFFICE_CHECKS.md), [interjurisdictional/setup audit](SETUP_AND_SCENARIO_AUDIT.md). E4 board-chair participation is completed and has moved to the archive.

## 2. Education management and achievement integration

| ID | Confirmed build / repair | Done when development and internal tests establish |
|---|---|---|
| LG-1 | Author the fourteen English namespace catalogs absent from every conference locale (c_bill, c_community, c_explore, c_host, c_learn, c_legislature_workspace, c_live_commons, c_loading, c_navigation, c_references, c_rooms, c_term_sync, flows, places) for ar, es, fr, hi, pt and zh-Hans; fr and pt additionally c_shell, c_shellv2, chrome and registry. Machine first pass through `scripts/i18n/translate_run.py` once the provider is ruled (rubric `translation-first-pass-provider`). | Every English namespace file exists per conference locale and the absence check in `tests/js/i18nCoverage.test.mjs` passes. |
| LG-2 | Complete the untranslated keys in the namespaces that exist per conference locale (10,597 keys on 14 September; c_education the largest at 165 to 259 keys per locale). Same machine first pass, then the human naturalness review that the register keeps separate. | The key diff in `tests/js/i18nCoverage.test.mjs` reports zero missing keys for the six conference locales. |
| LG-3 | Internationalize `resources/js/Components/Media/MultiTrackVideoPlayer.vue`: the audio and captions labels, the transport aria-labels and the three audio, video and caption error and fallback messages through vue-i18n, with keys in all seven locales. | No English literal remains in the player's labels or messages; the keys exist in every locale; the player's synthetic and browser tests stay green. |
| A11Y-1 | Label the 67 accessible-name gaps the static scan pins in `tests/js/a11y-baseline.json` (18 .vue files): 64 form controls with no programmatic name (Setup/Step1_Constants.vue 28 label-without-for inputs and selects; Jurisdictions/Federation.vue 9 placeholder-only fields; Setup/OperatorSetup.vue 4; Jurisdictions/Index.vue 4; Operator/Dns.vue 3; Economy/AgreementDetail.vue, Economy/OrgSettings.vue, Legislature/Districts.vue, Legislature/TypeBDistricts.vue, Operator/Operations.vue 2 each; ImportBackupPanel.vue file input, RowDetailPanel.vue textarea, Invite/InviteButton.vue read-only input, Jurisdictions/Show.vue, Setup/Step0_CosmicAddress.vue and Setup/Step2_MapData.vue selects), the live participant video tile (Components/Civic/Room/ParticipantTile.vue:76, name it from its label) and the two controls-less audio sinks (ParticipantTile.vue:85, Media/MultiTrackVideoPlayer.vue:352, aria-hidden). | `tests/js/a11yStaticScan.test.mjs` reports zero remaining baseline findings and the baseline file is empty; `multiTrackVideoPlayer.test.mjs` and the browser accessibility sweep stay green. |
| A11Y-2 | Contrast. Raise `--gov-fg-subtle` (`resources/css/cga/tokens.css:94`, renders 4.16:1 on the page background, comment claims 5.57:1) to at least 4.5:1 on the darkest surface it lands on and correct the comment; stop dimming role-card text with opacity in `resources/js/Pages/Social/Achievements.vue` (lines 89, 119, 145, 158) and `.role-card--planned` (`components.css:818`), use a token instead; replace Tailwind `text-gray-500` labels in `Setup/Progress.vue:148` and `Social/Reach.vue` (167, 192) with a passing token; give the Atlas eyebrow (`Atlas.vue:686`) a passing colour; fix the two `/setup/bootstrap` nodes. 1,008 axe color-contrast nodes on six routes. | `tests/browser/accessibility.test.mjs` reports zero color-contrast nodes on every established guest route at desktop and 375 px. |
| A11Y-3 | Inline prose links. Give `.citation a` and the generic prose link style an underline or a 3:1 contrast against the surrounding text (30 axe link-in-text-block nodes on 11 routes, lowest 1.41:1). | The sweep reports zero link-in-text-block nodes. |
| A11Y-4 | Semantics. Make the shared `DataTable` scroll container (`components.css:480`) keyboard focusable with a name only when it overflows (4 scrollable-region-focusable nodes on `/system/clocks` at 375 px); add an Inertia page title to `Setup/Bootstrap.vue` (2 document-title nodes). | The sweep reports zero scrollable-region-focusable and document-title nodes. |

Evidence: [education/achievement checks](../2026-09-13/EDUCATION_ACHIEVEMENT_CHECKS.md). The existing profile tab, catalog, lesson registry and video selectors are recorded as implemented and checked where evidence supports it. Do not rebuild them. Full language, lesson accuracy and accessibility checking is in the review register; demonstrated failures become specific repairs here.

## 3. Setup, mesh and scale

| ID | Confirmed build / repair | Done when development and internal tests establish |
|---|---|---|

Evidence: [setup/scenario audit](SETUP_AND_SCENARIO_AUDIT.md), [mesh/setup checks](../2026-09-13/MESH_SETUP_CHECKS.md). The public Matrix state/configuration repairs M1/M2 and term-creation repair Q1 are archived after their targeted passes; full fresh-install and two-node acceptance remain internal checks.

All tests use isolated fixtures, queues and rooms. Step 5/Dev sequences may seed those fixtures; do not rerun, reset or revert the existing completed world. Move each completed build immediately to the archive. A remote-host dependency is an internal check dependency, not a human-only deferral.
