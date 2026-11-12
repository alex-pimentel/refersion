<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\DTOs\LeaderboardEntry;
use App\DTOs\LeaderboardMeta;
use App\DTOs\LeaderboardResult;
use App\Exceptions\RefersionApiException;
use App\Services\Leaderboard\LeaderboardService;
use Inertia\Testing\AssertableInertia;
use Mockery\MockInterface;
use Tests\TestCase;

class LeaderboardRefreshTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_refresh_forces_a_new_build(): void
    {
        $this->mock(LeaderboardService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('refresh')
                ->once()
                ->andReturn($this->leaderboardResult());
        });

        $this->post('/leaderboard/refresh')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $page->component('leaderboard')
                    ->has('leaderboard.data', 1)
                    ->where('leaderboard.data.0.name', 'Avery Cole')
                    ->where('leaderboard.error', null)
                    ->where('leaderboard.meta.cached', false);
            });
    }

    public function test_refresh_failure_returns_the_cached_result_with_a_stale_error(): void
    {
        $this->mock(LeaderboardService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('refresh')
                ->once()
                ->andThrow(new RefersionApiException('upstream failure'));
            $mock->shouldReceive('cached')
                ->once()
                ->andReturn($this->leaderboardResult()->withCached(true));
        });

        $this->post('/leaderboard/refresh')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $page->component('leaderboard')
                    ->has('leaderboard.data', 1)
                    ->where('leaderboard.data.0.name', 'Avery Cole')
                    ->where('leaderboard.meta.cached', true)
                    ->where('leaderboard.error.message', 'We could not load the leaderboard right now. Please try again in a moment.');
            });
    }

    public function test_refresh_is_throttled_after_ten_requests(): void
    {
        $this->mock(LeaderboardService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('refresh')
                ->times(10)
                ->andReturn($this->leaderboardResult());
        });

        for ($request = 1; $request <= 10; $request++) {
            $this->post('/leaderboard/refresh')->assertOk();
        }

        $this->post('/leaderboard/refresh')->assertStatus(429);
    }

    private function leaderboardResult(): LeaderboardResult
    {
        return new LeaderboardResult(
            [
                new LeaderboardEntry(
                    rank: 1,
                    affiliateId: 8572722,
                    name: 'Avery Cole',
                    email: 'avery@example.com',
                    commissionCents: 702513,
                    currency: 'USD',
                ),
            ],
            new LeaderboardMeta(
                generatedAt: '2026-11-12T20:00:00+00:00',
                cached: false,
                totalAffiliates: 44,
                scannedAffiliates: 13,
                eligibleCount: 1,
                customFieldRequests: 20,
                durationMs: 120,
            ),
        );
    }
}
