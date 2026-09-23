<?php

namespace CantoTrack\Core;

/**
 * Somebody else saved the same thing first. Carries what they saved, so the
 * person whose save was refused can see it next to their own.
 */
class ConflictError extends \RuntimeException
{
    public function __construct(string $message, public readonly array $current)
    {
        parent::__construct($message, 409);
    }
}
