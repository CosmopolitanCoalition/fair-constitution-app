# Live rooms: implementation and verification

12 September 2026. This pass prioritizes usable Matrix/LiveKit rooms; economics work is paused.

| Area | Delivered | Verified / remaining |
|---|---|---|
| Browse rooms | `/rooms` is linked from the menu and place tools. Select any place; browse chambers, committees and hearings in 20-row cursor pages. Private boards begin from the viewer's current seats. | Anne Arundel and Poland navigated in the browser. Fixtures verify forward/back paging, place exclusions and private membership. No world-sized room listing or eager world provisioning. |
| Institutional rooms | Existing chamber, court and board records open their own rooms. Actual rosters populate the shared floor layouts; consent names replace raw handles. Board view, call, discussion and floor actions require a current seat on that board. | Access, identity, privacy and roster fixtures passed. Chamber's 86 assigned members rendered live. Preview is capped at 100 seats; connected participants beyond it resolve from current institution records in batches of at most 100. |
| Speaking floor | Participants raise/lower their own hands. The current Speaker, presiding judge or board chair recognizes a queued person or yields the floor. Court witnesses remain at the stand during questioning; yielding clears the witness position. | Exact-office, closed-institution, wrong-room and stale-queue fixtures pass. Per-room cache locks prevent lost updates. These actions change temporary choreography only; testimony and other official acts remain in their existing workflows. |
| Live calls | Local LiveKit is running. Browser URL corrected; join starts with mic/camera off. Explicit media controls, cancellation cleanup, readable device failures and browser audio activation are supported. | Actual app joins succeeded in Anne Arundel's chamber and Poland's public square. Two isolated test identities exchanged generated audio and 320×180 video both ways through the SFU, then did so again after one left/rejoined. No physical microphone/camera was captured. |
| Matrix discussion | Authorized virtual users are registered and joined before sending, with appservice invitations for invite-only rooms. Rooms show real bounded timelines. Committee discussion is separate from formal testimony. | Two distinct synthetic senders posted to an isolated private Matrix room; both events were read back. The public square composer rendered after fixing its incorrect FormCard usage. No test messages were sent as the operator or placed in civic records. |
| Recovery | Poll failures retry; unmount/stop invalidate pending callbacks. Missing room provisioning occurs only on explicit page visits, never poll/prefetch/HEAD. | Focused tests cover outages, wrong-place/private-room denials, late joins and reconnect. Missing rooms expose an explicit retry. |
| Validation | 65 PHP tests / 826 assertions, 23 JavaScript checks, 11 changed Vue components compiled individually. | No production frontend build or full live test suite. Four new browse indexes applied concurrently and verified valid; the scoped court directory plan uses its new index. |

## Local Docker setup

The Windows browser needs a host-reachable signaling URL, separate from the app's Docker-internal `LIVEKIT_URL`. Local `.env` now has `LIVEKIT_PUBLIC_URL=ws://localhost:7880`. Local credentials were checked against the mounted LiveKit configuration without printing them; the existing `voice.sfu` capability was already enabled.

The checked-in override binds only localhost and preserves the existing LiveKit resource limits:

```powershell
docker compose -p wos -f docker-compose.yml -f docker-compose.voice-local.yml --profile voice up -d --no-deps livekit
docker exec fc_app php artisan config:cache
docker exec fc_app php artisan route:clear
docker restart fc_horizon
docker exec fc_app php artisan migrate --path=database/migrations/2026_09_12_220000_room_browsing_indexes.php --force
```

The migration adds indexes to existing data. It does not reset the world. No simulation control or volume deletion was performed. Horizon was restarted to load the shared Matrix service changes.

## Remaining room checks

