# Root integration and map cleanup — 13 September 2026

The primary agent reviewed and integrated EO-2, EO-3 and the completed confirmation portion of EO-4. No further implementation or cross-review was delegated after the operator asked the primary agent to integrate personally.

## Combined checks

The final combined PHP run passed **117 tests / 1,731 assertions**, 76.067 seconds, 32 MB:

```text
docker exec -e APP_ENV=testing -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: fc_app php vendor/bin/phpunit tests/Unit/RecurringElectedOfficeCycleTest.php tests/Unit/LegislativeRolloverWorkflowTest.php tests/Unit/CorrectedElectionRecertificationTest.php tests/Unit/CgcGovernorWorkflowTest.php tests/Constitutional/TermLockstepTest.php tests/Constitutional/ElectionClockTest.php tests/Unit/InstitutionActWorkspaceTest.php tests/Unit/LegislatureWorkspaceTest.php tests/Unit/JudicialConfirmationSurfaceTest.php --colors=never
```

The final combined frontend run passed **20 compiled Vue tests**, 2.115 seconds:

```text
docker exec fc_vite node --experimental-vm-modules --test tests/js/institutionActs.test.mjs tests/js/judicialConfirmations.test.mjs tests/js/cgcGovernors.test.mjs
```

The fixture/database isolation and exact real-service versus doubled-service boundaries remain documented in [recurring offices](RECURRING_ELECTED_OFFICES.md), [institution filings](INSTITUTION_ACT_WORKSPACE.md) and [judicial confirmations](JUDICIAL_APPOINTMENT_SURFACE.md). This integration does not upgrade isolated application/component evidence into complete voter, institutional-adoption or real-transport rehearsals.

## Applied locally

All three additive migrations completed through the established application container:

- `2026_09_13_102000_judicial_confirmation_directory_index.php`
- `2026_09_13_110000_link_recurring_office_elections_to_general_cycles.php`
- `2026_09_13_120000_institution_act_directory_indexes.php`

A bounded catalog query confirmed all eight new indexes valid, including both recurring-election uniqueness indexes. The new general-cycle foreign key exists as deliberately `NOT VALID`: new non-null writes are enforced; a historical world-wide validation scan was not run. Routes were cleared, configuration cached, all three new institution routes confirmed, and `fc_horizon` restarted after migration completion. Pulling hosts must apply these migrations and refresh caches/workers through their own deployment process.

Docker Desktop and local HTTP briefly stopped responding during verification, then recovered. The final `/up` check returned HTTP 200 in 0.393 seconds, and the actual browser subsequently loaded populated institution and map pages. No Docker disk, volumes, live civic records or simulation controls were changed. No production frontend build was run.

## Additional map request

The jurisdiction viewer now keeps child and neighboring polygon navigation on `/jurisdictions/{slug}/map`. Its separate **Jurisdiction overview** button opens the selected place's main page. **Legislative maps** remains a prominent direct link.

Activation and Reach panels are removed. Geographic metadata is collapsed under **Geographic details**, and direct-child totals read **places within**. Data-review flags, map acceptance, scale-up and activation controls require an explicitly unfinished setup and the existing operator authority. A saved setup completion timestamp overrides a stale shared unfinished flag. Completed worlds do not mount the world flag queue or fetch the selected-place flag list. Normal governance engines and constitutional settings are unchanged.

Leaving a map aborts pending map reads and removes its Leaflet instance; new map visits use fresh component state. The actual component compiled, and one-off rendered checks passed for completed worlds, stale shared flags, unfinished operator setup and non-operator viewing. These were lightweight component checks, not a production build.

Actual browser verification walked **Earth → France → Bretagne** by clicking polygons. Each resulting URL retained `/map`, the selected place and ancestor links updated, and the map redrew at the selected scope with its contextual basemap. The completed-world sidebar exposed overview, legislative map, election and executive links without activation/reach/setup/scale controls. The overview button's destination also loaded the actual Bretagne place page with its map return links. The institution workspace showed existing adopted proposals, recorded vote thresholds and exact resulting-institution links. The existing Superior Court in 'Eua fo'ou loaded all five confirmed nominees with display names, public profile links, recorded vote totals and original term dates. Closed records offered no false voting action. No live proposal or vote was filed.

## Accounting

EO-2 and EO-3 are closed builds. EO-4 confirmation is archived as a completed portion; the missing player nomination authority/designation and entry remain open. Fixed-baseline counts are **13 build closures / 20 remaining**, **2 full internal review closures / 27 remaining**. The completed extra map request is reported separately without changing that baseline. No full review is claimed closed by this bounded integration pass.
