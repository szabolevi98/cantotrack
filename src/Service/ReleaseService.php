<?php

namespace CantoTrack\Service;

use CantoTrack\Core\DatabaseConnection;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\ProjectRepository;
use CantoTrack\Model\ReleaseRepository;
use CantoTrack\Model\TicketRepository;
use PDO;

/**
 * The rules about releases: a name that is the project's own, days that make
 * sense, and what happens to the unfinished work when one goes out.
 */
class ReleaseService
{
    private ReleaseRepository $releases;
    private TicketRepository $tickets;
    private Activity $activity;
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? DatabaseConnection::get();
        $this->releases = new ReleaseRepository($this->db);
        $this->tickets = new TicketRepository($this->db);
        $this->activity = new Activity($this->db);
    }

    /** @throws ValidationError */
    public function create(int $projectId, string $name, string $description, string $startsOn, string $releaseOn): int
    {
        $project = (new ProjectRepository($this->db))->find($projectId);

        if ($project === null) {
            throw new ValidationError(__('There is no such project.'));
        }

        [$name, $starts, $release] = $this->checked($projectId, $name, $startsOn, $releaseOn, null);

        return $this->releases->create($projectId, $name, trim($description) ?: null, $starts, $release);
    }

    /** @throws ValidationError */
    public function update(array $release, string $name, string $description, string $startsOn, string $releaseOn): void
    {
        [$name, $starts, $end] = $this->checked((int) $release['project_id'], $name, $startsOn, $releaseOn, (int) $release['id']);

        $this->releases->update((int) $release['id'], $name, trim($description) ?: null, $starts, $end);
    }

    /**
     * Out it goes. What is not finished moves on — to another release still
     * coming, or out of any — so a release that went out holds what went out.
     *
     * @throws ValidationError
     */
    public function release(array $release, ?int $moveTo, ?int $actorId): void
    {
        if ($release['released_at'] !== null) {
            throw new ValidationError(__('{name} is out already.', ['name' => $release['name']]));
        }

        $next = null;

        if ($moveTo !== null) {
            $next = $this->releases->find($moveTo);

            if ($next === null || (int) $next['project_id'] !== (int) $release['project_id'] || $next['released_at'] !== null || (int) $next['id'] === (int) $release['id']) {
                throw new ValidationError(__('Unfinished work can only move on to another release of the project that is still coming.'));
            }
        }

        $unfinished = $this->releases->unfinishedTicketIds((int) $release['id']);
        $this->releases->assign($unfinished, $next === null ? null : (int) $next['id']);

        foreach ($unfinished as $id) {
            $ticket = $this->tickets->find($id);

            if ($ticket !== null) {
                $this->activity->happened($ticket, $actorId, 'changed', 'release', (string) $release['name'], $next['name'] ?? null);
            }
        }

        $this->releases->setReleased((int) $release['id'], date('Y-m-d H:i:s'));
    }

    /** Back to still coming: a release marked out by mistake. */
    public function unrelease(array $release): void
    {
        $this->releases->setReleased((int) $release['id'], null);
    }

    /**
     * The release notes: what is finished in it, by kind, as Markdown — to be
     * pasted into a changelog, an email, or a page of the project's.
     */
    public function notes(array $release): string
    {
        $done = array_filter(
            $this->tickets->search(['release_id' => (int) $release['id'], 'top_level' => true, 'status' => 'done'], 500),
            // Decided against, or the same as another: not what went out.
            static fn(array $t): bool => $t['status_category'] === 'done' && in_array($t['resolution'] ?? 'done', ['done', null], true)
        );
        $when = $release['released_at'] ?? $release['release_on'] ?? null;
        $lines = ['## ' . $release['name'] . ($when ? ' — ' . \CantoTrack\Core\Format::day(substr((string) $when, 0, 10)) : '')];

        if (!empty($release['description'])) {
            $lines[] = '';
            $lines[] = trim((string) $release['description']);
        }

        // Each kind of ticket under its own heading, in the order they are read.
        foreach (['story' => __('New'), 'bug' => __('Fixed'), 'task' => __('Changed')] as $type => $heading) {
            $these = array_filter($done, static fn(array $t): bool => $t['type'] === $type);

            if ($these === []) {
                continue;
            }

            usort($these, static fn(array $a, array $b): int => (int) $a['number'] <=> (int) $b['number']);
            $lines[] = '';
            $lines[] = '### ' . $heading;
            $lines[] = '';

            foreach ($these as $ticket) {
                $lines[] = '- ' . $ticket['project_code'] . '-' . $ticket['number'] . ' ' . $ticket['title'];
            }
        }

        if ($done === []) {
            $lines[] = '';
            $lines[] = __('Nothing in it is finished yet.');
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @return array{0: string, 1: ?string, 2: ?string}
     * @throws ValidationError
     */
    private function checked(int $projectId, string $name, string $startsOn, string $releaseOn, ?int $id): array
    {
        $name = trim($name);

        if ($name === '') {
            throw new ValidationError(__('A release needs a name.'));
        }

        if (mb_strlen($name) > 60) {
            throw new ValidationError(__('A name can be at most {count} characters.', ['count' => 60]));
        }

        if ($this->releases->nameTaken($projectId, $name, $id)) {
            throw new ValidationError(__('The project has a release called {name} already.', ['name' => $name]));
        }

        $starts = self::day($startsOn);
        $release = self::day($releaseOn);

        if ($starts !== null && $release !== null && $starts > $release) {
            throw new ValidationError(__('A release cannot go out before work on it starts.'));
        }

        return [$name, $starts, $release];
    }

    /** @throws ValidationError */
    private static function day(string $given): ?string
    {
        $given = trim($given);

        if ($given === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $given);

        if ($date === false || $date->format('Y-m-d') !== $given) {
            throw new ValidationError(__('“{value}” is not a date.', ['value' => $given]));
        }

        return $given;
    }
}
