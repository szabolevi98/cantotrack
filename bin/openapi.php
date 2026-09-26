<?php

/**
 * Builds docs/openapi.json: the API described for programs — Postman,
 * Insomnia, a client generator — the way docs/API.md describes it for
 * people. OpenAPI 3.1.
 *
 * Written here as PHP rather than as the JSON itself, so that the shapes a
 * dozen endpoints share (a ticket, a worklog, an error) are said once. The
 * output is committed, like the stylesheet, and served at
 * /api/v1/openapi.json with the installation's own address in it.
 * tests/Unit/OpenApiTest.php fails when a route under /api/v1 is missing
 * from it, or it names one that is not there.
 *
 *   php bin/openapi.php
 */

$root = dirname(__DIR__);

// ---------------------------------------------------------------------------
// Pieces
// ---------------------------------------------------------------------------

/** A reference to a schema. */
function ref(string $name): array
{
    return ['$ref' => '#/components/schemas/' . $name];
}

/** @param array<string, array> $properties */
function obj(array $properties, array $required = [], ?string $description = null): array
{
    // No properties at all is an empty object in JSON, not an empty list.
    $out = ['type' => 'object', 'properties' => $properties === [] ? new stdClass() : $properties];

    if ($required !== []) {
        $out['required'] = $required;
    }

    if ($description !== null) {
        $out = ['description' => $description] + $out;
    }

    return $out;
}

function str(?string $description = null, array $more = []): array
{
    return ['type' => 'string'] + ($description === null ? [] : ['description' => $description]) + $more;
}

function int(?string $description = null): array
{
    return ['type' => 'integer'] + ($description === null ? [] : ['description' => $description]);
}

function boolean(?string $description = null): array
{
    return ['type' => 'boolean'] + ($description === null ? [] : ['description' => $description]);
}

/** @param array $schema */
function nullable(array $schema): array
{
    if (isset($schema['$ref'])) {
        return ['oneOf' => [$schema, ['type' => 'null']]];
    }

    // A value from a list, or none: null has to be in the list too.
    if (isset($schema['enum'])) {
        $schema['enum'][] = null;
    }

    return array_merge($schema, ['type' => [$schema['type'], 'null']]);
}

function listOf(array $items): array
{
    return ['type' => 'array', 'items' => $items];
}

function date_(?string $description = null): array
{
    return str($description, ['format' => 'date', 'examples' => ['2026-09-22']]);
}

/** {"data": …}, optionally with "meta". */
function envelope(array $data, ?array $meta = null): array
{
    return obj(['data' => $data] + ($meta === null ? [] : ['meta' => $meta]), $meta === null ? ['data'] : ['data', 'meta']);
}

function json(array $schema, string $description): array
{
    return ['description' => $description, 'content' => ['application/json' => ['schema' => $schema]]];
}

function body(array $schema, bool $required = true): array
{
    return ['required' => $required, 'content' => ['application/json' => ['schema' => $schema]]];
}

/** The refusals an operation can meet, by status. */
function errors(int ...$statuses): array
{
    $names = [400 => 'BadRequest', 401 => 'Unauthorized', 403 => 'Forbidden', 404 => 'NotFound', 409 => 'Conflict', 413 => 'TooLarge', 422 => 'Unprocessable', 429 => 'TooManyRequests'];
    $out = [];

    foreach ($statuses as $status) {
        $out[(string) $status] = ['$ref' => '#/components/responses/' . $names[$status]];
    }

    return $out;
}

/**
 * One operation. A change (anything but a GET) takes an Idempotency-Key, and
 * every one but signing in needs a token.
 *
 * @param array<int|string, array> $responses
 */
function op(string $method, string $tag, string $id, string $summary, string $description, array $responses, array $parameters = [], ?array $requestBody = null, bool $public = false): array
{
    $out = ['tags' => [$tag], 'operationId' => $id, 'summary' => $summary];

    if ($description !== '') {
        $out['description'] = $description;
    }

    if ($method !== 'get' && !$public) {
        $parameters[] = ['$ref' => '#/components/parameters/IdempotencyKey'];
    }

    if ($parameters !== []) {
        $out['parameters'] = $parameters;
    }

    if ($requestBody !== null) {
        $out['requestBody'] = $requestBody;
    }

    $out['responses'] = $responses + ($public ? [] : errors(401));
    ksort($out['responses'], SORT_STRING);

    if ($public) {
        $out['security'] = [];
    }

    return $out;
}

