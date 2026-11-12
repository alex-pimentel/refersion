<?php

declare(strict_types=1);

namespace App\Exceptions;

use Throwable;

class RefersionScanLimitException extends RefersionApiException
{
    public function __construct(
        string $message = 'The affiliate scan limit was reached before five eligible affiliates were found.',
        ?string $reference = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, null, $reference, $previous);
    }
}
