# Share issuance and ownership browsing

13 September 2026. E3 now has a usable share-issuance entry on the organization's Finances page. Actions use the existing F-ORG-008 engine path.

| Participant step | Implemented behavior |
|---|---|
| Find a recipient | Search a person's public display name or an organization's name by prefix. Twenty matches per page; no roster query until a name is entered. Selection and quantity are retained independently from search and ownership pagination. |
| Review and issue | The exact organization agent selects a recipient and enters a positive quantity. Only stock organizations can issue; dissolved organizations refuse. The form explains that issuance changes public ownership percentages and does not collect payment. |
| Validate | Both HTTP and handler verify recipient type, UUID and current existence. The route determines the organization and the authenticated user determines the actor. Units remain decimal text, with the database's 14 integer/six fractional digit capacity enforced. |
| Record | The existing ownership service opens a stake, maintains the user-holder membership invariant and recomputes percentages. Existing stake history is retained. Engine role, validation and audit processing remain in place. |
| Browse | Current ownership stakes have 20-entry cursor pages, with batched public names for that page. Each row is a stake, not a unique holder. The old all-stakes load, per-row name queries and page-entry aggregate total are removed. |

The finances page distinguishes ledger visibility from action authority. Seated board members retain their existing private ledger access. The current F-ORG-001 settings and F-ORG-008 issuance handlers require the exact agent, so the page now reflects those actual restrictions instead of promising board members a settings action that fails. This does not extend board authority. A separate board policy/action review can resolve the unused broader permission in OrgSettingsService deliberately.

Search and ownership pagination use Inertia partial responses. Currency, account bindings, ledger, taxes, dues and conversion readers are deferred, so these partials do not load unrelated financial sections. The existing ledger/tax/conversion history caps still belong to the remaining B3/P2 work.

## Scaling and consistency

The ownership writer now reads the selected organization's stakes in host-derived chunks and uses decimal totals and percentages. Stake closure is chunked as well. Transactions publish one coherent ownership change; partial percentage updates do not commit on their own. Issuance, resale and dissolution take the organization lock before changing stakes. Dissolution retains its existing rules and histories, with rollback protection around the complete operation. Registered stock organizations can still issue shares; no new active-only or timing gate was added.

Recalculation is still synchronous and proportional to that organization's current stake count. This avoids the prior unbounded PHP hydration but is not proof of low latency for a giant organization. SQLite fixtures verify ordering and rollback behavior, not PostgreSQL contention. A dedicated multi-connection contention check and larger-organization timing remain future evidence.

## Validation and rollout

- **71 isolated tests / 539 assertions passed** across `ShareIssuanceWorkflowTest`, `OrgShareSurfaceTest` and `OrgShareDirectoriesTest`, including the final targeted feedback rerun. These explicitly use private SQLite memory connections, with text decimal fixtures. Coverage includes exact large quantities, recipient/agent/structure/lifecycle refusals, selected-organization scope, retained membership/history, bounded multiple-page percentage recomputation, private HTTP partials and recipient search. Actual resale with a binary-float legacy remainder and dissolution success/rollback are covered. The HTTP engine boundary is mocked; this is not a claim of a complete browser/audit-chain transaction.
- Changed PHP passes syntax checks. The finances Vue component compiles individually. No frontend production build was run.
- The live organization directory and a simulated organization's Finances page loaded read-only, with loading feedback and restricted wallet/levy state. No operator share issuance, trade, dissolution or settings change was performed. That sampled simulated organization has no recorded ownership structure or representative, which is relevant to later demo-world acceptance checks; it is not evidence that every simulated organization has that condition.
- Migration `2026_09_13_010000_org_share_directory_indexes.php` was applied locally. All three indexes are valid. Non-executing PostgreSQL plans use the two recipient-name indexes. The sampled empty organization's ownership plan chooses the existing scoped organization index and a small sort; the new organization/ID index is available for paging and chunked passes. No whole-world diagnostic query was run.

Pulling hosts must apply the additive migration and refresh cached routes. Restart existing Horizon workers after pulling the changed shared ownership/organization handlers. Existing worlds are retained. Remote Linux deployment and the full simulated civic rehearsal remain separate checks.

Related evidence: [organization board actions](ORGANIZATION_BOARD_AUDIT.md), [help workflow](HELP_WORKFLOW.md), [setup and scenario audit](SETUP_AND_SCENARIO_AUDIT.md), [action plan](DEMO_ACTION_PLAN.md).
