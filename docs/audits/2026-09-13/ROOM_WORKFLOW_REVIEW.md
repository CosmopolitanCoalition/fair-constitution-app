# R2 room workflow review

13 September 2026. This pass closes the **combined application workflow** portion of R2. It does not claim that mocked HTTP replies or synthetic track objects are a live Matrix/LiveKit session. No new production-code defect was demonstrated.

## What had already passed

The existing [live room evidence](../2026-09-12/LIVE_ROOMS.md) records these prior results; they were inspected, not rerun or counted as new checks here:

| Existing evidence | What it establishes |
|---|---|
| Institution, floor, participant-roster, public-room-access and name/token PHP fixtures | Scoped current-office checks, private-board denials, bounded roster lookup, chosen public names, witness state and individual endpoint contracts. Some fixture dependencies are mocked, so their sum alone did not establish the combined actor journey. |
| `useLiveRoom`, `roomParticipants`, `voiceRoom`, presentation and room-component checks | Poll/retry/cancel behavior, connected identity lookup, simulated SDK lifecycle failures, witness placement and media attachment logic. |
| Prior isolated Matrix transport run | Two separate synthetic senders posted into a separate private room and both messages were read back. |
| Prior isolated LiveKit transport run | Two synthetic identities exchanged generated audio and 320×180 video in both directions, then repeated that after one left and rejoined. No physical microphone/camera capture. |

The gap was the connection between application roles/authorization, the same canonical identity across floor/message/token/roster paths, and the presentation consuming the resulting room state.

## Minimal integration plan executed

1. Create seven synthetic actors and a small chamber, case/panel and private board in a verified SQLite memory connection. Seed office facts only inside that fixture; give the outsider a seat on a different board.
2. Use the real room page, discussion, private-token, public voice-reach, floor and participant controllers with their real identity, name, floor, roster and access services.
3. Exercise Speaker/member recognition, judge/witness positioning and questioning, reopening/rejoining the application, private-board discussion/recognition, and current-office refusals. Reuse the same actors throughout the successful journey.
4. Export only the resulting synthetic public seating props to a uniquely named ignored test file. Run the production JavaScript seating function against those exact props with explicit synthetic track objects; check movement, disconnect/rejoin and lack of duplicate media placement. Verify the file identity and remove only that file afterwards.

The room operations are **temporary choreography and discussion**, not a constitutional form submission. There is no mocked constitutional-engine success. No testimony was filed, case advanced, official appointment made or operator seat granted. The fixture verifies that its case remains `paneled` after the witness workflow.

## New passing evidence

| Combined path | Result |
|---|---|
| First participation and names | Real `MatrixIdentityProvisioner` creates each actor's private fixture identity. The same canonical handle and chosen public name appear in floor queues, room/connected rosters, Matrix requests and signed LiveKit claims. Legal-name/email sentinels do not appear in page/token responses. |
| Chamber | Member raises only their own hand despite a supplied other handle. Member cannot recognize; the current Speaker recognizes the member. Page and participant roster agree on offices. The actual seating function moves that participant and the same synthetic tracks to the speaking well exactly once. |
| Court | Judge places a waiting claimant at the witness stand without changing their official role. Judge then requests/receives the speaking floor; witness remains at the stand while the judge speaks from the bench. |
| Reopen/rejoin | A separate token/page/message request preserves the witness's persisted identity, queue/floor position and discussion history. The protocol fixture rejoins the same sender. JavaScript absence/reappearance of that identity yields an offline named witness position and then only the replacement synthetic tracks in the same position. |
| Yield | The judge's yield clears floor/witness state. The actual seating function returns the claimant to the counsel area; case state is unchanged. |
| Private board | Actual chair/member gates authorize the correct board page, participants, floor actions, discussion and signed call grant. MatrixClientService performs registration, invite-only admission and send through the protocol fixture. The URL's board determines the call/discussion room despite a client-supplied other room ID. Reopening retains history and identity. |
| Unauthorized access | Guest page/token requests refuse. An outsider seated only on another board and a removed member are refused at page, token, discussion, participant lookup, raise, recognize and yield doors before any Matrix request. The public voice endpoint also refuses the private board. No outsider identity is provisioned. |
| Stale authority and cross-room inputs | A former Speaker loses floor authority and connected-roster office; a recused judge cannot yield. A chamber hand cannot be selected as another court's witness. Closed cases refuse floor requests and hide stale queue/witness state. |

