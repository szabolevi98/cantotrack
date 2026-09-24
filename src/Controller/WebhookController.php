<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Controller;
use CantoTrack\Core\OutboundUrl;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\ProjectRepository;
use CantoTrack\Model\SettingRepository;
use CantoTrack\Model\WebhookRepository;
use CantoTrack\Service\Webhooks;

/**
 * Webhooks and the GitHub integration, for administrators: where messages
 * go, what each was sent and what came back.
 */
class WebhookController extends Controller
{
    public function index(): void
    {
        Auth::requireAdmin();

        $this->render('settings/webhooks.twig', [
            'hooks' => (new WebhookRepository())->all(),
            'projects' => (new ProjectRepository())->allWithCounts(),
            'events' => WebhookRepository::EVENTS,
            'github_secret' => (new SettingRepository())->get(IntegrationController::GITHUB_SECRET),
            'github_url' => \CantoTrack\Service\Presenter::url('/integrations/github'),
        ]);
    }

    public function show(int $id): void
    {
        Auth::requireAdmin();

        $hooks = new WebhookRepository();
        $hook = $this->hookOr404($id);

        $this->render('settings/webhook.twig', [
            'hook' => $hook,
            'deliveries' => $hooks->deliveries($id),
            'projects' => (new ProjectRepository())->allWithCounts(),
            'events' => WebhookRepository::EVENTS,
            'chosen' => array_map('trim', explode(',', (string) $hook['events'])),
        ]);
    }

    public function create(): void
    {
        Auth::requireAdmin();

        try {
            [$name, $url, $events, $projectId] = $this->validated();
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
            $this->redirect('/settings/webhooks');
        }

        $id = (new WebhookRepository())->create($name, $url, $events, $projectId, Auth::id());
        \CantoTrack\Service\AuditLog::record('webhook_created', 'webhook', $id, $name, $url);

        $this->flash(__('Webhook added. Its secret is below, for the receiving end to check the signatures with.'));
        $this->redirect('/settings/webhooks/' . $id);
    }

    public function update(int $id): void
    {
        Auth::requireAdmin();

        $this->hookOr404($id);

        try {
            [$name, $url, $events, $projectId] = $this->validated();
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
            $this->redirect('/settings/webhooks/' . $id);
        }

        (new WebhookRepository())->update($id, $name, $url, $events, $projectId, isset($_POST['is_active']));
        \CantoTrack\Service\AuditLog::record('webhook_updated', 'webhook', $id, $name, $url);

        $this->flash(__('Saved.'));
        $this->redirect('/settings/webhooks/' . $id);
    }

    public function delete(int $id): void
    {
        Auth::requireAdmin();

        $hook = $this->hookOr404($id);
        (new WebhookRepository())->delete($id);
        \CantoTrack\Service\AuditLog::record('webhook_deleted', 'webhook', $id, (string) ($hook['name'] ?? '#' . $id));

        $this->flash(__('Webhook deleted, with its log.'), 'warning');
        $this->redirect('/settings/webhooks');
    }

    public function newSecret(int $id): void
    {
        Auth::requireAdmin();

        $this->hookOr404($id);
        (new WebhookRepository())->newSecret($id);

        $this->flash(__('A new secret: messages are signed with it from now on.'), 'warning');
        $this->redirect('/settings/webhooks/' . $id);
    }

    public function ping(int $id): void
    {
        Auth::requireAdmin();

        $hook = $this->hookOr404($id);
        $delivery = (new WebhookRepository())->delivery((new Webhooks())->ping($hook, (array) Auth::user()));

        $this->flash(
            ($delivery['state'] ?? '') === 'delivered'
                ? __('Ping delivered: {status}.', ['status' => $delivery['response_status']])
                : __('The ping did not get through: {why}', ['why' => $delivery['error'] ?? ('HTTP ' . ($delivery['response_status'] ?? '?'))]),
            ($delivery['state'] ?? '') === 'delivered' ? 'success' : 'danger'
        );
        $this->redirect('/settings/webhooks/' . $id);
    }

    public function redeliver(int $id, int $deliveryId): void
    {
        Auth::requireAdmin();

        $hooks = new WebhookRepository();
        $delivery = $hooks->delivery($deliveryId);

        if ($delivery === null || (int) $delivery['webhook_id'] !== $id) {
            $this->notFound(__('There is no such message.'));
        }

        $hooks->retry($deliveryId);
        (new Webhooks())->send([$deliveryId]);

        $after = $hooks->delivery($deliveryId);
        $this->flash(
            ($after['state'] ?? '') === 'delivered' ? __('Delivered.') : __('Still not delivered; it will be tried again.'),
            ($after['state'] ?? '') === 'delivered' ? 'success' : 'warning'
        );
        $this->redirect('/settings/webhooks/' . $id);
    }

    /** Makes (or replaces) the secret GitHub signs its pushes with. */
    public function githubSecret(): void
    {
        Auth::requireAdmin();

        $settings = new SettingRepository();

        if (isset($_POST['off'])) {
            $settings->set(IntegrationController::GITHUB_SECRET, null);
            $this->flash(__('The GitHub integration is off. Pushes are refused until a new secret is made.'), 'warning');
        } else {
            $settings->set(IntegrationController::GITHUB_SECRET, bin2hex(random_bytes(24)));
            $this->flash(__('A new GitHub secret. Put it into the repository’s webhook settings.'));
        }

        $this->redirect('/settings/webhooks');
    }

    /**
     * @return array{0: string, 1: string, 2: list<string>, 3: ?int}
     * @throws ValidationError
     */
    private function validated(): array
    {
        $name = $this->input('name');
        $url = $this->input('url');
        $events = is_array($_POST['events'] ?? null) ? array_values(array_map('strval', $_POST['events'])) : [];
        $projectId = $this->idInput('project_id');

        if ($name === '') {
            throw new ValidationError(__('A webhook needs a name.'));
        }

        if (mb_strlen($url) > 500) {
            throw new ValidationError(__('That address is too long.'));
        }

        OutboundUrl::check($url);

        if ($projectId !== null && (new ProjectRepository())->find($projectId) === null) {
            throw new ValidationError(__('There is no such project.'));
        }

        return [$name, $url, $events, $projectId];
    }

    private function hookOr404(int $id): array
    {
        $hook = (new WebhookRepository())->find($id);

        if ($hook === null) {
            $this->notFound(__('There is no such webhook.'));
        }

        return $hook;
    }
}
