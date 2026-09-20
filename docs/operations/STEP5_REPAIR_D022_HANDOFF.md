# D022 — Committee vocabulary exhaustion

The completed repair reached 923,090 DONE / 923,095 with five reviews and no
remaining claims. Four are the separate candidate-allocation conflicts already
recorded in D020. Earth successfully recovered its election and seated all 3,153
representatives, but governance stopped at 24 of the formula's 25 committees.
The generator's preferred name list had only 24 entries. This is a generator
defect, not a new size rule or an election failure.

The normal GovernanceStage now supplements its preferred names with distinct
numbered General Affairs names until the existing target is reached. Existing
names, committees and targets remain unchanged. Every new committee still uses
F-LEG-009 and the real legislative vote. Fresh runs use the same correction.

`sim:repair --retry-committee-names=RUN --scope=UUID` accepts only the exact
blocked governance receipt proving vocabulary exhaustion, with matching current
inventory, formula target and original source scope. It preserves prior receipt
history and requeues the existing item. Unrelated reviews and all completed
items/actions remain untouched. No inventory, Apply, election or payment replay.

A fully settled repair may continue only when its completed worklist is retained,
there are no workers/open claims or competing active run, and a matching review
was actually requeued. Pump/repair-control locks coordinate the transition. Its
prior completion/timings are audited, and it returns to halted/repairing until
explicit Resume. Repeated requests cannot requeue the pending item again.

Local validation: **38 tests / 647 assertions**, guarded disposable PostgreSQL
and constitutional compatibility tests. Covers fresh 25-committee generation
through adopted acts, 24-to-25 recovery, naming collisions, repeat idempotence,
source-bound CLI retry, competing-run/live-claim refusal, preservation of applied
election/training receipts, old committees, ledger entries, other reviews and
completed items. The constitutional fingerprint is unchanged.

Deployment: PHP only, under the existing exclusive deployment lock. The same
repair run is already done/drained. Pull the committed fix, refresh Horizon only,
retry Earth (`27cf7c64-2c76-4fd5-8544-bfd223cfdc72`), resume the same run. No
migration, assets, scheduler/PostgreSQL/Redis restart or concurrency change.
Validate the final committee act, dependent institutions, retained election,
payments and bounded chain tails. Live acceptance remains pending at this commit.

## Deployed and Earth accepted

`f7b71a8c` deployed at 20:42:47 UTC, Horizon only. Earth's item completed DONE
with no gaps. At 20:57 UTC, all 3,153 prior representatives, the original election
and 24 existing committees compare unchanged. The new General Affairs 1 committee
has its adopted creation vote. Total: 923,091 DONE, four reviews, no open work.
The operator then explicitly requested the final four fixes; see D023. The
operator-directed D8 resize is separate from this deployment.
