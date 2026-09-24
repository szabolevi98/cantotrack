<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Controller;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\CustomFieldRepository;
use CantoTrack\Model\ProjectRepository;
use CantoTrack\Service\CustomFields;

/**
 * A project's own fields, kept by the administrators in the project's
 * settings — what a ticket of this project needs to say beyond the rest.
 */
class FieldController extends Controller
{
    public function index(int $projectId): void
    {
        Auth::requireAdmin();

        $this->show($this->projectOr404($projectId));
    }

    public function create(int $projectId): void
    {
        Auth::requireAdmin();

        $project = $this->projectOr404($projectId);

        try {
            (new CustomFields())->define($projectId, $this->input('name'), $this->input('kind'), $this->input('options'), isset($_POST['is_required']));
        } catch (ValidationError $e) {
            $this->show($project, $e->getMessage(), $_POST);

            return;
        }

        $this->flash(__('Field added. Tickets of the project show it from now on.'));
        $this->redirect('/projects/' . $projectId . '/fields');
    }

    public function update(int $id): void
    {
        Auth::requireAdmin();

        $field = $this->fieldOr404($id);

        try {
            (new CustomFields())->define((int) $field['project_id'], $this->input('name'), (string) $field['kind'], $this->input('options'), isset($_POST['is_required']), $field);
            $this->flash(__('Field saved.'));
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        $this->redirect('/projects/' . $field['project_id'] . '/fields');
    }

    public function move(int $id): void
    {
        Auth::requireAdmin();

        $field = $this->fieldOr404($id);
        (new CustomFieldRepository())->move($field, $this->input('direction') === 'up' ? -1 : 1);

        $this->redirect('/projects/' . $field['project_id'] . '/fields');
    }

    public function delete(int $id): void
    {
        Auth::requireAdmin();

        $field = $this->fieldOr404($id);
        (new CustomFieldRepository())->delete($id);

        $this->flash(__('The field “{field}” is gone, and its values with it.', ['field' => $field['name']]), 'warning');
        $this->redirect('/projects/' . $field['project_id'] . '/fields');
    }

    /** @param array<string, mixed> $values what the new-field form holds */
    private function show(array $project, ?string $error = null, array $values = []): void
    {
        $this->render('projects/fields.twig', [
            'project' => $project,
            'fields' => (new CustomFieldRepository())->forProject((int) $project['id']),
            'kinds' => CustomFields::KINDS,
            'error' => $error,
            'values' => $values,
        ], $error === null ? 200 : 422);
    }

    private function fieldOr404(int $id): array
    {
        $field = (new CustomFieldRepository())->find($id);

        if ($field === null) {
            $this->notFound(__('There is no such field.'));
        }

        return $field;
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
