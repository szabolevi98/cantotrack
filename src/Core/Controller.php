<?php

namespace CantoTrack\Core;

/**
 * What every controller does the same way: draw a page, redirect after a post,
 * refuse a request, and read what a form sent.
 *
 * Each controller used to carry its own copy of `redirect()` and of a
 * `somethingOr404()`, and the copies had started to differ — one checked the
 * "back" address it was given, the next did not. One copy is one behaviour.
 */
abstract class Controller
{
    protected function render(string $template, array $context = [], int $status = 200): void
    {
        http_response_code($status);
        View::render($template, $context);
    }

    /** A path inside the application, never a full URL. */
    protected function redirect(string $path): never
    {
        header('Location: ' . rtrim((string) Config::get('app.base_url'), '/') . '/' . ltrim($path, '/'));
        exit;
    }

    /**
     * Back to where a form said it came from, if that is a path of ours.
     *
     * "Back" comes from the form, which means from the browser, which means it
     * can say anything. A value starting with two slashes is a URL on another
     * host, and redirecting to it after a post is how a phishing page gets a
     * link that starts with this application's address.
     */
    protected function back(string $fallback): never
    {
        $given = (string) ($_POST['back'] ?? $_GET['back'] ?? '');

        $this->redirect(self::isLocalPath($given) ? $given : $fallback);
    }

    public static function isLocalPath(string $path): bool
    {
        return str_starts_with($path, '/') && !str_starts_with($path, '//') && !str_contains($path, '\\');
    }

    protected function notFound(string $message): never
    {
        throw HttpError::notFound($message);
    }

    protected function forbidden(string $message): never
    {
        throw HttpError::forbidden($message);
    }

    /** @param array<string, mixed> $data */
    protected function json(array $data, int $status = 200): never
    {
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        header('Cache-Control: no-store');
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        exit;
    }

    /** A trimmed string from the form. */
    protected function input(string $key, string $default = ''): string
    {
        $value = $_POST[$key] ?? $default;

        return is_string($value) ? trim($value) : $default;
    }

    /** A positive id from the form, or null for "none". */
    protected function idInput(string $key): ?int
    {
        $value = $_POST[$key] ?? null;

        return is_scalar($value) && ctype_digit((string) $value) && (int) $value > 0 ? (int) $value : null;
    }

    /** A positive id from the query string, or null. */
    protected function idQuery(string $key): ?int
    {
        $value = $_GET[$key] ?? null;

        return is_scalar($value) && ctype_digit((string) $value) && (int) $value > 0 ? (int) $value : null;
    }

    protected function flash(string $message, string $kind = 'success'): void
    {
        Session::flash($message, $kind);
    }
}
