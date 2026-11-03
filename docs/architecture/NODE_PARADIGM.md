# The node paradigm

Status: design record, written 2026-11-02 from the operator's direction. The code is the authority on what is built (see CLAUDE.md). Every "Built" statement below was checked against the repository on this date. The diagram is `NODE_PARADIGM.drawio` in this folder (four pages).

## 1. The idea

A node is a self-hosted instance that keeps a set of people, groups and their data organized. One node type serves one scale of life. The nodes share data with each other by consent, under permissions that the data owner sets. Privacy is the default. Nothing leaves a node unless its owner agreed and the receiving node is known.

## 2. The four node types

| Type | You use it to keep | Typical uses | Built today |
|---|---|---|---|
| Individual | yourself organized | calendar, tasks, contacts and CRM, inbox, messenger and calls, secret vault, API keys for tools | Yes. Added 2026-11-02. |
| Household | your close relationships organized | couples, polycules, single and multi family homes, parents together or apart, friends and social networks, people living alone | No. |
| Organization | your cohorts organized | businesses and workplaces, private membership groups, charities and NGOs, governing bodies, schools | Partly. The organizations module (Phase 4) exists. Shared files and shared CRM do not. |
| Community | your communities organized | small and large communities, civic institutions, networks of communities, markets of goods and skills, self-governance tools | Yes. Almost all of Statecraft today is this type, in simulation. |

The type is a role, not a machine. One box can host any mix of types, many users, and many of each type. A person can hold an Individual node on the same box as their Household node and a community's node.

## 3. Inside one instance

Every type on a box shares the same base:

- permission and consent layer: owner-only data, per-user keys, scoped API keys, the secret vault
- node identity: Ed25519 keys, signed requests between nodes
- data and queues: Postgres with PostGIS, Redis
- talk: Matrix for text, LiveKit for calls. One floating window holds the call and its chat.
- files and backup: local storage, with copies on peers the owner chooses
- sync layer: what this node shares with which other nodes

The Individual node is the base. Household, Organization and Community nodes add shared spaces on top of it.

## 4. Permissions and consent

These rules apply to every type.

1. A person chooses which boxes hold their data.
2. Shared assets and joint organizations may live on several boxes.
3. Nothing syncs without consent. The sending node signs every request.
4. A box that is not physically in a place may hold that place's data read-only.
5. A tool that acts for a person uses a key that person issued, with only the scopes they chose. Nothing is sent as the person without their approval.
6. Hosting a domain carries the community's agreement to that DNS entry. Every user on an agreeing domain is traceable, and nodes know which domains each other host.

## 5. Addressing

The root `worldofstatecraft.org` follows the jurisdiction map. A community node is mapped to the real places it occupies. Individuals are addressed in an email-compatible form. Any other domain holder may offer its domain to chosen nodes.

The diagram shows sample names. The name shape is not decided. Three decisions wait in the rubric (`docs/plans/ui/tools/gen_app_rubric.py`):

| Id | Question | Desk recommendation |
|---|---|---|
| dns-name-shape | How a node's name maps onto the jurisdiction tree | A: delegated subzones, each community node runs its own zone. Stable key is the jurisdiction slug, never its position. |
| dns-foreign-domain-keys | How a domain holder lets chosen nodes host its domain | A: ACME challenge delegation by CNAME. No private key leaves the holder. Revoke by deleting one record. |
| dns-home-node-reach | How a home node behind a router reaches the mesh | B: a community node relays for its families. |

Constraint that drives the first one: a wildcard certificate covers one label. The demo uses hyphens for that reason (`demo-...`), not dots.

Nothing in DNS is built.

## 6. Ways in

Any screen can drive a node. Phones, desktops, laptops and TVs use the installed web app or a native app where one exists. A smart speaker is voice only. The voice satellite on the second machine (named Jupiter by the operator) turns a spoken request into a command for the node and can pick which screen shows the answer.

Built today: the installed web app (manifest and service worker in `public/`), the sign-in session, per-user API keys. Prototyped on the second machine: the voice satellite, n8n pipelines, screen routing. Not built: native apps, TV and smart-speaker surfaces.

## 7. What Setup does today and what it needs

Today the wizard walks: bootstrap (migrate, founder account), the solo or join fork (`instance_settings.setup_mode`), operator profile, then steps 0 to 6. Steps 0 to 6 build a Community node: cosmic address, constants, map data, districts, scale up, simulate, confirm. Nothing asks what the box should host.

Needed, in order:

1. A node profile step right after the operator account. The operator chooses which node types this box hosts. Individual is always on.
2. A scope step per type: who may hold an account here (only me, my household, invited people, residents of a place), which places or groups the data covers, and what is shared outward.
3. Gate the Community build (steps 0 to 6) so it runs only when the Community type is chosen.
4. A services step: Matrix and LiveKit on or off, backup peers, tools by API key.
5. An interfaces step: install the web app, issue the first API keys, pair a voice satellite.
6. An address step, after the DNS decisions are ruled.

Storage: one JSON column on `instance_settings` for the hosted types and their scopes. Proposed names `hosted_node_types` and `node_scopes`. Not created.

## 8. Open decisions

Two decisions need the operator. They are in the rubric as `node-types-default` and `node-scope-vocabulary`.
