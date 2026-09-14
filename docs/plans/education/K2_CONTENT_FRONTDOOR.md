# K2 — Front-door authentication surfaces (LE-5)

**Owner:** LE-5 · **Added:** 2026-09-14
**Authority:** operator ruling `learn-disclosure-auth-pages` = B ("Education is important. If there
are front door pages that need education surfaces add them.").

These two sign-in screens carry no shell, so they carry no Learn drawer. LE-5 mounts a Learn-only
command bar on each and authors its guidance here. `auth/register` already has an entry in
`K2_CONTENT_SURFACES.md` and is not repeated. Both ids below are registered in
`config/cga/surfaces.php`, so this file is settled corpus, not authored-ahead.

**Voice:** second person, present tense, no jargon, no phase words. State where the app does not
gate a right.

---

## Wave LE-5 — front-door authentication (2 surfaces)

### 1. `auth/login` — Log in
**learn:** Signing in reconnects you to the record you already made. It adds no right and it removes
none. Your rights ride with where you live, not with this session.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Enter your email and your password | Your account is yours alone. The server allows five tries a minute, then it pauses new tries for a short time and says so on the email field. | Art. I |
| 2 | Sign in | You return to where you were headed, or to your civic home. Signing in never changes what you may do. | — |
| 3 | Confirm where you live, if you have not | On this beta, residency confirms the moment you declare it. Voting and standing for office switch on right away. There is no second step and no one approves it. | F-IND-003 · Art. I |

> **The why:** the app derives the right to vote from residency itself, in the same breath as the
> fact of living somewhere, so nothing can sit between the two. A session is only a key to your
> record. It is never the source of a right.

---

### 2. `auth/operator-login` — Operator sign-in
**learn:** This sign-in runs the instance. It is not a way into the government. Operator status is
infrastructure and carries no vote, no seat, and no standing in any jurisdiction.

| # | do | detail | cite |
|---|---|---|---|
| 1 | Enter your operator username and your password | This login is separate from your citizen account. The server allows five tries a minute, then it pauses new tries for a short time and says so on the username field. | — |
| 2 | Sign in as operator | You reach the operator console: host services, mesh peers, and settings. You gain no governance power by signing in here. | — |
| 3 | Use your citizen account for anything civic | Voting, standing for office, and filing civic forms all belong to your citizen record, never to this login. | Art. I |

> **The why:** the constitution ties authority to a jurisdiction and to the people in it, never to
> whoever runs the servers. Keeping this login separate keeps that line bright: administering the
> software is not a seat in the government.
