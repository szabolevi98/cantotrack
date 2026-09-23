<img src="web/assets/favicon.svg" width="72" alt="CantoTrack">

# CantoTrack

An issue tracker with time tracking for a small team: work is broken down into
**projects → epics → tickets**, planned on boards and in sprints, and the hours
that go into it are logged against the tickets, handed in a week at a time,
approved, and added up for invoicing.

![The board of a project: a column per status, the running sprint above it](docs/board.png)

Written in PHP 8.4 with Twig and MariaDB/MySQL. No framework: a router, a
handful of core classes, services that every way in goes through — the forms,
the JSON API and the integrations alike — and a stylesheet built by
concatenating its own sources. Small enough to read in an afternoon, and to
deploy with `git pull`.

**Demo:** [cantotrack.levente.net](https://cantotrack.levente.net/)

## What it does

### Planning

- **Projects, epics and tickets.** A ticket is a task, a bug or a story, with a
  priority, labels, story points, an estimate and a due date. Ticket numbers
  are per project (`CT-4`, `WEB-3`) and come from a counter raised in the same
  transaction as the insert, so two people creating a ticket at the same moment
  cannot end up with the same one.
- **A board per project**, with the project's own columns, WIP limits, and
  swimlanes by person or epic. Cards are dragged between columns — and every
  card also has a select, so the board works on a phone and on a keyboard too.
- **Backlog and sprints.** A sprint writes down what it committed to when it
  starts; closing it hands the unfinished work on to the next one. Each sprint
  has a burndown, and the project a velocity chart — drawn on the server as
  SVG, so there is no charting library.
- **Links between tickets** — blocks, relates to, duplicates. A ticket blocked by
  an unfinished one says so on the board.
- **Private projects and guests.** A project is open to the team, or private to
  the people added to it. A guest — somebody from the client — sees only the
  projects they were added to, and can read and comment but not change the work.

![Backlog and sprints](docs/backlog.png)

![A closed sprint: what it committed to, and its burndown](docs/sprint.png)

### Talking about the work

- **Comments in Markdown**, with task lists, `@mentions` that notify, and ticket
  keys (`CT-14`) that link themselves. A screenshot pasted into a comment is
  attached to the ticket.
- **Every change is in the ticket's history**, written in words ("moved it from
  In progress to Review"), next to the comments or on its own.
- **Notifications** in the application and by email, to the people who follow a
  ticket — which anybody who creates, comments on or is given a ticket does —
  and never about a ticket in a project they cannot see.
- **Saved filters**, private or shared with the team, and a ticket list that
  filters into the URL, so a list can be bookmarked or sent to somebody.
- **Bulk changes** from the ticket list, **CSV import** of tickets from a
  spreadsheet (columns recognised by name, in English or Hungarian, with a
  preview of every row before anything is made), and keyboard shortcuts (`?`
  lists them).

![A ticket: its history and comments, the links, and the facts beside them](docs/ticket.png)

### Time

- **Logged where the work was**: on the ticket, on the day it happened — which
  is rarely the day it is typed in. Durations are written the way people say
  them: `1h 30m`, `90m`, `1.5h`, `1:30` or `1d 2h`. The remaining estimate goes
  down as time is logged, and a timer turns a running stretch into an entry.
  **Log time** in the top bar (or `l`) logs on any ticket from any page, with
  starred and recently worked-on tickets offered first.
- **The week**, day by day, as a grid of tickets by days that can be typed
  straight into, or as a calendar of the hours: entries sit where they started,
  and dragging over an empty stretch logs it. Each day is measured against the
  person's own working week, and a public holiday or a day away is not a short
  day — it says what it is. Last week's tickets come back to the grid in one
  click.
- **One's own calendar**, read from its private iCal address (Google, Outlook
  or anything else that publishes one): the week's meetings show up on the
  calendar, and a click turns one into an entry.
- **Work types** — development, design, a meeting — set by an administrator,
  chosen per entry, and a way to split the reports.
- **The team's week** on one page, every day of every person against their own
  week, and **the missing hours**: who logged less than their working days over
  a stretch, and by how much.
- **Planning**: hours a day on a ticket or a project for a stretch of days, next
  to what each person can work and what they have logged since.
- **Handing a week in**: a handed-in week's hours stop changing until an
  administrator approves it or sends it back with a reason. A lock date closes
  everything before it — for the month that has been invoiced.
- **Billable or not**, by project default or per entry, with clients on the
  projects; **reports** by project, person, client, ticket, work type or day, exported as
  CSV (safe to open in Excel: formulas are neutralised) or as a real `.xlsx`.

