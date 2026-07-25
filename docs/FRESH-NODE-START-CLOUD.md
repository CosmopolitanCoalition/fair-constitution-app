# Run a node in the cloud

This puts a CGA instance on the public internet at your own domain, with HTTPS, Matrix
chat and voice/video working. It is the **same app and the same one command** as a laptop
or Raspberry Pi install — a cloud VM is just a computer you rent. The only extra work is
the three things a public address needs: a machine, DNS records, and open ports.

Written for **Azure** because that is what has been tested. Any provider with a Linux VM
and a public IP works the same way — the only provider-specific bits are steps 1–3.

**Time:** about 20 minutes of your attention, then 20–40 minutes of waiting.

---

## Before you start, decide your hostname

Pick it now and be sure, because **two of the values derived from it can never be changed
on this box**: the Matrix server name (it is written into every chat message, so renaming
it orphans every account) and the federation URL (other nodes pin it when they connect).

Examples: `earth.example.org`, `node1.example.org`. It must be a real DNS name you
control — not an IP address, and not a bare word. This guide calls it **`<HOST>`**.

---

## Step 1 — create the machine

A Linux VM with a **public IP** and a **separate data disk**. Ubuntu 24.04 LTS.

**Sizes — these are recommendations, not requirements.** Start wherever you like; you can
resize an Azure VM later without rebuilding.

| If the node will… | VM | Data disk | ≈ US$/mo |
|---|---|---|---|
| Serve a world someone else built | `Standard_D4as_v5` (4 vCPU / 16 GB) | 256 GB Premium SSD | ~170 |
| Also import map data and draw districts | `Standard_D8as_v5` (8 vCPU / 32 GB) | 512 GB Premium SSD | ~330 |
| Just be tried out, small world | `Standard_D2as_v5` (2 vCPU / 8 GB) | 128 GB Premium SSD | ~85 |

Prices are list, gathered July 2026 — check the portal, they vary by region. The whole
planet's map data is about 31 GB in the database, so do not go below 128 GB of disk if you
intend to load it.

**Azure portal:** Virtual machines → Create → Ubuntu Server 24.04 LTS → pick a size →
Administrator account: SSH public key → **Public inbound ports: None** (step 3 opens the
right ones) → Disks → Create and attach a new disk.

**Azure CLI equivalent:**

```bash
az group create --name cga-earth --location eastus
az vm create --resource-group cga-earth --name cga-earth --image Ubuntu2404 --size Standard_D4as_v5 --admin-username azureuser --generate-ssh-keys --public-ip-sku Standard --data-disk-sizes-gb 256 --nsg-rule NONE
```

Note the public IP it prints.

---

## Step 2 — point DNS at it

Three A records, **all to that same IP**:

| Name | Purpose |
|---|---|
| `<HOST>` | the app, and Matrix chat |
| `auth.<HOST>` | the login service Matrix uses |
| `rtc.<HOST>` | voice and video |

Create them wherever your domain is managed. **Wait until they resolve before step 5** —
certificates are issued by a service that looks up your name from the outside, so it has
to find it. Check with `nslookup <HOST>` or `dig +short <HOST>`.

---

## Step 3 — open the ports

| Port | Protocol | What it is for |
|---|---|---|
| 80 | TCP | certificate issuance, and redirecting visitors to https |
| 443 | TCP | the app, Matrix, login, voice signalling |
| 8448 | TCP | Matrix server-to-server |
| 7881 | TCP | voice/video media, fallback path |
| 7882 | UDP | voice/video media — **UDP, not TCP** |

```bash
az network nsg rule create --resource-group cga-earth --nsg-name cga-earthNSG --name cga-public --priority 100 --access Allow --protocol Tcp --destination-port-ranges 80 443 8448 7881
az network nsg rule create --resource-group cga-earth --nsg-name cga-earthNSG --name cga-media --priority 110 --access Allow --protocol Udp --destination-port-ranges 7882
```

