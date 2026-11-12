<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Leaderboard;

use App\DTOs\CustomField;
use App\Services\Leaderboard\LeaderboardEligibility;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class LeaderboardEligibilityTest extends TestCase
{
    public function test_exact_true_is_eligible(): void
    {
        $this->assertTrue(
            (new LeaderboardEligibility)->isEligible([
                new CustomField('Display in leaderboard', 'TRUE'),
            ]),
        );
    }

    #[DataProvider('ineligibleValues')]
    public function test_near_matches_are_not_eligible(string $value): void
    {
        $this->assertFalse(
            (new LeaderboardEligibility)->isEligible([
                new CustomField('Display in leaderboard', $value),
            ]),
        );
    }

    /**
     * @return array<string, array{0: string}>
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
        ];
    }

    public function test_a_different_label_is_not_eligible(): void
    {
        $this->assertFalse(
            (new LeaderboardEligibility)->isEligible([
                new CustomField('Display In Leaderboard', 'TRUE'),
            ]),
        );
    }

    public function test_any_exact_match_in_a_duplicate_field_list_is_eligible(): void
    {
        $this->assertTrue(
            (new LeaderboardEligibility)->isEligible([
                new CustomField('Display in leaderboard', 'false'),
                new CustomField('Display in leaderboard', 'TRUE'),
            ]),
        );
    }

    public function test_an_empty_field_list_is_not_eligible(): void
    {
        $this->assertFalse((new LeaderboardEligibility)->isEligible([]));
    }
}
