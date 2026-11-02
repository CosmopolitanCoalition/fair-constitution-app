# Personal workspace API (for n8n and other automation)

Base URL: `https://<host>/api/v1`. Auth: `Authorization: Bearer cga_...`. Every key belongs to one
user and sees only that user's rows. Create keys at `/connections` (signed in). The key is shown once.

## Scopes

| Scope | Allows |
|---|---|
| calendar:read | list calendars, events, availability, free slots |
| calendar:write | book, change, cancel events; set availability; sync from Microsoft or Google |
| tasks:read / tasks:write | list, create, update, delete tasks and projects; sync from Planner or To Do |
| crm:read / crm:write | contacts, caller identity, notes, follow-ups; sync from EspoCRM |
| mail:read | messages, counts, drafts |
| mail:write | sync messages, flag or mark read, create drafts, request send |
| mail:send | fetch drafts the owner approved, mark them sent |

Give n8n one key per job with the fewest scopes that job needs. Revoke a key at `/connections`.

## Mail send gate

A key can create a draft and call `request-send`. That only moves the draft to `pending`.
The owner approves or rejects it on `/connections` in a signed-in session. No API key can approve.
After approval a key with `mail:send` reads `GET /mail/approved`, sends through its own Microsoft or
Google credential, and calls `POST /mail/drafts/{id}/sent`.

## Endpoints

| Method and path | Scope | Notes |
|---|---|---|
| GET /ping | calendar:read | check the key |
| GET /calendars, /events?from&to, /availability | calendar:read | |
| GET /slots?from&to&minutes&appointment_type | calendar:read | free slots inside availability, minus busy events, max 31 days |
| POST /events | calendar:write | 409 on overlap unless `force:true` |
| PATCH /events/{id}, POST /events/{id}/cancel | calendar:write | |
| PUT /availability | calendar:write | replaces all windows: `rules[{weekday 0-6, starts_at HH:MM, ends_at HH:MM}]` |
| POST /events/sync | calendar:write | `source` microsoft or google, up to 500 events keyed by `external_id` |
| GET /tasks?status&project_id&due_before, /projects | tasks:read | |
| POST /tasks, PATCH /tasks/{id}, DELETE /tasks/{id}, POST /projects, POST /tasks/sync | tasks:write | |
| GET /contacts?q, /contacts/{id}, /followups | crm:read | |
| GET /contacts/identify?phone= or ?email= | crm:read | caller identity; phone matches any format |
| POST /contacts, PATCH /contacts/{id}, POST /contacts/sync | crm:write | |
| POST /contacts/{id}/notes, /contacts/{id}/followups, /followups/{id}/complete | crm:write | |
| GET /mail/messages?unread&flagged&account, /mail/messages/{id}, /mail/counts, /mail/drafts | mail:read | |
| POST /mail/sync, PATCH /mail/messages/{id}, POST /mail/drafts, POST /mail/drafts/{id}/request-send | mail:write | |
| GET /mail/approved, POST /mail/drafts/{id}/sent | mail:send | |

Times go in as ISO 8601 and are stored in UTC. Errors return JSON with `message`.
Rate limit: 240 requests a minute per client.

## Stored credentials

`/connections` also holds a per-user vault for the passwords and tokens of other services.
Each value is sealed with AES-256-GCM under a key derived from the application key and the user id.
A value shows only after the owner types their password again. No API route reads the vault.
n8n keeps its own credentials. The vault is for statecraft's own sync jobs and for the owner's records.
Rotating `APP_KEY` makes stored secrets unreadable, so re-enter them after a rotation.