function query(string $name, array $schema, string $description): array
{
    return ['name' => $name, 'in' => 'query', 'required' => false, 'schema' => $schema, 'description' => $description];
}

function p(string $name): array
{
    return ['$ref' => '#/components/parameters/' . $name];
}

$noContent = ['204' => ['description' => 'Done; nothing to show for it.']];

// ---------------------------------------------------------------------------
// Shapes
// ---------------------------------------------------------------------------

$category = str('What the column is: open work not started, work under way, or finished.', ['enum' => ['todo', 'in_progress', 'done']]);

$schemas = [
    'Error' => obj([
        'error' => obj([
            'status' => int('The HTTP status again.'),
            'message' => str('A sentence for a person, in the language of the account.'),
            'details' => obj([], [], 'For a client to act on — two_factor_required, say.'),
        ], ['status', 'message']),
    ], ['error']),
    'PageMeta' => obj(['page' => int(), 'per_page' => int(), 'total' => int(), 'pages' => int()], ['page', 'per_page', 'total', 'pages']),
    'PersonRef' => obj(['id' => int(), 'name' => str()], ['id', 'name']),
    'Person' => obj(['id' => int(), 'name' => str(), 'handle' => nullable(str('What an @mention says.'))], ['id', 'name']),
    'Me' => ['allOf' => [ref('Person'), obj([
        'email' => str(null, ['format' => 'email']),
        'role' => str(null, ['enum' => ['admin', 'member', 'guest']]),
        'service' => boolean('A service account: a program\'s, not a person\'s.'),
    ], ['email', 'role'])]],
    'Status' => obj(['id' => int(), 'name' => str(), 'category' => $category], ['name', 'category']),
    'Project' => obj([
        'code' => str(null, ['examples' => ['CT']]),
        'name' => str(),
        'description' => nullable(str()),
        'archived' => boolean(),
        'tickets' => nullable(int('How many tickets it has, in the list.')),
        'url' => str(null, ['format' => 'uri']),
    ], ['code', 'name', 'archived', 'url']),
    'ProjectDetail' => ['allOf' => [ref('Project'), obj(['statuses' => listOf(ref('Status'))], ['statuses'])]],
    'Ticket' => obj([
        'key' => str(null, ['examples' => ['CT-14']]),
        'id' => int(),
        'project' => str('The project\'s code.'),
        'type' => str(null, ['enum' => ['task', 'bug', 'story']]),
        'title' => str(),
        'status' => ref('Status'),
        'resolution' => nullable(str('Why a finished ticket is finished.', ['enum' => ['done', 'wont_do', 'duplicate', 'cannot_reproduce']])),
        'priority' => str(null, ['enum' => ['low', 'normal', 'high', 'urgent']]),
        'assignee' => nullable(ref('PersonRef')),
        'labels' => listOf(str()),
        'sprint' => nullable(str('The sprint\'s name.')),
        'epic' => nullable(obj(['id' => int(), 'title' => str()], ['id', 'title'])),
        'parent' => nullable(str('The key of the ticket this is a subtask of.')),
        'release' => nullable(str('The release\'s name.')),
        'subtasks' => obj(['count' => int(), 'done' => int()], ['count', 'done']),
        'story_points' => nullable(int()),
        'estimate_minutes' => nullable(int()),
        'logged_minutes' => int(),
        'remaining_minutes' => nullable(int()),
        'due_on' => nullable(date_()),
        'version' => int('Send it back with a PATCH to make the change conditional.'),
        'created_at' => str(),
        'updated_at' => str(),
        'url' => str(null, ['format' => 'uri']),
    ], ['key', 'id', 'project', 'type', 'title', 'status', 'priority', 'labels', 'version', 'url']),
    'TicketDetail' => ['allOf' => [ref('Ticket'), obj([
        'description' => str('Markdown, as it was written.'),
        'reporter' => nullable(ref('PersonRef')),
        'fields' => ['type' => 'object', 'description' => 'The project\'s own fields, by name; null where empty.', 'additionalProperties' => true],
    ], ['description', 'fields'])]],
    'TicketInput' => obj([
        'project' => str('A project\'s code. Needed for a new ticket; ignored in a PATCH.'),
        'title' => str(),
        'description' => str('Markdown.'),
        'type' => str(null, ['enum' => ['task', 'bug', 'story']]),
        'priority' => str(null, ['enum' => ['low', 'normal', 'high', 'urgent']]),
        'status' => str('A column by its id or its name. In a PATCH, a move the workflow has to allow.'),
        'resolution' => str('With a done status, or for a ticket that is done already.'),
        'assignee_id' => nullable(int('null takes it off whoever has it. Not a service account.')),
        'labels' => ['oneOf' => [listOf(str()), str('Several, with commas.')]],
        'estimate' => str('A duration: "4h", "1d 2h".'),
        'estimate_minutes' => nullable(int()),
        'due_on' => nullable(date_()),
        'story_points' => nullable(int()),
        'epic_id' => nullable(int()),
        'parent' => nullable(str('A ticket\'s key: makes this its subtask.')),
        'release' => nullable(str('A release\'s name or id, one not out yet.')),
        'sprint' => nullable(int('PATCH only: a sprint\'s id, or null for the backlog.')),
        'fields' => ['type' => 'object', 'additionalProperties' => true, 'description' => 'The project\'s own fields, by name.'],
        'version' => int('PATCH only: the version read. Somebody else\'s save in between is 409.'),
    ]),
    'Comment' => obj([
        'id' => int(),
        'author' => ref('PersonRef'),
        'body' => str('Markdown.'),
        'created_at' => str(),
        'edited_at' => nullable(str()),
    ], ['id', 'author', 'body', 'created_at']),
    'CommentInput' => obj(['body' => str('Markdown; @handle mentions, CT-12 links.')], ['body']),
    'Link' => obj([
        'id' => int(),
        'kind' => str('Read from the ticket asked about.', ['enum' => ['blocks', 'blocked_by', 'relates', 'duplicates', 'duplicated_by']]),
        'ticket' => str('The other ticket\'s key.'),
        'title' => str(),
        'status' => obj(['name' => str(), 'category' => $category], ['name', 'category']),
    ], ['id', 'kind', 'ticket', 'title', 'status']),
    'LinkInput' => obj([
        'kind' => str(null, ['enum' => ['blocks', 'blocked_by', 'relates', 'duplicates', 'duplicated_by']]),
        'ticket' => str('The other ticket\'s key.'),
    ], ['kind', 'ticket']),
    'Attachment' => obj([
        'id' => int(),
        'name' => str(),
        'type' => str('Its media type, as found by its content.'),
        'size' => int('Bytes.'),
        'image' => nullable(obj(['width' => nullable(int()), 'height' => nullable(int())])),
        'author' => obj(['id' => int(), 'name' => nullable(str())], ['id']),
        'created_at' => str(),
        'url' => str('Where the file itself is: GET /attachments/{id}.', ['format' => 'uri']),
    ], ['id', 'name', 'type', 'size', 'url']),
    'Worklog' => obj([
        'id' => int(),
        'ticket' => str('The ticket\'s key.'),
        'user' => ref('PersonRef'),
        'date' => date_(),
        'start' => nullable(str('09:30', ['examples' => ['09:30']])),
        'minutes' => int(),
        'work_type' => nullable(str()),
        'note' => nullable(str()),
        'billable' => boolean(),
        'created_at' => nullable(str()),
        'rounded' => boolean('Only after logging or changing: the time was rounded up to the smallest slice.'),
    ], ['id', 'ticket', 'user', 'date', 'minutes', 'billable']),
    'WorklogInput' => obj([
        'time' => str('A duration: "1h 30m", "90m", "1:30". Needed for a new entry.'),
        'date' => date_('Today when not given; never a day to come.'),
        'start' => str('When it started: "09:30".'),
        'note' => nullable(str()),
        'remaining' => str('New entries only: what is left on the ticket now.'),
        'billable' => boolean(),
        'work_type' => str('A work type\'s name or id.'),
    ]),
    'Timer' => obj([
        'ticket' => str(),
        'title' => str(),
        'started_at' => str(null, ['format' => 'date-time']),
        'seconds' => int('How long it has run, by the server\'s clock.'),
    ], ['ticket', 'title', 'started_at', 'seconds']),
    'Logged' => obj(['minutes' => int(), 'ticket' => nullable(str())], ['minutes']),
    'Board' => obj([
        'id' => int(),
        'name' => str(),
        'shared' => boolean('A board of several projects, not one project\'s own.'),
        'projects' => listOf(str('A project\'s code.')),
        'query' => nullable(str('What it narrows its projects\' tickets to, in the query language.')),
        'url' => str(null, ['format' => 'uri']),
    ], ['id', 'name', 'shared', 'projects', 'url']),
    'BoardDetail' => ['allOf' => [ref('Board'), obj([
        'columns' => listOf(obj(['name' => str(), 'done' => boolean(), 'status_ids' => listOf(int())], ['name', 'done', 'status_ids'])),
        'sprints' => listOf(ref('Sprint')),
    ], ['columns', 'sprints'])]],
    'Sprint' => obj([
        'id' => int(),
        'name' => str(),
        'goal' => nullable(str()),
        'state' => str(null, ['enum' => ['planned', 'active', 'closed']]),
        'starts_on' => nullable(date_()),
        'ends_on' => nullable(date_()),
        'tickets' => nullable(obj(['count' => int(), 'done' => int()], ['count', 'done'])),
        'points' => nullable(obj(['total' => int(), 'done' => int()], ['total', 'done'])),
        'url' => str(null, ['format' => 'uri']),
    ], ['id', 'name', 'state', 'url']),
    'SprintDetail' => ['allOf' => [ref('Sprint'), obj(['board' => obj(['id' => int(), 'name' => str()], ['id', 'name'])], ['board'])]],
    'Epic' => obj([
        'id' => int(),
        'title' => str(),
        'description' => nullable(str()),
        'done' => boolean(),
        'starts_on' => nullable(date_()),
        'ends_on' => nullable(date_()),
        'tickets' => obj(['count' => int(), 'done' => int()], ['count', 'done']),
        'url' => str(null, ['format' => 'uri']),
    ], ['id', 'title', 'done', 'tickets', 'url']),
    'Release' => obj([
        'id' => int(),
        'name' => str(),
        'description' => nullable(str()),
        'starts_on' => nullable(date_()),
        'release_on' => nullable(date_()),
        'released' => boolean(),
        'released_at' => nullable(str()),
        'tickets' => obj(['count' => int(), 'done' => int()], ['count', 'done']),
        'url' => str(null, ['format' => 'uri']),
    ], ['id', 'name', 'released', 'tickets', 'url']),
    'WorkType' => obj(['id' => int(), 'name' => str()], ['id', 'name']),
    'Notification' => obj([
        'id' => int(),
        'kind' => str(null, ['enum' => ['assigned', 'mentioned', 'status', 'commented', 'changes']]),
        'reason' => str('Why it reached you.', ['enum' => ['assigned', 'mentioned', 'watching']]),
        'read' => boolean(),
        'text' => str('The bell\'s sentence, in the account\'s language.'),
        'actor' => nullable(ref('PersonRef')),
        'ticket' => nullable(obj(['key' => str(), 'title' => str()], ['key', 'title'])),
        'epic' => nullable(obj(['id' => int(), 'title' => str()], ['id', 'title'])),
        'project' => str(),
        'created_at' => str(),
        'url' => str(null, ['format' => 'uri']),
    ], ['id', 'kind', 'reason', 'read', 'text', 'project', 'created_at', 'url']),
];

