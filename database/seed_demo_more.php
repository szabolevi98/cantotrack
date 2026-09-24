<?php

/**
 * The rest of the demo: the agency around the two hand-written projects.
 *
 * Five more projects — three for clients, run in sprints, and two kept as a
 * flow of work without them — eight more people, two of them from the
 * clients, and three months of it: tickets written, planned, worked on and
 * finished, the sprints that held them, the releases they went out in, and
 * every working day's hours, handed in and approved week by week.
 *
 * The words are written out by hand, because a demo reads its tickets; the
 * rest — who did what on which day — is drawn from a fixed seed, so the demo
 * comes out the same every time it is made on the same day.
 *
 * Included by seed_demo.php, after the tracker's own two projects; it uses
 * what that file has set up.
 *
 * @var PDO $database
 * @var CantoTrack\Model\ProjectRepository $projects
 * @var CantoTrack\Model\TicketRepository $tickets
 * @var CantoTrack\Model\UserRepository $users
 * @var CantoTrack\Model\WorklogRepository $worklogs
 * @var CantoTrack\Model\EpicRepository $epics
 * @var CantoTrack\Model\SprintRepository $sprints
 * @var CantoTrack\Service\TicketService $ticketService
 * @var CantoTrack\Service\CommentService $comments
 * @var CantoTrack\Service\LinkService $links
 * @var CantoTrack\Service\SprintService $sprintService
 * @var CantoTrack\Service\Calendar $calendar
 * @var array<string, int> $typeIds
 * @var array<string, string> $madePasswords
 * @var DateTimeImmutable $thisMonday
 * @var DateTimeImmutable $today
 * @var int $me
 * @var int $anna
 * @var int $mark
 * @var int $julia
 */

use CantoTrack\Model\ClientRepository;
use CantoTrack\Model\ReleaseRepository;
use CantoTrack\Service\Planning;
use CantoTrack\Service\ReleaseService;
use CantoTrack\Service\WeekReview;

mt_srand(20260923);

$pick = static fn(array $from): mixed => $from[mt_rand(0, count($from) - 1)];
$chance = static fn(int $percent): bool => mt_rand(1, 100) <= $percent;
$ymd = static fn(DateTimeImmutable $d): string => $d->format('Y-m-d');
$todayYmd = $today->format('Y-m-d');
/** A ticket this file made: it is there, so never null. */
$ticketRow = static fn(int $id): array => (array) $tickets->find($id);
$lastMonday = $thisMonday->modify('-7 days');

// ---------------------------------------------------------------------------
// The rest of the agency
// ---------------------------------------------------------------------------
$more = [
    'bence' => ['Bence Szabó', 'bence@cantotrack.demo', 'member'],
    'zsofia' => ['Zsófia Balogh', 'zsofia@cantotrack.demo', 'member'],
    'dora' => ['Dóra Kiss', 'dora@cantotrack.demo', 'member'],
    'reka' => ['Réka Horváth', 'reka@cantotrack.demo', 'member'],
    'gergo' => ['Gergő Molnár', 'gergo@cantotrack.demo', 'member'],
    'tamas' => ['Tamás Farkas', 'tamas@cantotrack.demo', 'admin'],
    // From the clients: they read and comment on their own project.
    'peter' => ['Péter Lakatos', 'peter@balatonbikes.demo', 'guest'],
    'kata' => ['Kata Fehér', 'kata@mecsekclinic.demo', 'guest'],
];

/** @var array<string, int> $who */
$who = ['me' => $me, 'anna' => $anna, 'mark' => $mark, 'julia' => $julia];

foreach ($more as $handle => [$name, $email, $role]) {
    $existing = $users->findByEmail($email);

    if ($existing !== null) {
        $who[$handle] = (int) $existing['id'];
        continue;
    }

    $password = CantoTrack\Model\UserRepository::newPassword();
    $who[$handle] = $users->create($name, $email, $password, $role);
    $madePasswords[$email] = $password;
}

// Dóra works six-hour days.
$users->setWorkingWeek($who['dora'], [360, 360, 360, 360, 360, 0, 0]);

// ---------------------------------------------------------------------------
// The projects, and who is on each
// ---------------------------------------------------------------------------
$clients = new ClientRepository();

/**
 * @var array<string, array{
 *     name: string, about: string, client: ?string, billable: bool, private: bool, sprints: ?string,
 *     team: list<string>, guest?: string,
 *     epics: array<string, array{0: string, 1: list<array{0: string, 1: string, 2: ?int, 3: string, 4: string, 5: ?string}>}>,
 *     subtasks: array<string, list<array{0: string, 1: string}>>,
 *     releases: list<array{0: string, 1: string, 2: int, 3: int, 4: bool}>
 * }> $catalogue
 */
