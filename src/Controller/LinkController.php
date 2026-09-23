<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Controller;
use CantoTrack\Core\ValidationError;
use CantoTrack\Model\LinkRepository;
use CantoTrack\Service\LinkService;

/** Linking one ticket to another, and taking the link away again. */
class LinkController extends Controller
{
    public function create(int $ticketId): void
    {
        Auth::require();

        try {
            (new LinkService())->link($ticketId, $this->input('kind'), $this->input('key'), Auth::id());
        } catch (ValidationError $e) {
            $this->flash($e->getMessage(), 'danger');
        }

        $this->redirect('/tickets/' . $ticketId . '#links');
    }

    public function delete(int $id): void
    {
        Auth::require();

        $link = (new LinkRepository())->find($id);

        if ($link === null) {
            $this->notFound(__('There is no such link.'));
        }

        $from = (int) ($this->idInput('from') ?? $link['source_id']);
        (new LinkService())->unlink($link, $from, Auth::id());

        $this->redirect('/tickets/' . $from . '#links');
    }
}
