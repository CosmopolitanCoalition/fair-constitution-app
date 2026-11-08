# Defiant plan for the Voyager interface

Status: draft for Voyager's review. Written 2026-11-08 by the Defiant (Statecraft) session.
Source for Voyager's side: `jarvis_rubric.html` and `jarvis_work.json` (plan folder, General Assistant project).
Rule: the code is the authority. This file states intent and what the code does today. Where they differ, the code wins and this file is corrected.

## 1. Two flows

Voyager (Jarvis, the personal assistant) and Defiant (Statecraft) have separate orders.
The Voyager plan owns the Voyager order. The Defiant session owns the Defiant order.
Jarvis is the operator's own assistant. It is not synced with Statecraft.
No Voyager data crosses to Defiant. No Defiant user data crosses to Voyager.

## 2. What Defiant builds (rubric phases D1, D2, D3)

All multi-user, household and multi-node work is built in Statecraft, on its own permission system, inside the multi-community demo.
Work rows are in `docs/plans/ui/tools/work.json`. "State" is read from the code, not from plans.

| Voyager row | Meaning | Defiant row | State |
|---|---|---|---|
| N-hhadmin | Household accounts, invites, roles | W-0491, W-0492 | open |
| P-85 rings of trust | Who sees what | W-0504 | open |
| N-private | Private, household, guest scopes | W-0504, W-0522 | open. The assistant check already refuses stores owned by a shared owner. |
| N-spaces | Shared household calendar, tasks, notes, media | W-0493 | open |
| P-115 portable profile | Persona, memory, permissions travel with the person | W-0517, W-0531, W-0518 | open |
| N-grant, N-xmsg | Consent grants and cross-node messages | W-0503, W-0528 | open |
| P-111, P-112, P-120 | Addressing, certificates, labels | W-0495, W-0497 | open or blocked on W-0496 |
| N-orgrel | Business and organization relationships | W-0507, W-0529 | open. The organizations module already exists. |
| N-opboard | Community operator onboarding | W-0494, W-0519 | open |
| P-197 social platform | Community social platform with privacy scopes | W-0504, W-0541 | open |
| N-pkg, N-import | Data-free feature package from Voyager | W-0509, W-0536 | open |
| N-signin, N-client | Voyager signs in as a client | W-0510, W-0537 | open |
| N-voiceid, N-tvmulti, N-guest, N-usertabs | Per-person tabs, guests, speaker recognition | W-0538 | new |
| N-pubaccess | Public hosting and remote access | W-0496, W-0499, W-0539 | blocked and new |
| P-196 credential onboarding flow | Credentials entered through a flow, kept in the vault | W-0527 | open |
| N-defiant-ready | Defiant declares itself ready | W-0540 | new |

## 3. Interface contract (proposal for Voyager to accept or change)

1. **Identity.** Voyager signs in to Defiant as an OIDC client of the node's own provider. One login. No second password.
2. **Assistant.** Defiant already has the drawer, the chat service, the per-person permission check and the tool catalog.
   The Statecraft-derived assistant on Defiant acts only as the signed-in person and only with rights the person holds.
   Voyager onboards that assistant as its Defiant-side brain. Voyager's own Jarvis stays separate.
3. **Feature package.** Voyager lists each feature (service, tab, voice intent, workflow) with code location and data location.
   The package holds no credentials, personal media, transcripts or memory. Defiant imports the package into the demo.
4. **Screens.** Each Defiant page is a route. Voyager adapts a route as a tab. Defiant keeps a route list Voyager can read for the parity check (N-parity).
5. **Credentials.** Keys live in the node vault and untracked files. Neither plan carries a secret. The scan tool and hooks enforce this on Defiant.
6. **Public access.** Cloudflare Tunnel where possible. Defiant gives the answer for phone access away from home. Voyager follows it.

## 4. The ready gate

Defiant declares "ready" (W-0540) when these work in the demo:
household social attachments; business and organization relationships; multi-user; multi-household nodes; multi-community nodes.
A test proves one person cannot read another person's private data.
Voyager waits on this gate before it returns as a client.

## 5. Order on Defiant

1. N1 household of one, relationship roles and invites, household kit, community switch (W-0491 to W-0494, W-0533).
2. Permission check and policy for shared owners (W-0516, W-0522), then the assistant surfaces (W-0523 to W-0532).
3. Addressing and consent between boxes (W-0495, W-0503, W-0504, W-0528).
4. Data-free import, client sign-in, tabs and guests (W-0536 to W-0538).
5. Public access and the ready gate (W-0539, W-0540).

Community surfaces already exist on Defiant. Voyager adapts them after the gate, in the order Defiant lists.

## 6. Gaps found in the plan (Defiant's assessment)

- The rubric keeps identity, scopes and consent on Defiant. Voyager needs no work there until the gate. No change needed on that point.
- A Statecraft-derived assistant needs a model endpoint. None is set on Defiant today. The chat uses the phrase router. Voyager's choice of brain (Claude login first, Ollama key second) is the model for onboarding here.
- Speaker recognition, guest mode and TV use with several people have no Defiant row until W-0538.
- Voyager rows marked "Owned by Lane 7" (P-130, P-121, P-114, P-116) are not yet mapped to Defiant code. They need a check against the code, not this file.
- Old development values remain in git history on Defiant. A history rewrite needs the operator's word.

## 7. Questions for Voyager

1. Does the contract in section 3 match what Voyager needs?
2. Which feature does Voyager package first?
3. Which Defiant route list format does the parity check need?
