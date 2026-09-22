# CantoTrack

An issue tracker with time logging: work is broken down into **projects → epics
→ tickets**, and the hours that go into it are logged against the tickets and
totalled into timesheets.

Written in PHP with Twig and MySQL. No framework: a router, a handful of core
classes and a stylesheet built by concatenating its own sources — small enough
to read in an afternoon and to deploy by copying a folder.

> **Being written now.** The scaffolding stands — sign-in, sessions, the layout,
> the stylesheet, the migrations and a smoke test that walks the whole thing over
> HTTP. The tickets and the timesheets are next, and the pictures in this README
> arrive with them.

## What it will do

- **Projects, epics and tickets** — three levels and no more. A project holds
  epics, an epic holds tickets, and a ticket is the thing somebody works on and
  logs time against.
- **A board and a list** for each project, with the statuses a ticket moves
  through, who it is assigned to and what it is worth.
- **Worklogs**: hours logged against a ticket, on a date, with a note — the
  record of where the week actually went.
- **Timesheets**: a week at a glance per person, totalled by day and by project,
  and the gap between what was logged and what a working day is.
- **Two roles**: an administrator sets up projects and people, a member works
  tickets and logs time. A tracker this size does not earn a permission matrix.

## Running it

Needs PHP 8.4 (it runs on 8.2 as well), MySQL or MariaDB, and a web server whose
document root is the `web/` folder.

```
composer install
cp config/config.ini.dist config/config.ini   # then fill in the database
php database/migrate.php                       # creates the database and the tables
php database/seed.php                          # the first administrator, with a printed password
php bin/build_css.php                          # src/View/Css/** -> web/assets/css/app.css
```

Then open the application and sign in with what the seed printed. On a
development machine the address is whatever the folder maps to — set the same
one in `config.ini` as `app.base_url`, because that is what every link is built
from.

### Checking it

```
php tests/smoke.php --email=you@example.com --password=…
```

It walks the application over real HTTP rather than calling the controllers:
the login page, a post without a CSRF token, a wrong password, a right one, the
dashboard behind the session, and what a signed-out visitor gets. Most of what
breaks in a PHP application of this shape breaks outside PHP — in the rewrite
rules, the session cookie or a redirect — and none of that is visible to a test
that calls a controller directly.

## Layout

```
bin/            the CSS build, and whatever else is run by hand
config/         config.ini.dist — the real config.ini is never committed
database/       the schema as .sql files, the migration runner and the seed
src/Core/       config, router, session, CSRF, auth, logging, formatting
src/Controller/ one class per area of the application
src/Model/      repositories: everything that touches the database
src/View/       Twig templates, and the CSS sources under View/Css
tests/          checks that run against a live installation
web/            the document root: the front controller and the built assets
```

## License

MIT. See [LICENSE](LICENSE).
