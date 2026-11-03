# The node paradigm

Status: design record, written 2026-11-02 from the operator's direction. Rulings of the same day are in section 9. The mesh, address, sharing and replication designs are in `NODE_MESH_AND_DATA_SHARING.drawio` (fourteen linked pages, merged from the former NODE_PARADIGM.drawio on 2026-11-03). The code is the authority on what is built (see CLAUDE.md). Every "Built" statement below was checked against the repository on this date. Pages 1 (node types) and 4 (ways in) came from the former `NODE_PARADIGM.drawio`. Page 12 is the former "Mesh and addresses" page, kept as a second view of how nodes connect.

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
7. A person opens data to individuals, to user-defined groups by membership, or to the community data of the places they occupy. Joining a community is the person's own act of consent. It is what unlocks the community's civic tools for them.

## 5. Addressing

Ruled 2026-11-03. Every node uses the DNS address system. The community tree is the DNS tree. A person is the text before the `@`. The chain of places is the text after it, read from the right. The mesh root is `worldofstatecraft.org`. Any other TLD can be aliased to the same mesh. Diagram: page 5 of `NODE_MESH_AND_DATA_SHARING.drawio`.

Forms in use now:

| Form | Meaning | Sample |
|---|---|---|
| `User@Organization(s).LocalityChain(s)` | a person on a household or organization box | `Joseph@JDSileo.mecklenburg.nc.usa.earth` |
| `User@LocalityChain(s)` | a person held by a community box | `Maria@mecklenburg.nc.usa.earth` |
| `User@OwnDomain` | a domain the holder owns, aliased into the mesh | `Joseph@jdsileo.com` |
| `Organization(s)@LocalityChain(s)` | a group address | `acme@mecklenburg.nc.usa.earth` |
| `User+Unique+Alias@earth` | a generated id plus a chosen alias, unique in the mesh | `Joseph+Unique+Alias@earth` |

Short forms drop the mesh root. The web profile uses a dot in place of the `@`. The same text works for mail and SIP. A person may belong to several households and organizations, and holds one name on each. A client reads the chain from the right and connects to the nearest box that offers the datastore. This lets the client choose where its data goes.

Later, not now: recursive hosted inboxes. A county, state, country, planet or the root may offer to be an inbox for people below it, written with a `+` chain (`Joseph+JDSileo+mecklenburg@nc.usa.earth`).

Places are a choice. The tree follows real places or a custom layout. Setup step 0 (cosmic address, Multiverse) already places a community in virtual space or on the real-world map.

Boxes do not need a type of their own. Individual, household and community kits can all run on one box. Every box joins a household, an organization or a community. Personal, household, organization and community credential stores follow one model: an owner, a scope, and consent to share.

The three open DNS decisions are in the rubric (`docs/plans/ui/tools/gen_app_rubric.py`):

| Id | Question | Desk recommendation |
|---|---|---|
| dns-name-shape | How a node's name maps onto the jurisdiction tree | A: delegated subzones, each community node runs its own zone. Stable key is the jurisdiction slug, never its position. |
| dns-foreign-domain-keys | How a domain holder lets chosen nodes host its domain | A: ACME challenge delegation by CNAME. No private key leaves the holder. Revoke by deleting one record. |
| dns-home-node-reach | How a home node behind a router reaches the mesh | B: a community node relays for its families. |

A wildcard certificate covers one label. The demo uses hyphens for that reason (`demo-...`), not dots.

Nothing in DNS is built.

## 6. Ways in

Any screen can drive a node. Phones, desktops, laptops and TVs use the installed web app or a native app where one exists. A smart speaker is voice only. The Jarvis voice satellite turns a spoken request into a command for the node and can pick which screen shows the answer.

Built today: the installed web app (manifest and service worker in `public/`), the sign-in session, per-user API keys. Prototyped on the second machine: the voice satellite, n8n pipelines, screen routing. Not built: native apps, TV and smart-speaker surfaces.

## 7. What Setup does today and what it needs

Today the wizard walks: bootstrap (migrate, founder account), the solo or join fork (`instance_settings.setup_mode`), operator profile, then steps 0 to 6. Steps 0 to 6 build a Community node: cosmic address, constants, map data, districts, scale up, simulate, confirm. Nothing asks what the box should host.

Ruling (2026-11-02): no new front door for a fresh node is built now. The work targets the running development instance: interfaces to manage a node in progress, starting with a node profile in the operator area.

Needed, in order:

1. A node profile in the operator area. It shows and changes which node types this host runs. Individual is always on. Community is a switch (simulation, or a real community).
2. A scope per type: who may hold an account here, which places or groups the data covers, and what is shared outward. The vocabulary is open (rubric `node-scope-vocabulary`).
3. Resources per type: storage and compute this host offers the mesh, and how a community node sets its allotments and replication target (designs: `NODE_MESH_AND_DATA_SHARING.drawio`, pages 9 and 10).
4. A measured list of the containers each node set needs (section 10).
5. An interfaces area: install the web app, issue API keys, pair a voice satellite.
6. An address area, after the DNS decisions are ruled.

The full fresh-node front door is a later job. It will assume the data model above.

Storage: one JSON column on `instance_settings` for the hosted types and their scopes. Proposed names `hosted_node_types` and `node_scopes`. Not created.

