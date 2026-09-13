# Legislative rollover repair (EO-1)

13 September 2026. General-election certification now retires the outgoing chamber's committee placements and presiding offices along with its legislative seats. Retained committees return to the existing assignment path; incoming members can elect their Speaker and committee chairs.

## Repaired behavior

- Live committee seats are marked vacated with a turnover reason. Already-vacated placements, preferences and closed ballots remain historical records.
- Only retained, nondeleted committees return to `created`; their chair and alternate pointers clear. Dissolved/deleted committees are not revived.
- The chamber's Speaker pointer and denormalized member flags clear. Its unfinished Speaker/chair ballots become void because they describe the outgoing electorate; closed decisions are unchanged.
- Assignment reads preferences only for current members. Old-term preferences remain saved without accumulating in each new allocation's input.
- Special-election replacement does not retire the rest of the chamber. Existing term dates remain immutable; a general election still uses the configured interval.

All changes are scoped to the selected legislature, within the existing certification transaction. The existing post-certification role-cache flush remains in place. No allocation arithmetic, thresholds or constitutional defaults changed.

## Executed checks

```sh
docker exec fc_app php vendor/bin/phpunit tests/Unit/LegislativeRolloverWorkflowTest.php tests/Constitutional/CommitteeAssignmentTest.php tests/Unit/CommitteePreferenceRevisionTest.php
```

**16 tests / 154 assertions passed.** The four new workflow cases use an explicitly verified SQLite memory connection. They run actual `CertificationService::certify`, term/member insertion, `ClockService` arm/cancel, retained-committee assignment and open-ballot readers. Tests cover:

- Five incoming winners, including a reelected person whose old member row must retire before the new row can satisfy the current-member uniqueness constraint.
- Unicameral and bicameral committee assignment using only incoming member rows, correct kind splits and no repeated assignment.
- Preservation of another chamber, dissolved/deleted committees, already-vacated history, saved old preferences and closed voting records.
- A special replacement inheriting the old expiry without resetting offices or committees.
- Missing sealed tabulation after turnover: the outer transaction rolls back every retirement and timer cancellation.
- A nondefault 48-month interval, preserved original outgoing expiry, and new term records.

Upstream sealed tabulations are supplied fixtures. Audit persistence, settings lookup, referendum effects and successor-election scheduling are doubles; queued social provisioning is faked. This is not a complete voting/certification engine, PostgreSQL-trigger or fresh-install acceptance claim. The fixture does not touch the live world, PostgreSQL, Redis or external services.

An independent review found no blocking scope/history defect in the change. Corrected audit recertification is a separate existing path under review: repeating `certify` for the same election must not be assumed equivalent to a new general election. Full election and second-term journeys remain in the internal review register.

## Rollout

No migration or frontend build is needed. Pull the code and restart existing Horizon workers so queued certification uses the updated services. Do not rerun certification or the simulation against the completed world as a rollout step.
