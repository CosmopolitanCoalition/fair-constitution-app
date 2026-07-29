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

**Report directly to lane 7** via `mcp__ccd_session_mgmt__send_message`. Lane 7 reads every
lane's transcript directly with `mcp__ccd_session_mgmt__list_events`, so the truth is the chat,
not a file.

**A MESSAGE TO A SESSION IS AN ACTUATOR, NOT A MEMO.** The receiving session acts on your
message at delivery — "on your next wake" is meaningless, because your message IS the wake
(proven twice: lane 13's "flip the arcs" actuated lane 15; the desk's flatJson relay actuated
lane 5). Every cross-session message therefore carries an explicit disposition line — either
`ACTION:` (this starts work now) or `HOLD — no action until woken:` (record and stand by).
No third state; if you can't decide which, you are not ready to send.

**NO SESSION ID IS EVER WRITTEN IN A DOC — including this one.** (An earlier revision of this
very paragraph hardcoded an ID that was already dead; a broadcast repeated the mistake and
twelve lanes hit "Session not found".) The ID procedure, all three halves mandatory:

1. **Same-turn source.** Any call that carries a session ID (`send_message`, `list_events`)
   takes it from a `mcp__ccd_session_mgmt__list_sessions` RESULT in the CURRENT turn — never
   from memory, a prior turn, a compaction summary, or any document. Find lane 7 by TITLE
   (it starts with `7`).
2. **Copy verbatim, not from recall.** A fresh listing is not enough by itself — on
   2026-07-28 the desk itself mistyped an ID twice FROM a fresh listing by reconstructing
   the UUID from memory (`9ce8` became `9ec2`). Locate the entry's line, transcribe the
   `sessionId` character-for-character while looking at it, and confirm the composed ID is
   an exact substring of that result before sending.
3. **"Session not found" = suspect your own transcription FIRST.** Re-run `list_sessions`
   and diff your ID against the listing before concluding anything about the target session.
   Never brute-force ID variants.

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

### ⚑ THE FULL-SUITE NUMBER AND THE ISOLATED NUMBER DISAGREE — do not read the delta as breakage
**Confirmed 2026-07-26.** `MatrixCarveoutEmitterTest`, `ModerationFlipTest` and
`TopologyReconcilerTest` appear in the **full-suite** failing list and **pass in isolation.** That
is genuine order-dependence, cause not investigated, nobody claiming one.

**So a run of `--filter=X` and a run of the whole suite will report different things about the
same code, and neither is lying.** Before treating a difference as a regression, establish which
kind of run produced each number.

**This exact shape produced the day's worst round-trip:** a green isolated run was used to retract
a correct finding, four lanes spent an hour on it, and the real cause was a third thing again — the
file had been rewritten between the report and the run. **Two honest measurements disagreeing for
a structural reason nobody had named.**

### ⚑ A FIXTURE ESTABLISHES WHAT ITS SUBJECT REQUIRES — it does not assume the world provides it
Lane 3's general form, and it covers every "the suite broke and nobody changed the code" incident
on 2026-07-26. **The box now holds real data. Any fixture that reaches for whatever happens to be
there is borrowing something another lane can take away.**

| The fixture | Assumed | Broke when |
|---|---|---|
| `OperationalBundleSealedTest` | one election exists globally | a lane ran elections on the shared world — found 3 |
| `AutoscalePinTest` | 24 items exist globally | a world was founded — found 35, delta of exactly 11 jurisdictions |
| 11 Matrix commons tests | `jurisdictions` first row owns no rooms | Niue was seated at 16:53 and acquired real rooms |
| `GeodataRepairPlaneTest` ×5 | the repair window is open | the world was founded — the guard was **right** to refuse |

**Two different remedies, one diagnosis.** The Matrix tests needed different **data** — scope to a
jurisdiction the test can own. The geodata tests needed a different **precondition** — open the
window inside a transaction that always rolls back, **without weakening the guard**, then verify
afterwards that `map_accepted_at` is unchanged on the live row rather than trusting the `finally`.

**The rule is better than "scope your fixtures" because it says why**, and it is the one to apply
as the box keeps filling — which it will, every time a lane seats a world.