$errorResponse = static fn(string $description): array => json(ref('Error'), $description);

$components = [
    'securitySchemes' => [
        'token' => [
            'type' => 'http',
            'scheme' => 'bearer',
            'bearerFormat' => 'ct_ and 40 hex characters',
            'description' => 'A personal access token (Profile → Access tokens), a service account\'s, or one from POST /auth/login.',
        ],
    ],
    'parameters' => [
        'key' => ['name' => 'key', 'in' => 'path', 'required' => true, 'schema' => str(null, ['pattern' => '^[A-Za-z][A-Za-z0-9]{1,9}-[0-9]+$', 'examples' => ['CT-14']]), 'description' => 'A ticket\'s key.'],
        'id' => ['name' => 'id', 'in' => 'path', 'required' => true, 'schema' => int()],
        'code' => ['name' => 'code', 'in' => 'path', 'required' => true, 'schema' => str(null, ['examples' => ['CT']]), 'description' => 'A project\'s code.'],
        'page' => ['name' => 'page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1, 'default' => 1]],
        'per_page' => ['name' => 'per_page', 'in' => 'query', 'required' => false, 'schema' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 100, 'default' => 50]],
        'IdempotencyKey' => [
            'name' => 'Idempotency-Key',
            'in' => 'header',
            'required' => false,
            'schema' => str(null, ['pattern' => '^[\x21-\x7E]{1,100}$']),
            'description' => 'Makes a resend safe: the same key with the same request, from the same account within a day, gets the first answer again (with Idempotent-Replayed: true) and is not done again. 409 while the first is still being worked on; 422 for the same key with another request.',
        ],
    ],
    'responses' => [
        'BadRequest' => $errorResponse('The body is not a JSON object, or a header is malformed.'),
        'Unauthorized' => $errorResponse('No token, or one that is unknown, expired, revoked, or whose account is switched off.'),
        'Forbidden' => $errorResponse('Not yours to do: a guest changing the work, somebody else\'s hours.'),
        'NotFound' => $errorResponse('Not there — or not somewhere you can see.'),
        'Conflict' => $errorResponse('Somebody else saved it first (see version) — or the same request is still being worked on.'),
        'TooLarge' => $errorResponse('More than the server takes in one go.'),
        'Unprocessable' => $errorResponse('Understood, but it does not make sense: no title, a day to come, a move the workflow does not allow.'),
        'TooManyRequests' => $errorResponse('Too many tries; wait for Retry-After seconds.'),
    ],
    'schemas' => $schemas,
];

