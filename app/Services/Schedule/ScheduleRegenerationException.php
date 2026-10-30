<?php

namespace App\Services\Schedule;

use RuntimeException;

/** A refusal with a stable code the front end maps to an Armenian message. */
class ScheduleRegenerationException extends RuntimeException
{
    public function __construct(
        public readonly string $errorCode,
        string $message,
        public readonly int $status = 422,
        public readonly array $context = []
    ) {
        parent::__construct($message);
    }
}
