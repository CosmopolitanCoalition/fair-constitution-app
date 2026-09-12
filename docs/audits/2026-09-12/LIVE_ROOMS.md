# Live rooms: implementation and verification

12 September 2026. This pass prioritizes usable Matrix/LiveKit rooms; economics work is paused.

| Area | Delivered | Verified / remaining |
|---|---|---|
| Browse rooms | `/rooms` is linked from the menu and place tools. Select any place; browse chambers, committees and hearings in 20-row cursor pages. Private boards begin from the viewer's current seats. | Anne Arundel and Poland navigated in the browser. Fixtures verify forward/back paging, place exclusions and private membership. No world-sized room listing or eager world provisioning. |
| Institutional rooms | Existing chamber, court and board records open their own rooms. Actual rosters populate the shared floor layouts; consent names replace raw handles. Board view, call and discussion require a current seat on that board. | Access, identity, privacy and roster fixtures passed. Chamber's 86 assigned members rendered live. Preview is capped at 100 seats with an explicit full-record link. Active witness assignment still needs authoritative witness state. |
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
| Exercise chair recognition during a real committee meeting and the court's eventual active-witness state. | Current role assignments and known floor state are displayed; no official governance or witness event was fabricated to animate a demo. |
| Verify the full private-board journey with an actual member. | Membership and token isolation passed fixtures; the operator's account was not given a board seat for testing. |

If a caller has not chosen a public display name, the room uses a privacy-preserving resident label. It does not copy the private account name. The institution room links to the caller's public profile for editing.