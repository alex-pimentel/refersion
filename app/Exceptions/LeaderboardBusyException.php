<?php

declare(strict_types=1);

namespace App\Exceptions;

use Illuminate\Support\Str;
use RuntimeException;

class LeaderboardBusyException extends RuntimeException
{
    public readonly string $reference;

    public function __construct(
        string $message = 'A leaderboard refresh is already in progress.',
        ?string $reference = null,
    ) {
        $this->reference = $reference ?? Str::random(8);

        parent::__construct($message);
    }
}
