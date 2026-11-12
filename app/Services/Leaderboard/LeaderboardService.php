<?php

declare(strict_types=1);

namespace App\Services\Leaderboard;

use App\DTOs\AffiliateSummary;
use App\DTOs\CustomField;
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
    public function __construct(
        private readonly RefersionClient $client,
    ) {}

    public function get(bool $forceRefresh = false): LeaderboardResult
    {
        $cacheKey = $this->cacheKey();
        $ttl = max(60, (int) config('services.refersion.leaderboard_cache_ttl', 300));

        if (! $forceRefresh) {
            $cached = Cache::get($cacheKey);

            if ($cached instanceof LeaderboardResult) {
                return $cached->withCached(true);
            }
        }

        return $this->withLock($cacheKey, $forceRefresh, function () use ($cacheKey, $ttl): LeaderboardResult {
            $result = $this->build();
            Cache::put($cacheKey, $result, $ttl);

            return $result;
        });
    }

    /**
     * @param  callable(): LeaderboardResult  $callback
     */
    private function withLock(
        string $cacheKey,
        bool $forceRefresh,
        callable $callback,
    ): LeaderboardResult {
        try {
            return Cache::lock($this->lockKey(), 30)->block(10, function () use ($cacheKey, $forceRefresh, $callback): LeaderboardResult {
                if (! $forceRefresh) {
                    $cached = Cache::get($cacheKey);

                    if ($cached instanceof LeaderboardResult) {
                        return $cached->withCached(true);
                    }
                }

                return $callback();
            });
        } catch (LockTimeoutException) {
            if (! $forceRefresh) {
                $cached = Cache::get($cacheKey);

                if ($cached instanceof LeaderboardResult) {
                    return $cached->withCached(true);
                }
            }

            throw new LeaderboardBusyException;
        }
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

                if (! $this->isEligible($fieldsByAffiliate[$affiliate->id] ?? [])) {
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

    /**
     * @param  CustomField[]  $fields
     */
    private function isEligible(array $fields): bool
    {
        foreach ($fields as $field) {
            if ($field->label === 'Display in leaderboard' && $field->value === 'TRUE') {
                return true;
            }
        }

        return false;
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
