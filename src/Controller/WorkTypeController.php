<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Controller;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\WorkTypeRepository;

/**
 * The kinds of work, for administrators: added, renamed, retired. Never
 * deleted — the hours that had a type go on saying so.
 */
class WorkTypeController extends Controller
{
    public function index(): void
    {
        Auth::requireAdmin();

        $this->render('settings/work_types.twig', ['types' => (new WorkTypeRepository())->all()]);
    }

    public function create(): void
    {
        Auth::requireAdmin();

        try {
            (new WorkTypeRepository())->create($this->input('name'));
            $this->flash(__('{name} can be chosen on entries now.', ['name' => $this->input('name')]));
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        $this->redirect('/settings/work-types');
    }

    public function update(int $id): void
    {
        Auth::requireAdmin();

        $types = new WorkTypeRepository();

        if ($types->find($id) === null) {
            $this->notFound(__('There is no such work type.'));
        }

        try {
            $types->update($id, $this->input('name'), isset($_POST['is_active']));
            $this->flash(__('Saved.'));
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        $this->redirect('/settings/work-types');
    }
}
