<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Controller;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\ProjectRepository;
use CantoTrack\Model\TemplateRepository;
use CantoTrack\Model\TicketRepository;
use CantoTrack\Model\UserRepository;
use CantoTrack\Service\Templates;

/**
 * A project's ticket templates and its repeating tickets, kept by the
 * people who run the project — its leads and the administrators.
 */
class TemplateController extends Controller
{
    public function index(int $projectId): void
    {
        $project = $this->projectLed($projectId);
        $templates = new TemplateRepository();

        $this->render('projects/templates.twig', [
            'project' => $project,
            'templates' => $templates->forProject($projectId),
            'recurring' => $templates->recurringFor($projectId),
            'people' => (new UserRepository())->active(),
            'types' => TicketRepository::TYPES,
            'priorities' => TicketRepository::PRIORITIES,
        ]);
    }

    public function create(int $projectId): void
    {
        $this->projectLed($projectId);

        try {
            $id = (new TemplateRepository())->create($projectId, Templates::clean($_POST), Auth::id());
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
            $this->redirect('/projects/' . $projectId . '/templates#new-template');
        }

        $this->flash(__('Template saved. It is offered on the new-ticket form of this project now.'));
        $this->redirect('/projects/' . $projectId . '/templates#template-' . $id);
    }

    public function update(int $id): void
    {
        $template = $this->templateLed($id);

        try {
            (new TemplateRepository())->update($id, Templates::clean($_POST));
            $this->flash(__('Template saved.'));
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        $this->redirect('/projects/' . $template['project_id'] . '/templates#template-' . $id);
    }

    public function delete(int $id): void
    {
        $template = $this->templateLed($id);
        (new TemplateRepository())->delete($id);

        $this->flash(__('Template deleted, and the tickets it repeated stop.'), 'warning');
        $this->redirect('/projects/' . $template['project_id'] . '/templates');
    }

    /** One of the project's templates, made from by itself on its days. */
    public function repeat(int $projectId): void
    {
        $this->projectLed($projectId);
        $template = (new TemplateRepository())->find((int) $this->idInput('template_id'));

        if ($template === null || (int) $template['project_id'] !== $projectId) {
            $this->notFound(__('There is no such template.'));
        }

        $service = new Templates();

        try {
            $data = $service->cleanRecurring($template, $_POST, new \DateTimeImmutable('today'));
            (new TemplateRepository())->createRecurring($data, Auth::id());
            $this->flash(__('It repeats now. The first one is made on {day}.', ['day' => \CantoTrack\Core\Format::day($data['next_on'])]));
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        $this->redirect('/projects/' . $template['project_id'] . '/templates#repeating');
    }

    /** Paused, or going again — from the next day it falls on, not the ones it missed. */
    public function toggle(int $id): void
    {
        $recurring = $this->recurringLed($id);
        $active = !$recurring['is_active'];
        $next = $active ? (new Templates())->onOrAfter($recurring, new \DateTimeImmutable('today'))->format('Y-m-d') : null;

        (new TemplateRepository())->setActive($id, $active, $next);

        $this->flash($active ? __('It repeats again.') : __('Paused: no more are made until it is started again.'));
        $this->redirect('/projects/' . $recurring['project_id'] . '/templates#repeating');
    }

    public function deleteRecurring(int $id): void
    {
        $recurring = $this->recurringLed($id);
        (new TemplateRepository())->deleteRecurring($id);

        $this->flash(__('It no longer repeats. The tickets it made stay.'), 'warning');
        $this->redirect('/projects/' . $recurring['project_id'] . '/templates#repeating');
    }

    /** One made now, to see what it will make — its next day stays as it was. */
    public function makeNow(int $id): void
    {
        $recurring = $this->recurringLed($id);
        $ticket = (new Templates())->makeNow($recurring, new \DateTimeImmutable('today'));

        $this->flash(__('Made one now.'));
        $this->redirect('/tickets/' . $ticket);
    }

    private function projectLed(int $projectId): array
    {
        Auth::requireProjectLead($projectId);

        $project = (new ProjectRepository())->find($projectId);

        if ($project === null) {
            $this->notFound(__('There is no such project.'));
        }

        return $project;
    }

    private function templateLed(int $id): array
    {
        Auth::require();

        $template = (new TemplateRepository())->find($id);

        if ($template === null) {
            $this->notFound(__('There is no such template.'));
        }

        Auth::requireProjectLead((int) $template['project_id']);

        return $template;
    }

    private function recurringLed(int $id): array
    {
        Auth::require();

        $recurring = (new TemplateRepository())->findRecurring($id);

        if ($recurring === null) {
            $this->notFound(__('There is no such repeating ticket.'));
        }

        Auth::requireProjectLead((int) $recurring['project_id']);

        return $recurring;
    }
}