### ⚑ THE RULE HAS TWO HALVES — lane 3's, and it is the sharpest version
> **Run it, and check that what you ran is what you meant.**

*"Run it rather than reason about it"* is only the first half, and this desk proved the gap by
following the first half and still getting a wrong answer: a **green** run of
`DevImpersonationTest` was used to retract a correct finding — **because the file had been
rewritten between the report and the run.** Six tests with new names, where the original had three
with different ones. The instrument was fine. It answered a question about a different file.

**Every failure catalogued here has that shape:**

| The instrument | Worked perfectly | And omitted |
|---|---|---|
| `route:list` | listed every route | the middleware |
| lane 9's screenshot metrics | measured a real page | **which URL** — 8 healthy captures of the login screen |
| full-page capture | captured the page | the scroll position |
| lane 3's gate cases | ran and passed | that the *environment* gate fired first, so all three proved nothing |
| a green test run | ran the tests | **which version of the file** |

**In every case the instrument worked perfectly and answered a question nobody asked.**

**Why the second half is the harder one:** the target of a measurement *feels like context* rather
than measurement, so it gets assumed while the method gets checked. Ask what the tool was pointed
at, not only whether it ran.

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

### ⚑ A CHECK THAT CANNOT FAIL IS NOT A CHECK
Lane 3's principle, earned by volunteering something against their own interest, and lane 9's
audit is the evidence. **Ask of any green result: what would have made this show up?**

Three shapes, all found on 2026-07-26, all in gates that looked healthy:

| The gate | Why its result carried no information |
|---|---|
| lane 3's playtest-block absence check | passed — but the block was **absent from the page entirely**, so it would have passed whether or not the gate worked |
| lane 9's health probe | fetched from `about:blank` (null origin), so Vite's CORS rejected it **every time since it was written**. Invisible, because it only *warned*. The moment it was given authority to stop a run, **it stopped every run** |
| two of lane 9's factory gates | green since the day they were written and **never once seen failing on real work** |
| a 20 s timeout on `:8080/legislatures` | that route is **29.5 s cold** — the check can never pass cold, so it reports the box down every time |

**Both directions are the same defect.** A gate that can only pass and a gate that can only fail
are equally uninformative; the second merely announces itself sooner.

**The response that works — lane 9's, and it is not the obvious one:** audit every gate by asking
*"has this ever actually been seen failing on real work?"* For any that has not, write a
**negative control** — feed it something bad and prove it fails, then prove it still passes on the
true input. Seven of theirs had a failure history; two did not; both were fixed to 4/4.

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

### ⚑ THE COMMIT LAW, v2 — the pathspec COMMIT is itself the trap (4th sweep, 2026-07-29)
Twelve lanes share one worktree. Two opposite traps, one law:
- A **bare `git commit`** commits the shared INDEX — it sweeps whatever any lane has staged.
- **`git commit -- <pathspec>` commits the WORKING TREE of those paths and BYPASSES the
  index entirely** — lane 13 partial-staged shared files perfectly (`git apply --cached`,
  `git diff --cached` verified foreign-free) and the pathspec commit swept two peers'
  working-tree edits anyway, including references to still-uncommitted controllers:
  broken-by-construction for any clean checkout. The old rule ("always commit with a
  pathspec") DESTROYS the partial-staging it was supposed to protect.

**THE LAW: build the index to be exactly yours, then commit the INDEX — plain, no pathspec.**

```bash
git add path/you/own/whole.php                  # whole files that are entirely yours
git apply --cached my-changes.patch             # shared files: filtered patch into the index
git diff --cached                                # READ what you are about to commit — final gate
git commit -F msg.txt                            # PLAIN commit, NO pathspec — commits the index
git show --stat <hash>                           # ALWAYS, after — and COMPARE COUNTS:
                                                 # staged insertions ≠ committed insertions = SWEPT
```

The insertion-count compare is the alarm that caught sweep #4 in minutes (staged +1183,
committed +1332). If counts differ: soft-reset, rebuild the index, recommit — never push the
swept commit, never rewrite a pushed one.

