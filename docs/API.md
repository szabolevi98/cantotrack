# The CantoTrack API

A JSON API over HTTPS, for scripts, other programs and the mobile app. It is
another way in to the same rules the web uses, never a way around them: every
request acts as one account, with exactly that account's rights, and goes
through the same code the forms do. What a person cannot see on a page is not
there in the API either — a ticket in a project they are not on answers `404`,
the same as one that does not exist.

Everything is under one address:

```
https://tracker.example/api/v1
```

## Signing in

Every request but the app sign-in carries a token as a bearer token:

```
curl -H "Authorization: Bearer ct_…" https://tracker.example/api/v1/me
```

A token is `ct_` and forty hexadecimal characters. There are three ways to get
one.

**A personal access token**, made under **Profile → Access tokens**, for a
script of your own: a name, and a day it expires on (or never). It is shown
once, when it is made — only its hash is kept — and it acts as you until it is
revoked on the same page.

**Signing in from an app** with the email address and password, below. The
token that comes back is listed on the profile with an **App** badge and ends
with your sessions: a new password, a reset one or **Sign out everywhere else**
signs the app out too. A token made by hand is left alone by all three.

**A service account's token**, for a program the team relies on — a webshop, a
CI server, a bookkeeping export. A personal token acts as the person who made
it, so whatever it writes is signed with their name, and it stops working the
day their account is switched off. A service account is an account of its own
for the program instead: an administrator makes it under **Service accounts**,
names it after what uses it ("GitLab CI"), and makes its tokens on its page.
The tickets it makes and the comments it writes carry its name, and its
`@handle` shows it on the account's page.

A service account is either a *member*, which may change the work, or a
*guest*, which only reads and comments; never an administrator. Like a person
it sees the team's projects (a member) and the private projects it is added
to under the project's **Members**. It cannot sign in with a password, is not
offered as an assignee (giving it a ticket is `422`), has no hours expected of
it, and is never notified. `GET /me` says `"service": true` for one. Switching
it off refuses all its tokens at once; switching it on again brings them back.

A missing token, one that is unknown, expired or revoked, or one whose account
is deactivated, is answered `401`. A connection that keeps sending wrong tokens
is answered `429` for fifteen minutes; a right token is never held up by that.

### POST /auth/login

Signs an app in. No token needed.

```json
{"email": "anna@example.com", "password": "…", "device": "Pixel 8"}
```

| Field | |
|---|---|
| `email`, `password` | the account's |
| `device` | optional; the token is named "App — Pixel 8" on the profile |
| `code` | the two-step sign-in code, or a recovery code — see below |

Answers `201`:

```json
{"data": {"token": "ct_3f9a…", "user": {"id": 3, "name": "Anna Kovács", "handle": "anna", "email": "anna@example.com", "role": "member"}}}
```

With two-step sign-in on, the password alone is answered `403` with
`"details": {"two_factor_required": true}`. That is not a failure — the
password was right: ask for the code and send the same request again with
`"code": "123 456"`. A wrong password or a wrong code is `401`; both count
towards the sign-in form's own limit — five for one address and thirty for one
connection in fifteen minutes — and past it the answer is `429` with a
`Retry-After` header. There is no reCAPTCHA here, as there is none an app could
answer.

### POST /auth/logout

Revokes the token the request carries: the app's own "sign out". Answers `204`.

## Requests and answers

**Bodies** are JSON objects, sent with `Content-Type: application/json`. A form
(`application/x-www-form-urlencoded`) is read too, for the clients that only
send those; a file is sent as `multipart/form-data`. Anything else — a JSON
array, a string — is `400`.

**Answers** are JSON, with what was asked for under `data`:

```json
{"data": {"key": "CT-14", "title": "Export as PDF", "…": "…"}}
```

A list that comes in pages says which one under `meta`:

```json
{"data": [{"…": "…"}], "meta": {"page": 2, "per_page": 50, "total": 132, "pages": 3}}
```

`?page=` starts at 1, and `?per_page=` is at most 100 (50 when not given).

A change that leaves nothing to show — a delete, a sign-out — is `204` with no
body at all. Something new is `201`, with its address in `Location` where it
has one.

**Errors** are always JSON, whatever went wrong, and never an HTML page:

```json
{"error": {"status": 422, "message": "A ticket needs a title."}}
```

