# Hiring workflow — E1

12 September 2026. Implementation evidence for the resumed economic action pass.

## Delivered path

| Participant | Action | Recorded result |
|---|---|---|
| Current agent of an active organization | Open **My work & hiring → Hire for an organization**, select the organization and publish a title with complete work/pay/schedule terms. | Public work posting; no worker or contract created. |
| Applicant | Open the shared work posting and send an optional private note. | Existing work-application form records the application for the authenticated person's account. |
| Organization agent | Select the posting, privately review its applications, and send complete offer terms or decline. | Offer terms and date recorded. Sent terms cannot be replaced while the applicant decides. |
| Applicant | Open **My applications**, read the offered terms, and explicitly accept or withdraw. | Only the owner of the application's account can accept. Acceptance files the existing worker-registration form and stores its returned contract in the existing `org_contract_id` column. The posting is filled; employment is not labeled active yet. |
| Organization agent | Follow **Review and countersign agreement**, read the complete terms, then countersign. | Existing organization contract action records the second signature; the existing worker/headcount/co-determination chain remains authoritative. |

Closing a posting stops new applications and outstanding offer acceptance. Pending applicants can still withdraw. A prior application remains recorded after withdrawal or decline and cannot create a duplicate entry. Public organization job cards now lead to the shared review/application page. Existing applications lead to their status instead of a second apply button.

## Authority, privacy and browsing

- Posting, offer, decline and close check the exact current agent and active organization. A stale agent cannot continue managing hiring.
- The applicant/account binding is checked server-side for the signed-in worker. Employer review does not receive account identifiers, owner bindings or resolved private applicant identities; it receives the application note and date. Agreement parties follow the existing private contract policy.
- Acceptance locks the posting and application, verifies an outstanding offer and exact terms, and files worker registration as the actual applicant. Another acceptance cannot fill the posting again. Engine refusal rolls back acceptance and posting state.
- The selected agreement allows its current organization agent to read and countersign even if a different agent previously represented the organization. The global agreement directory is unchanged; unauthorized readers still receive 404.
- Organizations, postings and applications each use 20-entry cursor pages. Organization/posting/record identifiers and cursors are validated before domain reads. A person without an account does not query the application table. New indexes cover agent, employer, posting and applicant scopes.
- The forms expose inline errors and saving states, labels and keyboard focus. Posting drafts use an account/organization-specific remembered form. Complete language/accessibility coverage remains the later plan phase.

## Validation and rollout

- **44 targeted tests / 514 assertions passed** across `WorkWorkflowTest`, `EconomyNavigationTest`, `EconomyWriteFormsTest` and `EconomyPrivacyTest`. Fixtures cover applicant ownership, stale/wrong agents, immutable offers, duplicate filling, engine refusal, withdrawal/decline history, multi-page applicant/employer review, bad cursors, private fields, selected agreement signing permissions, additive migration resume and direct opening of a newly published posting. Docker Desktop briefly returned an API error before the last test run; its engine recovered and the final 13-test hiring suite passed.
- Changed PHP files pass syntax checks. Six affected Vue components compile individually (script, template and styles); no production frontend build was run.
- The signed-in browser loaded both **My applications** and **Hire for an organization**, showing truthful empty states for this operator's records/authority. The shared loading indicator appeared during navigation. Populated lists and decisions were verified with isolated fixtures, not fabricated live hires.
- New migration `2026_09_12_230000_work_offer_metadata.php` applied locally. It adds nullable offer terms/date and concurrent pagination indexes, retaining existing applications, contract references and status constraints. It can resume after an interrupted index creation.
- All four new indexes are valid in the local PostgreSQL catalog. Cached routes were cleared and existing Horizon workers restarted.
- Pulling hosts must run normal additive migrations and refresh cached routes. Restart existing Horizon workers when deploying changed shared services. No world reset is needed. No new dependency or Windows-specific implementation was introduced.

The hiring acceptance fixture mocks the engine boundary to verify the authenticated actor and exact payload; it does not claim a new end-to-end, multiple-participant employment rehearsal. The existing worker-registration/countersign machinery is reused without modifying constitutional rules. Actual hiring/signing was not performed against the simulated world. Full attendee scenario rehearsal remains S1.
