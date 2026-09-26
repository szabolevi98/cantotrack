<?php

namespace CantoTrack\Core;

/**
 * A request that ends in an HTTP error rather than a page: no such ticket, not
 * yours to change, not allowed at all.
 *
 * Thrown rather than answered on the spot, so that every one of them is drawn
 * by the same error page in the front controller. Before this, each controller
 * ended with its own `exit('There is no such ticket.')`, and a 404 was a line of
 * unstyled text on an otherwise empty white page.
 */
class HttpError extends \RuntimeException
{
    /**
     * @param array<string, mixed> $details said beside the message in the
     *     API's answer, for a client to act on (two_factor_required)
     */
    public function __construct(public readonly int $status, string $message, public readonly array $details = [])
    {
        parent::__construct($message, $status);
    }

    public static function notFound(string $message): self
    {
        return new self(404, $message);
    }

    public static function forbidden(string $message): self
    {
        return new self(403, $message);
    }
}