A few carry `details` beside the message, for a client to act on
(`two_factor_required`). The message is written for a person, in the language
the account has chosen on its profile; a program goes by the status:

| Status | |
|---|---|
| `400` | the body is not a JSON object, or a header is malformed |
| `401` | no token, or one that does not work |
| `403` | not yours to do: a guest changing work, somebody else's hours |
| `404` | not there — or not somewhere you can see |
| `409` | somebody else saved it first; read it again (see `version`) — or the same request is still being worked on (see below) |
| `413` | a file bigger than the server takes |
| `422` | the request is understood but does not make sense: no title, a day in the future, a move the workflow does not allow, an `Idempotency-Key` used for another request |
| `429` | too many tries; wait for `Retry-After` seconds |
| `500` | a fault on our side, written to the server's log |

**Dates** are `2026-09-22`, times of day `09:30`, and moments ISO 8601 with the
server's offset (`2026-09-22T14:05:11+02:00`) — or, in a few older fields,
`2026-09-22 14:05:11` in the server's time zone.

**Durations** are sent as a person types them: `1h 30m`, `90m`, `1.5h`,
`1:30`, `1h30`; estimates also take days and weeks (`2d`, `1w 2d`), a day being
the installation's working day. A bare number is minutes. They come back as
whole minutes, in fields that end in `_minutes`.

### Sending a change twice

A request whose answer never arrived — the train went into a tunnel — may or
may not have been done. Sending it again is safe when it carries an
`Idempotency-Key` header: any string of up to 100 visible characters, new for
each change the client means to make. A UUID is the usual choice.

```
curl -X POST -H "Authorization: Bearer ct_…" -H "Content-Type: application/json" \
     -H "Idempotency-Key: 5f0c2b1e-8d7a-4c1f-9a51-0e6b2f3c4d5e" \
     -d '{"time": "45m"}' https://tracker.example/api/v1/tickets/CT-14/worklogs
```

The same key with the same request, from the same account, within a day, is
answered with the first answer again — the same status, the same body, the
same `Location` — and an `Idempotent-Replayed: true` header, and nothing is
done a second time. While the first is still being worked on, the second is
`409` with `Retry-After: 1`. The same key with a *different* request — another
body, another address — is `422`: that is a bug in the client, not a resend.

Only a change that succeeded keeps its key. One that was refused did nothing,
so its key is let go, and the request can be corrected and sent again under
it. The header works on every `POST`, `PATCH` and `DELETE`, and is ignored on
a `GET`, which is safe to repeat anyway.

**Guests** — accounts that read and comment on the projects they were added to
— may read, comment and upload files. Everything that changes the work
answers them `403`.

## You and the team

### GET /me

The account the token acts as:

```json
{"data": {"id": 3, "name": "Anna Kovács", "handle": "anna", "email": "anna@example.com", "role": "member"}}
```

`role` is `admin`, `member` or `guest`.

### GET /users

The active people, by name — who a ticket can be given to:

```json
{"data": [{"id": 3, "name": "Anna Kovács", "handle": "anna"}, {"…": "…"}]}
```

## Projects

### GET /projects

The projects you can see; the archived ones too with `?archived=1`.

```json
{"data": [{"code": "CT", "name": "CantoTrack", "description": "…", "archived": false, "tickets": 42, "url": "https://tracker.example/projects/1"}]}
```

### GET /projects/{code}

One project, with its columns in order — the statuses its tickets can be in.
`category` is `todo`, `in_progress` or `done`, and is what "open" and "done"
mean everywhere else.

```json
{"data": {"code": "CT", "name": "CantoTrack", "…": "…",
          "statuses": [{"id": 1, "name": "To do", "category": "todo"}, {"id": 2, "name": "In progress", "category": "in_progress"}, {"…": "…"}]}}
```

## Tickets

A ticket is named by its key, the project's code and its number: `CT-14`.

```json
{
  "key": "CT-14",
  "id": 214,
  "project": "CT",
  "type": "story",
  "title": "Export as PDF",
  "status": {"id": 2, "name": "In progress", "category": "in_progress"},
  "resolution": null,
  "priority": "high",
  "assignee": {"id": 3, "name": "Anna Kovács"},
  "labels": ["reports"],
  "sprint": "CT Sprint 4",
  "epic": {"id": 4, "title": "Reporting"},
  "parent": null,
  "release": "1.4",
  "subtasks": {"count": 2, "done": 1},
  "story_points": 3,
  "estimate_minutes": 240,
  "logged_minutes": 90,
  "remaining_minutes": 150,
  "due_on": "2026-10-01",
  "version": 7,
  "created_at": "2026-09-20 10:12:03",
  "updated_at": "2026-09-22 14:05:11",
  "url": "https://tracker.example/t/CT-14"
}
```

