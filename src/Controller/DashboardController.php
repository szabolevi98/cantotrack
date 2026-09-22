<?php

namespace CantoTrack\Controller;

use CantoTrack\Core\Auth;
use CantoTrack\Core\View;

/**
 * The page a signed-in person lands on.
 *
 * For now it only proves the way through the application works end to end:
 * the session, the layout, the stylesheet and the database connection. What it
 * will hold — the tickets assigned to you and the hours you logged this week —
 * needs the tables that come with the next step.
 */
class DashboardController
{
    public function index(): void
    {
        Auth::require();

        View::render('dashboard/index.twig');
    }
}
