<?php

declare(strict_types=1);

namespace Tests\Unit\DTOs;

use App\DTOs\AffiliateSummary;
use App\DTOs\CustomField;
use App\DTOs\LeaderboardEntry;
use App\DTOs\LeaderboardMeta;
use App\DTOs\LeaderboardResult;
use PHPUnit\Framework\TestCase;

class DtoTest extends TestCase
{
    public function test_custom_field_keeps_exact_label_and_value(): void
    {
        $field = new CustomField('Display in leaderboard', 'TRUE');

        $this->assertSame('Display in leaderboard', $field->label);
        $this->assertSame('TRUE', $field->value);
    }

    public function test_display_name_prefers_first_and_last_name(): void
    {
        $affiliate = $this->affiliate(firstName: 'Avery', lastName: 'Cole', company: 'Acme', email: 'avery@example.com');

        $this->assertSame('Avery Cole', $affiliate->displayName());
    }

    public function test_display_name_falls_back_to_company(): void
    {
        $affiliate = $this->affiliate(firstName: null, lastName: null, company: 'Acme', email: 'avery@example.com');

        $this->assertSame('Acme', $affiliate->displayName());
    }

    public function test_display_name_falls_back_to_email_local_part(): void
    {
        $affiliate = $this->affiliate(firstName: null, lastName: null, company: null, email: 'avery@example.com');

        $this->assertSame('avery', $affiliate->displayName());
    }

    public function test_display_name_falls_back_to_affiliate_id(): void
    {
        $affiliate = $this->affiliate(firstName: null, lastName: null, company: null, email: null);

        $this->assertSame('Affiliate #123', $affiliate->displayName());
    }

    public function test_leaderboard_entry_serializes_expected_page_shape(): void
    {
        $entry = new LeaderboardEntry(
            rank: 1,
            affiliateId: 123,
            name: 'Avery Cole',
            email: 'avery@example.com',
            commissionCents: 702513,
            currency: 'USD',
        );

        $this->assertSame([
            'rank' => 1,
            'affiliateId' => 123,
            'name' => 'Avery Cole',
            'email' => 'avery@example.com',
            'commissionCents' => 702513,
            'currency' => 'USD',
        ], $entry->toArray());
    }

    public function test_leaderboard_result_serializes_data_and_meta(): void
    {
        $entry = new LeaderboardEntry(1, 123, 'Avery Cole', 'avery@example.com', 702513, 'USD');
        $meta = new LeaderboardMeta(
            generatedAt: '2026-11-12T20:00:00+00:00',
            cached: false,
            totalAffiliates: 44,
            scannedAffiliates: 20,
            eligibleCount: 5,
            customFieldRequests: 20,
            durationMs: 120,
        );

        $result = new LeaderboardResult([$entry], $meta);

        $this->assertSame($entry, $result->entries[0]);
        $this->assertSame([
            'data' => [$entry->toArray()],
            'meta' => [
                'generatedAt' => '2026-11-12T20:00:00+00:00',
                'cached' => false,
                'totalAffiliates' => 44,
                'scannedAffiliates' => 20,
                'eligibleCount' => 5,
                'customFieldRequests' => 20,
                'durationMs' => 120,
            ],
        ], $result->toArray());
    }

    private function affiliate(
        ?string $firstName,
        ?string $lastName,
        ?string $company,
        ?string $email,
    ): AffiliateSummary {
        return new AffiliateSummary(
            id: 123,
            firstName: $firstName,
            lastName: $lastName,
            company: $company,
            email: $email,
            commissionCents: 702513,
            currency: 'USD',
        );
    }
}