$catalogue = [
    'BIKE' => [
        'name' => 'Balaton Bikes app',
        'about' => 'A phone app for renting bikes around the lake: find one, unlock it, ride, pay.',
        'client' => 'Balaton Bikes Kft.', 'billable' => true, 'private' => true, 'sprints' => 'Bikes',
        'team' => ['bence', 'zsofia', 'dora', 'reka', 'mark', 'anna', 'me', 'peter'],
        'guest' => 'peter',
        'epics' => [
            'Finding a bike' => ['The map of the stations, and how many bikes each has right now.', [
                ['story', 'Map of the stations with live bike counts', 5, '2d', 'map, api', 'Pins for every station, a number on each, refreshed while the map is open. Tapping one opens the station.'],
                ['story', 'Filter the map to e-bikes only', 2, '6h', 'map', 'Most summer riders want the electric ones; the filter should remember itself.'],
                ['bug', 'Station pins jump when the map is zoomed out', 2, '4h', 'map, ios', 'Seen on an iPhone 12: at zoom level 9 the clusters recalculate on every frame.'],
                ['story', 'Walking directions to the nearest station', 3, '1d', 'map', null],
                ['task', 'Cache the station list for offline start', 2, '5h', 'performance', 'The ferry has no signal. The app should open with the last list it had and say how old it is.'],
                ['bug', 'Bike count shows 0 for a station under maintenance', 1, '3h', 'api', null],
            ]],
            'Renting and riding' => ['Unlocking a bike with the phone, the ride itself, and bringing it back.', [
                ['story', 'Unlock a bike by scanning its QR code', 8, '3d', 'rental, hardware', "The lock answers over the network within two seconds, or the app says it is trying again.\n\n- [x] camera permission flow\n- [x] lock API\n- [ ] the lock that does not answer"],
                ['story', 'Ride screen with time and cost so far', 3, '1d', 'rental', null],
                ['story', 'End the ride at any station', 5, '2d', 'rental', 'The lock reports the dock it is in; a ride ended away from a station asks for a photo.'],
                ['bug', 'Ride timer keeps running after the lock closes', 3, '6h', 'rental, android', 'On Android 13 the background service is stopped by the battery saver, and the app only hears about the end of the ride when it is opened again.'],
                ['story', 'Pause a ride for a lunch stop', 3, '1d', 'rental', 'Up to 45 minutes at a lower rate, with the bike locked.'],
                ['task', 'Load test the lock API for a summer Saturday', 3, '1d', 'api, performance', 'Four hundred unlocks in ten minutes is last August\'s peak.'],
                ['bug', 'Photo upload fails on slow connections', 2, '5h', 'rental', null],
            ]],
            'Paying' => ['Cards, the ride price, and receipts for companies.', [
                ['story', 'Pay with a saved card', 5, '2d', 'payments', 'The card is kept by the payment provider; we keep a token and the last four digits.'],
                ['story', 'Apple Pay and Google Pay', 5, '2d', 'payments, ios, android', null],
                ['story', 'Company receipts with a VAT number', 3, '1d', 'payments, invoices', 'Hotels book bikes for their guests and need one invoice a month.'],
                ['bug', 'Refund is sent twice when the ride is disputed', 5, '1d', 'payments', 'Two support agents pressed the button within the same minute. The refund needs to be idempotent.'],
                ['task', 'Move prices into the admin, out of the code', 3, '1d', 'payments, admin', null],
                ['story', 'Day pass for 24 hours of riding', 3, '1d', 'payments', null],
            ]],
            'Accounts' => ['Signing up, signing in, and a rider\'s history.', [
                ['story', 'Sign up with a phone number', 3, '1d', 'accounts', 'A code by text message; no passwords for riders.'],
                ['story', 'Ride history with the route drawn', 3, '1d', 'accounts, map', null],
                ['task', 'Delete an account and its rides on request', 2, '6h', 'accounts, gdpr', 'The receipts stay for the accounts department — seven years — without the name on them.'],
                ['bug', 'Text message code arrives after it has expired', 2, '4h', 'accounts', 'The provider queues messages to Hungarian numbers at peak times. Two minutes is too short.'],
                ['story', 'Hungarian, English and German in the app', 5, '2d', 'i18n', null],
            ]],
            'Fleet and admin' => ['What the rental shop sees: the bikes, their batteries, and the repairs.', [
                ['story', 'Fleet list with battery levels', 5, '2d', 'admin, fleet', 'Every bike, where it is, its battery, and when it was last serviced.'],
                ['story', 'Mark a bike as broken from the app', 3, '1d', 'fleet, rental', 'A rider who finds a flat tyre reports it with a photo; the bike leaves the map.'],
                ['story', 'Repair queue for the mechanics', 3, '1d', 'admin, fleet', null],
                ['task', 'Nightly report of bikes left outside a station', 2, '6h', 'admin, reports', null],
                ['bug', 'Battery level stuck at 100% for the new e-bikes', 3, '5h', 'fleet, hardware', 'The new model reports in volts, not percent.'],
                ['story', 'Move bikes between stations: the van driver\'s list', 5, '2d', 'fleet', 'Tihany empties by ten in the morning; Füred fills up.'],
                ['task', 'Roles for the shop staff: owner, mechanic, driver', 2, '6h', 'admin', null],
                ['bug', 'Admin map does not show bikes with no signal', 2, '4h', 'admin, map', null],
            ]],
            'Quality' => ['Tests, crashes, and the phones the riders really have.', [
                ['task', 'End-to-end tests for renting a bike', 5, '2d', 'tests', null],
                ['task', 'Test on the ten most common phones', 3, '1d', 'tests, android, ios', 'From last season\'s analytics: six Samsungs, three iPhones and a Xiaomi.'],
                ['bug', 'Crash when the camera permission is denied', 2, '4h', 'ios', null],
                ['bug', 'Map is blank on the first start without a connection', 2, '5h', 'map', null],
                ['task', 'Accessibility pass: text sizes and contrast', 3, '1d', 'accessibility, design', null],
                ['bug', 'German texts overflow the buttons', 1, '3h', 'i18n, design', null],
                ['task', 'Performance budget for the app start', 2, '6h', 'performance', 'Under two seconds on a three-year-old Android.'],
            ]],
            'Launch' => ['The stores, the first stations, and the week it goes live.', [
                ['task', 'App Store and Play Store listings', 2, '6h', 'launch', 'Screenshots in three languages, and the privacy answers.'],
                ['task', 'Crash reporting and a dashboard', 2, '5h', 'launch, ops', null],
                ['task', 'Beta with thirty riders from the rental shop', 3, '1d', 'launch', null],
                ['story', 'A first-ride tutorial', 2, '6h', 'launch, design', null],
            ]],
        ],
        'subtasks' => [
            'Unlock a bike by scanning its QR code' => [['Camera permission and the scanner', 'bence'], ['Lock API client with retries', 'zsofia'], ['What the screen says while it waits', 'dora']],
            'Pay with a saved card' => [['Card form from the payment provider', 'zsofia'], ['Tokens and the last four digits', 'bence'], ['Receipt email', 'zsofia']],
        ],
        'releases' => [
            ['0.8 beta', 'The first rides, with thirty riders from the shop.', -70, -42, true],
            ['1.0', 'In the stores: find, unlock, ride and pay.', -41, -7, true],
            ['1.1', 'E-bikes, the day pass, and company receipts.', -6, 20, false],
            ['1.2', 'German, pausing a ride, and the tutorial.', 21, 55, false],
        ],
    ],
    'CLINIC' => [
        'name' => 'Mecsek Clinic booking',
        'about' => 'Online booking for a private clinic in Pécs: doctors\' calendars, reminders, and the front desk.',
        'client' => 'Mecsek Clinic', 'billable' => true, 'private' => false, 'sprints' => 'Clinic',
        'team' => ['gergo', 'zsofia', 'reka', 'julia', 'tamas', 'dora', 'kata'],
        'guest' => 'kata',
        'epics' => [
            'Booking' => ['A patient finding a free slot and taking it.', [
                ['story', 'Pick a doctor, then a free slot', 5, '2d', 'booking', 'The slots come from the doctor\'s working hours less what is booked, in 15-minute steps.'],
                ['story', 'Book without an account, with a confirmation email', 3, '1d', 'booking, email', null],
                ['bug', 'Two patients booked the same slot', 8, '1d', 'booking, database', "Two people pressed Book on the same slot within a second, and both got a confirmation.\n\nThe slot has to be taken inside the same transaction that checks it is free."],
                ['story', 'Cancel or move an appointment from the email', 3, '1d', 'booking, email', null],
                ['story', 'Search by complaint, not by specialty', 5, '2d', 'booking', 'Patients do not know that a sore knee is orthopaedics.'],
                ['bug', 'Slots show in UTC for a moment after the page loads', 1, '3h', 'booking', null],
                ['task', 'Accessibility review of the booking pages', 2, '6h', 'accessibility', null],
            ]],
            'Doctors\' calendars' => ['Working hours, holidays and blocked time, kept by the doctors themselves.', [
                ['story', 'Weekly working hours per doctor', 3, '1d', 'calendar', null],
                ['story', 'Block time for surgery days', 2, '6h', 'calendar', null],
                ['story', 'Sync with the doctors\' Google calendars', 8, '3d', 'calendar, integration', 'One way only: their private events block the time, and nothing of ours is written into theirs.'],
                ['bug', 'Holidays entered twice make a doctor bookable', 2, '4h', 'calendar', null],
                ['task', 'Import the current appointments from the old system', 5, '2d', 'migration', 'A CSV from the old desk software, 4,800 rows, some without a phone number.'],
            ]],
            'Reminders' => ['Emails and text messages before an appointment.', [
                ['story', 'Text message reminder the day before', 3, '1d', 'reminders, sms', null],
                ['story', 'Email reminder with what to bring', 2, '6h', 'reminders, email', null],
                ['bug', 'Reminders go out at 3 a.m.', 3, '5h', 'reminders', 'The queue ran after the nightly backup instead of at 9. Nobody should be woken by their dentist.'],
                ['task', 'Unsubscribe link in every reminder', 1, '3h', 'reminders, gdpr', null],
            ]],
            'Patients\' accounts' => ['Past appointments, documents, and the details a patient keeps up to date.', [
                ['story', 'Sign in with an email link', 3, '1d', 'accounts', 'Patients forget passwords; a link that works once is enough.'],
                ['story', 'Past and coming appointments in one list', 3, '1d', 'accounts', null],
                ['story', 'Download the doctor\'s report as a PDF', 5, '2d', 'accounts, documents', 'Only the report itself, never the internal notes.'],
                ['story', 'Family members on one account', 5, '2d', 'accounts', 'A parent booking for two children should not need three email addresses.'],
                ['task', 'Consent texts reviewed by the clinic\'s lawyer', 1, '3h', 'gdpr', null],
                ['bug', 'Date of birth accepted in the future', 1, '2h', 'accounts', null],
                ['task', 'Delete a patient account on request', 3, '1d', 'accounts, gdpr', 'Medical records stay for the legally required time; the account goes.'],
                ['bug', 'Phone numbers with +36 rejected', 1, '2h', 'accounts', null],
            ]],
            'Reports' => ['What the director asks for every month.', [
                ['story', 'Bookings per doctor per month', 3, '1d', 'reports', null],
                ['story', 'No-show rate, and who keeps missing', 3, '1d', 'reports', null],
                ['task', 'Export for the accountant', 2, '6h', 'reports, export', null],
                ['story', 'Busiest hours heat map', 3, '1d', 'reports', 'To decide whether Saturday mornings are worth opening.'],
                ['bug', 'Monthly totals double-count moved appointments', 3, '5h', 'reports', null],
                ['task', 'Dashboard on the waiting-room screen', 2, '6h', 'front-desk', null],
            ]],
            'Front desk' => ['What the receptionists see all day.', [
                ['story', 'Today\'s appointments, by doctor, on one screen', 5, '2d', 'front-desk', null],
                ['story', 'Mark a patient as arrived, or as a no-show', 2, '6h', 'front-desk', null],
                ['story', 'Book on behalf of a patient on the phone', 3, '1d', 'front-desk, booking', null],
                ['bug', 'The day view is slow with forty appointments', 3, '6h', 'front-desk, performance', 'Each row asked for its doctor separately: forty-one queries for one screen.'],
                ['task', 'Training session for the front desk', 1, '3h', 'training', null],
            ]],
        ],
        'subtasks' => [
            'Sync with the doctors\' Google calendars' => [['OAuth consent and the tokens', 'gergo'], ['Read busy times every ten minutes', 'gergo'], ['What a doctor sees when the sync fails', 'dora']],
            'Pick a doctor, then a free slot' => [['Slots from working hours less bookings', 'zsofia'], ['The slot picker on a phone', 'dora']],
        ],
        'releases' => [
            ['Phase 1', 'Booking online, and the front desk\'s day.', -63, -21, true],
            ['Phase 2', 'Reminders, and the doctors\' own calendars.', -20, 25, false],
            ['Phase 3', 'Search by complaint, and the calendar sync.', 26, 70, false],
        ],
    ],
    'WINE' => [
        'name' => 'Villány Wines webshop',
        'about' => 'A webshop for a family winery: the wines, the tastings, and shipping to half of Europe.',
        'client' => 'Villányi Borház', 'billable' => true, 'private' => false, 'sprints' => 'Wine',
        'team' => ['gergo', 'bence', 'dora', 'julia', 'tamas', 'zsofia'],
        'epics' => [
            'Catalogue' => ['The wines, with what the winemaker says about them.', [
                ['story', 'Product pages with tasting notes and food pairing', 5, '2d', 'catalogue', null],
                ['story', 'Filter by colour, grape and vintage', 3, '1d', 'catalogue', null],
                ['story', 'Awards and scores on the wines that have them', 2, '6h', 'catalogue', null],
                ['task', 'Photograph the 38 wines on a white background', 3, '1d', 'content, photos', null],
                ['bug', 'Vintage sorts as text: 2019 after 2020', 1, '2h', 'catalogue', null],
                ['story', 'Out of stock: tell me when the next vintage is in', 3, '1d', 'catalogue, email', null],
            ]],
            'Cart and checkout' => ['From a bottle in the cart to a paid order.', [
                ['story', 'Cart that keeps six-bottle boxes together', 5, '2d', 'checkout', 'Shipping is priced per box of six; the cart should nudge towards full boxes.'],
                ['story', 'Checkout with card and bank transfer', 8, '3d', 'checkout, payments', null],
                ['story', 'Age check before the first order', 2, '6h', 'checkout, legal', null],
                ['bug', 'Coupon removed when the address changes', 2, '4h', 'checkout', null],
                ['story', 'Invoices from the accounting system', 5, '2d', 'invoices, integration', null],
                ['task', 'Excise duty rules for each country', 3, '1d', 'legal, shipping', null],
            ]],
            'Shipping' => ['Couriers, rates, and the parcels that break.', [
                ['story', 'Rates from the courier for Hungary, Austria and Germany', 5, '2d', 'shipping, integration', null],
                ['story', 'Parcel tracking link in the email', 2, '6h', 'shipping, email', null],
                ['story', 'Pick up at the winery', 2, '6h', 'shipping', null],
                ['bug', 'Label printer cuts off the house number', 1, '2h', 'shipping', null],
                ['task', 'Breakage claims: a form for the customer', 3, '1d', 'shipping', null],
            ]],
            'Tastings and events' => ['Booking a place at a tasting in the cellar.', [
                ['story', 'Tasting calendar with places left', 5, '2d', 'events', null],
                ['story', 'Book and pay for a tasting', 5, '2d', 'events, payments', null],
                ['story', 'Group bookings for companies', 3, '1d', 'events', null],
                ['bug', 'Sold-out tasting still takes bookings', 3, '4h', 'events', null],
                ['task', 'Reminder email the day before', 1, '3h', 'events, email', null],
            ]],
            'Marketing' => ['Being found, and being remembered.', [
                ['story', 'Newsletter signup with a 10% first-order code', 2, '6h', 'marketing, email', null],
                ['task', 'Search engine basics: titles, descriptions, sitemap', 3, '1d', 'seo', null],
                ['story', 'Wine club: a box every quarter', 8, '3d', 'marketing, subscriptions', null],
                ['task', 'Analytics with a cookie banner that asks first', 2, '6h', 'marketing, gdpr', null],
                ['bug', 'Sitemap lists the unpublished wines', 1, '2h', 'seo', null],
            ]],
        ],
        'subtasks' => [
            'Checkout with card and bank transfer' => [['Card payment page', 'zsofia'], ['Bank transfer with the order number', 'bence'], ['Order confirmation email', 'zsofia']],
            'Wine club: a box every quarter' => [['What the member picks each quarter', 'dora'], ['Charging the card every three months', 'gergo']],
        ],
        'releases' => [
            ['Launch', 'The shop, the checkout, and shipping in Hungary.', -63, -14, true],
            ['Autumn', 'Austria and Germany, tastings, and the wine club.', -13, 30, false],
        ],
    ],
    'OPS' => [
        'name' => 'Infrastructure',
        'about' => 'The servers, the backups, and everything that should never be noticed.',
        'client' => null, 'billable' => false, 'private' => false, 'sprints' => null,
        'team' => ['gergo', 'mark', 'me', 'bence'],
        'epics' => [
            'Servers' => ['The machines, their updates, and what runs on them.', [
                ['task', 'Upgrade the web servers to the new LTS', null, '1d', 'servers', null],
                ['task', 'Move the staging sites to their own machine', null, '6h', 'servers, staging', null],
                ['bug', 'Disk filled up with old log files on web-2', null, '2h', 'servers, logs', 'Log rotation was set up for Apache but not for the application logs.'],
                ['task', 'Automatic security updates, with a weekly report', null, '5h', 'servers, security', null],
                ['task', 'Retire the old mail server', null, '1d', 'servers, email', null],
            ]],
            'Backups' => ['Every night, somewhere else, and tested.', [
                ['task', 'Nightly database dumps to a second provider', null, '6h', 'backups', null],
                ['task', 'Restore test every first Monday', null, '3h', 'backups', 'A backup nobody has restored is a hope, not a backup.'],
                ['bug', 'Backup job silently skipped the uploads folder', null, '3h', 'backups', 'A path with a space in it; the script split it in two.'],
                ['task', 'Keep 30 daily and 12 monthly copies', null, '2h', 'backups', null],
            ]],
            'Monitoring' => ['Knowing before the clients do.', [
                ['task', 'Uptime checks for every client site', null, '4h', 'monitoring', null],
                ['task', 'Alert on certificates that expire within 14 days', null, '2h', 'monitoring, security', null],
                ['bug', 'Uptime alerts sent to a mailbox nobody reads', null, '1h', 'monitoring', null],
                ['task', 'A status page for the clients', null, '6h', 'monitoring', null],
                ['task', 'Dashboards for response times', null, '5h', 'monitoring', null],
            ]],
            'Deployments' => ['Getting code onto the servers without anyone holding their breath.', [
                ['task', 'One deploy script for every client site', null, '1d', 'deploy', null],
                ['task', 'Deploys from the main branch after the tests pass', null, '6h', 'deploy, ci', null],
                ['task', 'Roll back with one command', null, '4h', 'deploy', null],
                ['bug', 'Deploy leaves the site in maintenance mode on failure', null, '2h', 'deploy', null],
                ['task', 'Staging refreshed from production every week, anonymised', null, '6h', 'staging, gdpr', null],
                ['task', 'Deploy notifications in the team chat', null, '2h', 'deploy', null],
            ]],
            'Security' => ['Passwords, keys, and who can get into what.', [
                ['task', 'Two-step sign-in for every admin panel', null, '6h', 'security', null],
                ['task', 'Rotate the deploy keys', null, '2h', 'security', null],
                ['task', 'Yearly access review: who still needs what', null, '3h', 'security', null],
                ['bug', 'Staging sites were indexed by search engines', null, '2h', 'security, staging', null],
            ]],
        ],
        'subtasks' => [
            'Upgrade the web servers to the new LTS' => [['web-1', 'gergo'], ['web-2', 'gergo'], ['Check every site after the upgrade', 'mark']],
        ],
        'releases' => [],
    ],
    'HELP' => [
        'name' => 'Support',
        'about' => 'What the clients write in about, answered and fixed.',
        'client' => null, 'billable' => true, 'private' => false, 'sprints' => null,
        'team' => ['reka', 'julia', 'tamas', 'zsofia', 'gergo'],
        'epics' => [
            'Nordic Coffee' => ['The roaster\'s website and shop.', [
                ['bug', 'Order confirmation email lands in spam', null, '3h', 'email', 'The shop sends from a domain without the right records. Needs a word with their IT person.'],
                ['task', 'Add the Christmas blend to the shop', null, '1h', 'content', null],
                ['bug', 'Discount code works twice for the same customer', null, '2h', 'shop', null],
                ['task', 'Update the opening hours for the holidays', null, '30m', 'content', null],
                ['task', 'Export last year\'s orders for the accountant', null, '1h', 'reports', null],
            ]],
            'Balaton Bikes' => ['The rental shop\'s site, and the app once it is out.', [
                ['bug', 'Rider cannot end a ride at the Tihany station', null, '2h', 'app, rental', 'The dock at Tihany reports the wrong station id. A hardware ticket with the lock supplier too.'],
                ['task', 'New station in Keszthely on the map', null, '1h', 'app, map', null],
                ['bug', 'Receipt shows the wrong VAT rate', null, '2h', 'app, payments', null],
                ['task', 'Refund a rider charged for a stolen bike', null, '30m', 'payments', null],
                ['task', 'Summer price list', null, '1h', 'payments', null],
                ['bug', 'App asks for location permission twice', null, '2h', 'app, android', null],
            ]],
            'Mecsek Clinic' => ['The clinic\'s old site until the booking goes live, and after.', [
                ['task', 'New doctor on the "Our team" page', null, '1h', 'content', null],
                ['bug', 'Contact form messages not arriving', null, '2h', 'email', null],
                ['task', 'Add the new opening hours', null, '30m', 'content', null],
                ['bug', 'A patient booked a doctor who has left', null, '1h', 'booking', null],
                ['task', 'Monthly booking statistics for the director', null, '2h', 'reports', null],
            ]],
            'Villány Wines' => ['The winery\'s shop, since it went live.', [
                ['bug', 'Shipping cost missing for Austria', null, '1h', 'shop', null],
                ['task', 'New vintage on the product pages', null, '2h', 'content', null],
                ['bug', 'Age check asked on every page', null, '2h', 'shop', null],
                ['task', 'Gift cards for Christmas', null, '3h', 'shop', null],
                ['task', 'Change the bank details on the invoices', null, '30m', 'invoices', null],
                ['bug', 'Newsletter signup gives an error for gmail addresses', null, '1h', 'email', null],
            ]],
            'Everybody else' => ['Smaller clients, and the questions that do not fit anywhere.', [
                ['task', 'Renew the domain for the dentist in Szeged', null, '30m', 'domains', null],
                ['bug', 'The law firm\'s site is slow in the mornings', null, '3h', 'performance', null],
                ['task', 'Mailbox for a new colleague at the bakery', null, '30m', 'email', null],
                ['task', 'Quote for a small shop for the florist', null, '2h', 'sales', null],
            ]],
        ],
        'subtasks' => [],
        'releases' => [],
    ],
];

