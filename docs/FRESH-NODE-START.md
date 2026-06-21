# Start a fresh node and join the mesh — the simple guide

You just got a box and you want it to join the network. Follow these steps **in order**. Do one step,
check the result, then do the next. When you see **✋ STOP — TELL YOUR OPERATOR**, do not continue until
your operator comes back to you. Your operator is the person coordinating the two boxes; the two boxes
never talk to each other directly — your operator passes short messages between them.

You do **not** need to understand the whole system. Just do each step.

---

## Part 1 — Bring your box up clean

**Step 1. Erase any old state (start completely fresh).**
```
docker compose -p fc down -v
```
This deletes the old database so you begin from zero. The **`-p fc` matters**: the app's containers and
volumes live under the project name `fc`, so on a re-used box a plain `docker compose down -v` (before
your first deploy writes the project name into `.env`) targets the wrong project and silently wipes
**nothing** — the old database survives and "fresh" isn't fresh. (Skip only if this is a brand-new machine
that has never run the app.)

**Step 2. Get the latest code.**
```
git checkout main
git pull
```
You should see it update to the newest version. (If `git pull` complains about local changes that aren't
yours to keep, ask your operator before forcing anything.)

**Step 3. Run the deploy script.**
- On a Linux box (e.g. a Raspberry Pi): `./deploy.sh`
- On Windows: `./deploy.ps1`

> **Two boxes on different machines on a network?** (Not both on the same computer.) Add your box's
> address so the other box can reach you back:
> - Linux: `./deploy.sh --self-url http://<YOUR-LAN-IP>:8080`
> - Windows: `./deploy.ps1 -SelfUrl http://<YOUR-LAN-IP>:8080`
>
> Your operator can tell you your LAN IP — it looks like `192.168.1.50`. If both boxes are on the **same**
> computer, ignore this and use the plain command above.

This takes a while the first time (it downloads things and builds the database). Let it finish. It already
waits for the database the right way and starts the chat service for you — you should not have to fix
anything by hand. If it stops with an error, **copy the exact error** and tell your operator.

**Step 4. Check it is up.** Run:
```
docker compose exec app php artisan mesh:gates
```
You want to see the line **"Node is ready to federate."** On a brand-new node a few **amber** `[warn]`
lines are normal and fine — typically *no transport advertised*, *no trusted peer yet*, and *no sync yet*
(you haven't joined anyone). Only a **red** `[FAIL]` line is a problem — if you see red, tell your operator
the red line text.

**Step 5. Get your box's identity.** Run:
```
docker compose exec app php artisan mesh:doctor
```
Near the top it prints **`server_id : ...`**. Copy that whole `server_id`.

> ✋ **STOP — TELL YOUR OPERATOR:**
> "My box is up. My server_id is `<the server_id you copied>` and my address is
> `http://<this box's LAN IP>:<the web port>`."
> Then wait. Your operator will come back with the OTHER box's server_id and address.

---

## Part 2 — Join the mesh (after your operator gives you the other box's details)

Your operator will tell you the other box's **address** (a URL like `http://<their-LAN-IP>:8081`) and its
**server_id**. Use the address as `<OTHER-URL>` below.

**Step 6. Discover the other box.**
```
docker compose exec app php artisan federation:peer:discover <OTHER-URL>
```
You should see it report the other box's name and server_id.

**Step 7. Shake hands (establish trust).**
```
docker compose exec app php artisan federation:peer:handshake <OTHER-URL>
```
Success looks like "handshake complete" / "trust_established."

**Step 8. Check the two-way connection.**
```
docker compose exec app php artisan mesh:doctor <OTHER-URL>
```
You want to see at least one transport reach the other box and **"version MATCH"**. If it says
**"version MISMATCH"**, the two boxes are on different code — tell your operator (you may both need to be
on the same `main`).

> ✋ **STOP — TELL YOUR OPERATOR:**
> "Handshake done. I reached the other box, version MATCH." (Or report the exact problem if not.)

---

## Part 3 — Sync the shared record (when your operator says go)

**Step 9. Send your records to the other box.**
```
docker compose exec app php artisan federation:sync:push <OTHER-SERVER-ID>
```
Success looks like a line ending in **": applied"**. That means the other box accepted your records.

> ✋ **STOP — TELL YOUR OPERATOR:**
> "Sync push done — it said applied." Your operator will have the other box push back to you, then confirm
> both sides match.

---

## Part 4 — Get a real HTTPS certificate (only if your operator asks)

You do **not** mint your own certificate. An **Identity Broker** box (often Box A) approves it and sends
you the permission automatically over the mesh. You just ask for the certificate once that permission has
arrived.

**Step 10.** When your operator says "the broker delivered your grant," run (your operator will give you the
exact domain, the subdomain name for your box, and the broker's server_id):
```
docker compose exec app php artisan mesh:request-cert <DOMAIN> <YOUR-SUBDOMAIN> --broker=<BROKER-SERVER-ID>
```
You do not need a grant file — the broker already delivered the permission to your box, and this command
finds it automatically. Success ends with **"Cert installed for ..."**.

> Preconditions (already true if you got here): your box is federating (the deploy turned that on) and the
> broker is a peer you've handshaked with (Part 2). A `transport: none` note is harmless when you pass
> `--broker`. If it complains the federation is off or the broker isn't trusted, tell your operator.

> ✋ **STOP — TELL YOUR OPERATOR:** "Cert installed." (Or copy the exact error if it failed.)

---

## If something goes wrong

- **Don't guess or hand-edit code.** Copy the exact error message and tell your operator. The fix is made
  on the other box (Box A) and you re-pull `main` and re-run — that is the normal loop, not a failure.
- If you need to start completely over, go back to **Step 1** (`docker compose down -v`).

That's it. Up → identity to operator → join → sync → (optional) cert. One step at a time.
