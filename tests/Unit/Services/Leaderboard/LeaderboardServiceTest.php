<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Leaderboard;

use App\DTOs\AffiliateSummary;
use App\DTOs\CustomField;
use App\Exceptions\LeaderboardBusyException;
use App\Exceptions\RefersionApiException;
use App\Exceptions\RefersionScanLimitException;
use App\Services\Leaderboard\LeaderboardService;
use App\Services\Refersion\RefersionClient;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Support\Facades\Cache;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class LeaderboardServiceTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();

        config([
            'services.refersion.client_id' => 'test-client-id',
            'services.refersion.leaderboard_size' => 5,
            'services.refersion.leaderboard_cache_ttl' => 300,
            'services.refersion.custom_fields_chunk' => 10,
            'services.refersion.max_candidates' => 1000,
        ]);
    }

    public function test_exact_true_is_eligible(): void
    {
        $client = Mockery::mock(RefersionClient::class);
        $client->shouldReceive('listAllAffiliates')->once()->andReturn([
            $this->affiliate(1, 702513, 'Avery Cole'),
        ]);
        $client->shouldReceive('getCustomFieldsForMany')->once()->with([1])->andReturn([
            1 => [new CustomField('Display in leaderboard', 'TRUE')],
        ]);

        $result = (new LeaderboardService($client))->get();

        $this->assertCount(1, $result->entries);
        $this->assertSame('Avery Cole', $result->entries[0]->name);
        $this->assertSame(702513, $result->entries[0]->commissionCents);
    }

    #[DataProvider('ineligibleValues')]
    public function test_near_matches_are_not_eligible(?string $value): void
    {
        $client = Mockery::mock(RefersionClient::class);
        $client->shouldReceive('listAllAffiliates')->once()->andReturn([
            $this->affiliate(1, 702513, 'Avery Cole'),
        ]);
        $client->shouldReceive('getCustomFieldsForMany')->once()->andReturn([
            1 => $value === null ? [] : [new CustomField('Display in leaderboard', $value)],
        ]);

        $result = (new LeaderboardService($client))->get();

        $this->assertSame([], $result->entries);
    }

    /**
     * @return array<string, array{0: string|null}>
     */
    public static function ineligibleValues(): array
    {
        return [
            'lowercase true' => ['true'],
            'trailing space' => ['TRUE '],
            'truncated' => ['TRU'],
            'mixed case' => ['TRUe'],
            'uppercase false' => ['FALSE'],
            'lowercase false' => ['false'],
            'no' => ['no'],
            'yes' => ['yes'],
            'yup' => ['YUP'],
            'missing field' => [null],
        ];
    }

    public function test_orders_by_commission_descending(): void
    {
        $client = Mockery::mock(RefersionClient::class);
        $client->shouldReceive('listAllAffiliates')->once()->andReturn([
            $this->affiliate(1, 100, 'Low'),
            $this->affiliate(2, 300, 'High'),
            $this->affiliate(3, 200, 'Middle'),
        ]);
        $this->mockAllEligible($client);

        $entries = (new LeaderboardService($client))->get()->entries;

        $this->assertSame(['High', 'Middle', 'Low'], array_map(fn ($entry) => $entry->name, $entries));
        $this->assertSame([1, 2, 3], array_map(fn ($entry) => $entry->rank, $entries));
    }

    public function test_ties_break_by_affiliate_id(): void
    {
        $client = Mockery::mock(RefersionClient::class);
        $client->shouldReceive('listAllAffiliates')->once()->andReturn([
            $this->affiliate(2, 500, 'Second'),
            $this->affiliate(1, 500, 'First'),
        ]);
        $this->mockAllEligible($client);

        $entries = (new LeaderboardService($client))->get()->entries;

        $this->assertSame(['First', 'Second'], array_map(fn ($entry) => $entry->name, $entries));
    }

    public function test_only_five_entries_are_returned(): void
    {
        $client = Mockery::mock(RefersionClient::class);
        $client->shouldReceive('listAllAffiliates')->once()->andReturn([
            $this->affiliate(1, 600, 'One'),
            $this->affiliate(2, 500, 'Two'),
            $this->affiliate(3, 400, 'Three'),
            $this->affiliate(4, 300, 'Four'),
            $this->affiliate(5, 200, 'Five'),
            $this->affiliate(6, 100, 'Six'),
        ]);
        $this->mockAllEligible($client);

        $entries = (new LeaderboardService($client))->get()->entries;

        $this->assertCount(5, $entries);
        $this->assertSame(['One', 'Two', 'Three', 'Four', 'Five'], array_map(fn ($entry) => $entry->name, $entries));
    }

    public function test_fewer_than_five_returns_available_entries(): void
    {
        $client = Mockery::mock(RefersionClient::class);
        $client->shouldReceive('listAllAffiliates')->once()->andReturn([
            $this->affiliate(1, 300, 'One'),
            $this->affiliate(2, 200, 'Two'),
        ]);
        $this->mockAllEligible($client);

        $result = (new LeaderboardService($client))->get();

        $this->assertCount(2, $result->entries);
        $this->assertSame(2, $result->meta->eligibleCount);
    }

    public function test_null_commission_sorts_last_and_becomes_zero(): void
    {
        $client = Mockery::mock(RefersionClient::class);
        $client->shouldReceive('listAllAffiliates')->once()->andReturn([
            $this->affiliate(1, 0, 'Zero'),
            $this->affiliate(2, 100, 'Positive'),
        ]);
        $this->mockAllEligible($client);

        $entries = (new LeaderboardService($client))->get()->entries;

        $this->assertSame(['Positive', 'Zero'], array_map(fn ($entry) => $entry->name, $entries));
        $this->assertSame(0, $entries[1]->commissionCents);
    }

    public function test_negative_commissions_sort_below_positive_commissions(): void
    {
        $client = Mockery::mock(RefersionClient::class);
        $client->shouldReceive('listAllAffiliates')->once()->andReturn([
            $this->affiliate(1, -100, 'Negative'),
            $this->affiliate(2, 100, 'Positive'),
        ]);
        $this->mockAllEligible($client);

        $entries = (new LeaderboardService($client))->get()->entries;

        $this->assertSame(['Positive', 'Negative'], array_map(fn ($entry) => $entry->name, $entries));
    }

    public function test_early_exit_stops_after_five_eligible_affiliates(): void
    {
        config(['services.refersion.custom_fields_chunk' => 5]);

        $affiliates = [];
        for ($id = 1; $id <= 12; $id++) {
            $affiliates[] = $this->affiliate($id, 1000 - $id, "Affiliate {$id}");
        }

        $client = Mockery::mock(RefersionClient::class);
        $client->shouldReceive('listAllAffiliates')->once()->andReturn($affiliates);
        $client->shouldReceive('getCustomFieldsForMany')
            ->once()
            ->with([1, 2, 3, 4, 5])
            ->andReturnUsing(fn (array $ids): array => $this->eligibleFields($ids));

        $result = (new LeaderboardService($client))->get();

        $this->assertCount(5, $result->entries);
        $this->assertSame(5, $result->meta->scannedAffiliates);
        $this->assertSame(5, $result->meta->customFieldRequests);
    }

    public function test_empty_affiliate_list_makes_no_custom_field_requests(): void
    {
        $client = Mockery::mock(RefersionClient::class);
        $client->shouldReceive('listAllAffiliates')->once()->andReturn([]);
        $client->shouldNotReceive('getCustomFieldsForMany');

        $result = (new LeaderboardService($client))->get();

        $this->assertSame([], $result->entries);
        $this->assertSame(0, $result->meta->totalAffiliates);
        $this->assertSame(0, $result->meta->customFieldRequests);
    }

    public function test_scan_limit_throws_instead_of_returning_a_partial_result(): void
    {
        config([
            'services.refersion.custom_fields_chunk' => 1,
            'services.refersion.max_candidates' => 2,
        ]);

        $client = Mockery::mock(RefersionClient::class);
        $client->shouldReceive('listAllAffiliates')->once()->andReturn([
            $this->affiliate(1, 300, 'One'),
            $this->affiliate(2, 200, 'Two'),
            $this->affiliate(3, 100, 'Three'),
        ]);
        $client->shouldReceive('getCustomFieldsForMany')->twice()->andReturn([]);

        $this->expectException(RefersionScanLimitException::class);

        (new LeaderboardService($client))->get();
    }

    public function test_second_call_uses_the_cache(): void
    {
        $client = Mockery::mock(RefersionClient::class);
        $client->shouldReceive('listAllAffiliates')->once()->andReturn([
            $this->affiliate(1, 702513, 'Avery Cole'),
        ]);
        $client->shouldReceive('getCustomFieldsForMany')->once()->andReturn([
            1 => [new CustomField('Display in leaderboard', 'TRUE')],
        ]);

        $service = new LeaderboardService($client);

        $first = $service->get();
        $second = $service->get();

        $this->assertFalse($first->meta->cached);
        $this->assertTrue($second->meta->cached);
        $this->assertCount(1, $second->entries);
    }

    public function test_refresh_bypasses_the_cache(): void
    {
        $client = Mockery::mock(RefersionClient::class);
        $client->shouldReceive('listAllAffiliates')->twice()->andReturn([
            $this->affiliate(1, 702513, 'Avery Cole'),
        ]);
        $client->shouldReceive('getCustomFieldsForMany')->twice()->andReturn([
            1 => [new CustomField('Display in leaderboard', 'TRUE')],
        ]);

        $service = new LeaderboardService($client);

        $first = $service->get();
        $second = $service->refresh();

        $this->assertFalse($first->meta->cached);
        $this->assertFalse($second->meta->cached);
    }

    public function test_lock_ttl_is_longer_than_the_interactive_build_budget(): void
    {
        config(['services.refersion.leaderboard_lock_ttl' => 600]);

        $service = new LeaderboardService(Mockery::mock(RefersionClient::class));

        $this->assertSame(600, $service->lockTtl());
    }

    public function test_lock_timeout_throws_busy_when_no_cached_result_exists(): void
    {
        $client = Mockery::mock(RefersionClient::class);
        $lock = Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->once()->andThrow(new LockTimeoutException);

        Cache::shouldReceive('get')->twice()->andReturnNull();
        Cache::shouldReceive('lock')->once()->andReturn($lock);

        $this->expectException(LeaderboardBusyException::class);

        (new LeaderboardService($client))->get();
    }

    public function test_cached_returns_the_last_successful_result_without_contacting_the_api(): void
    {
        $client = Mockery::mock(RefersionClient::class);
        $client->shouldReceive('listAllAffiliates')->once()->andReturn([
            $this->affiliate(1, 702513, 'Avery Cole'),
        ]);
        $client->shouldReceive('getCustomFieldsForMany')->once()->andReturn([
            1 => [new CustomField('Display in leaderboard', 'TRUE')],
        ]);

        $service = new LeaderboardService($client);

        $this->assertNull($service->cached());

        $first = $service->get();
        $cached = $service->cached();

        $this->assertNotNull($cached);
        $this->assertTrue($cached->meta->cached);
        $this->assertSame($first->entries[0]->name, $cached->entries[0]->name);
    }

    public function test_failed_refresh_preserves_the_previous_cache_entry(): void
    {
        $client = Mockery::mock(RefersionClient::class);
        $client->shouldReceive('listAllAffiliates')->once()->andReturn([
            $this->affiliate(1, 702513, 'Avery Cole'),
        ]);
        $client->shouldReceive('listAllAffiliates')->once()->andThrow(new RefersionApiException('upstream failure'));
        $client->shouldReceive('getCustomFieldsForMany')->once()->andReturn([
            1 => [new CustomField('Display in leaderboard', 'TRUE')],
        ]);

        $service = new LeaderboardService($client);
        $first = $service->get();

        try {
            $service->refresh();
            $this->fail('Expected the refresh to fail.');
        } catch (RefersionApiException) {
            // The previous cache entry must survive the failed refresh.
        }

        $cached = $service->get();

        $this->assertTrue($cached->meta->cached);
        $this->assertSame($first->entries[0]->name, $cached->entries[0]->name);
    }

    private function mockAllEligible(Mockery\MockInterface&RefersionClient $client): void
    {
        $client->shouldReceive('getCustomFieldsForMany')
            ->once()
            ->andReturnUsing(fn (array $ids): array => $this->eligibleFields($ids));
    }

    /**
     * @param  int[]  $ids
     * @return array<int, CustomField[]>
     */
    private function eligibleFields(array $ids): array
    {
        $fields = [];

        foreach ($ids as $id) {
            $fields[$id] = [new CustomField('Display in leaderboard', 'TRUE')];
        }

        return $fields;
    }

    private function affiliate(int $id, int $commissionCents, string $name): AffiliateSummary
    {
        return new AffiliateSummary(
            id: $id,
            firstName: $name,
            lastName: null,
            company: null,
            email: 'affiliate'.$id.'@example.com',
            commissionCents: $commissionCents,
            currency: 'USD',
        );
    }
}