| Next action | Why it remains |
|---|---|
| Run on the conference host with its public `wss://` endpoint and working ICE/TURN/network configuration. | Localhost bindings deliberately cover this Windows machine. Remote attendees and phones have not been verified. |
| Use two accounts on separate physical devices for microphone/camera, headphones, screen sharing and recovery. | The live transport check used two independent identities/connections in one browser and synthetic media. Permission/device behavior was tested with mocked SDK failures, not every hardware combination. |
| Exercise recognition and witness questioning with actual presiders and participants. | Floor authority, court witness positioning and media placement pass isolated fixtures. The operator raised/lowered a hand in the live chamber; no official appointments or testimony were fabricated for testing. |
| Verify the full private-board journey with an actual member. | Membership and token isolation passed fixtures; the operator's account was not given a board seat for testing. |

If a caller has not chosen a public display name, the room uses a privacy-preserving resident label. It does not copy the private account name. The institution room links to the caller's public profile for editing.

## Floor and connected seating follow-up

This code-only follow-up adds institution floor controls and current-office lookup for connected callers. Polling updates floor state every five seconds; connected roles refresh every thirty seconds and on participant changes. Requests are scoped to the selected institution and at most 100 supplied identities. Cancelled, cross-room or late responses cannot change the next room's seating, and a former officeholder becomes a guest. Court preview and connected rosters exclude dissolved panels.

Validation: **48 focused PHP tests / 334 assertions**, **27 JavaScript checks**, and four Vue components compiled individually. Coverage includes 205 connected identities requested in 100/100/5 batches, permission denials, current-office changes, polling recovery, witness video following its position without duplication, and continued media lifecycle behavior. The updated Anne Arundel room joined LiveKit in listen-only mode and left successfully with no browser errors. The operator's test hand was lowered, restoring the empty queue. Its public label was the resident fallback because no chosen public room name resolved.

No migration, simulation operation, official civic record, production frontend build or remote deployment was required. Pulling hosts need the new code and their route cache refreshed (`php artisan route:clear`). Physical-device/conference-host rehearsal remains unverified here.

## Archives, role exploration and hearing continuity

| Item | Delivered |
|---|---|
| Session archive | Public `/legislatures/{id}/sessions` uses 20-row cursors. An exact `session?session={id}` record pages attendance, motions, published statements/minutes and a selected motion's votes independently. Other sessions/chambers cannot substitute for the requested record; current ballots and filing controls are excluded. |
| Speaker and role previews | Visitors can open the Speaker workspace and follow links to the actual chamber, sessions, committees and oversight. Member-only office records remain outside the public preview. Role exploration adds labeled seating examples and direct chamber/committee/court room links. Shared legislature navigation now opens the institution's own live room. |
| Committee continuity | Room-to-workspace links retain the exact hearing. The workspace validates committee ownership, displays that hearing's testimony and links back to the same room. Closed/dissolved contexts disable formal controls. Informal Matrix discussion continues refreshing after adjournment in one polling stream. |
| Hand requests | Committee requests provision the same canonical local identity as calls/messages, so chosen public names resolve before a first call. Participants can raise and lower only their own hands. |

Live browser checks reached the public Speaker preview, the archive empty state for Anne Arundel, and the labeled courtroom role guide. Anne Arundel has no recorded sessions, so populated archives were exercised with isolated fixtures rather than fabricated live proceedings. Existing legislature/session and session/vote indexes scope the new readers; composite indexes remain a future optimization for very large histories within one session.

Final regression: **33 focused PHP tests / 454 assertions**, **10 JavaScript checks**, and **nine Vue components compiled individually**. Archive fixtures traverse 42 sessions, page each record section forward/backward, reject cross-session/chamber selections and exclude current action props. Committee fixtures pin exact-hearing submissions, closed/dissolved guards, continued discussion polling, chosen identity resolution and lowering only the caller's own hand. Changed PHP and routes pass syntax checks.

No new migration, frontend production build, simulation control, official record or deployment was required. Pull the code and refresh route caches. This completes the identified R3 build work; independent participant rehearsal on the intended host remains outstanding.
