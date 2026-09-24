<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\Controller;
use CantoTrack\Model\AuditRepository;
use CantoTrack\Service\AuditLog;

/** The record of who did what, for the administrators — see AuditLog. */
class AuditController extends Controller
{
    private const PER_PAGE = 100;

    public function index(): void
    {
        Auth::requireAdmin();

        $filters = [
            'user_id' => ctype_digit((string) ($_GET['person'] ?? '')) ? (int) $_GET['person'] : null,
            'group' => isset(AuditLog::GROUPS[(string) ($_GET['group'] ?? '')]) ? (string) $_GET['group'] : '',
            'q' => trim((string) ($_GET['q'] ?? '')),
        ];
        $page = max(1, (int) ($_GET['page'] ?? 1));
        $audit = new AuditRepository();
        $total = $audit->count($filters);

        $this->render('settings/audit.twig', [
            'entries' => $audit->page($filters, self::PER_PAGE, ($page - 1) * self::PER_PAGE),
            'total' => $total,
            'page' => $page,
            'pages' => max(1, (int) ceil($total / self::PER_PAGE)),
            'filters' => $filters,
            'people' => $audit->people(),
            'groups' => array_keys(AuditLog::GROUPS),
            'query' => http_build_query(array_filter(['person' => $filters['user_id'], 'group' => $filters['group'], 'q' => $filters['q']])),
        ]);
    }
}
