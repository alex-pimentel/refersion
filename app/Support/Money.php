<?php

declare(strict_types=1);

namespace App\Support;

final readonly class Money
{
    public function __construct(
        public int $minorUnits,
        public string $currency = 'USD',
    ) {}

    public static function fromApiValue(
        int|float|string|null $value,
        string $unit = 'cents',
        string $currency = 'USD',
    ): self {
        if ($value === null) {
            return new self(0, $currency);
        }

        $normalized = is_string($value) ? trim($value) : $value;

        if ($normalized === '') {
            return new self(0, $currency);
        }

        $isDecimal = is_float($normalized)
            || (is_string($normalized) && str_contains($normalized, '.'));

        if ($unit === 'major' || $isDecimal) {
            return new self((int) round((float) $normalized * 100), $currency);
        }

        return new self((int) $normalized, $currency);
    }

    public function toMajor(): float
    {
        return $this->minorUnits / 100;
    }
}
