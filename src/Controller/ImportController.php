<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Controller;
use CantoTrack\Core\Session;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\ProjectRepository;
use CantoTrack\Service\TicketImport;

/**
 * Tickets from a CSV, in three steps: the file, a look at what would be made
 * of it, and the go-ahead.
 *
 * Between the first two steps the rows wait in a file under var/imports —
 * not in the session, which a two-thousand-row spreadsheet would bloat on
 * every request after — named by a random key the session holds.
 */
class ImportController extends Controller
{
    private const KEY = '_import';

    public function form(int $projectId): void
    {
        Auth::requireMember();

        $this->render('projects/import.twig', ['project' => $this->projectOr404($projectId), 'preview' => null]);
    }

    public function upload(int $projectId): void
    {
        Auth::requireMember();

        $this->projectOr404($projectId);
        $file = $_FILES['file'] ?? null;

        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) $file['tmp_name'])) {
            $this->flash(__('Choose a CSV file first.'), 'danger');
            $this->redirect('/projects/' . $projectId . '/import');
        }

        try {
            $rows = TicketImport::read((string) $file['tmp_name']);
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
            $this->redirect('/projects/' . $projectId . '/import');
        }

        if (!in_array('title', TicketImport::columns($rows[0]), true)) {
            $this->flash(__('None of the columns is a title. Name one “Title” (or “Summary”, “Cím”).'), 'danger');
            $this->redirect('/projects/' . $projectId . '/import');
        }

        $key = bin2hex(random_bytes(16));
        self::directory(true);
        file_put_contents(self::path($key), json_encode($rows, JSON_UNESCAPED_UNICODE));
        $this->forget();
        Session::put(self::KEY, ['key' => $key, 'project' => $projectId, 'name' => (string) $file['name']]);

        $this->redirect('/projects/' . $projectId . '/import/preview');
    }

    public function preview(int $projectId): void
    {
        Auth::requireMember();

        $project = $this->projectOr404($projectId);
        $rows = $this->pending($projectId);

        $import = new TicketImport();
        $prepared = $import->prepare($projectId, $rows);
        $columns = $import->projectColumns($projectId, $rows[0]);
        $fields = array_values($columns);
        $headings = [];
        foreach ((new \CantoTrack\Model\CustomFieldRepository())->forProject($projectId) as $field) {
            $headings['field:' . $field['id']] = (string) $field['name'];
        }

        $this->render('projects/import.twig', [
            'project' => $project,
            'preview' => [
                'name' => Session::get(self::KEY)['name'] ?? '',
                'fields' => $fields,
                'ignored' => array_values(array_diff_key($rows[0], $columns)),
                'headings' => $headings,
                'rows' => $prepared,
                'good' => count(array_filter($prepared, static fn(array $row): bool => $row['problems'] === [])),
            ],
        ]);
    }

    public function confirm(int $projectId): void
    {
        Auth::requireMember();

        $this->projectOr404($projectId);
        $import = new TicketImport();
        $result = $import->import($import->prepare($projectId, $this->pending($projectId)), (int) Auth::id());
        $this->forget();

        $this->flash(__n('{count} ticket imported.', '{count} tickets imported.', count($result['created'])));

        foreach ($result['failed'] as $line => $why) {
            $this->flash(__('Line {line}: {why}', ['line' => $line, 'why' => $why]), 'danger');
        }

        $this->redirect('/projects/' . $projectId);
    }

    public function cancel(int $projectId): void
    {
        Auth::requireMember();

        $this->forget();
        $this->redirect('/projects/' . $projectId . '/import');
    }

    /** @return list<list<string>> */
    private function pending(int $projectId): array
    {
        $pending = Session::get(self::KEY);
        $key = is_array($pending) ? (string) ($pending['key'] ?? '') : '';

        if ($key === '' || (int) ($pending['project'] ?? 0) !== $projectId || !is_file(self::path($key))) {
            $this->flash(__('Upload the file again: the one from before is gone.'), 'warning');
            $this->redirect('/projects/' . $projectId . '/import');
        }

        $rows = json_decode((string) file_get_contents(self::path($key)), true);

        return is_array($rows) ? array_values($rows) : [];
    }

    private function forget(): void
    {
        $pending = Session::get(self::KEY);

        if (is_array($pending) && ctype_xdigit((string) ($pending['key'] ?? ''))) {
            @unlink(self::path((string) $pending['key']));
        }

        Session::forget(self::KEY);

        // Anything left behind by an import nobody finished, a day on.
        foreach (glob(self::directory() . '/*.json') ?: [] as $old) {
            if (filemtime($old) < time() - 86400) {
                @unlink($old);
            }
        }
    }

    private static function directory(bool $make = false): string
    {
        $dir = dirname(__DIR__, 2) . '/var/imports';

        if ($make && !is_dir($dir)) {
            mkdir($dir, 0770, true);
        }

        return $dir;
    }

    private static function path(string $key): string
    {
        return self::directory() . '/' . preg_replace('/[^0-9a-f]/', '', $key) . '.json';
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