// ---------------------------------------------------------------------------
// The operations
// ---------------------------------------------------------------------------

$paths = [];
$add = static function (string $path, string $method, array $operation) use (&$paths): void {
    $paths[$path][$method] = $operation;
};

$add('/openapi.json', 'get', op('get', 'Signing in', 'openapi', 'This description', 'No token needed.', ['200' => ['description' => 'The OpenAPI document, with this installation\'s address.', 'content' => ['application/json' => ['schema' => ['type' => 'object']]]]], public: true));

$add('/auth/login', 'post', op('post', 'Signing in', 'login', 'Sign an app in', 'With two-step sign-in on, the password alone is 403 with details.two_factor_required; send the same request again with code. Wrong passwords and codes count towards the sign-in form\'s limit.', [
    '201' => json(envelope(obj(['token' => str(), 'user' => ref('Me')], ['token', 'user'])), 'Signed in: a token made for this device.'),
] + errors(400, 401, 403, 429), [], body(obj([
    'email' => str(null, ['format' => 'email']),
    'password' => str(),
    'code' => str('The two-step code, or a recovery code.'),
    'device' => str('What the token is named after on the profile.'),
], ['email', 'password'])), public: true));
$add('/auth/logout', 'post', op('post', 'Signing in', 'logout', 'Revoke the token the request carries', '', $noContent));

