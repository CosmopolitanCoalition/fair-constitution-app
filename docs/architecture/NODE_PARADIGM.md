# The node paradigm

Status: design record, written 2026-11-02 from the operator's direction. Rulings of the same day are in section 9. The mesh, sharing and replication designs are in `NODE_MESH_AND_DATA_SHARING.drawio` (five linked pages). The code is the authority on what is built (see CLAUDE.md). Every "Built" statement below was checked against the repository on this date. The diagram is `NODE_PARADIGM.drawio` in this folder (four pages).

## 1. The idea

A node is a self-hosted instance that keeps a set of people, groups and their data organized. One node type serves one scale of life. The nodes share data with each other by consent, under permissions that the data owner sets. Privacy is the default. Nothing leaves a node unless its owner agreed and the receiving node is known.

## 2. The four node types

| Type | You use it to keep | Typical uses | Built today |
|---|---|---|---|
| Individual | yourself organized | calendar, tasks, contacts and CRM, inbox, messenger and calls, secret vault, API keys for tools | Yes. Added 2026-11-02. |
| Household | your close relationships organized | couples, polycules, single and multi family homes, parents together or apart, friends and social networks, people living alone | No. |
| Organization | your cohorts organized | businesses and workplaces, private membership groups, charities and NGOs, governing bodies, schools | Partly. The organizations module (Phase 4) exists. Shared files and shared CRM do not. |
| Community | your communities organized | small and large communities, civic institutions, networks of communities, markets of goods and skills, self-governance tools | Yes. Almost all of Statecraft today is this type, in simulation. |

The type is a role, not a machine. One box can host any mix of types, many users, and many of each type. A person can hold an Individual node on the same box as their Household node and a community's node. Every person starts in a household of one.

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

Ruling (2026-11-02): no new front door for a fresh node is built now. The work targets the running development instance: interfaces to manage a node in progress, starting with a node profile in the operator area.

Needed, in order:

1. A node profile in the operator area. It shows and changes which node types this host runs. Individual is always on. Community is a switch (simulation, or a real community).
2. A scope per type: who may hold an account here, which places or groups the data covers, and what is shared outward. The vocabulary is open (rubric `node-scope-vocabulary`).
3. Resources per type: storage and compute this host offers the mesh, and how a community node sets its allotments and replication target (designs: `NODE_MESH_AND_DATA_SHARING.drawio`, pages 4 and 5).
4. A measured list of the containers each node set needs (section 10).
5. An interfaces area: install the web app, issue API keys, pair a voice satellite.
6. An address area, after the DNS decisions are ruled.

The full fresh-node front door is a later job. It will assume the data model above.

Storage: one JSON column on `instance_settings` for the hosted types and their scopes. Proposed names `hosted_node_types` and `node_scopes`. Not created.

## 8. Open decisions

In the rubric: `node-scope-vocabulary`, and the three DNS questions in section 5. Ruled: `node-types-default` (section 9).

## 9. Rulings of 2026-11-02

- The Individual node is the default. Every person is in a household, and a household of one is the starting case.
- The tools built on the second machine (Voyager) are the Individual node's features. They are brought into this application and added to the default user profile.
- World of Statecraft becomes the simulation Community node, an optional add-on. A real community node is the other choice. Either can connect to another node and sync.
- This instance stays a running development instance of the game. Its demo data and maps stay as they are. The map setup and re-import steps are retired. A public deployment starts from scratch with a household node and no demo data.
- Defiant keeps a backup copy on Voyager. Voyager hosts the Individual node only.
- The constitutional engine is not a concern for this work.
- Email: use an established mail server and place it on the node's domain tree. Do not write mail transport from scratch. Calendar, CRM, tasks and projects are native tables.
- Pipeline view and builder: held until the second machine has it working.
- Dashboards do not replace the second machine's. That machine's individual node gains web apps that the Statecraft interfaces control. The Statecraft interfaces are the ones the assistant drives. The Statecraft screens are simplified as features arrive, for TV and voice use.
- Direction of work: features move from the second machine into this application. Surfaces move from this application to the second machine's TV-controllable interface. The last interface is the next demo.

## 10. Containers by node set

The compose files define these services. Which ones each node set truly needs is not measured yet.

| Set | Services | Source |
|---|---|---|
| Base | nginx, app, postgres, redis, scheduler, horizon | `docker-compose.yml` |
| Talk | matrix, mas, livekit (profile `voice`) | `docker-compose.yml` |
| Community simulation | etl (map ingestion, finished here), redis_queue, horizon at its large memory setting | `docker-compose.yml` |
| Dev only | vite (profile `dev`) | `docker-compose.yml` |
| Mesh options | caddy (public overlay), headscale (tailnet), patroni with etcd and haproxy (data-tier failover) | `docker-compose.public.yml`, `.headscale.yml`, `.ha.yml` |

Voyager runs the Individual set. Defiant runs every set. Measure on Defiant before cutting a service.

## 11. Data privacy and replication (designs)

Proposed, not built. See `NODE_MESH_AND_DATA_SHARING.drawio`.

- Page 3, who sees what: rings from Me to the public, and a table of data classes against audiences. Everything outside Me starts at None. A person raises it per item and per audience.
- Page 4, self-selecting replication: the owner chooses what is copied, how many copies, and which hosts. Hosts volunteer capacity. A host stores ciphertext it cannot read. A signed consent ledger records every offer and grant. A community node sets a replication target for community records, a quota per member, and roles for nodes with more storage or compute.
- Page 5, settings screen mockup: what this node offers, what is held elsewhere, what it holds for others, community allotments, and the relationships in plain words, generated from the ledger.

Built today and reusable: node identity and signed requests between nodes, a certificate broker for peers, a Headscale tailnet test, Patroni data-tier failover.