One ticket read on its own also has `description` (Markdown, as it was
written), `reporter`, and `fields` — the project's own fields by name, `null`
where empty.

`type` is `task`, `bug` or `story`; `priority` is `low`, `normal`, `high` or
`urgent`; `resolution`, set only on a finished ticket, is `done`, `wont_do`,
`duplicate` or `cannot_reproduce`.

### GET /tickets

A page of tickets: the unfinished ones first, then by priority, the newest
first within one — unless `query` says `ORDER BY`. Every filter is optional,
and they add up:

| Parameter | |
|---|---|
| `project` | a project's code: `CT` |
| `status` | a column's id, or a category: `todo`, `in_progress`, `done` |
| `open` | `1`: only the ones not done |
| `assignee` | `me`, `none`, or a person's id |
| `type` | `task`, `bug` or `story` |
| `label` | one label, exactly |
| `q` | words in the title, the text or the comments — or a key, which finds that ticket |
| `query` | the query language of the ticket list, the whole of it: `project = CT AND assignee = me ORDER BY priority DESC` |
| `page`, `per_page` | see above |

```
curl -H "Authorization: Bearer ct_…" "https://tracker.example/api/v1/tickets?assignee=me&open=1"
```

A mistake in `query` is `422`, with the message saying where.

### GET /tickets/{key}

One ticket, with its description, reporter and fields.

### POST /tickets

A new ticket. `project` and `title` are needed; everything else is optional.

```json
{"project": "CT", "title": "Export as PDF", "description": "As a **manager** …",
 "type": "story", "priority": "high", "status": "To do", "assignee_id": 3,
 "labels": ["reports"], "estimate": "4h", "due_on": "2026-10-01",
 "story_points": 3, "epic_id": 4, "parent": "CT-12", "release": "1.4",
 "fields": {"Platform": "iOS"}}
```

| Field | |
|---|---|
| `status` | a column by its id or its name; the project's first when not given |
| `estimate` | a duration (`4h`); or `estimate_minutes`, a number |
| `labels` | a list, or one string with commas |
| `parent` | makes it a subtask of that ticket (by key); it takes the parent's epic, release and sprint |
| `release` | by its name or id; one that has not gone out yet |
| `fields` | the project's own fields, by name |

Answers `201` with the ticket, and its address in `Location`.

```
curl -X POST -H "Authorization: Bearer ct_…" -H "Content-Type: application/json" \
     -d '{"project": "CT", "title": "Export as PDF", "type": "story", "priority": "high",
          "labels": ["reports"], "estimate": "4h", "assignee_id": 3}' \
     https://tracker.example/api/v1/tickets
```

### PATCH /tickets/{key}

Changes the fields sent, and no others — the same fields as a new ticket, and:

| Field | |
|---|---|
| `status` | moves it to another column, by id or name — one the project's workflow allows it to go to from where it is; `422` otherwise |
| `resolution` | why a finished ticket is finished: with `status`, or on its own for one that is done already |
| `assignee_id` | `null` to take it off whoever has it |
| `version` | the `version` you read: see below |

Sending the `version` you read makes the change conditional: if somebody else
saved the ticket in between, the answer is `409` and nothing changes. Read it
again, and decide. Without it, the last write wins.

```
curl -X PATCH -H "Authorization: Bearer ct_…" -H "Content-Type: application/json" \
     -d '{"status": "Review", "version": 7}' https://tracker.example/api/v1/tickets/CT-14
```

Answers `200` with the ticket as it is now.

## Comments

```json
{"id": 88, "author": {"id": 3, "name": "Anna Kovács"}, "body": "Looks good — @mark can you check the totals?",
 "created_at": "2026-09-22 14:05:11", "edited_at": null}
```

The body is Markdown. An `@handle` in it is a mention, and tells that person;
a key (`CT-12`) becomes a link.

### GET /tickets/{key}/comments

A ticket's comments, oldest first.

### POST /tickets/{key}/comments