$add('/me', 'get', op('get', 'People', 'me', 'Who the token acts as', '', ['200' => json(envelope(ref('Me')), 'The account.')]));
$add('/users', 'get', op('get', 'People', 'users', 'The active people', 'Who work can be given to; service accounts are not among them.', ['200' => json(envelope(listOf(ref('Person'))), 'By name.')]));

$add('/projects', 'get', op('get', 'Projects', 'projects', 'The projects you can see', '', ['200' => json(envelope(listOf(ref('Project'))), 'By code.')], [query('archived', ['type' => 'string', 'enum' => ['1']], 'The archived ones too.')]));
$add('/projects/{code}', 'get', op('get', 'Projects', 'project', 'One project, with its columns', '', ['200' => json(envelope(ref('ProjectDetail')), 'The project.')] + errors(404), [p('code')]));
$add('/projects/{code}/epics', 'get', op('get', 'Planning', 'epics', 'A project\'s epics', 'The open ones first.', ['200' => json(envelope(listOf(ref('Epic'))), 'The epics.')] + errors(404), [p('code')]));
$add('/projects/{code}/releases', 'get', op('get', 'Planning', 'releases', 'A project\'s releases', 'The ones still coming, soonest first, then the ones that went out.', ['200' => json(envelope(listOf(ref('Release'))), 'The releases.')] + errors(404), [p('code')]));

