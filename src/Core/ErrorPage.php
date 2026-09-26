<?php

namespace CantoTrack\Core;

/**
 * Draws the answer to a request that went wrong.
 *
 * The page is a template like any other — but drawing it can itself fail (the
 * database may be what broke, and the layout asks it who is signed in), so
 * there is a plain-text answer underneath that needs nothing at all.
 */
class ErrorPage
{
    private const TITLES = [
        400 => 'That request did not make sense',
        401 => 'Sign in first',
        403 => 'Not yours to open',
        404 => 'Nothing here',
        405 => 'Not like that',
        409 => 'Somebody got there first',
        413 => 'That is too big',
        422 => 'That did not look right',
        429 => 'Too many tries',
        500 => 'Something went wrong',
    ];

    /** @param array<string, mixed> $details only for the API: see HttpError */
    public static function render(int $status, string $message, array $details = []): void
    {
        if (!headers_sent()) {
            http_response_code($status);
        }

        // The API answers in JSON, whatever went wrong: a client that asked for
        // data and got an HTML page back has to guess what the page said.
        if (str_starts_with(Router::normalise($_SERVER['REQUEST_URI'] ?? '/'), '/api/')) {
            if (!headers_sent()) {
                header('Content-Type: application/json; charset=utf-8');
            }

            echo json_encode(['error' => ['status' => $status, 'message' => $message] + ($details === [] ? [] : ['details' => $details])], JSON_UNESCAPED_UNICODE);

            return;
        }

        try {
            echo View::twig()->render('errors/error.twig', [
                'status' => $status,
                'title' => __(self::TITLES[$status] ?? self::TITLES[500]),
                'message' => $message,
            ]);
        } catch (\Throwable $e) {
            Logger::error('The error page could not be drawn: ' . $e->getMessage());

            if (!headers_sent()) {
                header('Content-Type: text/plain; charset=utf-8');
            }

            echo $status . ' — ' . $message;
        }
    }
}