$made2 = [];          // every ticket this file made: id => [project code, title, type, assignee]
$finishedOn = [];     // ticket id => the day it was finished
$createdOn = [];      // ticket id => the day it came in, where that is known
$sprintDates = [];    // sprint id => its first day
$projectOf = [];      // code => project id
$teamOf = [];         // code => list of handles who work on it (not guests)
$openTickets = [];    // code => ids that are not finished
$window = [];         // ticket id => [first day worked on, last day worked on]
$said2 = [];

/** @var array<string, list<string>> $remarks */
$remarks = [
    'bug' => ['Reproduced it on staging.', 'Found it: {cause}.', 'Fixed, and a test that fails without the fix.', 'Can we check this on the client\'s phone too?', 'Deployed; the client confirmed it is gone.'],
    'story' => ['First version on staging — have a look.', 'Design is in the ticket now.', 'Split the edge cases into a subtask.', 'The client asked for one change: {change}.', 'Demoed it on Friday; they liked it.'],
    'task' => ['Done on staging, production tonight.', 'Needs a window with the client; Thursday evening?', 'Checklist is in the description.', 'Took longer than planned: {cause}.', 'All good now.'],
];
$causes = ['a timezone off by one hour', 'a cache nobody knew about', 'the old API still answering on the side', 'a missing index', 'a setting only production had', 'an update of the phone\'s operating system'];
$changes = ['a bigger button on the phone', 'the price in bold', 'the German texts checked by a native speaker', 'the date written out in full', 'a softer colour for the warning'];

