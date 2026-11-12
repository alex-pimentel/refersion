<?php

declare(strict_types=1);

namespace App\Exceptions;

use Throwable;

class RefersionRateLimitException extends RefersionApiException
{
    public function __construct(
        string $message = 'Refersion rate limit exceeded.',
        public readonly int $retryAfter = 0,
        ?string $reference = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 429, $reference, $previous);
    }
}
