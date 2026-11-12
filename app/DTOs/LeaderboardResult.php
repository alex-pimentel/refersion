<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class LeaderboardResult
{
    /**
     * @param  LeaderboardEntry[]  $entries
     */
    public function __construct(
        public array $entries,
        public LeaderboardMeta $meta,
    ) {}

    public function withCached(bool $cached): self
    {
        return new self($this->entries, $this->meta->withCached($cached));
    }

    /**
     * @return array{
     *     data: array<int, array{
     *         rank: int,
     *         affiliateId: int,
     *         name: string,
     *         email: string,
     *         commissionCents: int,
     *         currency: string
     *     }>,
     *     meta: array{
     *         generatedAt: string,
     *         cached: bool,
     *         totalAffiliates: int,
     *         scannedAffiliates: int,
     *         eligibleCount: int,
     *         customFieldRequests: int,
     *         durationMs: int
     *     }
     * }
     */
    public function toArray(): array
    {
        return [
            'data' => array_map(
                fn (LeaderboardEntry $entry): array => $entry->toArray(),
                $this->entries,
            ),
            'meta' => $this->meta->toArray(),
        ];
    }
}