foreach ($catalogue as $code => $project) {
    $id = $projects->create($code, $project['name'], $project['about']);
    $projectOf[$code] = $id;
    $projects->setBilling($id, $project['client'] === null ? null : $clients->findOrCreate($project['client']), $project['billable']);

    if ($project['private']) {
        $projects->setVisibility($id, 'private');
    }

    foreach ($project['team'] as $handle) {
        $projects->addMember($id, $who[$handle]);
    }

    $teamOf[$code] = array_values(array_filter($project['team'], static fn(string $h): bool => !in_array($h, ['peter', 'kata'], true)));
}

// ---------------------------------------------------------------------------
// The tickets, from the first sprint nine weeks ago to now
// ---------------------------------------------------------------------------
$sprintLength = 14;
$start = $lastMonday->modify('-' . (4 * $sprintLength) . ' days');
$span = (int) $start->diff($today)->days;

foreach ($catalogue as $code => $project) {
    $projectId = $projectOf[$code];
    $team = $teamOf[$code];
    $order = [];

    foreach ($project['epics'] as $epicTitle => [$epicAbout, $rows]) {
        $epicId = $epics->create($projectId, $epicTitle, $epicAbout);

        foreach ($rows as [$type, $title, $points, $estimate, $labels, $description]) {
            $assignee = $chance(88) ? $who[$pick($team)] : null;
            $reporter = $who[$pick(array_merge($team, isset($project['guest']) ? [$project['guest'], $project['guest']] : []))];

            $ticketId = $ticketService->create([
                'project_id' => $projectId, 'epic_id' => $epicId, 'type' => $type, 'title' => $title,
                'description' => $description ?? '', 'priority' => $type === 'bug' ? $pick(['high', 'normal', 'urgent', 'high']) : $pick(['normal', 'normal', 'high', 'low']),
                'assignee_id' => $assignee, 'estimate' => $estimate, 'story_points' => $points === null ? '' : (string) $points,
                'labels' => $labels, 'status' => 'backlog',
            ], $reporter);

            $made2[$ticketId] = [$code, $title, $type, $assignee];
            $order[] = $ticketId;

            foreach ($project['subtasks'][$title] ?? [] as [$subTitle, $subWho]) {
                $sub = $ticketService->addSubtask((array) $ticketRow($ticketId), $subTitle, $who[$subWho], $reporter);
                $ticketService->update($sub, ['estimate' => $pick(['3h', '4h', '6h', '1d'])], $reporter);
                $made2[$sub] = [$code, $subTitle, 'task', $who[$subWho]];
            }
        }
    }

    // Kept for the week's meetings and the small things around the work.
    $meetings = $ticketService->create([
        'project_id' => $projectId, 'type' => 'task', 'title' => $project['sprints'] !== null ? 'Planning, stand-ups and reviews' : 'Weekly check-in, and the odds and ends',
        'description' => 'Hours that belong to the project but not to one ticket.', 'status' => 'in_progress',
    ], $who[$team[0]]);
    $made2[$meetings] = [$code, 'meetings', 'task', null];
    $window[$meetings] = [$ymd($start), $todayYmd];

    shuffle($order);
    $count = count($order);

    if ($project['sprints'] !== null) {
        // Sprints of two weeks: four finished, one running since last Monday,
        // and the next one planned.
        $sprintIds = [];
        for ($k = 0; $k < 6; $k++) {
            $from = $lastMonday->modify(sprintf('%+d days', ($k - 4) * $sprintLength));
            $sprintIds[$k] = [$sprintService->create($projectId, $project['sprints'] . ' ' . ($k + 1), '', $ymd($from), $ymd($from->modify('+11 days'))), $from];
        }

        // About two thirds go through the sprints; the rest waits in the backlog.
        $planned = array_slice($order, 0, (int) round($count * 0.72));
        $perSprint = (int) ceil(count($planned) / 5);
        $carried = [];

        for ($k = 0; $k < 5; $k++) {
            [$sprintId, $from] = $sprintIds[$k];
            $these = array_merge($carried, array_slice($planned, $k * $perSprint, $perSprint));
            $top = array_values(array_filter($these, static fn(int $t): bool => $ticketRow($t)['parent_id'] === null));
            $sprintService->assign($top, $sprintId, $me);
            $sprintService->start((array) $sprints->find($sprintId));

            foreach ($top as $t) {
                $ticketService->changeStatus($t, 'todo', $me);
            }

            if ($k === 4) {
                // The running sprint: some finished already, the rest on the way.
                foreach ($top as $i => $t) {
                    $state = ['done', 'done', 'review', 'in_progress', 'in_progress', 'todo'][$i % 6];
                    $ticketService->changeStatus($t, $state, $me);
                    $firstDay = $ymd($from->modify('+' . mt_rand(0, 2) . ' days'));
                    $window[$t] = [$firstDay, $state === 'done' ? $ymd($from->modify('+' . mt_rand(2, 4) . ' days')) : $todayYmd];
                    if ($state === 'todo') {
                        unset($window[$t]);
                    }
                    if ($state === 'done') {
                        $finishedOn[$t] = $window[$t][1];
                    }
                }
                break;
            }

            // A finished sprint: nearly everything done within it, one or two
            // things carried on to the next.
            $carried = [];
            foreach ($top as $i => $t) {
                $firstDay = $from->modify('+' . mt_rand(0, 4) . ' days');
                if ($i > 0 && $chance(14)) {
                    $carried[] = $t;
                    $ticketService->changeStatus($t, 'in_progress', $me);
                    $window[$t] = [$ymd($firstDay), $ymd($from->modify('+11 days'))];
                    continue;
                }
                $doneOn = $from->modify('+' . mt_rand(3, 11) . ' days');
                $ticketService->changeStatus($t, 'done', $me);
                $window[$t] = [$ymd($firstDay), $ymd(max($doneOn, $firstDay))];
                $finishedOn[$t] = $window[$t][1];
            }

            $sprintService->close((array) $sprints->find($sprintId), $sprintIds[$k + 1][0], $me);
            $sprintDates[$sprintId] = $from;
        }
        $sprintDates[$sprintIds[4][0]] = $sprintIds[4][1];
        $sprintDates[$sprintIds[5][0]] = $sprintIds[5][1];

        foreach (array_slice($order, count($planned)) as $t) {
            if ($ticketRow($t)['parent_id'] === null && $chance(40)) {
                $ticketService->changeStatus($t, 'todo', $me);
            }
        }
    } else {
        // A flow of work, without sprints: what came in, week by week, and
        // most of it finished within days.
        foreach ($order as $i => $t) {
            if ($ticketRow($t)['parent_id'] !== null) {
                continue;
            }
            $cameIn = $start->modify('+' . (int) floor($i / $count * $span) . ' days');
            $age = (int) $today->diff($cameIn)->days;
            $state = $age > 10 ? ($chance(88) ? 'done' : 'in_progress') : $pick(['done', 'in_progress', 'review', 'todo', 'backlog']);
            $ticketService->changeStatus($t, $state, $me);
            $createdOn[$t] = $cameIn;

            if (in_array($state, ['done', 'in_progress', 'review'], true)) {
                $lastDay = $state === 'done' ? $cameIn->modify('+' . mt_rand(0, 6) . ' days') : $today;
                $lastDay = min($lastDay, $today);
                $window[$t] = [$ymd($cameIn), $ymd($lastDay)];
                if ($state === 'done') {
                    $finishedOn[$t] = $ymd($lastDay);
                }
            }
        }
    }

    // Subtasks: worked on with their parent, finished with it or just before.
    foreach ($order as $t) {
        $row = $ticketRow($t);
        if ($row['parent_id'] === null) {
            continue;
        }
        $parent = (int) $row['parent_id'];
        $parentState = $ticketRow($parent)['status_category'];
        if (isset($window[$parent])) {
            $window[$t] = $window[$parent];
        }
        $ticketService->changeStatus($t, $parentState === 'done' ? 'done' : $pick(['done', 'in_progress', 'todo']), $me);
        if ($parentState === 'done' && isset($finishedOn[$parent])) {
            $finishedOn[$t] = $finishedOn[$parent];
        }
    }

    // Releases, with what went out in each.
    $releaseService = new ReleaseService();
    $releaseRepository = new ReleaseRepository();
    foreach ($project['releases'] as [$name, $about, $fromOffset, $toOffset, $out]) {
        $releaseId = $releaseService->create($projectId, $name, $about, $ymd($thisMonday->modify($fromOffset . ' days')), $ymd($thisMonday->modify($toOffset . ' days')));
        $releaseDay = $ymd($thisMonday->modify($toOffset . ' days'));
        $releaseStart = $ymd($thisMonday->modify($fromOffset . ' days'));
        $in = [];
        foreach ($order as $t) {
            $row = $ticketRow($t);
            if ($row['parent_id'] !== null || $row['release_id'] !== null) {
                continue;
            }
            $done = $finishedOn[$t] ?? null;
            if ($out ? ($done !== null && $done <= $releaseDay && $done >= $releaseStart) : ($done === null ? $chance(55) : $done >= $releaseStart)) {
                $in[] = $t;
            }
        }
        $releaseRepository->assign($in, $releaseId);
        if ($out) {
            $releaseService->release((array) $releaseRepository->find($releaseId), null, $me);
            $database->prepare('UPDATE releases SET released_at = :at WHERE id = :id')->execute(['at' => $releaseDay . ' 16:00:00', 'id' => $releaseId]);
        }
    }

    $openTickets[$code] = array_values(array_filter($order, static fn(int $t): bool => $ticketRow($t)['status_category'] !== 'done'));

    // A few links between the project's tickets.
    for ($n = 0; $n < 4; $n++) {
        $a = $pick($order);
        $b = $pick($order);
        if ($a !== $b) {
            $bRow = $ticketRow($b);
            try {
                $links->link($a, $pick(['blocks', 'relates', 'relates']), $bRow['project_code'] . '-' . $bRow['number'], $me);
            } catch (Throwable) {
                // A pair that is linked already, or would loop: another time.
            }
        }
    }

    // And what people said about them.
    foreach ($order as $t) {
        if (!isset($window[$t]) || !$chance(45)) {
            continue;
        }
        $type = $made2[$t][2];
        $lines = $remarks[$type] ?? $remarks['task'];
        $times = mt_rand(1, 3);
        for ($n = 0; $n < $times; $n++) {
            $text = strtr($pick($lines), ['{cause}' => $pick($causes), '{change}' => $pick($changes)]);
            $author = $who[$pick(array_merge($team, isset($project['guest']) && $n > 0 ? [$project['guest']] : []))];
            $when = (new DateTimeImmutable($window[$t][0]))->modify('+' . mt_rand(0, max(0, (int) (new DateTimeImmutable($window[$t][0]))->diff(new DateTimeImmutable($window[$t][1]))->days)) . ' days');
            $said2[] = [$comments->add($t, $author, $text), $ymd(min($when, $today)) . sprintf(' %02d:%02d:00', mt_rand(9, 17), mt_rand(0, 59))];
        }
    }
}

