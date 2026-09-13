# Deferred human, device and deployment checks

Updated 13 September 2026. These checks are outside the [development/internal-testing punch list](DEMO_ACTION_PLAN.md). They do not keep internally completed development open. They remain explicitly unperformed until evidence exists.

## Human or physical-device checks

| Check | When available |
|---|---|
| Real microphone, camera, headphones and screen sharing on separate physical devices | Verify permission prompts, device selection, audio quality and recovery on the attendee hardware/browser combinations. |
| Human demo dress rehearsal | Have people follow the final scenarios and assess clarity and pacing. All required development rehearsals use simulated participants in the meantime. |
| Human usability and assistive-device feedback | Collect feedback after the internally checked language, keyboard, screen-reader semantics and media-alternative work. This does not replace those internal checks. |

## Conference-host deployment verification

| Check | Dependency |
|---|---|
| Pull/install the release on the separate Linux conference host; apply additive migrations and refresh caches/workers | Access to that host and its deployment owner. See [deployment handoff](NEXT_SESSION_HANDOFF.md). |
| Verify public secure signaling, Matrix, ICE/TURN and internet participant connectivity | The conference host's public URLs, certificates and network configuration. This can use synthetic clients once the host is available; it is an external-host check, not inherently a human test. |

Synthetic presider/member roles, private-board access, witness/floor behavior, full hiring and other civic/economic journeys remain **internal** work under R2/S1. They must not be moved here just because older notes called for an “actual member” or “actual participant.”

Completion of the development list does not claim remote deployment or physical hardware was verified. Any concrete defect discovered here becomes a new repair in the active list; the deployment check itself stays here.
