<?php

declare(strict_types=1);

namespace App\Exceptions;

use Throwable;

class RefersionAuthenticationException extends RefersionApiException
{
    public function __construct(
        string $message = 'Refersion authentication failed.',
        ?string $reference = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 401, $reference, $previous);
    }
}