// ---------------------------------------------------------------------------
// The projects' own fields, and what the tickets say in them
// ---------------------------------------------------------------------------
$customFields = new CantoTrack\Service\CustomFields();
$fieldValues = new CantoTrack\Model\CustomFieldRepository();
$ownFields = [
    'BIKE' => [['Platform', 'select', "iOS\nAndroid\nBoth\nBackend"], ['Customer impact', 'select', "Low\nMedium\nHigh"]],
    'CLINIC' => [['Site', 'select', "Pécs\nKaposvár\nBoth"], ['Needs legal review', 'checkbox', '']],
    'WINE' => [['Country', 'select', "Hungary\nAustria\nGermany\nAll"]],
    'OPS' => [['Server', 'select', "web-1\nweb-2\ndb-1\nbackup"]],
    'HELP' => [['Client contact', 'text', ''], ['Reported on', 'date', ''], ['Hours approved by the client', 'checkbox', '']],
];
$contacts = ['Kovács Ildikó', 'Szendrei Gábor', 'Nagy Péter', 'Tóth Andrea', 'Fekete Zoltán'];

foreach ($ownFields as $code => $defined) {
    foreach ($defined as [$name, $kind, $options]) {
        $fieldId = $customFields->define($projectOf[$code], $name, $kind, $options, false);
        $choices = $options === '' ? [] : explode("\n", $options);

        foreach ($made2 as $t => [$ticketCode, $title]) {
            if ($ticketCode !== $code || $title === 'meetings' || !$chance(80)) {
                continue;
            }
            $value = match ($kind) {
                'select' => $pick($choices),
                'checkbox' => $chance(35) ? '1' : null,
                'date' => $ymd(isset($window[$t]) ? new DateTimeImmutable($window[$t][0]) : $today->modify('-' . mt_rand(1, 60) . ' days')),
                default => $pick($contacts),
            };
            $fieldValues->setValue($t, $fieldId, $value);
        }
    }
}