```json
{"body": "Deployed to staging."}
```

Answers `201` with the comment. Guests may comment too.

## Hours

A worklog is one stretch of work on one ticket, on one day:

```json
{"id": 512, "ticket": "CT-14", "user": {"id": 3, "name": "Anna Kovács"}, "date": "2026-09-22",
 "start": "09:30", "minutes": 90, "work_type": "Development", "note": "Totals row",
 "billable": true, "created_at": "2026-09-22 11:02:40"}
```

`start` is `null` when only the length was given. The same rules hold as on
the web: no day in the future, at most a day in one entry, and nothing in a
week that has been handed in or approved, or in hours already billed — those
are `422`. The installation's smallest slice (`work.minimum_minutes`) rounds a
shorter entry up, and the answer then says `"rounded": true`.

### GET /worklogs

Hours in a range, oldest first — your own unless `user` says whose.

| Parameter | |
|---|---|
| `from`, `to` | the days, both included; this week so far when not given, and at most a year |
| `user` | a person's id |

```json
{"data": [{"…": "…"}], "meta": {"from": "2026-09-01", "to": "2026-09-30", "user_id": 3}}
```

### POST /tickets/{key}/worklogs

```json
{"time": "1h 30m", "date": "2026-09-22", "start": "09:30", "note": "Totals row",
 "remaining": "2h", "billable": true, "work_type": "Development"}
```

Only `time` is needed; `date` is today when not given.

| Field | |
|---|---|
| `remaining` | what is left on the ticket now; left out, the remaining time goes down by this entry |
| `billable` | left out, what the project's hours usually are |
| `work_type` | one of the installation's work types, by name or id |

Answers `201` with the entry.

### PATCH /worklogs/{id}

Changes an entry of yours — anybody's, as an administrator: `time`, `date`,
`note`, `start`, `billable`, `work_type`. Only the fields sent change. Answers
`200` with the entry.

### DELETE /worklogs/{id}

Removes an entry of yours. Answers `204`.

## The clock

The same clock the web shows in its corner: one per person, on one ticket at a
time.

```json
{"ticket": "CT-14", "title": "Export as PDF", "started_at": "2026-09-22T09:30:00+02:00", "seconds": 1834}
```

`seconds` is how long it has run by the server's clock, when it was read —
count on from there rather than from a phone's clock, which may be off.

### GET /timer

Your running clock, or `{"data": null}`.

### POST /tickets/{key}/timer

Starts it on a ticket. One running on another ticket is stopped and logged
first, and `logged` says what that came to:

```json
{"data": {"ticket": "CT-14", "…": "…"}, "logged": {"minutes": 45, "ticket": "CT-9"}}
```

Answers `201`.

### POST /timer/stop

Stops it and logs the time, with an optional note:

```json
{"note": "Totals row"}
```

Answers `{"data": {"minutes": 52, "ticket": "CT-14"}}` — or `{"data": null}`
when it ran for less than a minute, and nothing was logged. No clock running
is `422`.

### DELETE /timer

Stops it without logging anything. Answers `204`.

## Webhooks

The API is for asking; webhooks are for being told. An administrator adds them
under **Webhooks**: an address, the events it wants (`ticket.created`,
`ticket.changed`, `ticket.status`, `ticket.commented`, `ticket.logged`,
`ticket.commit` and the rest), and optionally one project. Each change is then a
`POST` of JSON to that address, with the ticket in the same shape as above:

```json
{
  "event": "ticket.changed",
  "sent_at": "2026-09-23T14:05:11+02:00",
  "actor": {"id": 1, "name": "Szabó Levente", "handle": "levente"},
  "ticket": {"key": "CT-5", "title": "…", "…": "…"},
  "changes": [{"field": "priority", "old": "normal", "new": "high"}]
}
```

The `X-CantoTrack-Signature` header is `sha256=` and the HMAC-SHA256 of the body
with the webhook's secret:

```php
$body = file_get_contents('php://input');
$expected = 'sha256=' . hash_hmac('sha256', $body, $secret);
if (!hash_equals($expected, $_SERVER['HTTP_X_CANTOTRACK_SIGNATURE'] ?? '')) { http_response_code(401); exit; }
```

Anything but a `2xx` is tried again after 1, 5, 30, 120 and 720 minutes, and
every delivery can be read and resent on the webhook's page.
