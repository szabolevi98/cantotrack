<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Controller;
use CantoTrack\Model\SavedFilterRepository;
use CantoTrack\Model\TicketRepository;

/**
 * The search box in the sidebar, and the ticket lists kept by name.
 *
 * The box does the one thing people use a tracker's search for most: type a
 * ticket's name and be on it. Anything that is not a ticket's name becomes a
 * text search of the ticket list.
 */
class SearchController extends Controller
{
    public function search(): void
    {
        Auth::require();

        $q = trim((string) ($_GET['q'] ?? ''));

        if ($q === '') {
            $this->redirect('/tickets');
        }

        $ticket = (new TicketRepository())->findByKey($q);

        if ($ticket !== null) {
            $this->redirect('/tickets/' . $ticket['id']);
        }

        // A bare number is the ticket of that number in the project somebody
        // was last looking at — "14" from the CT board means CT-14.
        $project = (string) ($_GET['project'] ?? '');
        if (ctype_digit($q) && $project !== '' && preg_match('/^[A-Z][A-Z0-9]{1,9}$/', $project) === 1) {
            $numbered = (new TicketRepository())->findByKey($project . '-' . $q);

            if ($numbered !== null) {
                $this->redirect('/tickets/' . $numbered['id']);
            }
        }

        $this->redirect('/tickets?' . http_build_query(['q' => $q]));
    }

    public function saveFilter(): void
    {
        Auth::require();

        $name = mb_substr($this->input('name'), 0, 80);
        $query = $this->cleanQuery($this->input('query'));

        if ($name === '') {
            $this->flash(__('A filter needs a name.'), 'danger');
            $this->redirect('/tickets?' . $query);
        }

        $id = (new SavedFilterRepository())->create((int) Auth::id(), $name, $query, isset($_POST['is_shared']));

        $this->flash(__('Saved as “{name}”. It is in the sidebar now.', ['name' => $name]));
        $this->redirect('/tickets?' . $query . ($query === '' ? '' : '&') . 'filter=' . $id);
    }

    public function deleteFilter(int $id): void
    {
        Auth::require();

        $filters = new SavedFilterRepository();
        $filter = $filters->find($id);

        if ($filter === null) {
            $this->notFound(__('There is no such filter.'));
        }

        if ((int) $filter['user_id'] !== (int) Auth::id() && !Auth::isAdmin()) {
            $this->forbidden(__('That filter is somebody else’s.'));
        }

        $filters->delete($id);

        $this->flash(__('Filter “{name}” deleted.', ['name' => $filter['name']]), 'warning');
        $this->redirect('/tickets');
    }

    /**
     * A list's query string, keeping only the parameters the list reads — so
     * a saved filter cannot carry anything else into the page it opens.
     */
    private function cleanQuery(string $query): string
    {
        parse_str($query, $parameters);
        $kept = array_intersect_key($parameters, array_flip(['q', 'project', 'status', 'assignee', 'type', 'label', 'due', 'open']));

        return http_build_query(array_filter($kept, static fn($value): bool => is_scalar($value) && $value !== ''));
    }
}