// ---------------------------------------------------------------------------
// Days away, before the hours
// ---------------------------------------------------------------------------
$away = [
    ['bence', -35, -31, 'vacation', 'Croatia'],
    ['reka', -20, -19, 'sick', ''],
    ['zsofia', 7, 11, 'vacation', 'Autumn break'],
    ['tamas', -49, -49, 'other', 'Conference in Vienna'],
    ['gergo', -63, -59, 'vacation', ''],
    ['dora', -12, -12, 'sick', ''],
    ['anna', -45, -42, 'vacation', ''],
    ['mark', -28, -28, 'other', 'Moving house'],
];
$awayOn = [];
foreach ($away as [$handle, $fromOffset, $toOffset, $kind, $note]) {
    $calendar->addAbsence($who[$handle], $ymd($thisMonday->modify($fromOffset . ' days')), $ymd($thisMonday->modify($toOffset . ' days')), $kind, $note);
    for ($d = $fromOffset; $d <= $toOffset; $d++) {
        $awayOn[$who[$handle] . '|' . $ymd($thisMonday->modify($d . ' days'))] = true;
    }
}

$holidays = [];
foreach ([(int) $start->format('Y'), (int) $today->format('Y')] as $year) {
    $holidays += CantoTrack\Service\Calendar::hungarianHolidays($year);
}

