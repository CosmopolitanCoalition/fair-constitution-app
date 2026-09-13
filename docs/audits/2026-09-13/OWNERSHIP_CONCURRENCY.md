# Ownership concurrency — S3, 2026-09-13

**Development and internal testing passed for the tested ownership paths.** Real PostgreSQL contention reproduced two defects; both were repaired and their regressions now pass. This evidence is distinct from prior SQLite checks, which could verify query order but could not exercise PostgreSQL row locks or numeric storage.

## Confirmed defects repaired

| Reproduction before the repair | Repair and observed result |
|---|---|
| Two independent sellers' requests for the same holding each read 100 units held and zero reserved, then both inserted an 80-unit offer. The database contained **160.000000 open-offer units against 100.000000 held**. | `ShareTradeService::offer()` now performs reservation reads and insertion in one transaction under the organization's existing cap-table mutation lock. The second real connection demonstrably waits; after the first commits, the second receives a `ConstitutionalViolation`. Final reservation: **80.000000**. |
| Selling **0.000001** from **10000000000000.123456** succeeded, but float conversion in the holdings/remainder path left **10000000000000.123048** across the seller and buyer: **0.000408 lost**. | Held, reserved, offered and remaining quantities remain decimal strings; comparisons/addition/subtraction use BCMath at the storage scale. The same successful sale now leaves **10000000000000.123456** exactly. |
| The form handler explicitly cast quantities to float, and Vue's number inputs implicitly coerced `v-model` values even without a `.number` modifier. | `ShareTrade` uses the existing exact quantity normalizer. Exchange quantity/price controls use text values with decimal keyboards, precision patterns, field hints and errors. Compiled input-event tests prove that submitted values and retries retain every digit. |

Changed runtime files: `app/Services/Economy/ShareTradeService.php`, `app/Domain/Forms/Handlers/ShareTrade.php`, `resources/js/Pages/Economy/Exchange.vue`. The HTTP controller's `offerShares()` path was inspected and already forwards the validated original quantity; no controller repair was needed. Existing internal float callers still use the established six-place normalization; browser decimal strings never pass through float. This changes neither ownership-class voting rules nor the currency system.

## Isolation and reproducibility

Run the opt-in harness inside the app container:

```sh
docker exec fc_app php tests/concurrency/ownership.php --run
```

The default invocation without `--run` prints usage and performs no database work. Each run creates a new `cga_ownership_YYYYMMDD_<16 random hex>` database from `template0`, containing only synthetic fixture tables in `ownership_fixture`. It does not clone the game database or load the schema baseline. Maintenance commands connect only to `postgres`; application reads/writes connect only to the new fixture database. There is no fallback connection to the game database.

Before any service runs, every process checks the exact database name, random marker nonce, search path, and absence of `public.organizations`. The minimal Illuminate container does not boot application providers or load runtime routes/config caches. The only configured database connection is the fixture. Query values, users, organizations, wallets and holdings are synthetic. The fixture uses PostgreSQL numeric columns, ownership indexes and the unique open-membership constraint; it does not import actual actor identities.

Workers use separate PHP processes and fresh PostgreSQL backend connections. A query-completion barrier pauses one process while it holds a row lock. The controlling connection verifies the other worker's `wait_event_type=Lock` and `pg_blocking_pids()` against the first backend before releasing it. This proves contention instead of guessing from sleep durations. Worker barriers and database statements have bounded timeouts.

Ownership, share trade, money-account and ledger services execute their real code. Issuance and offer creation use the real form handlers. Dissolution executes the real registry lifecycle transaction; its publication transport writes a synthetic marker or throws an injected fixture failure. Role-cache flushing remains local. Dispatch, unique-job locks and context stay in memory using a fake bus and array cache; no job executes and no Redis connection is configured. The outer constitutional-engine authorization/audit pipeline is not part of this concurrency harness.

