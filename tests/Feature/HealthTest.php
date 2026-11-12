<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Leaderboard\LeaderboardService;
use Mockery\MockInterface;
use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_endpoint_returns_ok(): void
    {
        $this->get('/health')
            ->assertOk()
            ->assertJson([
                'status' => 'ok',
            ]);
    }

    public function test_health_endpoint_does_not_call_refersion(): void
    {
        $this->mock(LeaderboardService::class, function (MockInterface $mock): void {
            $mock->shouldNotReceive('get');
        });

        $this->get('/health')->assertOk();
    }
}
