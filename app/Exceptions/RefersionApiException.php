<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class RefersionApiException extends RuntimeException
{
    public readonly string $reference;

    public function __construct(
        string $message = 'Refersion API request failed.',
        public readonly ?int $status = null,
        ?string $reference = null,
        ?Throwable $previous = null,
    ) {
        $this->reference = $reference ?? Str::random(8);

        parent::__construct($message, 0, $previous);
    }
}