![The timesheet: a week, day by day, measured against the person's own week](docs/timesheet.png)

![The week as a calendar: entries where they started, meetings from one's own calendar beside them](docs/calendar.png)

![Planning: the hours ahead, against what each person can work](docs/planning.png)

![Weeks handed in and waiting for approval](docs/approvals.png)

![Reports, and the exports](docs/reports.png)

### For other programs

- **A JSON API** under `/api/v1`, with personal access tokens made on the
  profile — see [The API](#the-api) below.
- **Webhooks**: signed messages about every change, to any address outside the
  server's own network, retried with backoff when the other end is down, with a
  log of every delivery that can be resent.
- **GitHub**: a push that names a ticket (`CT-14`) puts the commit into the
  ticket's history, and `Fixes CT-14` on the default branch moves it to done.

### The rest

- **English and Hungarian** throughout, chosen per person — every sentence has
  its translation, and CI refuses a sentence that does not.
- **A dark theme**, or the system's, with the same contrast figures as the light
  one (every text on its background at least 4.5:1).
- **Two-step sign-in** with an authenticator app (TOTP) and single-use recovery
  codes; profile pictures; passwords reset by email with a link that works once.
- **Three roles** — administrator, member, guest — and nobody ever deleted:
  people are deactivated, because tickets and hours point at them.

![The same week in Hungarian](docs/hungarian.png)

![The board in the dark theme](docs/dark.png)

## Security, in short

- A Content-Security-Policy of `script-src 'self'`: there is no inline script or
  event handler anywhere, so text that got onto a page cannot run.
- One CSRF gate for every form post; the API and the integrations carry no
  session at all and prove themselves with a token or a signature.
- Passwords with bcrypt at cost 12; a wrong address and a wrong password take
  the same time to answer; sign-in, token and code attempts are throttled.
- Hidden projects are filtered where the rows are read, so a ticket somebody
  cannot see is simply not there — on a page, in a search or in the API.
- Uploads are checked by content, not by name, and served so a browser cannot
  run them; webhook addresses are checked against private and reserved ranges,
  and the checked address is the one connected to.

## Running it

Needs PHP 8.4 (with `curl`, `fileinfo`, `gd`, `iconv`, `mbstring`, `pdo_mysql`
and `zip`), MariaDB or MySQL, and a web server whose document root is the `web/`
folder.

```
composer install
cp config/config.ini.dist config/config.ini   # then fill in the database and the address
php database/migrate.php                       # creates the tables, and later brings them up to date
php database/seed.php                          # the first administrator, with a printed password
```

Then open the application and sign in with what the seed printed. `app.base_url`
in `config.ini` has to be the address the application is opened at, because
every link is built from it. The built stylesheet and script are committed; after
changing their sources, `php bin/build_assets.php` builds them again.

For webhooks, one line of cron:

```
* * * * * www-data php /path/to/cantotrack/bin/webhooks.php
```

It sends what is waiting and retries what failed. Under PHP-FPM a request sends
its own messages after answering; under mod_php they wait for the cron job, so
that nobody waits for a slow receiver.

### Something to look at

```
php database/seed_demo.php
```

Two projects — the tracker itself, and a client's website that only its members
and the client's guest can see — with epics, twenty-one tickets, comments,
links, a finished and a running sprint, two weeks of hours, a handed-in week
waiting for approval, and the year's public holidays. It is what the pictures
above are. It prints the passwords of the people it invents, takes `--reset` to
start over, and refuses to run unless `app.env` is `dev`: it creates accounts
that can sign in, and an account nobody meant to create is exactly the kind of
thing that survives to a live server.

## The API

Every request carries a personal access token, made under **Profile → Access
tokens**, as a bearer token. It acts as the person it belongs to, with their
rights and nothing more, through the same services the forms use.

```
curl -H "Authorization: Bearer ct_…" https://tracker.example/api/v1/me
```

| Method | Path | |
|---|---|---|
| `GET` | `/api/v1/me` | who the token belongs to |
| `GET` | `/api/v1/users` | the active people |
| `GET` | `/api/v1/projects` | the projects you can see (`?archived=1` for all) |
| `GET` | `/api/v1/projects/{code}` | one project, with its columns |
| `GET` | `/api/v1/tickets` | a page of tickets: `?project=CT&status=in_progress&assignee=me&label=…&q=…&type=bug&open=1&page=2&per_page=50` |
| `POST` | `/api/v1/tickets` | a new ticket |
| `GET` | `/api/v1/tickets/{key}` | one ticket, by its key |
| `PATCH` | `/api/v1/tickets/{key}` | change the fields sent, and no others |
| `GET` | `/api/v1/tickets/{key}/comments` | its comments |
| `POST` | `/api/v1/tickets/{key}/comments` | `{"body": "…"}` |
| `POST` | `/api/v1/tickets/{key}/worklogs` | `{"time": "1h 30m", "date": "2026-09-22", "note": "…", "remaining": "2h", "billable": true}` |
| `GET` | `/api/v1/worklogs` | hours in a range: `?from=2026-09-01&to=2026-09-30&user=3` (your own by default) |
| `DELETE` | `/api/v1/worklogs/{id}` | remove an entry of yours |

A new ticket:

```
curl -X POST -H "Authorization: Bearer ct_…" -H "Content-Type: application/json" \
     -d '{"project": "CT", "title": "Export as PDF", "type": "story", "priority": "high",
          "labels": ["reports"], "estimate": "4h", "assignee_id": 3}' \
     https://tracker.example/api/v1/tickets
```

A `PATCH` may carry the `version` it read; if somebody else saved the ticket in
between, it answers `409` instead of overwriting their change. Failures are
always JSON — `{"error": {"status": 422, "message": "A ticket needs a title."}}`
— with `401` for a missing or wrong token, `403` for something that is not
yours, `404` for something that is not there (or not visible to you), and `429`
after too many wrong tokens.

## Webhooks

Administrators add them under **Webhooks**: an address, the events it wants
(`ticket.created`, `ticket.changed`, `ticket.status`, `ticket.commented`,
`ticket.logged`, `ticket.commit` and the rest — or all of them), and optionally
one project. Each change becomes a `POST` with a JSON body:

```json
{
  "event": "ticket.changed",
  "sent_at": "2026-09-23T14:05:11+02:00",
  "actor": {"id": 1, "name": "Szabó Levente", "handle": "levente"},
  "ticket": {"key": "CT-5", "title": "…", "status": {"name": "Review", "category": "in_progress"}, "url": "…", "…": "…"},
  "changes": [{"field": "priority", "old": "normal", "new": "high"}]
}
```

An edit of three fields is one message with three changes. The
`X-CantoTrack-Signature` header is `sha256=` and the HMAC-SHA256 of the body with
the webhook's secret; checking it takes three lines:

```php
$body = file_get_contents('php://input');
$expected = 'sha256=' . hash_hmac('sha256', $body, $secret);
if (!hash_equals($expected, $_SERVER['HTTP_X_CANTOTRACK_SIGNATURE'] ?? '')) { http_response_code(401); exit; }
```

Anything but a `2xx` is tried again after 1, 5, 30, 120 and 720 minutes.

For **GitHub**, **Webhooks → GitHub → Set it up** gives the payload URL and a
secret; in the repository add a webhook with content type `application/json`
and just the push event.

## Checking it

```
vendor/bin/phpunit                     # unit tests, and integration tests against a *_test database
vendor/bin/phpstan analyse             # static analysis, level 8
vendor/bin/php-cs-fixer fix --dry-run  # code style
php bin/i18n_check.php                 # every sentence has its Hungarian
php tests/smoke.php --email=you@example.com --password=…
```

The smoke test walks the application over real HTTP rather than calling the
controllers: the login page, a post without a CSRF token, a wrong password, a
right one and a second step, the board, a ticket with its comments and
attachments, the hours, the timesheet, the reports and their exports, the API
with a token, a signed GitHub push, and an import. It creates what it needs and
deletes it again; the one thing it leaves behind is a deactivated account,
because the application does not delete people and the checks do not make an
exception for themselves.

Most of what breaks in a PHP application of this shape breaks outside PHP — in
the rewrite rules, the session cookie or a redirect — and none of that is
visible to a test that calls a controller directly. CI runs all of the above on
every push, against a real MariaDB.

## Layout

```
bin/            asset build, webhook sender, translation check, the dev router
config/         config.ini.dist — the real config.ini is never committed
database/       the migrations as .sql files, the runner, and the seeds
docs/           the pictures in this README
lang/           the Hungarian catalogue
src/Core/       config, router, session, CSRF, auth, access, markdown, charts, …
src/Controller/ one class per area of the application
src/Model/      repositories: everything that touches the database
src/Service/    the rules: tickets, hours, sprints, calendar, notifications, webhooks
src/View/       Twig templates, and the CSS and JavaScript sources
tests/          unit and integration tests, and the smoke test over HTTP
web/            the document root: the front controller and the built assets
```

## License

MIT. See [LICENSE](LICENSE).