// ---------------------------------------------------------------------------
// The hours: every working day of the twelve weeks
// ---------------------------------------------------------------------------
/** @var array<string, list<?string>> $phrases */
$phrases = [
    'bug' => ['Reproduced it', 'Found the cause', 'The fix, and a test for it', 'Checked the fix on staging', 'Went through the logs'],
    'story' => ['First version', 'The screens on a phone', 'Edge cases', 'Review comments', 'Wired it to the API', 'Tests', null],
    'task' => ['Worked through the checklist', 'Set it up on staging', 'Done on production', 'Notes for the client', null],
    'meetings' => ['Meeting: stand-up', 'Meeting: sprint planning', 'Meeting: review with the client', 'Meeting: weekly check-in', 'Answering the client'],
];

$loggedMore = 0;
$ticketMinutes = [];
$estimateOf = [];
$billableOf = [];
foreach (array_keys($made2) as $t) {
    $row = $ticketRow($t);
    $estimateOf[$t] = (int) ($row['estimate_minutes'] ?? 0);
    $billableOf[$t] = (int) ($row['project_billable'] ?? 1) === 1;
}

$peopleOnProjects = [];
foreach ($teamOf as $code => $team) {
    foreach ($team as $handle) {
        $peopleOnProjects[$handle][] = $code;
    }
}

// The four from the tracker's own projects have their last two weeks written
// out by hand already; their hours here stop before that.
$handWritten = ['me', 'anna', 'mark', 'julia'];
$byPersonDay = [];

for ($d = $start; $d <= $today; $d = $d->modify('+1 day')) {
    $date = $ymd($d);
    $weekday = (int) $d->format('N');

    if ($weekday > 5 || isset($holidays[$date])) {
        continue;
    }

    foreach ($peopleOnProjects as $handle => $codes) {
        $userId = $who[$handle];

        if (isset($awayOn[$userId . '|' . $date]) || ($handle === 'anna' && $weekday === 5)) {
            continue;
        }

        if (in_array($handle, $handWritten, true) && $date >= $ymd($lastMonday)) {
            continue;
        }

        if ($date === $todayYmd && (int) date('G') < 17 && $chance(50)) {
            continue;
        }

        // What they could be working on that day: their own tickets first,
        // then anything open in their projects.
        $candidates = [];
        $overrun = [];
        foreach ($window as $t => [$first, $last]) {
            [$code] = $made2[$t];
            if ($first <= $date && $date <= $last && in_array($code, $codes, true) && $made2[$t][1] !== 'meetings') {
                $weight = $made2[$t][3] === $userId ? 6 : 1;
                $spent = $ticketMinutes[$t] ?? 0;
                if ($spent < max(300, (int) ($estimateOf[$t] * 2))) {
                    $candidates[$t] = $weight;
                } elseif ($estimateOf[$t] >= 240 && $spent < $estimateOf[$t] * 3.5) {
                    // Only the bigger pieces run long; a quick fix stays quick.
                    $overrun[$t] = $weight;
                }
            }
        }
        // Nothing within its estimate left: then whatever is on the way, as
        // the tickets that take longer than planned do.
        if ($candidates === []) {
            $candidates = $overrun;
        }

        $target = ($handle === 'dora' ? 360 : 480) - $pick([0, 0, 0, 15, 30, 60, -15, -30]);
        /** @var list<array{0: int, 1: int, 2: ?string}> $entries */
        $entries = [];

        // Monday's meeting in one of their projects.
        if ($weekday === 1 || $chance(15)) {
            $code = $pick($codes);
            $meetingTicket = array_search([$code, 'meetings', 'task', null], $made2, true);
            if ($meetingTicket !== false) {
                $entries[] = [(int) $meetingTicket, $pick([30, 30, 45, 60]), $pick($phrases['meetings'])];
            }
        }

        $left = $target - array_sum(array_column($entries, 1));
        while ($left >= 30 && $candidates !== []) {
            $bag = [];
            foreach ($candidates as $t => $weight) {
                $bag = array_merge($bag, array_fill(0, $weight, $t));
            }
            $t = $pick($bag);
            unset($candidates[$t]);
            $minutes = min($left, $pick([60, 90, 120, 150, 180, 210, 240, 300]));
            $entries[] = [$t, $minutes, $pick($phrases[$made2[$t][2]] ?? $phrases['task'])];
            $left -= $minutes;
        }

        // What is left of the day: an hour or so of the project's odds and
        // ends, and the rest to what they spent the day on — the ticket took
        // longer than planned, as tickets do.
        if ($left >= 45) {
            $code = $pick($codes);
            $oddsAndEnds = array_search([$code, 'meetings', 'task', null], $made2, true);
            $odd = min($left, $pick([30, 45, 60, 90]));
            if ($oddsAndEnds !== false) {
                $entries[] = [(int) $oddsAndEnds, $odd, $pick(['Answering the client', 'Code review', 'Helping a colleague', 'Email', null])];
                $left -= $odd;
            }
            $work = array_keys(array_filter($entries, static fn(array $e): bool => !str_starts_with((string) $e[2], 'Meeting') && $made2[$e[0]][1] !== 'meetings'));
            $lastEntry = array_key_last($entries);
            if ($left >= 15 && $lastEntry !== null) {
                $longest = $work[0] ?? $lastEntry;
                $entries[$longest] = [$entries[$longest][0], $entries[$longest][1] + (int) (floor($left / 15) * 15), $entries[$longest][2]];
            }
        }

        $clockAt = $pick([8 * 60 + 30, 9 * 60, 9 * 60, 9 * 60 + 15]);
        foreach ($entries as $n => [$t, $minutes, $note]) {
            if ($clockAt < 13 * 60 && $clockAt + $minutes > 12 * 60 + 30) {
                $clockAt = max($clockAt, 13 * 60);
            }
            $typeName = str_starts_with((string) $note, 'Meeting') ? 'Meeting'
                : ($handle === 'dora' ? 'Design' : (str_contains((string) $note, 'Review') || $handle === 'reka' ? 'Review' : 'Development'));
            $startAt = $n % 4 === 3 ? null : sprintf('%02d:%02d:00', intdiv($clockAt, 60), $clockAt % 60);
            $worklogs->create($t, $userId, $date, $minutes, $note, $billableOf[$t], $startAt, $typeIds[$typeName]);
            $clockAt += $minutes + 15;
            $ticketMinutes[$t] = ($ticketMinutes[$t] ?? 0) + $minutes;
            $loggedMore += $minutes;
        }

        $byPersonDay[$userId][$date] = true;
    }
}

// ---------------------------------------------------------------------------
// The weeks handed in: all approved, apart from last week's last few
// ---------------------------------------------------------------------------
$review = new WeekReview();
$reviewer = $who['tamas'];
$weekStates = [];
foreach ($peopleOnProjects as $handle => $codes) {
    if (in_array($handle, $handWritten, true)) {
        continue;
    }
    $userId = $who[$handle];
    for ($w = $start; $w < $thisMonday; $w = $w->modify('+7 days')) {
        $monday = $ymd($w);
        $review->submit($userId, $monday);
        if ($w < $lastMonday || $handle !== 'zsofia') {
            $review->review($userId, $monday, $handle === 'tamas' ? $julia : $reviewer, true, '');
        }
        $weekStates[] = [$userId, $monday];
    }
}

