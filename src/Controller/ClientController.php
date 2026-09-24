<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Controller;
use CantoTrack\Model\ClientRepository;
use CantoTrack\Model\StatementRepository;

/**
 * The clients, for the administrators: who each is on paper and who to talk
 * to, and on one page its projects, its hours this month and last, and its
 * statements. A client is still made the first time a project names one;
 * here it is filled in.
 */
class ClientController extends Controller
{
    public function index(): void
    {
        Auth::requireAdmin();

        $month = new \DateTimeImmutable('first day of this month');

        $this->render('clients/index.twig', [
            'clients' => (new ClientRepository())->overview($month->format('Y-m-d'), $month->format('Y-m-t')),
        ]);
    }

    public function create(): void
    {
        Auth::requireAdmin();

        $id = (new ClientRepository())->findOrCreate($this->input('name'));

        if ($id === null) {
            $this->flash(__('A client needs a name.'), 'danger');
            $this->redirect('/clients');
        }

        $this->redirect('/clients/' . $id);
    }

    public function show(int $id): void
    {
        Auth::requireAdmin();

        $clients = new ClientRepository();
        $client = $this->clientOr404($id);
        $thisMonth = new \DateTimeImmutable('first day of this month');
        $lastMonth = $thisMonth->modify('-1 month');

        $this->render('clients/show.twig', [
            'client' => $client,
            'projects' => $clients->projects($id),
            'this_month' => $clients->hours($id, $thisMonth->format('Y-m-d'), $thisMonth->format('Y-m-t')) + ['label' => $thisMonth->format('Y-m')],
            'last_month' => $clients->hours($id, $lastMonth->format('Y-m-d'), $lastMonth->format('Y-m-t')) + ['label' => $lastMonth->format('Y-m')],
            'statements' => (new StatementRepository())->all([$id]),
            'in_use' => $clients->inUse($id),
        ]);
    }

    public function update(int $id): void
    {
        Auth::requireAdmin();

        $clients = new ClientRepository();
        $this->clientOr404($id);

        $text = static fn(string $key, int $length): ?string => ($value = trim(str_replace("\r\n", "\n", (string) ($_POST[$key] ?? '')))) === '' ? null : mb_substr($value, 0, $length);
        $name = (string) $text('name', 120);
        $email = $text('contact_email', 190);

        if ($name === '') {
            $this->flash(__('A client needs a name.'), 'danger');
        } elseif ($clients->nameTaken($name, $id)) {
            $this->flash(__('There is already a client called {name}.', ['name' => $name]), 'danger');
        } elseif ($email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $this->flash(__('“{value}” is not an email address.', ['value' => $email]), 'danger');
        } else {
            $clients->update($id, [
                'name' => $name,
                'billing_address' => $text('billing_address', 1000),
                'tax_number' => $text('tax_number', 40),
                'contact_name' => $text('contact_name', 120),
                'contact_email' => $email,
                'note' => $text('note', 5000),
            ]);
            $this->flash(__('Saved. The next statement is addressed this way.'));
        }

        $this->redirect('/clients/' . $id);
    }

    /** Only one nothing names any more: no project, no statement. */
    public function delete(int $id): void
    {
        Auth::requireAdmin();

        $clients = new ClientRepository();
        $client = $this->clientOr404($id);

        if ($clients->inUse($id)) {
            $this->flash(__('{name} still has projects or statements, so it stays.', ['name' => $client['name']]), 'danger');
            $this->redirect('/clients/' . $id);
        }

        $clients->delete($id);

        $this->flash(__('{name} is deleted.', ['name' => $client['name']]), 'warning');
        $this->redirect('/clients');
    }

    private function clientOr404(int $id): array
    {
        $client = (new ClientRepository())->find($id);

        if ($client === null) {
            $this->notFound(__('There is no such client.'));
        }

        return $client;
    }
}
