<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Leaderboard\LeaderboardService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

class LeaderboardIntegrationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
        Sleep::fake();
        Http::preventStrayRequests();

        config([
            'services.refersion.client_id' => 'test-client-id',
            'services.refersion.client_secret' => 'test-client-secret',
            'services.refersion.auth_url' => 'https://auth.refersion.test/oauth/token',
            'services.refersion.base_url' => 'https://api.refersion.test/v2026-08',
            'services.refersion.token_type' => 'short_lived',
            'services.refersion.page_limit' => 100,
            'services.refersion.custom_fields_chunk' => 10,
            'services.refersion.leaderboard_size' => 5,
            'services.refersion.leaderboard_cache_ttl' => 300,
            'services.refersion.leaderboard_lock_ttl' => 600,
            'services.refersion.commission_unit' => 'cents',
            'services.refersion.max_pages' => 500,
            'services.refersion.max_candidates' => 1000,
            'services.refersion.timeout' => 15,
            'services.refersion.connect_timeout' => 5,
        ]);
    }

    public function test_a_decoy_chunk_continues_to_the_next_chunk_and_stops_after_five_eligible(): void
    {
        $values = [
            1 => 'TRUE',
            2 => 'true',
            3 => 'false',
            4 => 'TRUE',
            5 => 'no',
            6 => 'TRUE',
            7 => 'TRUE',
            8 => 'FALSE',
            9 => 'false',
            10 => 'no',
            11 => 'TRUE',
            12 => 'TRUE',
        ];

        $affiliates = [];
        for ($id = 1; $id <= 12; $id++) {
            $affiliates[] = [
                'id' => $id,
                'firstName' => "Affiliate {$id}",
                'lastName' => null,
                'company' => null,
                'email' => "affiliate{$id}@example.com",
                'performance' => [
                    'total' => [
                        'commission' => 1300 - ($id * 100),
                    ],
                ],
            ];
        }

        Http::fake(function (Request $request) use ($affiliates, $values) {
            if (str_contains($request->url(), 'auth.refersion.test')) {
                return Http::response([
                    'expires_in' => 3600,
                    'access_token' => 'token-1',
                ]);
            }

            if (str_contains($request->url(), '/affiliates?') || str_ends_with($request->url(), '/affiliates')) {
                return Http::response(['data' => $affiliates]);
            }

            if (preg_match('/affiliates\/(\d+)\/custom-fields/', $request->url(), $matches) === 1) {
                $id = (int) $matches[1];

                return Http::response([
                    'data' => [
                        ['label' => 'Display in leaderboard', 'value' => $values[$id] ?? ''],
                    ],
                ]);
            }

            return Http::response(['error' => 'Unexpected request'], 500);
        });

        $result = app(LeaderboardService::class)->refresh();

        $this->assertSame([1, 4, 6, 7, 11], array_map(fn ($entry) => $entry->affiliateId, $result->entries));
        $this->assertSame([1, 2, 3, 4, 5], array_map(fn ($entry) => $entry->rank, $result->entries));
        $this->assertSame(11, $result->meta->scannedAffiliates);
        $this->assertSame(12, $result->meta->customFieldRequests);
        Http::assertSentCount(14); // 1 auth + 1 list + 10 first-chunk fields + 2 second-chunk fields
    }
}
