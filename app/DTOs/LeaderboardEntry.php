<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class LeaderboardEntry
{
    public function __construct(
        public int $rank,
        public int $affiliateId,
        public string $name,
        public string $email,
        public int $commissionCents,
        public string $currency,
    ) {}

    /**
     * @return array{
     *     rank: int,
     *     affiliateId: int,
     *     name: string,
     *     email: string,
     *     commissionCents: int,
     *     currency: string
     * }
     */
    public function toArray(): array
    {
        return [
            'rank' => $this->rank,
            'affiliateId' => $this->affiliateId,
            'name' => $this->name,
            'email' => $this->email,
            'commissionCents' => $this->commissionCents,
            'currency' => $this->currency,
        ];
    }
}