Leave everything else closed. The database, the raw chat server and the login service all
listen on the machine's own loopback address only — they are never exposed, and the app
deliberately configures them that way.

---

## Step 4 — install Docker and mount the data disk

SSH in (`ssh azureuser@<your-ip>`), then:

```bash
curl -fsSL https://get.docker.com | sh && sudo usermod -aG docker $USER
```

Log out and back in so the group membership takes effect.

Put Docker's storage on the data disk, so the world data is on the big disk and can be
snapshotted on its own. `lsblk` shows the empty disk — usually `/dev/sdc`:

```bash
sudo mkfs.ext4 /dev/sdc && sudo mkdir -p /var/lib/docker && echo '/dev/sdc /var/lib/docker ext4 defaults,nofail 0 2' | sudo tee -a /etc/fstab && sudo mount -a && sudo systemctl restart docker
```

---

## Step 5 — one command

Get the code and start it, with your hostname:

```bash
git clone --depth 1 https://github.com/CosmopolitanCoalition/fair-constitution-app.git && cd fair-constitution-app && ./deploy.sh --public-url https://<HOST> --with-etl
```

Drop `--with-etl` if this node will join an existing world rather than import map data
itself — it skips the map-loading container.

This takes 10–30 minutes on the first run. It builds the app, generates its own private
keys, sets up chat and voice with fresh secrets, and gets an HTTPS certificate. It ends by
printing your address.

**It is safe to re-run** — for updates, or if something fails partway. It keeps the keys it
already made.

---

## Step 6 — set the world up in your browser

Open **`https://<HOST>/setup`** and answer the questions. This is the same wizard as any
other install: create your account, choose **join an existing world** or **start a fresh
one**, then work through cosmic address → constitution defaults → map data → districts →
institutions.

That is the whole difference between a cloud node and a home node: none, past this point.

---

## Checking it worked

```bash
docker compose exec app php artisan mesh:gates
```

Look for **"Node is ready to federate."** Amber warnings are normal on a fresh node; red
failures are not.

```bash
docker compose ps                # all services up
docker compose logs edge         # certificate issuance, if https isn't loading
docker compose exec app php artisan mesh:doctor    # your node's ID
```

---

## If something goes wrong

**The page doesn't load / certificate errors.** Almost always DNS or ports. Check the name
resolves from *outside* the machine, check 80 and 443 are open, then `docker compose logs
edge` — it says plainly what the certificate service told it. Certificates can take a
minute after first boot.

**Voice connects but nobody can hear anyone.** Port **7882 must be open for UDP**, not TCP.
This is the single most common miss.

**It says the Matrix domain can't change.** It can't — see the top of this page. If you
brought the box up with the wrong hostname, the fix is to start over on a clean machine
(or `docker compose down -v`, which erases everything and lets you re-run step 5).

**Chat login fails.** `docker compose logs mas`. The login service needs `auth.<HOST>` to
resolve and be reachable over https.

---

## Adding more nodes

Repeat this whole page with a different hostname (`node2.example.org`). Each node is
independent: its own machine, its own certificate, its own identity, its own chat server.
Then connect them:

```bash
docker compose exec app php artisan federation:peer:discover https://<FIRST-HOST>
docker compose exec app php artisan federation:peer:handshake https://<FIRST-HOST>
docker compose exec app php artisan mesh:doctor https://<FIRST-HOST>
```

Nodes must be on the same version of the code — `mesh:doctor` will tell you if they aren't.

**One thing to know before you plan a mesh:** a node that *joins* another world is a
read-only mirror of it. It holds the record and can serve it, but it is not the authority
for anything and it has no user accounts of its own — people sign in on the node that
*started* the world. So the node you want people to use is the one you set up as **fresh**.

---

## Costs to keep an eye on

The VM and the disk are the bill; they run whether anyone visits or not. Outbound traffic
is free for the first 100 GB a month, then about US$0.09/GB. Snapshots are cheap. If you
load the full-planet base map, host that file in blob storage rather than on the VM disk —
the app streams only the tiles a visitor actually looks at.
