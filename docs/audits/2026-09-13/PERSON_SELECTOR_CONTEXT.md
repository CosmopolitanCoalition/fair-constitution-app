# Repeated-name selection — B4 ownership recipients and agreement parties

Development and targeted internal testing completed on 2026-09-13 for the two assigned selectors. Approval-ballot candidate disambiguation is covered by the election work; board-chair seat context was already completed.

| Completed repair | Evidence |
|---|---|
| Distinguishable ownership recipients | `resources/js/Pages/Economy/OrgSettings.vue` renders a public profile/organization-record link, existing profile/organization UUID reference and an optional public handle for each recipient. Select controls have identity-specific accessible names. The chosen recipient retains the same context across result pages and navigation back to the draft. |
| Distinguishable agreement parties | `resources/js/Pages/Economy/ResidentAgreements.vue` renders the same context for result rows and all selected parties. Two people with identical names remain separate by their existing IDs. Remove controls identify the exact person. Profile links sit outside the checkbox label, avoiding accidental selection when opening a profile. |
| Reusable presentation | `resources/js/Components/Ui/SelectionIdentity.vue` links to existing `/people?who=<uuid>` or `/organizations/<uuid>` records. The full existing reference remains visible, so disambiguation does not rely on truncation collisions or private residence/wallet clues. |
| Bounded public context only | `app/Support/PublicPersonSelectionContext.php` looks up handles for IDs on the already-selected page, restricted to public, non-deleted social profiles. `AgreementPartyDirectory.php` and `OrgShareRecipientDirectory.php` keep their indexed name/ID cursor search and then add one lookup for at most 20 people. Empty searches/results cause no extra profile query; organization searches do not query people. |
| Stable consent payload | Remembered context is presentation state. Share issuance still submits only holder type/ID/units; agreements still submit their title, terms and signer IDs. Refreshing a selected result updates its public context, including clearing a handle no longer returned. |

## Passed internal checks

- `php vendor/bin/phpunit tests/Unit/AgreementPartyDirectoryTest.php tests/Unit/OrgShareDirectoriesTest.php`: **20 tests, 256 assertions passed**, each using an explicit private SQLite memory connection. Existing 47-person duplicate-name cursor traversal and backward navigation remain intact. New 24-person same-name fixtures confirm unique profile URLs, public/private/jurisdiction/deleted handle filtering, at-most-20-ID enrichment, and access to later-page context. Existing malformed-cursor, wildcard, non-Latin, self/deleted-record and ownership-scope checks still pass.
- `node --experimental-vm-modules --test tests/js/personSelection.test.mjs`: **5 tests passed**. Real compiled OrgSettings, ResidentAgreements and SelectionIdentity render and update in a synthetic Vue renderer. Inertia's form/remember/router boundary is simulated; tests cover duplicate-name selection, page replacement, remount of saved drafts, exact payload IDs, clearing/changing recipients, distinct accessible controls and refreshed removal of a previously public handle. No real issuance, agreement filing or browser session mutation occurs.
- The changed page/component templates compile as part of those tests. `git diff --check` passed.

## Existing naming policy kept separate

The two searches already have a deliberate contract different from the public person profile:

- Agreement-party search matches `users.name`; `AgreementPartyDirectoryTest` explicitly identifies this as the **consent-name** roster and excludes the unrelated public display name from that search.
- Ownership-recipient search prefers `display_name` but falls back to `users.name` for the existing **named ownership** record.
- Public profile/governance presentation uses the chosen public name and pseudonym contract; it should not silently redefine names used by these existing consent records.

This pass preserves those search semantics and their existing indexes. It adds no private fields to their payloads: the extra handle appears only when its social profile is public; location, legal-name lookup beyond the existing contract, email and wallet binding are not added. Whether consent/ownership names should be unified with public pseudonyms requires operator interpretation of these differing contracts. That is a naming-policy decision, not an unfinished repeated-name UI repair. No new search architecture, identity system, schema migration or constitutional rule was introduced.