$add('/tickets', 'get', op('get', 'Tickets', 'tickets', 'A page of tickets', 'The unfinished ones first, then by priority, the newest first within one — unless query says ORDER BY. The filters add up.', [
    '200' => json(envelope(listOf(ref('Ticket')), ref('PageMeta')), 'A page.'),
] + errors(404, 422), [
    query('project', str(), 'A project\'s code.'),
    query('status', str(), 'A column\'s id, or a category: todo, in_progress, done.'),
    query('open', ['type' => 'string', 'enum' => ['1']], 'Only the ones not done.'),
    query('assignee', str(), 'me, none, or a person\'s id.'),
    query('type', str(null, ['enum' => ['task', 'bug', 'story']]), 'One type.'),
    query('label', str(), 'One label, exactly.'),
    query('q', str(), 'Words in the title, the text or the comments; or a key.'),
    query('query', str(), 'The ticket list\'s query language: project = CT AND assignee = me ORDER BY priority DESC.'),
    query('sprint', str(), 'A sprint\'s id, or none for the backlog.'),
    query('epic', int(), 'An epic\'s id.'),
    query('release', int(), 'A release\'s id.'),
    query('parent', str(), 'A ticket\'s key: its subtasks.'),
    query('top_level', ['type' => 'string', 'enum' => ['1']], 'Only tickets, not subtasks.'),
    query('due', str(null, ['enum' => ['overdue', 'week']]), 'Late, or due in the next seven days; unfinished only.'),
    p('page'),
    p('per_page'),
]));
$add('/tickets', 'post', op('post', 'Tickets', 'createTicket', 'A new ticket', 'project and title are needed.', [
    '201' => ['description' => 'Made; its address is in Location.', 'headers' => ['Location' => ['schema' => str(null, ['format' => 'uri'])]], 'content' => ['application/json' => ['schema' => envelope(ref('TicketDetail'))]]],
] + errors(400, 403, 404, 422), [], body(ref('TicketInput'))));
$add('/tickets/{key}', 'get', op('get', 'Tickets', 'ticket', 'One ticket', '', ['200' => json(envelope(ref('TicketDetail')), 'The ticket.')] + errors(404), [p('key')]));
$add('/tickets/{key}', 'patch', op('patch', 'Tickets', 'updateTicket', 'Change the fields sent, and no others', 'Its column, its sprint too. With version, conditional.', ['200' => json(envelope(ref('TicketDetail')), 'The ticket as it is now.')] + errors(400, 403, 404, 409, 422), [p('key')], body(ref('TicketInput'))));
$add('/tickets/{key}', 'delete', op('delete', 'Tickets', 'deleteTicket', 'Delete a ticket', 'Administrators only; one with hours or subtasks is 422.', $noContent + errors(403, 404, 422), [p('key')]));
$add('/tickets/{key}/watch', 'post', op('post', 'Tickets', 'watch', 'Follow a ticket', '', $noContent + errors(404), [p('key')]));
$add('/tickets/{key}/watch', 'delete', op('delete', 'Tickets', 'unwatch', 'Stop following it', '', $noContent + errors(404), [p('key')]));

$add('/tickets/{key}/comments', 'get', op('get', 'Comments', 'comments', 'A ticket\'s comments', 'Oldest first.', ['200' => json(envelope(listOf(ref('Comment'))), 'The comments.')] + errors(404), [p('key')]));
$add('/tickets/{key}/comments', 'post', op('post', 'Comments', 'addComment', 'Comment on a ticket', 'Guests may too.', ['201' => json(envelope(ref('Comment')), 'The comment.')] + errors(400, 404, 422), [p('key')], body(ref('CommentInput'))));
$add('/comments/{id}', 'patch', op('patch', 'Comments', 'updateComment', 'Correct a comment of yours', '', ['200' => json(envelope(ref('Comment')), 'The comment, edited_at set.')] + errors(400, 403, 404, 422), [p('id')], body(ref('CommentInput'))));
$add('/comments/{id}', 'delete', op('delete', 'Comments', 'deleteComment', 'Take a comment back', 'Yours — anybody\'s, as an administrator.', $noContent + errors(403, 404), [p('id')]));

