# Per-lane git worktrees — proposal

**Status:** proposed, **deferred to after the walk** by lane 7 (2026-07-26).
**Author:** lane 13. **Requested by:** lane 7 — *"the layout, the merge step, what breaks."*
**Owner on adoption:** lane 7 (fleet workflow). Relocate this file if `docs/plans/fleet/` is the
wrong home; it was created new to avoid colliding with anyone's tree.

---

## 1. The problem, stated as evidence rather than opinion

Twelve lanes share one working tree at `E:\fair-constitution-app`. In a single day, **three**
commits silently contained another lane's uncommitted work:

| # | Commit | Message said | Also contained | Lost? |
|---|---|---|---|---|
| 1 | `ddde935` (L15) | — | lane 3's plan, 710 lines | no |
| 2 | `dd3d5b1` (L6) | persona switcher | 149 lines of lane 13's `Wallet.vue` — a money-transfer form | no |
| 3 | `6e6d803` (L7) | `legislature_max_seats` is a legacy name | lane 13's `CLAUDE.md` form-count edit | no |

**Nothing has been lost in any of them. That is luck, not process.**

Two facts make this structural rather than a discipline problem:

- **Lane 6 followed the rule and it swept anyway.** They passed a pathspec to `git commit`. It
  didn't help, because `git add <file>` had already staged another lane's lines *inside that file*.
  **A pathspec guards against other FILES, never against another lane's changes inside the SAME
  file.**
- **Lane 7 wrote the rule, corrected it, and then swept someone four hours later.** They did not run
  `git diff --cached` — the check they had themselves put at the top of the guidance.

The near-miss that stopped a fourth is the clinching detail: lane 13 ran `git add CLAUDE.md`, saw
`+8/-6` against their own `+7/-5`, and stopped **because the arithmetic didn't match**. A control
that depends on someone doing sums on a diffstat at the right moment is not a control.

> After one it's a mistake. After three in a day it's a property of twelve lanes sharing one
> worktree.

**Interim measure, already in force (lane 7 ruling):** `CLAUDE.md`, `routes/web.php` and
`FormRegistry.php` are **hot files** — `git diff` before touching one; if dirty, wait or coordinate.
Those three account for every cross-lane collision observed. It is a good rule and it is still a
rule someone has to remember.

---

## 2. What worktrees change

`git worktree` gives each lane its **own checkout of the same repository**, on its own branch,
sharing one `.git` object store.

```
E:\fair-constitution-app          main — INTEGRATION checkout, stays as-is
E:\fleet\lane-01                  branch lane/01-geodata
E:\fleet\lane-03                  branch lane/03-scaling
E:\fleet\lane-06                  branch lane/06-ui
E:\fleet\lane-13                  branch lane/13-economy
```

```bash
git worktree add ../fleet/lane-13 -b lane/13-economy
```

**The mechanism, and why it is a control rather than a habit:** lane 6 could not have staged lane
13's `Wallet.vue` changes, because **those changes would not exist in lane 6's working tree.**
`git add .` becomes safe. `git commit -a` becomes safe. Nobody has to remember anything.

---

## 3. What it costs — the honest section

### 3.1 Docker bind mounts — the one that could sink it

`docker-compose.yml` binds `E:\fair-constitution-app` → `/var/www/html`. A worktree is a different
path, so the naive reading is *one stack per lane*. **That is fatal on this box:** two stacks
already consume **6.32 of 7.64 GiB** and cause the `setns`/overlay failures that took out `docker
exec` for most of 2026-07-26. Twelve stacks is not arithmetic anyone needs to do.

**The workable shape, and it is already proven in practice:**

| Purpose | How | Memory cost |
|---|---|---|
| Browsing the app | ONE long-running stack bound to the **integration** checkout | unchanged |
| A lane's tests / artisan / tinker | **transient container bound to that lane's worktree** | ~zero, exits immediately |

The transient form is the pattern that ran a full suite and a live engine proof on 2026-07-26 while
`docker exec` was dead box-wide:

