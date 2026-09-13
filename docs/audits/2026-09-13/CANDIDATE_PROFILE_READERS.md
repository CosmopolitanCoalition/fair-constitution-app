# Remaining candidacy-tab reader repairs within the one public profile

13 September 2026. These findings are from running application source, not completed performance tests. They add a concrete reader repair alongside EO-5 individual endorsement controls.

There is **one public profile per person** at `/people?who=...`. Candidate links redirect to that person's Candidacy tab; no separate candidate identity or profile is proposed. The operator reiterated that all public activity, candidacies and current/past office must be reachable together. The additional [public history repair](PERSON_PUBLIC_HISTORY.md) now pages old civic actions, published documents and recorded office history. The performance findings below concern that same profile's remaining candidacy-tab queries.

`CandidacyPanel::standingFor` calls `ApprovalService::standings` and materializes a whole race to display one candidate's position, finalist line and leader. The open-ballot directory was already repaired; this separate profile consumer still uses the full collection.

`CandidacyPanel::endorsementsFor` loads every organization endorsement, every individual endorser, the public endorsements of all those people in the election, and all candidate user IDs in that election. The public endorsement web therefore expands beyond the selected profile without a page bound. It needs independent organization/individual pages and bounded expansion for a selected public endorser, while preserving private individual nondisclosure.

The same profile reader filters individual/organization types through `Endorsement::ENDORSER_USER` (`user`) and `ENDORSER_ORGANIZATION` (`organization`). `CivicsStage::mintEndorsements` writes `users` and `organizations`. The baseline permits either string and the unique key includes that string. The reader must recognize existing simulated rows consistently; individual endorsement create/update must not create a second logical endorsement under the other spelling. Use scoped compatibility reads/writes rather than a whole-world rewrite.

These are confirmed code gaps, not a measured claim that a selected live candidate currently times out. Completion requires fixtures with more than one page, exact expansion, private-name/endorsement suppression, standing/threshold parity with the existing source, duplicate spelling compatibility, and PostgreSQL plans for the bounded queries.