$add('/tickets/{key}/links', 'get', op('get', 'Links', 'links', 'A ticket\'s links', 'Each read from this ticket\'s end.', ['200' => json(envelope(listOf(ref('Link'))), 'The links.')] + errors(404), [p('key')]));
$add('/tickets/{key}/links', 'post', op('post', 'Links', 'addLink', 'Link two tickets', '', ['201' => json(envelope(listOf(ref('Link'))), 'The link, as seen from this ticket.')] + errors(400, 403, 404, 422), [p('key')], body(ref('LinkInput'))));
$add('/links/{id}', 'delete', op('delete', 'Links', 'deleteLink', 'Take a link away', '', $noContent + errors(403, 404), [p('id')]));

$add('/tickets/{key}/attachments', 'get', op('get', 'Attachments', 'attachments', 'A ticket\'s files', 'Oldest first.', ['200' => json(envelope(listOf(ref('Attachment'))), 'The files.')] + errors(404), [p('key')]));
$add('/tickets/{key}/attachments', 'post', op('post', 'Attachments', 'upload', 'Attach files', 'Checked by what they are, not by their name. Guests may too.', [
    '201' => json(obj(['data' => listOf(ref('Attachment')), 'errors' => listOf(str('Why a file did not get in.'))], ['data', 'errors']), 'The ones that got in, and why the others did not.'),
] + errors(404, 413, 422), [p('key')], ['required' => true, 'content' => ['multipart/form-data' => ['schema' => obj([
    'file' => str('One file.', ['format' => 'binary']),
    'files[]' => listOf(str(null, ['format' => 'binary'])),
])]]]));
$add('/attachments/{id}', 'get', op('get', 'Attachments', 'download', 'The file itself', 'As a download, with its own type.', ['200' => ['description' => 'The file.', 'content' => ['application/octet-stream' => ['schema' => str(null, ['format' => 'binary'])]]]] + errors(404), [p('id')]));
$add('/attachments/{id}', 'delete', op('delete', 'Attachments', 'deleteAttachment', 'Remove a file', 'One you attached — anybody\'s, as an administrator.', $noContent + errors(403, 404), [p('id')]));

$add('/worklogs', 'get', op('get', 'Hours', 'worklogs', 'Hours in a range', 'Your own unless user says whose. At most a year.', [
    '200' => json(envelope(listOf(ref('Worklog')), obj(['from' => date_(), 'to' => date_(), 'user_id' => int()], ['from', 'to', 'user_id'])), 'Oldest first.'),
] + errors(422), [
    query('from', date_(), 'This week\'s Monday when not given.'),
    query('to', date_(), 'Today when not given.'),
    query('user', int(), 'A person\'s id.'),
]));
$add('/tickets/{key}/worklogs', 'get', op('get', 'Hours', 'ticketWorklogs', 'A ticket\'s hours', 'Everybody\'s, the newest day first.', ['200' => json(envelope(listOf(ref('Worklog'))), 'The entries.')] + errors(404), [p('key')]));
$add('/tickets/{key}/worklogs', 'post', op('post', 'Hours', 'logWork', 'Log time on a ticket', 'Only time is needed. A closed, handed-in or approved day is 422.', ['201' => json(envelope(ref('Worklog')), 'The entry.')] + errors(400, 403, 404, 422), [p('key')], body(ref('WorklogInput'))));
$add('/worklogs/{id}', 'patch', op('patch', 'Hours', 'updateWorklog', 'Change an entry', 'Yours — anybody\'s, as an administrator. Only the fields sent change; billed hours are 422.', ['200' => json(envelope(ref('Worklog')), 'The entry.')] + errors(400, 403, 404, 422), [p('id')], body(ref('WorklogInput'))));
$add('/worklogs/{id}', 'delete', op('delete', 'Hours', 'deleteWorklog', 'Remove an entry', '', $noContent + errors(403, 404, 422), [p('id')]));

