<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Controller;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\AutomationRepository;
use CantoTrack\Model\CustomFieldRepository;
use CantoTrack\Model\ProjectRepository;
use CantoTrack\Service\Automation;
use CantoTrack\Service\TicketQuery;

/**
 * The automation rules, kept by the administrators: the list, a rule's form
 * with what it did lately, and a few to start from.
 */
class AutomationController extends Controller
{
    /** Rules to start from: a name, a trigger, a condition and the actions. */
    private const TEMPLATES = [
        'subtasks' => ['Done when its subtasks are', 'subtasks_done', 'category != done', [['status', 'done'], ['comment', 'All its subtasks are done, so this is too.']]],
        'urgent' => ['Urgent bugs get looked at', 'created', 'type = bug AND priority = urgent', [['add_label', 'triage'], ['sprint', 'active']]],
        'review' => ['Review goes back to the reporter', 'moved', 'category = "in progress" AND status = Review', [['assign', 'reporter']]],
        'overdue' => ['A nudge the day after it was due', 'daily', 'due = -1d AND category != done', [['comment', '{key} was due yesterday and is not finished. Is the date still right?']]],
        'started' => ['Whoever starts it, has it', 'moved', 'category = "in progress" AND assignee IS EMPTY', [['assign', 'actor']]],
    ];

    public function index(): void
    {
        Auth::requireAdmin();

        $this->render('automation/index.twig', [
            'rules' => (new AutomationRepository())->all(),
            'templates' => self::TEMPLATES,
        ]);
    }

    public function createForm(): void
    {
        Auth::requireAdmin();

        $template = self::TEMPLATES[(string) ($_GET['template'] ?? '')] ?? null;
        $values = $template === null ? [] : [
            'name' => __($template[0]), 'trigger' => $template[1], 'condition' => $template[2],
            'actions' => array_map(static fn(array $a): array => ['type' => $a[0], 'value' => $a[0] === 'comment' ? __($a[1]) : $a[1]], $template[3]),
        ];

        $this->form(null, $values);
    }

    public function create(): void
    {
        Auth::requireAdmin();

        $this->save(null);
    }

    public function editForm(int $id): void
    {
        Auth::requireAdmin();

        $rule = $this->ruleOr404($id);
        $this->form($rule, $rule);
    }

    public function update(int $id): void
    {
        Auth::requireAdmin();

        $this->save($this->ruleOr404($id));
    }

    public function toggle(int $id): void
    {
        Auth::requireAdmin();

        $rule = $this->ruleOr404($id);
        (new AutomationRepository())->setActive($id, (int) $rule['is_active'] !== 1);
        \CantoTrack\Service\AuditLog::record('rule_toggled', 'rule', $id, (string) $rule['name'], (int) $rule['is_active'] === 1 ? 'paused' : 'at work');

        $this->flash((int) $rule['is_active'] === 1 ? __('“{name}” is paused.', ['name' => $rule['name']]) : __('“{name}” is at work again.', ['name' => $rule['name']]));
        $this->back('/settings/automation');
    }

    public function delete(int $id): void
    {
        Auth::requireAdmin();

        $rule = $this->ruleOr404($id);
        (new AutomationRepository())->delete($id);
        \CantoTrack\Service\AuditLog::record('rule_deleted', 'rule', $id, (string) $rule['name']);

        $this->flash(__('The rule “{name}” is gone.', ['name' => $rule['name']]), 'warning');
        $this->redirect('/settings/automation');
    }

    private function save(?array $rule): void
    {
        $name = mb_substr($this->input('name'), 0, 120);
        $trigger = $this->input('trigger');
        $condition = mb_substr($this->input('condition'), 0, 1000);
        $projectId = $this->idInput('project_id');
        $actions = [];

        foreach ((array) ($_POST['actions'] ?? []) as $action) {
            $type = is_array($action) ? (string) ($action['type'] ?? '') : '';
            $value = is_array($action) ? trim((string) ($action['value'] ?? '')) : '';

            if ($type !== '' && in_array($type, Automation::ACTIONS, true)) {
                $actions[] = ['type' => $type, 'value' => mb_substr($value, 0, 2000)];
            }
        }

        $values = ['name' => $name, 'trigger' => $trigger, 'condition' => $condition, 'project_id' => $projectId, 'actions' => $actions];

        try {
            if ($name === '') {
                throw new ValidationError(__('A rule needs a name.'));
            }
            if (!in_array($trigger, Automation::TRIGGERS, true)) {
                throw new ValidationError(__('Pick what sets the rule off.'));
            }
            if ($actions === []) {
                throw new ValidationError(__('A rule needs something to do.'));
            }
            if ($projectId !== null && (new ProjectRepository())->find($projectId) === null) {
                throw new ValidationError(__('There is no such project.'));
            }
            // A condition that cannot be read is refused now, not found out
            // at three in the morning.
            if ($condition !== '') {
                TicketQuery::compile($condition, Auth::id(), null, (new CustomFieldRepository())->kindsByName());
            }
        } catch (ValidationError $e) {
            $this->form($rule, $values, $e->getMessage());

            return;
        }

        $id = (new AutomationRepository())->save($rule === null ? null : (int) $rule['id'], $projectId, $name, $trigger, $condition, $actions, Auth::id());
        \CantoTrack\Service\AuditLog::record($rule === null ? 'rule_created' : 'rule_updated', 'rule', $id, $name, $trigger . ($condition !== '' ? ' · ' . $condition : ''));

        $this->flash($rule === null ? __('The rule is at work.') : __('Rule saved.'));
        $this->redirect('/settings/automation/' . $id);
    }

    /** @param array<string, mixed> $values */
    private function form(?array $rule, array $values, ?string $error = null): void
    {
        $this->render('automation/form.twig', [
            'rule' => $rule,
            'values' => $values,
            'error' => $error,
            'projects' => (new ProjectRepository())->allWithCounts(),
            'triggers' => Automation::TRIGGERS,
            'actions' => Automation::ACTIONS,
            'log' => $rule === null ? [] : (new AutomationRepository())->recentLog((int) $rule['id']),
        ], $error === null ? 200 : 422);
    }

    private function ruleOr404(int $id): array
    {
        $rule = (new AutomationRepository())->find($id);

        if ($rule === null) {
            $this->notFound(__('There is no such rule.'));
        }

        return $rule;
    }
}
