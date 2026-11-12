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

class LeaderboardPageTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }

    public function test_page_returns_the_leaderboard_component_with_deferred_data(): void
    {
        $this->mock(LeaderboardService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('get')->once()->andReturn($this->leaderboardResult());
        });

        $this->get('/')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $page->component('leaderboard')
                    ->loadDeferredProps(function (AssertableInertia $page): void {
                        $page->has('leaderboard.data', 1)
                            ->where('leaderboard.data.0.name', 'Lily Kertzmann')
                            ->where('leaderboard.data.0.commissionCents', 702513)
                            ->where('leaderboard.error', null)
                            ->where('leaderboard.meta.cached', false);
                    });
            });
    }

    public function test_page_renders_a_friendly_error_payload_when_the_service_fails(): void
    {
        $this->mock(LeaderboardService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('get')->once()->andThrow(new RefersionApiException('upstream failure'));
        });

        $this->get('/')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $page->component('leaderboard')
                    ->loadDeferredProps(function (AssertableInertia $page): void {
                        $page->has('leaderboard.data', 0)
                            ->where('leaderboard.meta', null)
                            ->where('leaderboard.error.message', 'We could not load the leaderboard right now. Please try again in a moment.')
                            ->where('leaderboard.error.reference', fn ($value): bool => is_string($value) && $value !== '');
                    });
            });
    }

    public function test_page_returns_an_empty_success_state_when_there_are_no_eligible_affiliates(): void
    {
        $this->mock(LeaderboardService::class, function (MockInterface $mock): void {
            $mock->shouldReceive('get')->once()->andReturn($this->leaderboardResult(entries: []));
        });

        $this->get('/')
            ->assertOk()
            ->assertInertia(function (AssertableInertia $page): void {
                $page->component('leaderboard')
                    ->loadDeferredProps(function (AssertableInertia $page): void {
                        $page->has('leaderboard.data', 0)
                            ->where('leaderboard.error', null)
                            ->where('leaderboard.meta.eligibleCount', 0);
                    });
            });
    }

    private function leaderboardResult(?array $entries = null): LeaderboardResult
    {
        $entries ??= [
            new LeaderboardEntry(
                rank: 1,
                affiliateId: 8572722,
                name: 'Lily Kertzmann',
                email: 'lily@example.com',
                commissionCents: 702513,
                currency: 'USD',
            ),
        ];

        return new LeaderboardResult(
            $entries,
            new LeaderboardMeta(
                generatedAt: '2026-11-12T20:00:00+00:00',
                cached: false,
                totalAffiliates: 44,
                scannedAffiliates: 20,
                eligibleCount: count($entries),
                customFieldRequests: 20,
                durationMs: 120,
            ),
        );
    }
}