```bash
MSYS_NO_PATHCONV=1 docker run --rm --network fcd_fc_network \
  -v "E:/fleet/lane-13:/var/www/html" -v fcd_vendor:/var/www/html/vendor \
  -w /var/www/html fcd-app php artisan test --filter=<Suite>
```

**The real regression: browser verification.** The long-running stack serves the *integration*
checkout, so a lane cannot see its own un-merged UI in a browser. Given the screenshot-proof rule,
that matters. Three options, in order of preference:

1. **Merge to see it.** Frequent small merges are wanted anyway; this makes the incentive explicit.
2. **A lane that needs a browser takes the stack temporarily**, repointing the bind mount. One lane
   at a time, coordinated — the same shape as today's hot-file rule but for a scarcer resource.
3. **A second lightweight stack** (nginx + php-fpm only, sharing postgres/redis) pointed at whichever
   worktree needs eyes. Costs perhaps 400–600 MB, which this box does not currently have.

**This is the item that decides whether the proposal is worth it. It should be tested before adoption,
not assumed.**

### 3.2 Conflicts don't vanish — they change form, loudly

Two lanes editing `FormRegistry.php` on separate branches produce a **merge conflict** instead of a
silent sweep.

**That is the win, not a cost.** A conflict is visible, blocking and resolvable by the person who
knows the code. A sweep is silent and discovered — if ever — by someone doing arithmetic on a
diffstat. But it *is* more work at merge time, and pretending otherwise would be selling this badly.

### 3.3 Untracked files don't come along

A fresh worktree has no `.env`, no `storage/` scaffolding, no `public/hot`, no `node_modules`. Needs
a one-time setup script:

```bash
cp ../../fair-constitution-app/.env .env
mkdir -p storage/framework/{cache,sessions,views} storage/logs bootstrap/cache
```

`vendor` and `node_modules` stay as **shared named volumes** mounted into transient containers —
they do not need to exist on disk per worktree. Caveat: a lane adding a composer/npm dependency
updates the shared volume for everyone, so dependency changes remain a coordinated act. That is
true today too.

### 3.4 One database, many branches — NOT fixed by this

All lanes share one Postgres. A migration on one branch applies to the shared DB, and another
lane's checkout then runs older code against a newer schema. **Worktrees do not address this and
should not be sold as if they do.** It is today's situation unchanged. If it becomes a problem the
answer is per-lane databases, which is a separate and larger proposal.

### 3.5 Disk

Twelve checkouts of the repo. One `.git` object store, shared. Modest.

---

## 4. The merge step

- **Integrator: lane 7** (owns fleet workflow).
- **Cadence: daily, and small.** Weekly merges of twelve branches is how this becomes hated. The
  cost of a merge scales with divergence, not with the number of merges.
- **Hot files stay hot.** `CLAUDE.md`, `routes/web.php`, `FormRegistry.php` will be the conflict
  sites. They are already the collision sites; the difference is that git now stops rather than
  silently picking a winner.
- **Direction:** lanes rebase onto `main` before requesting a merge, so conflicts are resolved by
  the lane that wrote the code rather than by the integrator guessing.

---

## 5. Recommendation

**Adopt after the walk, not before.** Lane 7's reasoning is right and worth restating: re-plumbing
twelve lanes' git mid-flight is a large, novel, untested change to **the one thing currently holding
all the work**, on the eve of a 53-card walk against a world that finished building an hour ago. The
failure mode of getting it wrong is worse than messy history — and messy history has, so far, cost
zero work.

**Before adopting, test exactly one thing:** §3.1, the browser-verification loop. Everything else in
this document is mechanical and low-risk. That one is a genuine unknown and it is the only part that
could make the whole thing not worth doing.

**Until then:** the hot-file rule, `git diff --cached` before committing a file a peer might be
inside, and `git show --stat <hash>` before quoting a hash — which is the habit that actually caught
all three incidents.
