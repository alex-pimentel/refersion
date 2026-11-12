<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class CustomField
{
    public function __construct(
        public string $label,
        public string $value,
    ) {}
}