// The three from the hand-written weeks hand in their older weeks too.
foreach (['anna', 'mark', 'julia'] as $handle) {
    for ($w = $start; $w < $lastMonday; $w = $w->modify('+7 days')) {
        $review->submit($who[$handle], $ymd($w));
        $review->review($who[$handle], $ymd($w), $handle === 'julia' ? $reviewer : $julia, true, '');
        $weekStates[] = [$who[$handle], $ymd($w)];
    }
}

// ---------------------------------------------------------------------------
// What is planned from here
// ---------------------------------------------------------------------------
$planningMore = new Planning();
foreach ($openTickets as $code => $open) {
    $open = array_values(array_filter($open, static fn(int $t): bool => $ticketRow($t)['parent_id'] === null && $made2[$t][3] !== null && $made2[$t][1] !== 'meetings'));
    foreach (array_slice($open, 0, 5) as $n => $t) {
        $row = $ticketRow($t);
        $fromOffset = $n < 3 ? 0 : 7;
        try {
            $planningMore->add((int) $row['assignee_id'], $row['project_code'] . '-' . $row['number'], null, $ymd($thisMonday->modify('+' . $fromOffset . ' days')), $ymd($thisMonday->modify('+' . ($fromOffset + 4) . ' days')), $pick(['2h', '3h', '4h']), '', $me);
        } catch (Throwable) {
            // Somebody who cannot be planned (a guest): skip them.
        }
    }
}

// ---------------------------------------------------------------------------
// And the dates, moved back to when it all happened
// ---------------------------------------------------------------------------
$set = static function (string $sql, array $params) use ($database): void {
    $database->prepare($sql)->execute($params);
};

foreach (array_keys($made2) as $t) {
    $first = $window[$t][0] ?? null;
    $created = $createdOn[$t] ?? null;
    $created ??= $first !== null
        ? (new DateTimeImmutable($first))->modify('-' . mt_rand(2, 12) . ' days')
        : $start->modify('+' . mt_rand(0, $span) . ' days');
    $created = min($created, $today);
    $createdAt = $ymd($created) . sprintf(' %02d:%02d:00', mt_rand(8, 17), mt_rand(0, 59));

    $set('UPDATE tickets SET created_at = :at, updated_at = GREATEST(:at2, COALESCE(:done, :at3)) WHERE id = :id', [
        'at' => $createdAt, 'at2' => $createdAt, 'at3' => $createdAt, 'done' => isset($finishedOn[$t]) ? $finishedOn[$t] . ' 16:30:00' : null, 'id' => $t,
    ]);
    $set("UPDATE ticket_events SET created_at = :at WHERE ticket_id = :id AND kind IN ('created', 'changed', 'linked')", ['at' => $createdAt, 'id' => $t]);

    if (isset($finishedOn[$t])) {
        $doneAt = $finishedOn[$t] . sprintf(' %02d:%02d:00', mt_rand(14, 18), mt_rand(0, 59));
        $set('UPDATE tickets SET closed_at = :at WHERE id = :id', ['at' => $doneAt, 'id' => $t]);
        $set("UPDATE ticket_events SET created_at = :at WHERE ticket_id = :id AND kind = 'status'", ['at' => $doneAt, 'id' => $t]);
    } else {
        $moved = $window[$t][0] ?? $ymd($created);
        $set("UPDATE ticket_events SET created_at = :at WHERE ticket_id = :id AND kind = 'status'", ['at' => $moved . ' 09:30:00', 'id' => $t]);
    }
}

foreach ($sprintDates as $sprintId => $from) {
    $sprint = (array) $sprints->find($sprintId);
    $closed = $sprint['state'] === 'closed' ? $ymd($from->modify('+11 days')) . ' 17:00:00' : null;
    $set('UPDATE sprints SET started_at = CASE WHEN started_at IS NULL THEN NULL ELSE :started END, closed_at = :closed WHERE id = :id', [
        'started' => $ymd($from) . ' 09:00:00', 'closed' => $closed, 'id' => $sprintId,
    ]);
    $set("UPDATE ticket_events e JOIN tickets t ON t.id = e.ticket_id SET e.created_at = :at WHERE t.project_id = :project AND e.kind = 'sprint' AND e.old_value IS NULL AND e.new_value = :name", [
        'at' => $ymd($from) . ' 08:45:00', 'project' => $sprint['project_id'], 'name' => $sprint['name'],
    ]);
    if ($closed !== null) {
        $set("UPDATE ticket_events e JOIN tickets t ON t.id = e.ticket_id SET e.created_at = :at WHERE t.project_id = :project AND e.kind = 'sprint' AND e.old_value = :name", [
            'at' => $closed, 'project' => $sprint['project_id'], 'name' => $sprint['name'],
        ]);
    }
}

foreach ($said2 as [$commentId, $when]) {
    $set('UPDATE comments SET created_at = :at WHERE id = :id', ['at' => $when, 'id' => $commentId]);
}

foreach ($weekStates as [$userId, $monday]) {
    $friday = (new DateTimeImmutable($monday))->modify('+4 days');
    $set('UPDATE timesheet_weeks SET submitted_at = :at, reviewed_at = CASE WHEN reviewed_at IS NULL THEN NULL ELSE :at2 END WHERE user_id = :user AND week_start = :week', [
        'at' => $ymd($friday) . ' 16:45:00', 'at2' => $ymd($friday->modify('+3 days')) . ' 10:00:00', 'user' => $userId, 'week' => $monday,
    ]);
}

// The epics' days, as a team would have set them: from the first hour
// worked on them to the last ticket finished — or, while any is open, a few weeks on.
// One epic in every project is left without, and drawn from its tickets.
$database->exec(
    "UPDATE epics e
     JOIN (SELECT t.epic_id,
                  COALESCE(MIN((SELECT MIN(w.work_date) FROM worklogs w WHERE w.ticket_id = t.id)), MIN(DATE(t.created_at))) AS first_day,
                  MAX(DATE(t.closed_at)) AS last_done, SUM(t.closed_at IS NULL) AS open_count
           FROM tickets t WHERE t.epic_id IS NOT NULL AND t.parent_id IS NULL GROUP BY t.epic_id) x ON x.epic_id = e.id
     JOIN projects p ON p.id = e.project_id
     SET e.starts_on = x.first_day,
         e.ends_on = CASE WHEN x.open_count > 0 THEN GREATEST(CURDATE(), COALESCE(x.last_done, CURDATE())) + INTERVAL (14 + (e.id % 4) * 10) DAY ELSE x.last_done END,
         e.is_done = x.open_count = 0
     WHERE p.code IN ('CT', 'WEB', 'BIKE', 'CLINIC', 'WINE', 'OPS', 'HELP')
       AND e.id <> (SELECT MAX(e2.id) FROM epics e2 WHERE e2.project_id = e.project_id)"
);

$moreSummary = [
    'projects' => count($catalogue),
    'tickets' => count($made2),
    'comments' => count($said2),
    'hours' => intdiv($loggedMore, 60),
];