## 8. Open decisions

In the rubric: the three DNS questions in section 5. Held until those are ruled: the mail server choice (Postfix with Dovecot, or Stalwart). Ruled: `node-types-default` and `node-scope-vocabulary` (section 9).

## 9. Rulings of 2026-11-02

- The Individual node is the default. Every person is in a household, and a household of one is the starting case.
- The tools built in the Jarvis prototype are the Individual node's features. They are brought into this application and added to the default user profile.
- World of Statecraft becomes the simulation Community node, an optional add-on. A real community node is the other choice. Either can connect to another node and sync.
- This instance stays a running development instance of the game. Its demo data and maps stay as they are. The map setup and re-import steps are retired. A public deployment starts from scratch with a household node and no demo data.
- The running demo box keeps a backup copy on a second box that hosts the Individual node only.
- The constitutional engine is not a concern for this work.
- Email: use an established mail server and place it on the node's domain tree. Do not write mail transport from scratch. Calendar, CRM, tasks and projects are native tables.
- Pipeline view and builder: held until the second machine has it working.
- Dashboards do not replace the second machine's. That machine's individual node gains web apps that the Statecraft interfaces control. The Statecraft interfaces are the ones the assistant drives. The Statecraft screens are simplified as features arrive, for TV and voice use.
- Scope and roles: free-form rules inside four contexts (individual, household, organization, community), built on the civic roles. The system roles (R-xx) are reused for organizational roles. Individual and relationship roles are added on top, with user-defined labels such as a CRM label (friend, family, coworker).
- Onboarding a person: the node operator onboards each person by the relationship the operator defines, for example by an invite sent over an external channel.
- A household is an organization type. An organization is an organization, so the civic roles extend to households. A household manages permission delegation by presets it chooses, and names triggers for succession (a custom ruleset, or a larger organization toolkit for a family that runs as a business).
- A community node maps up to a top-level domain or subdomain that offers a community mesh. The operator adopts that mesh, self-signs, or simulates. The operator then runs the build-from-scratch engine or a prebuilt option (like this instance), and connects with their own network equipment, a cloud tunnel, or stays LAN-only behind their own VPN.
- Joining a community is the owner's consent. It unlocks the person's civic tools there: founding or joining organizations, and the marketplace. Elections run on the existing engine.
- Mail server: held until the three DNS questions are ruled.
- Next build: the backup outline, `docs/operations/BACKUP_DEFIANT_TO_VOYAGER.md`.
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

A box can run one set or all of them. The running demo box runs every set. Measure on it before cutting a service.

## 11. Data privacy and replication (designs)

Proposed, not built. See `NODE_MESH_AND_DATA_SHARING.drawio`.

- Page 1, node types: the four types as circles with the uses each serves.
- Page 2, clients and the open mesh: the community tree as the DNS tree, boxes with their screens and addresses.
- Page 3, inside a box: node kits and shared layers on one box, with the containers by kit.
- Page 4, ways in: every screen drives the same node through one surface layer.
- Page 5, address forms.
- Page 6, naming and internet protocols: one name serves DNS, HTTPS, SMTP, IMAP, SIP, Matrix, TURN, RTMP, CalDAV and WebFinger on the box. Profiles (personal, organization, community) link to outside services and public lines through a profile card, and contacts exchange handles by shared network kinds with per-field consent. Home boxes need a relay or smarthost for port 25 and NAT.
- Page 7, peer circles and the short path: households and organizations link directly and form their own community, independent of any physical community box. Data takes the shortest path (same network, direct, relay, tree) and stays on the member boxes. A link is a pair of signed grants in the consent ledger.
- Page 8, who sees what: rings from Me to the public, and a table of data classes against audiences. Everything outside Me starts at None. A person raises it per item and per audience.
- Page 9, self-selecting replication: the owner chooses what is copied, how many copies, and which hosts. Hosts volunteer capacity. A host stores ciphertext it cannot read. A signed consent ledger records every offer and grant. A community node sets a replication target for community records, a quota per member, and roles for nodes with more storage or compute.
- Page 10, settings screen mockup: what this node offers, what is held elsewhere, what it holds for others, community allotments, and the relationships in plain words, generated from the ledger.
- Page 11, off-network profile links: a catalogue of what can be linked (messaging apps, social networks, professional, media, meetings, phone and mail, web, payments, organizations, identity proofs), the link record, sharing between two people, publishing to a profile by audience, three ways to prove a link, and the rules (a link is a pointer, phone-number links default to private, removal cannot recall what was seen).
- Page 12, nodes one person may belong to: a high-level view of how nodes connect, with sample names in a delegated-subzone tree and the boxes that host them.
- Page 13, onboarding flow: nine steps for a node operator (who the box is for, where it lives, how it is reached, who is on it, what it offers, which tools to bundle, which AI assistants help, review, install and check). Box size picks the defaults.
- Page 14, bundled tools and AI assistants: native tools, self-hosted bundles (home and devices, monitoring, automation, media, AI brains, voice, office, files, notifications), pipelines that join devices and people through one permission layer, and proposed AI-assisted roles with a human sponsor.

Built today and reusable: node identity and signed requests between nodes, a certificate broker for peers, a Headscale tailnet test, Patroni data-tier failover.
