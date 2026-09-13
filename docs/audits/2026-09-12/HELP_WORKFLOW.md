# Requests for help — E2

13 September 2026. The help workspace is available at `/economy/help` and from the market and shared work/trade navigation.

| Step | Participant action | Result |
|---|---|---|
| Create | Write a title and need; save privately (default) or publish. | A pseudonymous request owned by the authenticated participant's account. No payment or contract. |
| Publish | Owner explicitly publishes an open private draft. | The title/need becomes discoverable in the public help market. |
| Respond | Another participant offers help with a private message. | Only requester and that responder can read the message. Each responder has one retained response per request. |
| Choose | Requester selects an available offer. | Request is matched to that helper. Other offers remain recorded; another helper cannot be selected concurrently. |
| Recover | Selected helper withdraws. | The match clears and the request reopens with the same privacy. Private former helpers return to their accessible directory rather than a now-forbidden detail page. |
| Finish | Requester marks completed, or withdraws the request. | A retained outcome/history. No automatic money movement or contract signing. |

An existing open personal wallet supplies the pseudonymous participation identity, using the same root-currency account resolver as the economy. GET creates no wallet. People without one can browse public requests and receive an explicit participation notice. Private and legacy jurisdiction-scoped requests are visible only to recorded owner/assigned responder; there was no implemented jurisdiction-sharing policy to authorize a wider audience. The market now filters `privacy = public`, closing its prior accidental exposure of jurisdiction-labeled requests.

Public, owned and responding lists, plus response review, use 20-entry cursor pages. The responding list selects candidate request IDs through owner indexes; viewers without accounts do not query personal help directories. An owner sees all responses to that request; other participants only see their own response. Account IDs, user IDs and account bindings do not enter page props. Authority is checked server-side on every action, including request/response pairing. Match and withdrawal operations lock the request before the response so competing decisions serialize.

Validation:

- `AssistanceWorkflowTest`: **13 tests / 143 assertions**, explicit SQLite memory fixtures with independent requester and helper actors. Covers private draft/publication, selected helper withdrawal and replacement, completion, owner/account injection refusals at controller boundaries, response privacy, frozen/closed or absent account rejection, duplicate and late actions, cursor scope, forward/back pages, empty-account short circuit and accessible withdrawal redirects. Ledger posting is forbidden by the fixture's mock.
- `MarketDirectoryTest`: **8 tests / 345 assertions**, including public/private/jurisdiction visibility before pagination and existing tab/cursor behavior.
- Four affected Vue components compile individually; changed PHP and routes pass lint. No production build was run.
- Read-only browser check loaded the public help workspace and My offers of help, displaying the operator's true no-wallet/empty states and navigation loading indicator. Populated forms and decisions were tested in isolated fixtures, not by publishing operator requests.
- Migration `2026_09_13_000010_add_assistance_responses.php` applied locally. Five scoped indexes were checked valid. The migration retains existing requests/status/privacy constraints, adds the response table and can retry interrupted concurrent indexes. Pulling hosts must apply it and refresh cached routes. No worker deployment change is needed for these request-only paths.

This delivers the E2 implementation and simulated service/controller journeys. It does not claim a full multi-browser civic rehearsal, remote-host verification, translated teaching coverage or a second human participant. Those remain separate plan checks.