$add('/timer', 'get', op('get', 'The clock', 'timer', 'Your running clock', 'The same one the web shows.', ['200' => json(envelope(nullable(ref('Timer'))), 'The clock, or null.')] + errors(403)));
$add('/timer', 'delete', op('delete', 'The clock', 'discardTimer', 'Stop it without logging', '', $noContent + errors(403)));
$add('/tickets/{key}/timer', 'post', op('post', 'The clock', 'startTimer', 'Start it on a ticket', 'One running elsewhere is stopped and logged first.', [
    '201' => json(obj(['data' => nullable(ref('Timer')), 'logged' => nullable(ref('Logged'))], ['data', 'logged']), 'Running.'),
] + errors(403, 404, 422), [p('key')]));
$add('/timer/stop', 'post', op('post', 'The clock', 'stopTimer', 'Stop it and log the time', 'Under a minute nothing is logged, and data is null. No clock running is 422.', [
    '200' => json(envelope(nullable(ref('Logged'))), 'What was logged.'),
] + errors(400, 403, 422), [], body(obj(['note' => str()]), false)));

$add('/boards', 'get', op('get', 'Planning', 'boards', 'Every board you can see', 'The projects\' own first, then the shared ones.', ['200' => json(envelope(listOf(ref('Board'))), 'The boards.')]));
$add('/boards/{id}', 'get', op('get', 'Planning', 'board', 'One board', 'Its columns and its sprints, the running one first.', ['200' => json(envelope(ref('BoardDetail')), 'The board.')] + errors(404), [p('id')]));
$add('/sprints/{id}', 'get', op('get', 'Planning', 'sprint', 'One sprint', 'Its tickets are GET /tickets?sprint={id}.', ['200' => json(envelope(ref('SprintDetail')), 'The sprint.')] + errors(404), [p('id')]));
$add('/work-types', 'get', op('get', 'Planning', 'workTypes', 'What hours can be logged as', '', ['200' => json(envelope(listOf(ref('WorkType'))), 'By name.')]));

$add('/notifications', 'get', op('get', 'Notifications', 'notifications', 'The bell', 'Newest first.', [
    '200' => json(envelope(listOf(ref('Notification')), ['allOf' => [ref('PageMeta'), obj(['unread' => int('How many are unread in all.')], ['unread'])]]), 'A page.'),
], [query('unread', ['type' => 'string', 'enum' => ['1']], 'Only the unread ones.'), p('page'), p('per_page')]));
$add('/notifications/{id}/read', 'post', op('post', 'Notifications', 'readNotification', 'Mark one read', '', $noContent + errors(404), [p('id')]));
$add('/notifications/read', 'post', op('post', 'Notifications', 'readAllNotifications', 'Mark them all read', '', $noContent));

ksort($paths);

$document = [
    'openapi' => '3.1.0',
    'info' => [
        'title' => 'CantoTrack API',
        'version' => '1',
        'summary' => 'Tickets, their comments, files and hours, the clock, and how the work is planned.',
        'description' => 'Every request acts as one account, with exactly its rights, through the same rules as the web. The whole description, for people, is docs/API.md — and inside the application under API documentation.',
        'license' => ['name' => 'AGPL-3.0-only', 'identifier' => 'AGPL-3.0-only'],
    ],
    'servers' => [['url' => 'https://tracker.example/api/v1']],
    'security' => [['token' => []]],
    'tags' => array_map(static fn(string $t): array => ['name' => $t], ['Signing in', 'People', 'Projects', 'Tickets', 'Comments', 'Links', 'Attachments', 'Hours', 'The clock', 'Planning', 'Notifications']),
    'paths' => $paths,
    'components' => $components,
];

$file = $root . '/docs/openapi.json';
file_put_contents($file, json_encode($document, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");

$operations = array_sum(array_map('count', $paths));
printf('Built docs/openapi.json: %d paths, %d operations, %d schemas.%s', count($paths), $operations, count($schemas), PHP_EOL);
