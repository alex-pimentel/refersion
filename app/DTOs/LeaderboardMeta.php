<?php

declare(strict_types=1);

namespace App\DTOs;

final readonly class LeaderboardMeta
{
    public function __construct(
        public string $generatedAt,
        public bool $cached,
        public int $totalAffiliates,
        public int $scannedAffiliates,
        public int $eligibleCount,
        public int $customFieldRequests,
        public int $durationMs,
    ) {}

    public function withCached(bool $cached): self
    {
        return new self(
            generatedAt: $this->generatedAt,
            cached: $cached,
            totalAffiliates: $this->totalAffiliates,
            scannedAffiliates: $this->scannedAffiliates,
            eligibleCount: $this->eligibleCount,
            customFieldRequests: $this->customFieldRequests,
            durationMs: $this->durationMs,
        );
    }

    /**
     * @return array{
     *     generatedAt: string,
     *     cached: bool,
     *     totalAffiliates: int,
     *     scannedAffiliates: int,
     *     eligibleCount: int,
     *     customFieldRequests: int,
     *     durationMs: int
     * }
     */
    public function toArray(): array
    {
        return [
            'generatedAt' => $this->generatedAt,
            'cached' => $this->cached,
            'totalAffiliates' => $this->totalAffiliates,
            'scannedAffiliates' => $this->scannedAffiliates,
            'eligibleCount' => $this->eligibleCount,
            'customFieldRequests' => $this->customFieldRequests,
            'durationMs' => $this->durationMs,
        ];
    }
}
