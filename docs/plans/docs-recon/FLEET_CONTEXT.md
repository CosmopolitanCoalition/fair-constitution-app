# Fleet Context — the standing brief

*Replaces the `parallel-chats\fleet\` board, scrapped by operator order 2026-07-26.*
*Maintained by lane 7. Every lane reads this; nobody else writes to it.*

---

## 1. How we communicate now

**The board is dead.** Do not write to `E:\fair-constitution-personal\parallel-chats\fleet\lane-NN.md`.

It failed for one concrete reason: **lanes finished work and did not post.** On 2026-07-26 the
board told the operator that lane 13 was HOLDING when it had finished hours earlier, and that
lane 2 had shipped "plan only, no code changes" when its one-command cloud install was already
on GitHub. A status channel that lags the work is worse than none — it produces confident,
wrong briefings.

**Report directly to lane 7** via `mcp__ccd_session_mgmt__send_message`, session
`local_b316400b-55b4-4897-9aa2-28e06a794707`. Lane 7 reads every lane's transcript directly with
`mcp__ccd_session_mgmt__list_events`, so the truth is the chat, not a file.

---

## 2. THE REPORT FORMAT — every status classifies itself

The operator's complaint, verbatim: *"Are they waiting for me to do a thing? If so, what is that
thing? If they are not waiting on me to do a thing, what is it that they are needing?"*

A status that does not answer that is not a status. **Pick exactly one:**

| # | Category | What it means | What you write |
|---|---|---|---|
| 1 | **WAITING ON THE OPERATOR** | A physical act only he can do — record, provision, click, look, listen | The act, in one line. Nothing else. |
| 2 | **OPEN QUESTION** | You need an up-or-down before you can build | The question in plain words · the options · what each costs · **your recommendation** |
| 3 | **READY TO RUN** | Nothing needed from him at all | One line on what you'd do if told to go. Say plainly this is NOT a request. |
| 4 | **DONE, HOLDING** | Complete, no questions, waiting on the rest of the fleet | Say so and stop. |

**Never let a category-3 read like a category-1.** That was the exact failure that produced the
word "nudge" in a report to him — it implied he owed five lanes something when he owed them
nothing.

### Language rules
- **Plain English.** He reads these to decide, not to admire. No lane shorthand.
- **No jargon he would have to ask you to explain.** If you write "migrate slot" or "provisioning
  UI" or "counting stage", write the next sentence that says what it means and who does it.
- **Tables and bullets over paragraphs.** Numbers side by side.
- **Commit hashes only after `git show --stat`.** Two lanes cited hashes on 2026-07-26 that do
  not resolve in the repository.

---

## 3. WHERE WE ARE GOING — playtesting, not parity

**The walkthrough has changed shape.** It is no longer a screen-by-screen comparison against
mockups. A parity tour checks pages against pictures; it does not check that a person can
accomplish anything.

**The target: the operator sits down, assumes any role through dev mode, advances the clock turn
by turn, and walks a real journey end to end.** *Can I run a court case? Can I run an election?*
One named jurisdiction per flow. Then he plays them repeatedly as regression tests.

Consequences for everyone:
- **BUILT is not TESTED.** A phase row reading BUILT means code exists and pins pass. It does not
  mean a human walked it. Always say which one you mean.

  **The evidence, 2026-07-26, and it is against a lane's own work by their own choice.** Lane 6
  had *server-side proof* for six economy pages: exact contract props, 200 responses, a green
  pinned `EconomyPropContractTest`. All true. The moment their browser pane worked and they could
  actually look, they found two real defects **in minutes**, both invisible to every one of those
  checks — `Quantity 1.000000` (quantities stored at the same `numeric(24,6)` precision as money,
  so a compass reads as a machine talking) and `Art. II §9 · [POLICY]`, a raw internal token
  sitting in player-facing copy in a build that had run a whole pass to remove exactly that.

  Their own conclusion, which is the sentence to remember:
  > **"Verified without looking means the data is right, not that the screen is."**
- Your next orders will be about making your area **walkable**, not about building more.
- "It renders" is not a deliverable. "A person can complete X" is.

---

## 4. Standing rulings — settled, do not re-derive

| Ruling | Substance |
|---|---|
| **The bicameral seat law** | Type A = `max(5, round(pop^⅓))`, districted into 5–9 seat races, no ceiling on the total. Type B = equal per constituent, population irrelevant, **one at-large STV race at any size**, bounded only by the Type A total. Ladder 5→4→3→2, then compact grouping. Full text in `CLAUDE.md` § Bicameral Support. |
| **The seating law** | Giant-cascade apportionment. **Never** Webster, Sainte-Laguë or largest-remainder. `CLAUDE.md` § Apportionment Law. |
| **PROTECTED means live-deployment** | We are in development. A protected file that is broken protects nothing. **Announce → fix → pin → move on.** His word is needed to change what the constitution REQUIRES, not to make code match what it already requires. |
| **The goal is the sanction** | The campaign goal is standing permission. Detect → fix → verify → ship engineering dials without asking. Never park a known blocker. |
| **git pathspec** | `git add X && git commit` sweeps the whole shared index across 12 lanes. Always `git commit -- <path>`, then verify with `git show --stat`. |
| **Verify the probe** | `curl` without `Accept: text/html` skips the setup lock and reports the wrong gate. A tool answering a different question than you asked produces confident fleet-wide error. |
| **The ETL rule** | Bulk writes run as bounded committed chunks with per-chunk progress. Never one planet-wide statement. |

---

## 4b. Diagnosing on a shared box

Twelve lanes share one working tree, one database and one machine. Every trap below produced a
confident, wrong, fleet-wide belief before it was named.

### ⚑ THE CLASS THEY ALL BELONG TO — name it and you will spot the next one
**A probe answered a different question than the one you asked, and you read its answer as
though it had answered yours.**

Six instances in a single day, 2026-07-26, in six different costumes:

| The probe said | You read it as | It actually answered |
|---|---|---|
| `curl` → 302 to `/login` | "this route is auth-gated" | "what happens without an `Accept` header" |
| `docker ps` → timeout | "the daemon has crashed" | "the box is busier than my timeout" |
| background run → `exit 0`, no output | "the suite ran and passed" | "the wrapper exited" |
| page text → empty | "the page is dead" | "what the DOM held before hydration" |
| rendered DOM → full of data | "this page looks fine" | "the markup exists" — not that it is legible |
| a report of two identical lines | "there is a duplicate import" | a summary, not the file |
| 8 screenshots passing every pixel check | "8 pages render" | "8 images look like real pages" — they were 8 photographs of the **login screen** |

**The habit that defeats all seven: verify the probe, not just the result.** Before believing an
answer, ask what question the tool actually answered. Lane 15's framing, which is the shortest
version: *"'completed, exit 0' answered 'did the wrapper exit?' and I read it as 'did the suite
run?'"*

### ⚑ THE SERVER SENDS IT, THE SCREEN DOES NOT SHOW IT — three instances in one afternoon
The specific failure that BUILT-is-not-TESTED produces. **The capability is real and its
reachability is zero.** Every server-side check passes — correct props, 200s, green pins — and a
human sees nothing, or sees the wrong thing.

| Where | The server did | The screen did |
|---|---|---|
| `DevBar.vue` | nothing wrong — the slot was there from the start | rendered the slot **empty and self-closing**, so the persona switcher never existed |
| `/system/clocks` | `ClocksController` sent `dueNow` and the playtest preview | `Clocks.vue` **declared neither prop** and rendered neither — "what is due now" was invisible |
| the six economy pages | exact contract props, green `EconomyPropContractTest` | `Quantity 1.000000` and a raw `[POLICY]` token in player-facing copy |

**Why it hides so well: a half-wired component looks finished.** `DevBar` had chrome, a label, a
collapsible panel and a real status line. Nothing about it said unfinished. **Absence that
presents as presence is far harder to see than a blank screen.**

**The audit that finds it:** for any capability you believe exists, ask *what does a person click*
— then check that something in `resources/js` actually references the endpoint. Grep the route
name. A controller with no caller is a feature nobody can reach.

### ⚑ "I found zero" is not "I found nothing to count"
Lane 9's distinction, and it is what caught the clock page. They reported that the string `due`
appeared nowhere in 4,882 characters of body text — and **refused to collapse those two claims**.
"Zero results" can mean the thing is genuinely zero, or that nothing was ever rendered to count.
They read very differently and a report that merges them hides the second.

**The sharper corollary, from the same fix:** lane 3 had reported the playtest safety gate as
holding, and it *was* holding — but lane 9 found the block **absent from the page entirely**. So
the absence check would have passed **whether or not the gate worked**. In their own words: *"the
absence check passed for a weaker reason than I claimed."* **A safety property confirmed by the
absence of something is only as good as your proof that the something would have appeared.**

### Two gates that catch what no single check can — lane 9, from the login-screen near-miss
A verification rig reported **8 of 11 pages passing**. All 8 were the login screen: the dev login
had briefly 502'd, every authenticated route redirected, and **a login page passes every pixel
test** — real colours, real text, real edges. It would have certified six economy pages that were
never captured.

Build both of these into anything that verifies pages:
- **Redirect check** — if the final URL's path differs from the requested path, **FAIL**. An
  expired or broken session otherwise turns silently into a healthy-looking report.
- **Duplicate check** — distinct URLs must produce distinct output. Eight identical hashes is not
  eight passes.

**The second is the deeper lesson and generalises past screenshots: some failures are invisible
to any per-item check and appear only when you compare results against each other.** If a rig
examines each result in isolation, it cannot see that they are all the same result.

### An uncommitted fatal is everyone's outage
Laravel scans the whole `app/Console/Commands/` directory to build its command list. **One class
that cannot load kills every `php artisan` call for every lane** — tests, migrations, seeders,
demo commands, tinker. On 2026-07-26 a half-written command took the fleet's console down; three
lanes diagnosed it independently and two of them had already lost time to it.

- **If artisan dies and you did not touch the file: `git pull` first, then re-test.** It may
  already be fixed. That is the cheap first move and it was the right one that day.
- **Do not edit a peer's untracked file to fix it.** Diagnose it, route it to the owner, let them
  fix it. Both lanes that found it refused to touch it, correctly.
- **If you are writing a command in the shared tree, get it loadable fast.** A half-written class
  in that directory is not a private draft — it is a fleet outage.

### Slow is not down
The box runs two full stacks. When it saturates, `docker` calls queue for **minutes** — a 30-second
timeout reads as a crashed daemon while a 45-second one succeeds on the same machine. Three lanes
filed "waiting on the operator to restart Docker" against a box where all 20 containers were
running and healthy. **Use `timeout 45 docker ...` from bash; PowerShell is worse on this box.**
Measure before concluding, and never call slow work "expected" without a baseline.

### ANNOUNCE BEFORE YOU RESTART SHARED INFRASTRUCTURE
`fcd_app`, `fcd_postgres`, the vite containers and the whole `fc_*` stack are shared by twelve
lanes. **Restarting or rebuilding one stops every other lane from running tests, migrations or
artisan** — while the app keeps serving HTTP the whole time, which is the misleading combination
that has cost this fleet hours twice in one day.

- **Say so on this channel before you do it**, and say when it is done.
- If you find the box churning and you did not do it, **diagnose and stand off** — do not add a
  second restart on top of someone else's operation. Two lanes did exactly this correctly on
  2026-07-26 and both were right.
- A restart that clears a symptom usually leaves the cause armed. Say which you did.

### ⚑ RUN ARTISAN AS THE WEB USER — `docker exec -u www-data`
**This one is a live landmine and every lane has stepped on it, including this desk.**

```bash
docker exec -u www-data fcd_app php artisan <command>     # correct
docker exec fcd_app php artisan <command>                 # arms the landmine
```

Running artisan as **root** makes Laravel create or rotate `storage/logs/laravel.log` **owned by
root, mode 644**. php-fpm runs as `uid=33(www-data)` and can then never append to it. The logging
failure itself throws, so **every subsequent web request dies with a 500 instead of degrading** —
and the error names monolog, not your command, so it looks like an app defect on whatever page
someone opens next.

Evidence from 2026-07-26 (`-rw-r--r-- 2 root root … laravel.log` against `uid=33(www-data)`):
`/economy` and `/civic` both 500'd together. Two unrelated pages failing at once is what
identified it as the machine rather than either lane's work.

**Restarting the container clears the symptom and leaves the cause armed.** The only durable fix
is the habit above.

**⚠ DO NOT ABANDON THE FIX IF IT ERRORS.** When the exec layer is broken, `-u www-data` reports:

```
unable to find user www-data: no matching entries in passwd file
```

**That user plainly exists** — it has been read off the running container as `uid=33(www-data)`.
The missing-passwd message is **the broken exec wearing a different mask**, not your command
being wrong. Wait for exec to recover and use the flag again. Concluding "the recommended fix is
broken" and reverting to root is how this landmine gets re-armed, and it is itself an instance of
the class above: the error answered *"can I attach a process at all"*, not *"does this user
exist"*.

### A single text read is not proof of blankness
A page read before hydration returns empty. Lane 13 nearly filed `/economy` as a blank page;
`#app` was 28,377 characters a moment later. **Check `#app` innerHTML length before concluding a
page is dead.** Same family as the `curl`-without-`Accept` trap — the probe answered a different
question than the one asked.