Corollary from sweep #6 (4 lines, attribution noise only — but the mechanism generalizes):
**the INDEX is shared too.** A peer can stage entries between your `git diff --cached` verify
and your plain commit, and the plain commit takes them. Shrink the race window: do the
filtered-patch, the foreign-entry reset (`git reset -- <their paths>` for anything staged
that is not yours), the `git diff --cached` read, and the commit **in the SAME shell call**.
The verify and the commit must not be separate turns.

Two corollaries from sweep #5 (same night, same mechanism, lane 2):
- **Remediation resets target the EXACT hash, never HEAD~N** — `git reset --soft HEAD~1`
  undid the DESK's newer commit because one had landed on top between lane 2's commit and
  its remediation. `git log` first; `git reset --soft <the-hash-you-mean>`.
- **A sweep that references a peer's UNTRACKED files is a time bomb on origin**: any push
  (by anyone — pushes publish the whole branch) ships routes pointing at classes that do
  not exist, breaking every fresh checkout until the peer commits. If your sweep carried
  references to untracked files, say so IMMEDIATELY — the fix is the peer committing a
  loadable state fast, not holding pushes (someone else's push will carry your commit out
  anyway, which is exactly what happened).

### ⚑ THREE HOT FILES — `git diff` before you touch them
```
CLAUDE.md · routes/web.php · app/Domain/Forms/FormRegistry.php
```
**Every cross-lane collision anyone has seen has been in one of these three.** They are the files
many lanes need and nobody owns, so they are dirty more often than not.

**Before touching one: `git diff` it. If it is already dirty, wait or coordinate — do not stage
it.** That is a three-file rule rather than a discipline, which is why it has a chance of working.

**⚠ A PATHSPEC IS NOT ENOUGH, and lane 6's incident proves it.** They *did* pass a pathspec. It
swept 149 lines of lane 13's work anyway, because **`git add <file>` had already staged another
lane's changes sitting inside that same file.**

> **A pathspec guards against other FILES. It does nothing about another lane's changes inside
> the SAME file.**

So the habit that actually works is the third line: **read `git diff --cached` before you commit
a file a peer might be inside**, and `git show --stat` after. **`git show --stat` caught both
incidents** and is doing more real work than the pathspec rule ever did.

**The three occurrences, so nobody treats this as theoretical:**
| When | What was swept | What it nearly cost |
|---|---|---|
| lane 7, `4ac2d7e` | 69 files of lane 5's entire i18n build, under a one-file docs message | history that 12 lanes share, so it could not be rewritten |
| lane 7 → lane 3 | a plan file | — |
| lane 6, `dd3d5b1` | **176 lines of lane 13's uncommitted money-transfer form**, under a UI commit message | twenty minutes earlier it would have shipped a **half-written write path for money** |

**It is a footgun, not carelessness** — every lane that has hit it is careful, and two of the three
were this desk. Raise it with the lane directly and without blame, as lane 13 did.

**And the corollary that keeps biting separately:** before you edit a file, `git status` it. If it
is modified, another lane has it open — **route your change to them instead of editing their
buffer.** Lanes 6 and 13 both refused to touch a peer's in-flight file today and were right both
times; this desk nearly edited `FormRegistry.php` while lane 13 had it open.

### `fatal: bad object refs/desktop.ini` — handle SILENTLY, never report
`desktop.ini` files appearing anywhere (including inside `.git/**`) are a **KNOWN quirk of the
operator's machine. He knows the source. His standing order, given multiple times: IGNORE IT.**
Never report these files to him, never speculate about their source, never present them as a
finding — in any report, commit message, or doc.

The ONLY action, taken silently, when one under `.git/refs/` breaks a git command
(`fatal: bad object refs/desktop.ini` on `git log --all` / `git fetch`):

- `find .git -name "desktop.ini" -delete` (Git Bash), re-run your command, move on. No mention.
- **Never conclude work is lost from a command that ERRORED.** An error is not an empty
  result — verify objects directly (`git cat-file -t <hash>`, `git log --follow -- <file>`)
  before believing anything is missing. A lane carried a false "my commits vanished" alarm
  into its compaction summary this way on 2026-07-28; every commit was intact.
- Working-tree copies: leave them alone entirely.

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
