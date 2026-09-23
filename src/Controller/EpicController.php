<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Controller;
use CantoTrack\Core\Session;
use CantoTrack\Core\View;
use CantoTrack\Model\EpicRepository;
use CantoTrack\Model\ProjectRepository;
use CantoTrack\Model\TicketRepository;

/**
 * Epics: the middle level.
 *
 * They are edited by anyone signed in rather than by administrators only.
 * Grouping tickets is part of doing the work, and a grouping that needs somebody
 * else's permission is one people stop maintaining.
 */
class EpicController extends Controller
{
    public function show(int $id): void
    {
        Auth::require();

        $epic = $this->epicOr404($id);

        View::render('epics/show.twig', [
            'epic' => $epic,
            'project' => (new ProjectRepository())->find((int) $epic['project_id']),
            'tickets' => (new TicketRepository())->search(['epic_id' => $id], 500),
        ]);
    }

    public function createForm(int $projectId): void
    {
        Auth::requireMember();

        View::render('epics/form.twig', [
            'project' => $this->projectOr404($projectId),
            'epic' => null,
            'error' => null,
        ]);
    }

    public function create(int $projectId): void
    {
        Auth::requireMember();

        $project = $this->projectOr404($projectId);
        $title = trim((string) ($_POST['title'] ?? ''));

        if ($title === '') {
            View::render('epics/form.twig', [
                'project' => $project,
                'epic' => ['title' => $title, 'description' => $_POST['description'] ?? '', 'is_done' => 0],
                'error' => __('An epic needs a title.'),
            ]);

            return;
        }

        $days = $this->days();

        if (is_string($days)) {
            View::render('epics/form.twig', [
                'project' => $project,
                'epic' => ['title' => $title, 'description' => $_POST['description'] ?? '', 'is_done' => 0, 'starts_on' => $_POST['starts_on'] ?? '', 'ends_on' => $_POST['ends_on'] ?? ''],
                'error' => $days,
            ]);

            return;
        }

        $id = (new EpicRepository())->create($projectId, $title, (string) ($_POST['description'] ?? ''), $days[0], $days[1]);

        Session::flash(__('Epic created.'));
        $this->redirect('/epics/' . $id);
    }

    public function editForm(int $id): void
    {
        Auth::requireMember();

        $epic = $this->epicOr404($id);

        View::render('epics/form.twig', [
            'project' => (new ProjectRepository())->find((int) $epic['project_id']),
            'epic' => $epic,
            'error' => null,
        ]);
    }

    public function update(int $id): void
    {
        Auth::requireMember();

        $epic = $this->epicOr404($id);
        $title = trim((string) ($_POST['title'] ?? ''));

        if ($title === '') {
            View::render('epics/form.twig', [
                'project' => (new ProjectRepository())->find((int) $epic['project_id']),
                'epic' => $epic,
                'error' => __('An epic needs a title.'),
            ]);

            return;
        }

        $days = $this->days();

        if (is_string($days)) {
            View::render('epics/form.twig', [
                'project' => (new ProjectRepository())->find((int) $epic['project_id']),
                'epic' => ['starts_on' => $_POST['starts_on'] ?? '', 'ends_on' => $_POST['ends_on'] ?? ''] + $epic,
                'error' => $days,
            ]);

            return;
        }

        (new EpicRepository())->update($id, $title, (string) ($_POST['description'] ?? ''), isset($_POST['is_done']), $days[0], $days[1]);

        Session::flash(__('Epic saved.'));
        $this->redirect('/epics/' . $id);
    }

    public function delete(int $id): void
    {
        Auth::requireMember();

        $epic = $this->epicOr404($id);
        (new EpicRepository())->delete($id);

        // Worth saying, because it is the opposite of what deleting a project
        // does: the work stays, only the grouping is gone.
        Session::flash(__('Epic deleted. Its tickets are still in the project, without an epic.'), 'warning');
        $this->redirect('/projects/' . $epic['project_id']);
    }

    /**
     * The epic's days from the roadmap's drag: its new first and last day,
     * answered in JSON for the script that moved the bar.
     */
    public function moveDays(int $id): void
    {
        Auth::requireMember();

        $this->epicOr404($id);
        $days = $this->days();

        if (is_string($days)) {
            $this->json(['ok' => false, 'error' => $days], 422);
        }

        (new EpicRepository())->setDays($id, $days[0], $days[1]);
        $this->json(['ok' => true]);
    }

    /**
     * The first and last day from the form: both optional, real dates, and
     * the end not before the start — or what is wrong with them.
     *
     * @return array{0: ?string, 1: ?string}|string
     */
    private function days(): array|string
    {
        $out = [];

        foreach (['starts_on', 'ends_on'] as $field) {
            $given = trim((string) ($_POST[$field] ?? ''));
            $date = $given === '' ? null : \DateTimeImmutable::createFromFormat('!Y-m-d', $given);

            if ($date === false || ($date !== null && $date->format('Y-m-d') !== $given)) {
                return __('“{value}” is not a date.', ['value' => $given]);
            }

            $out[] = $date?->format('Y-m-d');
        }

        if ($out[0] !== null && $out[1] !== null && $out[1] < $out[0]) {
            return __('An epic cannot end before it starts.');
        }

        return [$out[0], $out[1]];
    }

    private function epicOr404(int $id): array
    {
        $epic = (new EpicRepository())->find($id);

        if ($epic === null) {
            $this->notFound(__('There is no such epic.'));
        }

        return $epic;
    }

    private function projectOr404(int $id): array
    {
        $project = (new ProjectRepository())->find($id);

        if ($project === null) {
            $this->notFound(__('There is no such project.'));
        }

        return $project;
    }
}