Executed runner:

```powershell
./tests/rooms/run-workflow.ps1
```

It performs:

```sh
docker exec -e DB_CONNECTION=sqlite -e DB_DATABASE=:memory: -e ROOM_WORKFLOW_SNAPSHOT_ID=<unique-32-hex-id> fc_app php vendor/bin/phpunit tests/Unit/RoomWorkflowIntegrationTest.php --do-not-cache-result
docker exec -e ROOM_WORKFLOW_SNAPSHOT_ID=<same-id> fc_vite node --test tests/rooms/seating-workflow.test.mjs
```

| Check | Executed result |
|---|---|
| Combined PHP integration fixture | **3 tests / 460 assertions passed**, no skips; 4.126 seconds, 20 MB with snapshot export enabled. |
| JavaScript consuming those controller snapshots | **4 tests passed**, no skips; 0.178 seconds. |
| Isolation/cleanup | Private snapshot identity verified and exact file removed by runner. Its storage path is gitignored. SQLite connection purged; private array-cache store forgotten. |
| Syntax | PHP fixture lint and PowerShell runner parser check passed. |

An initial fixture parse error and a test-helper name collision were fixed before the passing run; neither reached application actions or indicated a production defect. No production file changed in this pass, so existing large/live helper suites were not rerun.

## Isolation and limits

All database reads/writes use `room_workflow_fixture`, explicitly verified as SQLite `:memory:`; no PostgreSQL connection or migration is used. Floor state uses a named ArrayStore, verified at runtime, not Redis. The bus is faked and the workflow asserts that no job was dispatched.

`InstitutionRoomController`, `RoomFloorController`, `RoomParticipantController`, `VoiceReachController`, `RoomFloorService`, `LiveFloorService`, `BoardRoomAccess`, `PublicVoiceRoomAccess`, `RoomParticipantRoster`, `PublicRoomNames`, `MatrixIdentityProvisioner`, identity helpers, `MatrixClientService`, `VoiceReachService`'s local branch and `LiveKitTokenService` are real. Requests pass real controller validation and real seat/room queries.

External boundaries are explicit:

- Matrix HTTP is a stateful in-memory fixture, with `preventStrayRequests()` and an exact `.invalid` host assertion. It enforces registration, invitation, membership and retained messages before accepting a send. It proves emitted protocol and application authorization, **not a running homeserver**.
- Local service reach is a controlled pointer. Peer attestation and multiplex calls are mocked with “never” expectations. No server identity key or real peer is read or contacted.
- JWTs use fixture-only signing values and the real signer/verifier. No token or signing value is exported. No SFU connection is made.
- JavaScript receives synthetic audio/video track objects. The test checks the real seating function's identity/track mapping, not media encoding, playback or bytes crossing an SFU.
- Controllers are called with synthetic authenticated Request objects. This is not an HTTP-kernel/CSRF/session-login test or a browser component interaction run.

## What remains within R2

| Remaining internal check | Required isolated environment |
|---|---|
| Combined role journey over real transport | The same distinct presider/member/witness/board actors, actual private Matrix rooms and LiveKit connections, generated media, and app-derived grants/rosters/floor state in one run. Prior separate transport success and this application journey do not substitute for that joined run. |
| Real disconnect/reconnect and retained history | Interrupt actual participant connections, restore them, and assert media exchange, canonical identity, floor position and Matrix history in that combined run. No new human participants are needed. |
| Private-room authorization across transport lifetime | Check direct unauthorized join attempts and previously joined/removed members against actual Matrix membership and already-issued LiveKit grants, including expiry/rejoin behavior. This pass proves all new application requests refuse after removal; it does not establish termination of an already-active remote connection. |
| Public host/TLS/network | Use isolated public `wss://`, discovery, ICE/TURN and certificate endpoints, then verify failures/recovery. This remains the separate mesh/rooms TLS review, not a human-only deferral. |

These are unfinished internal checks, not newly confirmed build defects. No R2 production repair or human-only dependency was invented to close the review prematurely.