Cleanup disconnects workers, checks the fixture's exact identity again, and drops only that uniquely named database, without `FORCE`. Cleanup was confirmed for the initial infrastructure attempt, failing reproduction run, and final passing run. Final fixture **`cga_ownership_20260913_a7e1b0a6e08a16b1` was removed**. A cleanup identity failure would retain the fixture and report failure rather than target another database.

## Executed results

The final machine-readable evidence is [OWNERSHIP_CONCURRENCY_RESULTS.jsonl](OWNERSHIP_CONCURRENCY_RESULTS.jsonl). All values in it are synthetic.

| Test set | Result |
|---|---|
| PostgreSQL harness | **23 checks passed, zero failures**, including **13 concurrent pairs** with distinct backend PIDs and observed lock waits. |
| `ShareTradeQuantityTest.php` + `ShareIssuanceWorkflowTest.php` | **66 tests, 353 assertions passed**, no skips, 34.397 seconds / 24 MB. The latter explicitly creates its own SQLite memory fixtures; neither uses `LivePgConnection`. |
| `tests/js/shareTradeInput.test.mjs` | **2 tests passed**, no skips, 0.633 seconds. Actual compiled SFC, Vue input directives/events, submit and retry are exercised. |
| Syntax and patch checks | PHP lint for service, handler and new tests/harness; Exchange SFC script/template/style compilation; `git diff --check` all passed. No production frontend build. |

PHP command:

```sh
docker exec -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: fc_app php vendor/bin/phpunit tests/Unit/ShareTradeQuantityTest.php tests/Unit/ShareIssuanceWorkflowTest.php --do-not-cache-result
```

Vue command:

```sh
docker exec fc_vite node --experimental-vm-modules --test tests/js/shareTradeInput.test.mjs
```

The concurrency cases cover simultaneous issuance to one holder, issuance/resale in both orders, two buyers of one paid offer, oversubscribed settlements, one buyer competing for two paid offers, issuance/dissolution and resale/dissolution in both orders, membership-write failure before a waiting issuance, publication failure before a waiting resale, and competing offer reservations. Final checks compare open user holdings with owner-class memberships and recalculate each stored percentage from committed units. Fully divested sellers lose their owner membership; dissolved organizations retain no open stakes or owner memberships. Failed transactions leave the competing operation a consistent committed state.

Precision checks cover a one-millionth full sale, 0.3 split into 0.2/0.1, the supported **99999999999999.999999** maximum split leaving exactly **0.000001**, exact handler-to-offer insertion, and rejection beyond six fractional places before any reservation query. The real cash ledger retains balanced postings and passes its chain verification; four paid postings were checked in the disposable fixture.

`tests/Feature/ShareTradeTest.php` and `OrgShareIssuanceTest.php` were inspected but **not run**, because they use `LivePgConnection` and create actors against the live world's root currency. Their existing sequential checks were not counted as fresh evidence.

## Measured organization cost

These are one local run's unblocked operation measurements using the unmodified production host-derived ownership batch calculation and representative synthetic cap tables. No host settings, resource limits or machine size were changed. Data preparation and PHP startup are excluded from the operation timer; PHP peak memory includes the minimal fixture runtime. PostgreSQL process memory was not measured.

| Open holders/stakes | Percentage recalculation | SQL statements | PHP peak | PHP growth during operation |
|---:|---:|---:|---:|---:|
| 100 | 224.957 ms | 103 | 8 MB | 2 MB |
| 1,000 | 1,105.524 ms | 1,003 | 8 MB | 2 MB |
| 5,000 | 4,394.897 ms | 5,005 | 10 MB | 4 MB |

The two-pass reader bounds PHP memory, but still updates percentages one stake per statement. The measured cost grows with stake count. No OOM, timeout, partial percentage publication or corruption occurred in these sizes, so this is an identified scaling characteristic rather than a demonstrated overload defect. It is not a planet-scale throughput guarantee or a latency prediction for a different volunteer host. Larger organizations and sustained multi-organization load remain performance review work if required; they do not need human participants.

No additional confirmed ownership defect remains from this pass. Fresh-host deployment, real publication/federation transport and full-engine role/audit checks remain separate scopes and are not silently marked passed here.
