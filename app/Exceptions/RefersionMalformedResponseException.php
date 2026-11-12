<?php

declare(strict_types=1);

namespace App\Exceptions;

use Throwable;

class RefersionMalformedResponseException extends RefersionApiException
{
    public function __construct(
        string $message = 'Refersion returned an unexpected response.',
        ?string $reference = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, null, $reference, $previous);
    }
}