---

## 5. Dev mode — what exists today

Registered only when `app()->environment('local')` **and** `config('cga.impersonation')` is on.

| Route | What it does |
|---|---|
| `GET /dev/users` | Pick anyone to become |
| `POST /dev/impersonate/{user}` · `/stop` | Assume and drop a persona |
| `POST /dev/login-as` | Passwordless session for any user — **works with no prior session** |
| `POST /dev/residency/grant` | Declare → simulated pings → verify, one request, through the real engine |
| `POST /dev/pings/simulate` | GPS pings |
| `POST /dev/board/seat` · `/unseat` | Seat yourself on the active election board (R-08) |
| `GET /dev/electoral-kit` · `legislature-kit` · `executive-kit` · `judiciary-kit` | Every component in every state |

**THE GAP: there is no time control.** `ClockService` has `arm()`, `fire()` and `cancel()`, but
**no route, no console command and no UI exposes them**, and there is no global clock offset.
Any journey with a waiting period — election windows, 30-day residency, 90-day emergency
ceilings, 10-year appointment terms — cannot be advanced by hand today. This is the single
largest blocker to the playtesting plan.

---

## 6. Authority

| Document | Holds |
|---|---|
| `CLAUDE.md` | Constitutional constraints, the seat law, protected-file rules |
| `docs/plans/docs-recon/BUILT_INVENTORY.md` | What is built versus claimed; the risk register |
| `docs/plans/docs-recon/OPERATOR_QUEUE.md` | Open operator decisions |
| `docs/plans/ui/TOUR_ACT_COVERAGE.md` | Which tour stops are live, partial or dark |
| **This file** | How the fleet communicates and where it is headed |
