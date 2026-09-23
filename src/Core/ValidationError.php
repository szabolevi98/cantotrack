<?php

namespace CantoTrack\Core;

/**
 * What somebody sent does not make sense, and here is the sentence that says
 * why. Thrown by the services, caught by whoever called them: a form shows the
 * sentence above what was typed, the API answers it with a 422.
 */
class ValidationError extends \RuntimeException
{
}
