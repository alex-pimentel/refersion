<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class AffiliateSummary
{
    public function __construct(
        public int $id,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $company,
        public ?string $email,
        public int $commissionCents,
        public string $currency,
    ) {}

    public function displayName(): string
    {
        $name = trim(($this->firstName ?? '').' '.($this->lastName ?? ''));

        if ($name !== '') {
            return $name;
        }

        $company = trim($this->company ?? '');

        if ($company !== '') {
            return $company;
        }

        $email = trim($this->email ?? '');

        if ($email !== '') {
            $localPart = strstr($email, '@', true);

            if (is_string($localPart) && $localPart !== '') {
                return $localPart;
            }

            return $email;
        }

        return "Affiliate #{$this->id}";
    }

    public function displayEmail(): string
    {
        return trim($this->email ?? '');
    }
}
