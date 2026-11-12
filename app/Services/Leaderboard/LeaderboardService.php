<?php

declare(strict_types=1);

namespace App\Services\Leaderboard;

use App\DTOs\AffiliateSummary;
use App\DTOs\LeaderboardEntry;
use App\DTOs\LeaderboardMeta;
use App\DTOs\LeaderboardResult;
use App\Exceptions\LeaderboardBusyException;
use App\Exceptions\RefersionScanLimitException;
use App\Services\Refersion\RefersionClient;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;

class LeaderboardService
{
    private readonly LeaderboardEligibility $eligibility;

    public function __construct(
        private readonly RefersionClient $client,
        ?LeaderboardEligibility $eligibility = null,
    ) {
        $this->eligibility = $eligibility ?? new LeaderboardEligibility;
    }

    public function get(): LeaderboardResult
    {
        if ($cached = $this->cached()) {
            return $cached;
        }

        return $this->withLock(function (): LeaderboardResult {
            if ($cached = $this->cached()) {
                return $cached;
            }

            return $this->rebuild();
        }, true);
    }

    public function refresh(): LeaderboardResult
    {
        return $this->withLock(fn (): LeaderboardResult => $this->rebuild(), false);
    }

    public function cached(): ?LeaderboardResult
    {
        $cached = Cache::get($this->cacheKey());

        return $cached instanceof LeaderboardResult ? $cached->withCached(true) : null;
    }

    public function lockTtl(): int
    {
        return max(300, (int) config('services.refersion.leaderboard_lock_ttl', 600));
    }

    /**
     * @param  callable(): LeaderboardResult  $callback
     */
    private function withLock(callable $callback, bool $allowCachedFallback): LeaderboardResult
    {
        try {
            return Cache::lock($this->lockKey(), $this->lockTtl())->block(10, $callback);
        } catch (LockTimeoutException) {
            if ($allowCachedFallback && ($cached = $this->cached())) {
                return $cached;
            }

            throw new LeaderboardBusyException;
        }
    }

    private function rebuild(): LeaderboardResult
    {
        $result = $this->build();
        Cache::put($this->cacheKey(), $result, $this->ttl());

        return $result;
    }

    private function ttl(): int
    {
        return max(60, (int) config('services.refersion.leaderboard_cache_ttl', 300));
    }

    private function build(): LeaderboardResult
    {
        $startedAt = microtime(true);

        $affiliates = $this->client->listAllAffiliates();

        usort($affiliates, function (AffiliateSummary $left, AffiliateSummary $right): int {
            return [$right->commissionCents, $left->id] <=> [$left->commissionCents, $right->id];
        });

        $limit = max(1, (int) config('services.refersion.leaderboard_size', 5));
        $chunkSize = max(1, (int) config('services.refersion.custom_fields_chunk', 10));
        $maxCandidates = max(1, (int) config('services.refersion.max_candidates', 1000));

        $entries = [];
        $scanned = 0;
        $customFieldRequests = 0;

        foreach (array_chunk($affiliates, $chunkSize) as $chunk) {
            $remaining = $maxCandidates - $scanned;

            if ($remaining <= 0) {
                break;
            }

            $chunk = array_slice($chunk, 0, $remaining);
            $fieldsByAffiliate = $this->client->getCustomFieldsForMany(
                array_map(fn (AffiliateSummary $affiliate): int => $affiliate->id, $chunk),
            );
            $customFieldRequests += count($chunk);

            foreach ($chunk as $affiliate) {
                $scanned++;

                if (! $this->eligibility->isEligible($fieldsByAffiliate[$affiliate->id] ?? [])) {
                    continue;
                }

                $entries[] = new LeaderboardEntry(
                    rank: count($entries) + 1,
                    affiliateId: $affiliate->id,
                    name: $affiliate->displayName(),
                    email: $affiliate->displayEmail(),
                    commissionCents: $affiliate->commissionCents,
                    currency: $affiliate->currency,
                );

                if (count($entries) >= $limit) {
                    break 2;
                }
            }
        }

        if (
            count($entries) < $limit
            && $scanned < count($affiliates)
            && $scanned >= $maxCandidates
        ) {
            throw new RefersionScanLimitException;
        }

        $meta = new LeaderboardMeta(
            generatedAt: now()->toIso8601String(),
            cached: false,
            totalAffiliates: count($affiliates),
            scannedAffiliates: $scanned,
            eligibleCount: count($entries),
            customFieldRequests: $customFieldRequests,
            durationMs: max(0, (int) round((microtime(true) - $startedAt) * 1000)),
        );

        return new LeaderboardResult($entries, $meta);
    }

    private function cacheKey(): string
    {
        return 'refersion.leaderboard.'.sha1((string) config('services.refersion.client_id'));
    }

    private function lockKey(): string
    {
        return 'refersion.leaderboard.lock.'.sha1((string) config('services.refersion.client_id'));
    }
}
